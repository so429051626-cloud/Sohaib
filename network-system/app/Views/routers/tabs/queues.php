<?php
/** @var App\Core\View $v */
$pair = static function (?string $v): array {
    $p = explode('/', (string) $v);
    return [$p[0] ?? '0', $p[1] ?? '0'];
};
?>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>الاسم</th><th>الهدف</th><th>الحد الأقصى (رفع/تنزيل)</th><th>السرعة الحالية</th><th>البيانات</th><th>النوع</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $q):
        [$ru, $rd] = $pair($q['rate'] ?? '0/0');
        [$bu, $bd] = $pair($q['bytes'] ?? '0/0'); ?>
      <tr>
        <td><?= e($q['name'] ?? '') ?></td>
        <td><span class="mono text-sm"><?= e($q['target'] ?? '') ?></span></td>
        <td><span class="mono text-sm"><?= e($q['max-limit'] ?? '—') ?></span></td>
        <td class="nowrap text-sm"><?= e(fmt_rate((int) $rd)) ?> / <?= e(fmt_rate((int) $ru)) ?></td>
        <td class="nowrap text-sm"><?= e(fmt_bytes((int) $bd)) ?> / <?= e(fmt_bytes((int) $bu)) ?></td>
        <td><?= ($q['dynamic'] ?? 'false') === 'true' ? '<span class="badge">ديناميكي</span>' : '<span class="badge badge-outline">ثابت</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= icon('gauge') ?><div>لا توجد قوائم سرعة.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
