<?php
/**
 * سطر الحقوق أسفل الصفحات + نافذة Madix.
 * @var bool $dialog إدراج النافذة (مرة واحدة في الصفحة)
 */
use App\Core\Brand;

$dialog = $dialog ?? true;
?>
<button type="button" class="madix-credit" data-madix-open dir="ltr" aria-haspopup="dialog">
  <span>Powered by</span>
  <img src="<?= e(Brand::markSrc()) ?>" width="16" height="16" alt="">
  <b>Madix</b>
  <span class="madix-credit-sep">—</span>
  <span class="madix-credit-dev">Developed by <?= Brand::developer(14) ?></span>
</button>
<?php if ($dialog): ?>
<dialog class="madix-dialog" id="madix-about" aria-label="Madix">
  <div class="madix-card">
    <div class="madix-orb madix-orb-a"></div>
    <div class="madix-orb madix-orb-b"></div>
    <div class="madix-grid"></div>
    <button type="button" class="madix-close" data-madix-close aria-label="إغلاق"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="madix-head">
      <span class="madix-logo"><img src="<?= e(Brand::markSrc()) ?>" width="58" height="58" alt="Madix"></span>
      <div class="madix-titles" dir="ltr">
        <div class="madix-name">MADIX <span>NEXUS</span></div>
        <div class="madix-by">Developed by <?= Brand::developer(17) ?></div>
      </div>
    </div>
    <div class="madix-divider"><span>تواصل معنا</span></div>
    <div class="madix-actions">
      <a class="madix-action is-whatsapp" href="https://wa.me/970595679770" target="_blank" rel="noopener">
        <span class="madix-action-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M20.52 3.48A11.86 11.86 0 0 0 12.06 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.15 1.6 5.96L0 24l6.3-1.65a11.9 11.9 0 0 0 5.76 1.47h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.24-6.17-3.45-8.44ZM12.07 21.8h-.01a9.88 9.88 0 0 1-5.04-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.27c0-5.46 4.44-9.9 9.9-9.9 2.64 0 5.13 1.03 7 2.9a9.84 9.84 0 0 1 2.9 7c0 5.46-4.44 9.9-9.9 9.9Zm5.43-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.25-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.08.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.69.25-1.29.18-1.41-.07-.12-.27-.2-.57-.35Z"/></svg></span>
        <span class="madix-action-text"><b>واتساب</b><small dir="ltr">0595679770</small></span>
        <span class="madix-arrow"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>
      </a>
      <a class="madix-action is-facebook" href="https://www.facebook.com/share/19Ln63g4xe/" target="_blank" rel="noopener">
        <span class="madix-action-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.5h-2.8V24C19.62 23.1 24 18.1 24 12.07Z"/></svg></span>
        <span class="madix-action-text"><b>فيسبوك</b><small>صفحة Madix</small></span>
        <span class="madix-arrow"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></span>
      </a>
    </div>
    <div class="madix-divider"><span>منتجاتنا</span></div>
    <a class="madix-product" href="https://madix.kozow.com/" target="_blank" rel="noopener">
      <span class="madix-product-icon"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="12" rx="2"/><path d="M7 21h10M12 15v6M7 8h4M7 11h7"/></svg></span>
      <span class="madix-action-text"><b>نظام الكاشير لإدارة المحلات</b><small dir="ltr">madix.kozow.com</small></span>
      <span class="madix-product-go">فتح</span>
    </a>
    <div class="madix-foot" dir="ltr">© <?= date('Y') ?> Madix · <?= e(Brand::NAME) ?></div>
  </div>
</dialog>
<?php endif; ?>
