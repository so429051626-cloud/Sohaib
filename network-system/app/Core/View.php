<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * محرك القوالب: ملفات PHP عادية داخل app/Views.
 * القالب يضبط خصائص الصفحة عبر $v (العنوان، الأزرار، السكربتات...).
 */
final class View
{
    public string $title = '';
    public ?string $subtitle = null;
    public array $breadcrumbs = [];
    public string $actions = '';
    public array $scripts = [];
    public string $nav = '';
    public string $bodyClass = '';
    public bool $wide = false;

    public static function render(string $template, array $data = [], string $layout = 'app'): string
    {
        $view = new self();
        $content = $view->renderFile($template, $data);
        if ($layout === '') {
            return $content;
        }
        return $view->renderFile('layouts/' . $layout, ['content' => $content] + $data);
    }

    public function renderFile(string $template, array $data = []): string
    {
        $file = APP_PATH . '/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('القالب غير موجود: ' . $template);
        }
        // نطاق معزول حتى لا تتعارض متغيرات القالب مع متغيرات هذه الدالة
        $include = function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            $v = $this;
            include $__file;
        };
        ob_start();
        try {
            $include($file, $data);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile('partials/' . $template, $data);
    }

    public function script(string $path): void
    {
        if (!in_array($path, $this->scripts, true)) {
            $this->scripts[] = $path;
        }
    }
}
