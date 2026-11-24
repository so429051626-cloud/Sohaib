<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;

abstract class Controller
{
    /**
     * تحقق من المدخلات؛ عند الفشل يعيد المستخدم إلى النموذج مع الأخطاء.
     * @throws ValidationFailed
     */
    protected function validate(Request $request, array $rules, array $labels = [], ?callable $extra = null): array
    {
        $v = Validator::make($request->all(), $rules, $labels);
        if ($extra) {
            $extra($v);
        }
        if ($v->fails()) {
            throw new ValidationFailed($v, $request);
        }
        return $v->validated();
    }

    /**
     * التحقق من حقول إعدادات [مفتاح => [قاعدة، تسمية]] وإرجاع القيم الجاهزة للحفظ.
     * الحقل في النموذج يُسمّى بالمفتاح بعد استبدال النقطة بـ __، وقاعدة bool لمفاتيح التشغيل.
     */
    protected function settingsInput(Request $request, array $fields, ?callable $extra = null): array
    {
        $rules = [];
        $labels = [];
        $input = [];
        foreach ($fields as $key => [$rule, $label]) {
            $form = str_replace('.', '__', $key);
            $labels[$form] = $label;
            if ($rule === 'bool') {
                $input[$key] = $request->bool($form) ? '1' : '0';
                continue;
            }
            $rules[$form] = $rule;
        }
        $data = $this->validate($request, $rules, $labels, $extra);
        foreach ($rules as $form => $_) {
            $val = $data[$form];
            $input[str_replace('__', '.', $form)] = $val === null ? '' : (string) $val;
        }
        return $input;
    }

    protected function backWithErrors(Request $request, array $errors, string $fallback = '/'): Response
    {
        if ($request->wantsJson()) {
            return json_response(['ok' => false, 'message' => (string) reset($errors), 'errors' => $errors], 422);
        }
        Session::flashInput($request->all(), $errors);
        flash('error', (string) reset($errors));
        return back($fallback);
    }

    protected function notFoundUnless(mixed $value): void
    {
        if (!$value) {
            throw new HttpException(404);
        }
    }

    protected function ok(string $message, string $redirect, array $query = []): Response
    {
        flash('success', $message);
        return redirect($redirect, $query);
    }
}
