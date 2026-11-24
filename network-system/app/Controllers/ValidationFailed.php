<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Validator;
use RuntimeException;

/** يُرمى عند فشل التحقق ويُحوَّل إلى إعادة توجيه مع الأخطاء */
final class ValidationFailed extends RuntimeException
{
    public function __construct(public readonly Validator $validator, public readonly Request $request)
    {
        parent::__construct((string) $validator->first());
    }
}
