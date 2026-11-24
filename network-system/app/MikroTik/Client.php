<?php
declare(strict_types=1);

namespace App\MikroTik;

/**
 * عميل بروتوكول RouterOS API (المنفذ 8728، أو 8729 المشفّر).
 * يعمل مع RouterOS 6 و 7، ويدعم طريقتي تسجيل الدخول (6.43+ والأقدم).
 */
final class Client
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port = 8728,
        private bool $ssl = false,
        private float $timeout = 5.0
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(string $username, string $password): void
    {
        $this->open();
        $reply = $this->talk(['/login', '=name=' . $username, '=password=' . $password], true);
        if ($reply['trap'] !== null) {
            $this->close();
            throw new MikroTikException($reply['trap'], MikroTikException::AUTH, $reply['trap']);
        }
        // RouterOS أقدم من 6.43: يعيد تحدياً يجب الرد عليه بـ MD5
        $challenge = $reply['done']['ret'] ?? null;
        if (is_string($challenge) && preg_match('/^[0-9a-f]{32}$/i', $challenge)) {
            $response = '00' . md5("\x00" . $password . (string) hex2bin($challenge));
            $reply = $this->talk(['/login', '=name=' . $username, '=response=' . $response], true);
            if ($reply['trap'] !== null) {
                $this->close();
                throw new MikroTikException($reply['trap'], MikroTikException::AUTH, $reply['trap']);
            }
        }
    }

    private function open(): void
    {
        $host = $this->host;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'security_level'    => 0,
                'ciphers'           => 'DEFAULT:ADH:@SECLEVEL=0',
            ],
            'socket' => ['tcp_nodelay' => true],
        ]);
        $errno = 0;
        $errstr = '';
        $uri = ($this->ssl ? 'ssl://' : 'tcp://') . $host . ':' . $this->port;
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            $reason = $errstr !== '' ? $errstr : 'لا يوجد رد';
            if ($errno === 110 || stripos($reason, 'timed out') !== false) {
                throw new MikroTikException('انتهت مهلة الاتصال بـ ' . $this->host . ':' . $this->port, MikroTikException::TIMEOUT);
            }
            throw new MikroTikException($this->host . ':' . $this->port . ' — ' . $reason, MikroTikException::CONNECT);
        }
        stream_set_timeout($socket, (int) ceil(max(2.0, $this->timeout * 3)));
        $this->socket = $socket;
    }

    public function connected(): bool
    {
        return is_resource($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** تعديل مهلة القراءة (للأوامر البطيئة مثل ping) */
    public function setReadTimeout(int $seconds): void
    {
        if (is_resource($this->socket)) {
            stream_set_timeout($this->socket, max(1, $seconds));
        }
    }

    // ---------------------------------------------------------------------
    // الأوامر عالية المستوى
    // ---------------------------------------------------------------------

    /**
     * تنفيذ أمر وإرجاع صفوف !re.
     * @param array $args   خصائص =key=value
     * @param array $where  شروط ?key=value
     */
    public function command(string $path, array $args = [], array $where = [], ?array $proplist = null): array
    {
        $words = [$path];
        foreach ($args as $k => $v) {
            $words[] = '=' . $k . '=' . self::value($v);
        }
        if ($proplist) {
            $words[] = '=.proplist=' . implode(',', $proplist);
        }
        foreach ($where as $k => $v) {
            $words[] = '?' . $k . '=' . self::value($v);
        }
        $reply = $this->talk($words);
        if ($reply['trap'] !== null) {
            throw new MikroTikException($reply['trap'], MikroTikException::TRAP, $reply['trap']);
        }
        return $reply['rows'];
    }

    /**
     * أمر يرسل نتائج متتالية بلا نهاية (مثل cable-test على RouterOS، يتجدد حتى يُوقف): يُؤخذ أول صف مكتمل
     * ثم يُوقف الأمر بـ /cancel ويُقرأ كل ما تبقى من رده ورد الإيقاف، فيبقى الاتصال نظيفاً للأوامر التالية.
     * إن أنهى الجهاز الأمر بنفسه (!done) يُعاد آخر صف وصل.
     * @param ?callable $ready يقرر أن الصف مكتمل؛ وإلا يُؤخذ أول صف
     * @param int $maxRows أقصى عدد صفوف يُنتظر قبل أخذ آخرها
     */
    public function firstRow(string $path, array $args = [], ?callable $ready = null, int $maxRows = 6): array
    {
        if (!is_resource($this->socket)) {
            throw new MikroTikException('الاتصال بجهاز MikroTik غير مفتوح.', MikroTikException::CONNECT);
        }
        $tag = 'q' . bin2hex(random_bytes(4));
        $cancelTag = 'c' . substr($tag, 1);
        $words = [$path];
        foreach ($args as $k => $v) {
            $words[] = '=' . $k . '=' . self::value($v);
        }
        $words[] = '.tag=' . $tag;
        $this->writeSentence($words);
        $got = null;
        $last = [];
        $rows = 0;
        $trap = null;
        $cancelled = false;
        $commandDone = false;
        $cancelDone = false;
        while (!($commandDone && (!$cancelled || $cancelDone))) {
            $sentence = $this->readSentence();
            if (!$sentence) {
                continue;
            }
            $type = array_shift($sentence);
            $attrs = self::parseAttributes($sentence);
            $from = $attrs['.tag'] ?? '';
            if ($type === '!fatal') {
                $msg = (string) ($attrs['message'] ?? ($sentence[0] ?? 'انقطع الاتصال'));
                $this->close();
                throw new MikroTikException($msg, MikroTikException::PROTOCOL, $msg);
            }
            if ($from === $cancelTag) {
                $cancelDone = $cancelDone || $type === '!done';
                continue;
            }
            if ($from !== $tag) {
                continue;
            }
            if ($type === '!re') {
                unset($attrs['.tag']);
                $last = $attrs;
                $rows++;
                if ($got === null && ($ready === null || $ready($attrs) || $rows >= $maxRows)) {
                    $got = $attrs;
                    if (!$commandDone && !$cancelled) {
                        $this->writeSentence(['/cancel', '=tag=' . $tag, '.tag=' . $cancelTag]);
                        $cancelled = true;
                    }
                }
            } elseif ($type === '!trap') {
                // بعد الإيقاف يرد الجهاز «interrupted» (category=2): ليس خطأً
                if (!($cancelled && ($attrs['category'] ?? '') === '2')) {
                    $trap ??= (string) ($attrs['message'] ?? 'خطأ غير معروف');
                }
            } elseif ($type === '!done') {
                $commandDone = true;
            }
        }
        if ($trap !== null && $got === null) {
            throw new MikroTikException($trap, MikroTikException::TRAP, $trap);
        }
        return $got ?? $last;
    }

    /** نفس command مع إرجاع قيم !done (مثل ret) */
    public function exec(string $path, array $args = [], array $where = []): array
    {
        $words = [$path];
        foreach ($args as $k => $v) {
            $words[] = '=' . $k . '=' . self::value($v);
        }
        foreach ($where as $k => $v) {
            $words[] = '?' . $k . '=' . self::value($v);
        }
        $reply = $this->talk($words);
        if ($reply['trap'] !== null) {
            throw new MikroTikException($reply['trap'], MikroTikException::TRAP, $reply['trap']);
        }
        return ['rows' => $reply['rows'], 'done' => $reply['done']];
    }

    public function print(string $menu, array $where = [], ?array $proplist = null): array
    {
        return $this->command(rtrim($menu, '/') . '/print', [], $where, $proplist);
    }

    public function first(string $menu, array $where = [], ?array $proplist = null): ?array
    {
        $rows = $this->print($menu, $where, $proplist);
        return $rows[0] ?? null;
    }

    public function count(string $menu, array $where = []): int
    {
        $r = $this->exec(rtrim($menu, '/') . '/print', ['count-only' => ''], $where);
        return (int) ($r['done']['ret'] ?? 0);
    }

    public function add(string $menu, array $props): string
    {
        $r = $this->exec(rtrim($menu, '/') . '/add', $props);
        return (string) ($r['done']['ret'] ?? '');
    }

    public function set(string $menu, string $id, array $props): void
    {
        $this->exec(rtrim($menu, '/') . '/set', ['.id' => $id] + $props);
    }

    public function remove(string $menu, string|array $ids): void
    {
        $ids = is_array($ids) ? implode(',', $ids) : $ids;
        if ($ids === '') {
            return;
        }
        $this->exec(rtrim($menu, '/') . '/remove', ['.id' => $ids]);
    }

    /** تحويل القيم إلى صيغة RouterOS */
    public static function value(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if ($v === null) {
            return '';
        }
        return (string) $v;
    }

    // ---------------------------------------------------------------------
    // البروتوكول
    // ---------------------------------------------------------------------

    /**
     * إرسال جملة وقراءة الرد حتى !done.
     * @return array{rows: array, done: array, trap: ?string}
     */
    private function talk(array $words, bool $login = false): array
    {
        if (!is_resource($this->socket)) {
            throw new MikroTikException('الاتصال بجهاز MikroTik غير مفتوح.', MikroTikException::CONNECT);
        }
        $this->writeSentence($words);

        $rows = [];
        $done = [];
        $trap = null;
        while (true) {
            $sentence = $this->readSentence();
            if (!$sentence) {
                continue;
            }
            $type = array_shift($sentence);
            $attrs = self::parseAttributes($sentence);
            switch ($type) {
                case '!re':
                    $rows[] = $attrs;
                    break;
                case '!trap':
                    if ($trap === null) {
                        $trap = (string) ($attrs['message'] ?? 'خطأ غير معروف');
                    }
                    break;
                case '!fatal':
                    $msg = $attrs['message'] ?? (string) ($sentence[0] ?? 'انقطع الاتصال');
                    $this->close();
                    throw new MikroTikException((string) $msg, $login ? MikroTikException::AUTH : MikroTikException::PROTOCOL, (string) $msg);
                case '!done':
                    $done = $attrs;
                    return ['rows' => $rows, 'done' => $done, 'trap' => $trap];
                case '!empty':
                    break;
                default:
                    // جمل غير معروفة تُتجاهل
                    break;
            }
        }
    }

    public static function parseAttributes(array $words): array
    {
        $attrs = [];
        foreach ($words as $w) {
            if ($w === '' || $w[0] !== '=') {
                if (str_starts_with($w, '.tag=')) {
                    $attrs['.tag'] = substr($w, 5);
                }
                continue;
            }
            $pos = strpos($w, '=', 1);
            if ($pos === false) {
                $attrs[substr($w, 1)] = '';
            } else {
                $attrs[substr($w, 1, $pos - 1)] = substr($w, $pos + 1);
            }
        }
        return $attrs;
    }

    private function writeSentence(array $words): void
    {
        $data = '';
        foreach ($words as $w) {
            $data .= self::encodeLength(strlen($w)) . $w;
        }
        $data .= "\x00";
        $len = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @fwrite($this->socket, substr($data, $sent));
            if ($n === false || $n === 0) {
                $this->close();
                throw new MikroTikException('انقطع الاتصال أثناء الإرسال.', MikroTikException::CONNECT);
            }
            $sent += $n;
        }
    }

    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $len = $this->readLength();
            if ($len === 0) {
                return $words;
            }
            $words[] = $this->readBytes($len);
        }
    }

    private function readLength(): int
    {
        $b = ord($this->readBytes(1));
        if (($b & 0x80) === 0x00) {
            return $b;
        }
        if (($b & 0xC0) === 0x80) {
            return (($b & ~0xC0) << 8) + ord($this->readBytes(1));
        }
        if (($b & 0xE0) === 0xC0) {
            $r = $this->readBytes(2);
            return (($b & ~0xE0) << 16) + (ord($r[0]) << 8) + ord($r[1]);
        }
        if (($b & 0xF0) === 0xE0) {
            $r = $this->readBytes(3);
            return (($b & ~0xF0) << 24) + (ord($r[0]) << 16) + (ord($r[1]) << 8) + ord($r[2]);
        }
        if ($b === 0xF0) {
            $r = $this->readBytes(4);
            return (ord($r[0]) << 24) + (ord($r[1]) << 16) + (ord($r[2]) << 8) + ord($r[3]);
        }
        throw new MikroTikException('طول كلمة غير صالح في رد جهاز MikroTik.', MikroTikException::PROTOCOL);
    }

    private function readBytes(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : ['timed_out' => false, 'eof' => true];
                $this->close();
                if (!empty($meta['timed_out'])) {
                    throw new MikroTikException('انتهت مهلة انتظار رد جهاز MikroTik.', MikroTikException::TIMEOUT);
                }
                throw new MikroTikException('أغلق جهاز MikroTik الاتصال.', MikroTikException::CONNECT);
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    public static function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        if ($len < 0x4000) {
            $len |= 0x8000;
            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;
            return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        return chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }
}
