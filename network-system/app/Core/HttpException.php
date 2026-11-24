<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'طلب غير صالح.',
            401 => 'يلزم تسجيل الدخول.',
            403 => 'لا تملك صلاحية الوصول إلى هذه الصفحة.',
            404 => 'الصفحة المطلوبة غير موجودة.',
            405 => 'طريقة الطلب غير مسموحة.',
            419 => 'انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مجدداً.',
            422 => 'البيانات المدخلة غير صالحة.',
            429 => 'تجاوزت عدد المحاولات المسموح. حاول لاحقاً.',
            default => 'حدث خطأ غير متوقع.',
        };
    }
}
