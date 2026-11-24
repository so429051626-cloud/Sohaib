<?php
declare(strict_types=1);

namespace App\MikroTik;

use RuntimeException;

final class MikroTikException extends RuntimeException
{
    public const CONNECT = 1;
    public const AUTH = 2;
    public const TRAP = 3;
    public const PROTOCOL = 4;
    public const TIMEOUT = 5;

    public function __construct(string $message, int $code = self::TRAP, public readonly string $raw = '')
    {
        parent::__construct($message, $code);
    }

    public function isConnection(): bool
    {
        return in_array($this->code, [self::CONNECT, self::TIMEOUT, self::PROTOCOL], true);
    }

    /** نص عربي واضح للخطأ */
    public function friendly(): string
    {
        return match ($this->code) {
            self::CONNECT  => 'تعذّر الاتصال بجهاز MikroTik: ' . $this->getMessage(),
            self::AUTH     => 'اسم المستخدم أو كلمة المرور الخاصة بواجهة API غير صحيحة.',
            self::TIMEOUT  => 'انتهت مهلة الاتصال بجهاز MikroTik.',
            self::PROTOCOL => 'استجابة غير متوقعة من جهاز MikroTik.',
            default        => 'رفض جهاز MikroTik العملية: ' . $this->getMessage(),
        };
    }
}
