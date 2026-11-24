<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $current = null;

    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    public array $server;
    private ?array $json = null;
    private ?string $raw = null;

    public function __construct(array $server, array $query, array $post, array $files)
    {
        $this->server = $server;
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
        $this->method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        // عند التشغيل من جذر الاستضافة مع إعادة التوجيه إلى public/
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        }
        $path = '/' . trim($path, '/');
        $this->path = $path;
    }

    public static function capture(): self
    {
        self::$current = new self($_SERVER, $_GET, $_POST, $_FILES);
        return self::$current;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(self $request): void
    {
        self::$current = $request;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function raw(): string
    {
        if ($this->raw === null) {
            $this->raw = (string) file_get_contents('php://input');
        }
        return $this->raw;
    }

    public function json(): array
    {
        if ($this->json === null) {
            $type = (string) ($this->server['CONTENT_TYPE'] ?? '');
            $decoded = str_contains($type, 'json') ? json_decode($this->raw(), true) : null;
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        if (is_string($v)) {
            $v = latin_digits(trim($v));
        }
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->input($key);
        if (is_string($v)) {
            $v = latin_digits(trim($v));
        }
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key);
        return in_array($v, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        return is_array($v) ? $v : [];
    }

    /** مصفوفة أعداد صحيحة موجبة فريدة */
    public function ids(string $key): array
    {
        $ids = [];
        foreach ($this->arr($key) as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[(int) $v] = (int) $v;
            }
        }
        return array_values($ids);
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->input($k);
        }
        return $out;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->json(), $this->post);
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
            return null;
        }
        return $f['error'] === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name']) ? $f : null;
    }

    /** سبب فشل رفع الملف من جهة الخادم (إن وُجد) */
    public function uploadError(string $key): ?string
    {
        $e = $this->files[$key]['error'] ?? UPLOAD_ERR_NO_FILE;
        return match (is_int($e) ? $e : UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من الحد المسموح على الخادم.',
            UPLOAD_ERR_PARTIAL => 'لم يكتمل رفع الملف. حاول مرة أخرى.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'تعذّر حفظ الملف على الخادم: المجلد المؤقت للرفع غير متاح.',
            default => null,
        };
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || ($this->header('X-Requested-With') === 'XMLHttpRequest')
            || str_starts_with($this->path, '/api/');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        if (strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function origin(): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        return ($this->isHttps() ? 'https' : 'http') . '://' . $host;
    }

    /** الرابط الحالي مع استبدال/إضافة معاملات الاستعلام */
    public function urlWith(array $params): string
    {
        $q = array_merge($this->query, $params);
        return url($this->path, $q);
    }
}
