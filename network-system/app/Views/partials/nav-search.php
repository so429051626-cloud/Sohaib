<?php /* البحث السريع داخل القائمة الجانبية (يعمل عبر js/app.js) */ ?>
<div class="nav-search" role="search">
  <?= icon('search') ?>
  <input type="search" data-nav-search placeholder="ابحث في القائمة" aria-label="ابحث في القائمة" autocomplete="off" spellcheck="false" enterkeyhint="go">
  <kbd class="nav-kbd" aria-hidden="true"><bdi dir="ltr">Ctrl K</bdi></kbd>
</div>
