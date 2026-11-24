<?php
/**
 * الحسابات الخاصة: حسابات هوتسبوت باسم مستخدم وكلمة مرور اختيارية وحدود خاصة بكل حساب.
 * @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var int $total @var array $online @var array $counts @var array $filters
 */
use App\Core\Request;
use App\Services\CardRules;
use App\Services\CustomAccounts;
use App\Services\Units;

$v->title = 'الحسابات الخاصة';
$v->subtitle = 'حسابات هوتسبوت باسم مستخدم وكلمة مرور، بسرعة ومدة خاصة بكل حساب';
$v->actions = '<a class="btn btn-primary" href="' . e(url('/cards/accounts/create')) . '">' . icon('user-plus') . 'حساب خاص جديد</a>';
$req = Request::current();
// الحساب الجاهز الذي لم يُستخدم بعد يظهر «جاهز» بدل «مباع»
$badge = static fn (array $a): string => $a['status'] === 'sold'
    ? '<span class="badge badge-info">جاهز</span>' . ((int) $a['is_disabled'] === 1 ? ' <span class="badge badge-danger">' . icon('ban') . 'موقوف</span>' : '')
    : CardRules::statusBadge($a);
$tabs = ['' => ['الكل', (int) $counts['total']], 'usable' => ['الصالحة', (int) $counts['usable']], 'disabled' => ['الموقوفة', (int) $counts['disabled']], 'ended' => ['المنتهية', (int) $counts['ended']]];
?>
<?= $v->partial('cards-nav') ?>

<div class="stats">
  <div class="stat"><div class="stat-icon is-violet"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">كل الحسابات</div><div class="stat-value"><?= num((int) $counts['total']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('user-check') ?></div><div class="stat-body"><div class="stat-label">الصالحة للاستخدام</div><div class="stat-value"><?= num((int) $counts['usable']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('ban') ?></div><div class="stat-body"><div class="stat-label">الموقوفة</div><div class="stat-value"><?= num((int) $counts['disabled']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('hourglass') ?></div><div class="stat-body"><div class="stat-label">المنتهية</div><div class="stat-value"><?= num((int) $counts['ended']) ?></div></div></div>
</div>

<div class="panel">
  <nav class="tabs">
    <?php foreach ($tabs as $key => [$label, $n]): ?>
      <a class="tab<?= $filters['state'] === $key ? ' is-active' : '' ?>" href="<?= e(url('/cards/accounts', array_filter(['q' => $filters['q'], 'state' => $key]))) ?>"><?= e($label) ?> <span class="text-muted text-sm"><?= num($n) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <?php if ($filters['state'] !== ''): ?><input type="hidden" name="state" value="<?= e($filters['state']) ?>"><?php endif; ?>
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="اسم المستخدم، صاحب الحساب، MAC"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>بحث</button></div>
  </form>

  <div class="table-wrap">
    <table class="table table-compact">
      <thead>
        <tr>
          <th>اسم المستخدم</th><th>طريقة الدخول</th><th>السرعة (تنزيل / رفع)</th><th>مدة الاستخدام</th><th>الصلاحية</th><th>البيانات</th><th>الأجهزة</th><th>الحالة</th><th>آخر دخول</th><th class="w-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $a):
          $holder = $a['holder'] !== CustomAccounts::NAME_PREFIX . $a['username'] ? (string) $a['holder'] : '';
          $usedBytes = CardRules::usedBytes($a);
          $timeLimit = $a['time_limit'] !== null ? (int) $a['time_limit'] + (int) $a['bonus_time'] : null;
          $dataLimit = $a['data_limit'] !== null ? (int) $a['data_limit'] + (int) $a['bonus_data'] : null;
          $isOnline = !empty($online[(int) $a['id']]);
      ?>
        <tr>
          <td>
            <a class="mono cell-title" href="<?= e(url('/cards/accounts/' . $a['id'] . '/edit')) ?>"><?= e($a['username']) ?></a>
            <?php if ($isOnline): ?><span class="badge badge-success badge-dot" title="متصل الآن">متصل</span><?php endif; ?>
            <?php if ($holder !== '' || $a['notes']): ?><div class="cell-sub"><?= e(str_limit(trim($holder . ($holder !== '' && $a['notes'] ? ' · ' : '') . $a['notes']), 60)) ?></div><?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if (CustomAccounts::usernameOnly($a)): ?><span class="badge badge-info"><?= icon('user', 'icon-sm') ?>اسم المستخدم فقط</span>
            <?php else: ?><span class="badge badge-outline"><?= icon('key-round', 'icon-sm') ?>بكلمة مرور</span> <button type="button" class="btn btn-ghost btn-xs" data-reveal="<?= e($a['password']) ?>"><?= icon('eye') ?>إظهار</button><?php endif; ?>
          </td>
          <td class="nowrap text-sm"><?= e(Units::describeRate($a)) ?></td>
          <td class="nowrap text-sm">
            <?php if ($timeLimit === null): ?>غير محدودة<?php if ((int) $a['used_seconds'] > 0): ?><div class="cell-sub">استُخدم <?= e(fmt_duration((int) $a['used_seconds'])) ?></div><?php endif; ?>
            <?php else: ?><?= e(Units::describeSeconds($timeLimit)) ?><div class="cell-sub">متبقٍ <?= e(fmt_duration(max(0, $timeLimit - (int) $a['used_seconds']))) ?></div><?php endif; ?>
          </td>
          <td class="nowrap text-sm">
            <?php if ($a['expires_at']): ?><?= e(fmt_dt($a['expires_at'])) ?><div class="cell-sub"><?= strtotime((string) $a['expires_at']) > time() ? 'ينتهي بعد ' . e(fmt_duration(max(0, (int) strtotime((string) $a['expires_at']) - time()))) : 'انتهت' ?></div>
            <?php elseif ($a['validity'] !== null): ?><?= e(Units::describeSeconds((int) $a['validity'] + (int) $a['bonus_validity'])) ?><div class="cell-sub">من أول دخول</div>
            <?php else: ?>غير محدودة<?php endif; ?>
          </td>
          <td class="nowrap text-sm">
            <?php if ($dataLimit === null): ?>غير محدودة<?php if ($usedBytes > 0): ?><div class="cell-sub">استُخدم <?= e(fmt_bytes($usedBytes)) ?></div><?php endif; ?>
            <?php else: ?><?= e(fmt_bytes($dataLimit)) ?><div class="cell-sub">متبقٍ <?= e(fmt_bytes(max(0, $dataLimit - $usedBytes))) ?></div><?php endif; ?>
          </td>
          <td class="nowrap text-sm"><?= (int) $a['shared_users'] <= 0 ? 'غير محدود' : num((int) $a['shared_users']) ?><?php if ((int) $a['lock_mac'] === 1): ?><div class="cell-sub"><?= $a['bound_mac'] ? '<span class="mono">' . e($a['bound_mac']) . '</span>' : 'يرتبط بأول جهاز' ?></div><?php endif; ?></td>
          <td class="nowrap"><?= $badge($a) ?><?php if ((int) $a['router_limit'] > 0): ?><div class="cell-sub"><?= num((int) $a['router_limit']) ?> جهاز MikroTik</div><?php endif; ?></td>
          <td class="nowrap text-sm"><?= $a['last_login_at'] ? e(time_ago($a['last_login_at'])) : '—' ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/cards/accounts/' . $a['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a>
              <?php if (can('check.use')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/check/card/' . $a['id'])) ?>" title="فحص الحساب"><?= icon('scan-line') ?></a><?php endif; ?>
              <form method="post" action="<?= e(url('/cards/accounts/' . $a['id'] . '/state')) ?>"<?= (int) $a['is_disabled'] === 1 ? '' : ' data-confirm="إيقاف الحساب ' . e($a['username']) . ' وفصله عن الشبكة؟"' ?>>
                <?= csrf_field() ?><input type="hidden" name="enable" value="<?= (int) $a['is_disabled'] === 1 ? '1' : '0' ?>">
                <button class="btn btn-ghost btn-icon btn-sm" title="<?= (int) $a['is_disabled'] === 1 ? 'تفعيل' : 'إيقاف' ?>"><?= icon((int) $a['is_disabled'] === 1 ? 'play' : 'ban') ?></button>
              </form>
              <form method="post" action="<?= e(url('/cards/accounts/' . $a['id'] . '/delete')) ?>" data-confirm="حذف الحساب <?= e($a['username']) ?> نهائياً من النظام ومن أجهزة MikroTik؟">
                <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="10"><div class="empty"><?= icon('user-plus') ?><div class="empty-title"><?= $filters['q'] !== '' || $filters['state'] !== '' ? 'لا توجد حسابات مطابقة' : 'لا توجد حسابات خاصة' ?></div>
          <a class="btn btn-primary" href="<?= e(url('/cards/accounts/create')) ?>"><?= icon('user-plus') ?>حساب خاص جديد</a></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>
