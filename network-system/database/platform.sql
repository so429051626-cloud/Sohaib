-- ===========================================================================
-- MADIX NEXUS — قاعدة بيانات المنصة (الزبائن، المسؤولون، النشاط، الإعدادات)
-- كل زبون له قاعدة بيانات مستقلة تُنشأ عند التسجيل.
-- ===========================================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS platform_admins (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(120) NOT NULL,
  username       VARCHAR(60)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  last_login_ip  VARCHAR(45)  NULL,
  created_at     DATETIME     NOT NULL,
  is_owner       TINYINT(1)   NOT NULL DEFAULT 0,
  permissions    TEXT         NULL,
  email          VARCHAR(190) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platform_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenants (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(40)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  owner_name     VARCHAR(120) NOT NULL,
  phone          VARCHAR(30)  NULL,
  email          VARCHAR(120) NULL,
  status         ENUM('trial','active','suspended','expired','cancelled') NOT NULL DEFAULT 'trial',
  status_reason  VARCHAR(255) NULL,
  trial_ends_at  DATETIME     NULL,
  paid_until     DATETIME     NULL,
  db_name        VARCHAR(64)  NOT NULL,
  app_key        VARCHAR(80)  NOT NULL,
  cron_key       VARCHAR(64)  NOT NULL,
  notes          TEXT         NULL,
  signup_ip      VARCHAR(45)  NULL,
  created_at     DATETIME     NOT NULL,
  activated_at   DATETIME     NULL,
  last_seen_at   DATETIME     NULL,
  last_cron_at   DATETIME     NULL,
  updated_at     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_slug (slug),
  UNIQUE KEY uq_tenants_db (db_name),
  KEY idx_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_activity (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      INT UNSIGNED NULL,
  actor          ENUM('admin','tenant','system','visitor') NOT NULL,
  actor_name     VARCHAR(120) NULL,
  action         VARCHAR(40)  NOT NULL,
  description    VARCHAR(255) NOT NULL,
  ip             VARCHAR(45)  NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pact_created (created_at),
  KEY idx_pact_tenant (tenant_id, created_at),
  CONSTRAINT fk_pact_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_settings (
  `key`          VARCHAR(80)  NOT NULL,
  `value`        TEXT         NULL,
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تذاكر دخول لمرة واحدة (الدخول الموحّد، التسجيل، دخول المسؤول إلى حساب زبون)
CREATE TABLE IF NOT EXISTS login_tickets (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash     CHAR(64)     NOT NULL,
  tenant_id      INT UNSIGNED NOT NULL,
  employee_id    INT UNSIGNED NOT NULL,
  purpose        ENUM('login','signup','impersonate') NOT NULL,
  admin_id       INT UNSIGNED NULL,
  expires_at     DATETIME     NOT NULL,
  used_at        DATETIME     NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_token (token_hash),
  KEY idx_tickets_expires (expires_at),
  CONSTRAINT fk_tickets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الرسائل النصية من لوحة المسؤول إلى أصحاب الحسابات
CREATE TABLE IF NOT EXISTS platform_sms (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  audience       VARCHAR(255) NULL,
  body           TEXT         NOT NULL,
  segments       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  recipients     INT UNSIGNED NOT NULL DEFAULT 0,
  sent           INT UNSIGNED NOT NULL DEFAULT 0,
  failed         INT UNSIGNED NOT NULL DEFAULT 0,
  error          VARCHAR(255) NULL,
  admin_id       INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_psms_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_sms_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sms_id         INT UNSIGNED NOT NULL,
  tenant_id      INT UNSIGNED NULL,
  name           VARCHAR(120) NULL,
  phone          VARCHAR(15)  NOT NULL,
  ok             TINYINT(1)   NOT NULL DEFAULT 0,
  code           INT          NULL,
  description    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_psmsi_sms (sms_id),
  KEY idx_psmsi_phone (phone),
  CONSTRAINT fk_psmsi_sms FOREIGN KEY (sms_id) REFERENCES platform_sms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS throttle (
  `key`         VARCHAR(160) NOT NULL,
  attempts      INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME     NOT NULL,
  locked_until  DATETIME     NULL,
  PRIMARY KEY (`key`),
  KEY idx_throttle_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

SET FOREIGN_KEY_CHECKS = 1;

-- مدفوعات الاشتراكات (تبقى في السجل حتى بعد حذف الحساب)
CREATE TABLE IF NOT EXISTS tenant_payments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NULL,
  tenant_name  VARCHAR(120) NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  currency     VARCHAR(8)   NOT NULL,
  months       SMALLINT UNSIGNED NULL,
  paid_until   DATETIME     NULL,
  method       VARCHAR(40)  NULL,
  note         VARCHAR(255) NULL,
  admin_name   VARCHAR(120) NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_payments_tenant (tenant_id),
  KEY idx_payments_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- باقات المنصة: السعر والمدة والحدود والأقسام المفعّلة
CREATE TABLE IF NOT EXISTS platform_plans (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(80)  NOT NULL,
  tagline         VARCHAR(160) NULL,
  price           DECIMAL(10,2) NOT NULL DEFAULT 0,
  months          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_routers     INT UNSIGNED NULL,
  max_subscribers INT UNSIGNED NULL,
  max_employees   INT UNSIGNED NULL,
  ai_messages     INT UNSIGNED NULL,
  modules         TEXT         NULL,
  features        TEXT         NULL,
  is_public       TINYINT(1)   NOT NULL DEFAULT 1,
  is_featured     TINYINT(1)   NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  sort            INT          NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_plans_sort (is_active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- استهلاك الذكاء الاصطناعي (يبقى بعد حذف الحساب)
CREATE TABLE IF NOT EXISTS ai_usage (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     INT UNSIGNED NULL,
  feature       VARCHAR(40)  NOT NULL,
  model         VARCHAR(60)  NOT NULL,
  input_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cost          DECIMAL(12,6) NOT NULL DEFAULT 0,
  ok            TINYINT(1)   NOT NULL DEFAULT 1,
  actor         VARCHAR(120) NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_tenant (tenant_id, created_at),
  KEY idx_ai_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الحسابات البنكية التي تستقبل تحويلات الاشتراكات
CREATE TABLE IF NOT EXISTS bank_accounts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label       VARCHAR(80)  NOT NULL,
  holder      VARCHAR(120) NOT NULL,
  number      VARCHAR(80)  NOT NULL,
  details     VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort        INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تحويلات الزبائن بانتظار موافقة المسؤول
CREATE TABLE IF NOT EXISTS transfer_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  tenant_name     VARCHAR(120) NOT NULL,
  plan_id         INT UNSIGNED NULL,
  plan_name       VARCHAR(80)  NULL,
  months          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  amount          DECIMAL(10,2) NOT NULL,
  currency        VARCHAR(8)   NOT NULL,
  bank_account_id INT UNSIGNED NULL,
  bank_label      VARCHAR(80)  NULL,
  reference       VARCHAR(80)  NULL,
  sender_name     VARCHAR(120) NULL,
  note            VARCHAR(255) NULL,
  file            VARCHAR(160) NULL,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_note      VARCHAR(255) NULL,
  admin_name      VARCHAR(120) NULL,
  payment_id      INT UNSIGNED NULL,
  submitted_by    VARCHAR(120) NULL,
  reviewed_at     DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_transfers_status (status, created_at),
  KEY idx_transfers_tenant (tenant_id),
  KEY idx_transfers_reference (reference),
  CONSTRAINT fk_transfers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إعلانات تظهر داخل أنظمة الزبائن
CREATE TABLE IF NOT EXISTS platform_announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(120) NOT NULL,
  body        VARCHAR(600) NULL,
  level       ENUM('info','success','warning') NOT NULL DEFAULT 'info',
  audience    ENUM('all','selected') NOT NULL DEFAULT 'all',
  starts_at   DATETIME     NULL,
  ends_at     DATETIME     NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  admin_name  VARCHAR(120) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_announcements_active (is_active, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_tenants (
  announcement_id INT UNSIGNED NOT NULL,
  tenant_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (announcement_id, tenant_id),
  KEY idx_annt_tenant (tenant_id),
  CONSTRAINT fk_annt_announcement FOREIGN KEY (announcement_id) REFERENCES platform_announcements (id) ON DELETE CASCADE,
  CONSTRAINT fk_annt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تذاكر الدعم بين الزبون والمسؤول
CREATE TABLE IF NOT EXISTS support_tickets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       INT UNSIGNED NOT NULL,
  subject         VARCHAR(160) NOT NULL,
  category        VARCHAR(30)  NOT NULL DEFAULT 'general',
  status          ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  opened_by       VARCHAR(120) NULL,
  admin_unread    TINYINT(1)   NOT NULL DEFAULT 1,
  tenant_unread   TINYINT(1)   NOT NULL DEFAULT 0,
  last_message_at DATETIME     NOT NULL,
  created_at      DATETIME     NOT NULL,
  closed_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_tickets_status (status, last_message_at),
  KEY idx_tickets_tenant (tenant_id, last_message_at),
  CONSTRAINT fk_support_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  sender      ENUM('tenant','admin') NOT NULL,
  sender_name VARCHAR(120) NULL,
  body        TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_smsg_ticket (ticket_id, id),
  CONSTRAINT fk_smsg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رموز التحقق المرسلة إلى الهاتف: تأكيد الرقم عند التسجيل (signup) واستعادة كلمة المرور (reset)
CREATE TABLE IF NOT EXISTS phone_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone       VARCHAR(15)  NOT NULL,
  purpose     VARCHAR(20)  NOT NULL DEFAULT 'signup',
  code_hash   CHAR(64)     NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATETIME     NOT NULL,
  verified_at DATETIME     NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pcodes_phone (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- أسماء الحسابات السابقة: بعد تغيير اسم الحساب في الرابط يبقى الرابط القديم يعمل
-- (صفحات الهوتسبوت على الأجهزة، البوت، والتطبيق مربوطة به) ويُحوَّل المتصفح إلى الجديد
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_aliases (
  slug        VARCHAR(40)  NOT NULL,
  tenant_id   INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (slug),
  KEY idx_tenant_aliases_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل البريد المرسل من المنصة: الترحيب، الفواتير، التذكيرات، الإيقاف، التحويلات، ردود الدعم، والبريد الجماعي
CREATE TABLE IF NOT EXISTS platform_mail_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    INT UNSIGNED NULL,
  kind         VARCHAR(20)  NOT NULL,
  to_email     VARCHAR(190) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  ok           TINYINT(1)   NOT NULL DEFAULT 0,
  error        VARCHAR(255) NULL,
  admin_id     INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pml_created (created_at),
  KEY idx_pml_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- بوت خدمة الزبائن للمنصة (واتساب ونافذة الموقع): أصحاب الشبكات ومشتركوهم وأصحاب محلات MADIX ERP والزوار.
-- kind: من المرسل (guest | owner | subscriber) · tenant_id: الشبكة المعنية بالمحادثة
-- handler: من يرد حين تحتاج المحادثة شخصاً: فريق المنصة (admin) أو صاحب شبكة المشترك (network)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platform_bot_chats (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel         VARCHAR(16)  NOT NULL,
  external_id     VARCHAR(80)  NOT NULL,
  name            VARCHAR(120) NULL,
  phone           VARCHAR(32)  NULL,
  kind            VARCHAR(16)  NOT NULL DEFAULT 'guest',
  tenant_id       INT UNSIGNED NULL,
  subscriber_id   INT UNSIGNED NULL,
  handler         VARCHAR(10)  NOT NULL DEFAULT 'admin',
  mode            VARCHAR(8)   NOT NULL DEFAULT 'bot',
  needs_human     TINYINT(1)   NOT NULL DEFAULT 0,
  status          VARCHAR(8)   NOT NULL DEFAULT 'open',
  unread          INT UNSIGNED NOT NULL DEFAULT 0,
  assigned_to     VARCHAR(120) NULL,
  meta            TEXT         NULL,
  identified_at   DATETIME     NULL,
  last_message    VARCHAR(255) NULL,
  last_at         DATETIME     NOT NULL,
  last_inbound_at DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pbot_chat (channel, external_id),
  KEY idx_pbot_list (status, needs_human, last_at),
  KEY idx_pbot_tenant (tenant_id, kind, last_at),
  CONSTRAINT fk_pbot_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_bot_messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id     INT UNSIGNED NOT NULL,
  role        VARCHAR(8)   NOT NULL,
  body        TEXT         NOT NULL,
  sender      VARCHAR(120) NULL,
  side        VARCHAR(10)  NULL,
  tools       VARCHAR(255) NULL,
  external_id VARCHAR(100) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pbot_msg_ext (external_id),
  KEY idx_pbot_msg_chat (chat_id, id),
  KEY idx_pbot_msg_created (created_at),
  CONSTRAINT fk_pbot_msg_chat FOREIGN KEY (chat_id) REFERENCES platform_bot_chats (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
