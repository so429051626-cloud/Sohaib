<?php
declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public int $pages;
    public int $offset;

    public function __construct(public int $total, public int $perPage, public int $page)
    {
        $this->perPage = max(1, min(1000, $perPage));
        $this->pages = max(1, (int) ceil($total / $this->perPage));
        $this->page = max(1, min($page, $this->pages));
        $this->offset = ($this->page - 1) * $this->perPage;
    }

    public static function fromRequest(Request $request, int $total, int $default = 50): self
    {
        $per = $request->int('per', $default);
        if (!in_array($per, [25, 50, 100, 200, 500], true)) {
            $per = $default;
        }
        return new self($total, $per, $request->int('page', 1));
    }

    public function limitSql(): string
    {
        return ' LIMIT ' . (int) $this->perPage . ' OFFSET ' . (int) $this->offset;
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset + $this->perPage);
    }

    public function render(Request $request): string
    {
        $html = '<div class="pager">';
        $html .= '<div class="pager-info">' . num($this->from()) . '–' . num($this->to()) . ' من ' . num($this->total) . '</div>';
        if ($this->pages > 1) {
            $html .= '<nav class="pager-links" aria-label="الصفحات">';
            $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($request): string {
                if ($disabled) {
                    return '<span class="pager-link is-disabled">' . $label . '</span>';
                }
                return '<a class="pager-link' . ($active ? ' is-active' : '') . '" href="' . e($request->urlWith(['page' => $p])) . '">' . $label . '</a>';
            };
            $html .= $link($this->page - 1, icon('chevron-right'), false, $this->page <= 1);
            $window = 2;
            $last = 0;
            for ($p = 1; $p <= $this->pages; $p++) {
                if ($p === 1 || $p === $this->pages || abs($p - $this->page) <= $window) {
                    if ($last && $p - $last > 1) {
                        $html .= '<span class="pager-gap">…</span>';
                    }
                    $html .= $link($p, (string) $p, $p === $this->page);
                    $last = $p;
                }
            }
            $html .= $link($this->page + 1, icon('chevron-left'), false, $this->page >= $this->pages);
            $html .= '</nav>';
        }
        $html .= '<form class="pager-per" method="get">';
        foreach ($request->query as $k => $val) {
            if ($k === 'per' || $k === 'page' || is_array($val)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($val) . '">';
        }
        $html .= '<select name="per" class="input input-sm" data-autosubmit aria-label="عدد الصفوف">';
        foreach ([25, 50, 100, 200, 500] as $n) {
            $html .= '<option value="' . $n . '"' . ($n === $this->perPage ? ' selected' : '') . '>' . $n . '</option>';
        }
        $html .= '</select></form></div>';
        return $html;
    }
}
