<?php
declare(strict_types=1);

namespace App\Core;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * تخزين الجلسات في مجلد النظام نفسه (storage/sessions) بدل إعداد الخادم،
 * مع قفل الملف طوال الطلب كما يفعل معالج PHP الافتراضي.
 */
final class FileSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var resource|null */
    private $fh = null;
    private ?string $id = null;

    public function __construct(private string $dir)
    {
    }

    private function file(string $id): string
    {
        return $this->dir . '/sess_' . preg_replace('/[^A-Za-z0-9,-]/', '', $id);
    }

    private function lock(string $id): bool
    {
        if ($this->fh !== null && $this->id === $id) {
            return true;
        }
        $this->unlock();
        $fh = @fopen($this->file($id), 'c+');
        if ($fh === false) {
            return false;
        }
        flock($fh, LOCK_EX);
        $this->fh = $fh;
        $this->id = $id;
        return true;
    }

    private function unlock(): void
    {
        if ($this->fh !== null) {
            flock($this->fh, LOCK_UN);
            fclose($this->fh);
        }
        $this->fh = null;
        $this->id = null;
    }

    public function open(string $path, string $name): bool
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    public function close(): bool
    {
        $this->unlock();
        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->lock($id)) {
            return false;
        }
        rewind($this->fh);
        $data = stream_get_contents($this->fh);
        return $data === false ? '' : $data;
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->lock($id)) {
            return false;
        }
        rewind($this->fh);
        ftruncate($this->fh, 0);
        $ok = fwrite($this->fh, $data) === strlen($data);
        fflush($this->fh);
        return $ok;
    }

    public function destroy(string $id): bool
    {
        if ($this->id === $id) {
            $this->unlock();
        }
        $file = $this->file($id);
        return !is_file($file) || @unlink($file);
    }

    public function gc(int $max_lifetime): int|false
    {
        $n = 0;
        $limit = time() - $max_lifetime;
        foreach (glob($this->dir . '/sess_*') ?: [] as $file) {
            if (@filemtime($file) < $limit && @unlink($file)) {
                $n++;
            }
        }
        return $n;
    }

    /** الوضع الصارم: لا يُقبل معرّف جلسة غير موجود */
    public function validateId(string $id): bool
    {
        return is_file($this->file($id));
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return @touch($this->file($id));
    }
}
