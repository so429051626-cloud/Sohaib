<?php
declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * إرسال بريد عبر خادم SMTP (Gmail أو Outlook أو بريد الاستضافة أو أي خادم):
 * التشفير tls (STARTTLS، المنفذ 587 غالباً) أو ssl (المنفذ 465) أو بدون، والدخول بـ AUTH LOGIN ثم PLAIN.
 * الرسالة نص عادي وHTML معاً بترميز UTF-8، والعنوان بالعربية مُرمَّز. كلمة المرور لا تظهر في أي رسالة خطأ.
 */
final class Mailer
{
    public const SECURE = ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'بدون تشفير'];

    /** @var resource|null */
    private $sock = null;

    /**
     * @param array{host:string, port:int, secure:string, user:string, pass:string, from:string, from_name:string, timeout?:int} $cfg
     * @return array{ok:bool, message:string}
     */
    public static function send(array $cfg, string $to, string $subject, string $html, string $text): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'عنوان البريد غير صالح.'];
        }
        if (trim((string) $cfg['host']) === '' || !filter_var((string) $cfg['from'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'إعدادات البريد غير مكتملة.'];
        }
        $m = new self();
        try {
            $m->deliver($cfg, $to, $m->message($cfg, $to, $subject, $html, $text));
            return ['ok' => true, 'message' => 'تم الإرسال.'];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ((string) $cfg['pass'] !== '') {
                $msg = str_replace([(string) $cfg['pass'], base64_encode((string) $cfg['pass'])], '***', $msg);
            }
            return ['ok' => false, 'message' => $msg];
        } finally {
            $m->close();
        }
    }

    private function deliver(array $cfg, string $to, string $data): void
    {
        $host = trim((string) $cfg['host']);
        $port = (int) $cfg['port'] ?: ($cfg['secure'] === 'ssl' ? 465 : 587);
        $timeout = max(5, (int) ($cfg['timeout'] ?? 20));
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $target = ($cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $sock = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new \RuntimeException('تعذّر الاتصال بخادم البريد ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '.');
        }
        $this->sock = $sock;
        stream_set_timeout($sock, $timeout);
        $this->expect([220], 'الخادم لم يرحّب بالاتصال');
        $me = gethostname() ?: 'localhost';
        $ehlo = $this->command('EHLO ' . $me, [250], 'رفض الخادم التعريف');
        if ($cfg['secure'] === 'tls') {
            if (!preg_match('/^250[ -]STARTTLS/mi', $ehlo)) {
                throw new \RuntimeException('خادم البريد لا يدعم TLS (STARTTLS) على هذا المنفذ. جرّب SSL على المنفذ 465.');
            }
            $this->command('STARTTLS', [220], 'رفض الخادم بدء التشفير');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
                throw new \RuntimeException('تعذّر التشفير مع خادم البريد (شهادة غير صالحة أو إعداد خاطئ).');
            }
            $ehlo = $this->command('EHLO ' . $me, [250], 'رفض الخادم التعريف');
        }
        $user = (string) $cfg['user'];
        if ($user !== '') {
            $methods = preg_match('/^250[ -]AUTH[ =](.*)$/mi', $ehlo, $am) ? strtoupper($am[1]) : 'LOGIN PLAIN';
            $pass = (string) $cfg['pass'];
            $ok = false;
            if (str_contains($methods, 'LOGIN')) {
                [$code] = $this->raw('AUTH LOGIN');
                if ($code === 334) {
                    [$code] = $this->raw(base64_encode($user));
                    if ($code === 334) {
                        [$code] = $this->raw(base64_encode($pass));
                        $ok = $code === 235;
                    }
                }
            }
            if (!$ok && str_contains($methods, 'PLAIN')) {
                [$code] = $this->raw('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
                $ok = $code === 235;
            }
            if (!$ok) {
                throw new \RuntimeException('رفض خادم البريد اسم المستخدم أو كلمة المرور. (في Gmail استخدم «كلمة مرور التطبيقات».)');
            }
        }
        $this->command('MAIL FROM:<' . $cfg['from'] . '>', [250], 'رفض الخادم عنوان المرسل');
        $this->command('RCPT TO:<' . $to . '>', [250, 251], 'رفض الخادم عنوان المستلم');
        $this->command('DATA', [354], 'رفض الخادم استلام الرسالة');
        // سطر يبدأ بنقطة تُضاف له نقطة (حسب المعيار)
        $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
        fwrite($sock, str_replace("\n", "\r\n", (string) $body) . "\r\n.\r\n");
        $this->expect([250], 'لم يقبل الخادم الرسالة');
        $this->raw('QUIT');
    }

    private function message(array $cfg, string $to, string $subject, string $html, string $text): string
    {
        $enc = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $domain = substr((string) strrchr((string) $cfg['from'], '@'), 1) ?: 'localhost';
        $boundary = 'mnx-' . bin2hex(random_bytes(12));
        $name = trim((string) $cfg['from_name']);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($name !== '' ? $enc($name) . ' ' : '') . '<' . $cfg['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $part = static fn (string $type, string $content): string => '--' . $boundary . "\n"
            . 'Content-Type: ' . $type . '; charset=UTF-8' . "\n" . 'Content-Transfer-Encoding: base64' . "\n\n"
            . rtrim(chunk_split(base64_encode($content), 76, "\n")) . "\n";
        return implode("\n", $headers) . "\n\n" . $part('text/plain', $text) . $part('text/html', $html) . '--' . $boundary . "--\n";
    }

    /** @return array{0:int, 1:string} */
    private function raw(string $line): array
    {
        fwrite($this->sock, $line . "\r\n");
        return $this->read();
    }

    /** @return array{0:int, 1:string} */
    private function read(): array
    {
        $all = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $all .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        if ($all === '') {
            $meta = stream_get_meta_data($this->sock);
            throw new \RuntimeException(!empty($meta['timed_out']) ? 'انتهت مهلة الاتصال بخادم البريد.' : 'انقطع الاتصال بخادم البريد.');
        }
        return [(int) substr($all, 0, 3), $all];
    }

    private function expect(array $codes, string $what): string
    {
        [$code, $reply] = $this->read();
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException($what . ': ' . trim(mb_substr($reply, 0, 200)));
        }
        return $reply;
    }

    private function command(string $line, array $codes, string $what): string
    {
        [$code, $reply] = $this->raw($line);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException($what . ': ' . trim(mb_substr($reply, 0, 200)));
        }
        return $reply;
    }

    private function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }
}
