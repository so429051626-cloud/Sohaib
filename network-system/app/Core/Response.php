<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
        $this->headers += ['Content-Type' => 'text/html; charset=UTF-8'];
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self($body === false ? '{}' : $body, $status, $headers + [
            'Content-Type'  => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain; charset=UTF-8'): self
    {
        return new self($body, $status, ['Content-Type' => $type]);
    }

    public static function download(string $content, string $filename, string $mime = 'application/octet-stream'): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self($content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Content-Length'      => (string) strlen($content),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
