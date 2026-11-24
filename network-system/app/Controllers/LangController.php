<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;

/** تبديل لغة الواجهة (العربية / الإنجليزية) ثم العودة إلى الصفحة نفسها */
final class LangController extends Controller
{
    public function switch(Request $request, string $code): Response
    {
        if (isset(Lang::LOCALES[$code]) && Lang::switchable()) {
            Lang::remember($code, $request->isHttps());
        }
        $back = (string) $request->input('back', '/');
        // العودة داخل الموقع فقط (لا روابط خارجية)
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || str_contains($back, '\\') || preg_match('/[\r\n]/', $back)) {
            $back = root_path() . '/';
        }
        return Response::redirect($back);
    }
}
