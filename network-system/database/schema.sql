-- ============================================================================
--  مخطط قاعدة بيانات نظام إدارة الشبكة
--  MySQL 5.7+/8 أو MariaDB 10.3+ — InnoDB — utf8mb4
--  كل الأوقات تُولَّد من PHP بتوقيت النظام المضبوط في الإعدادات.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- الإعدادات العامة (مفتاح/قيمة)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(80)  NOT NULL,
  `value`     MEDIUMTEXT   NULL,
  updated_at  DATETIME     NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الموظفون والصلاحيات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(120) NOT NULL,
  username         VARCHAR(60)  NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  phone            VARCHAR(30)  NULL,
  email            VARCHAR(120) NULL,
  is_owner         TINYINT(1)   NOT NULL DEFAULT 0,
  role_preset      VARCHAR(40)  NULL,
  router_scope     ENUM('all','selected') NOT NULL DEFAULT 'all',
  status           ENUM('active','disabled') NOT NULL DEFAULT 'active',
  theme            ENUM('system','light','dark') NOT NULL DEFAULT 'light',
  session_version  INT UNSIGNED NOT NULL DEFAULT 1,
  totp_secret      VARCHAR(255) NULL,
  totp_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  recovery_codes   TEXT         NULL,
  last_login_at    DATETIME     NULL,
  last_login_ip    VARCHAR(45)  NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_permissions (
  employee_id  INT UNSIGNED NOT NULL,
  permission   VARCHAR(60)  NOT NULL,
  PRIMARY KEY (employee_id, permission),
  CONSTRAINT fk_ep_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id  INT UNSIGNED NULL,
  action       VARCHAR(60)  NOT NULL,
  entity_type  VARCHAR(40)  NULL,
  entity_id    BIGINT UNSIGNED NULL,
  summary      VARCHAR(255) NOT NULL,
  details      MEDIUMTEXT   NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_employee (employee_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تقييد المحاولات (دخول اللوحة، واجهة الهوتسبوت)
CREATE TABLE IF NOT EXISTS throttle (
  `key`         VARCHAR(160) NOT NULL,
  attempts      INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME     NOT NULL,
  locked_until  DATETIME     NULL,
  PRIMARY KEY (`key`),
  KEY idx_throttle_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- ---------------------------------------------------------------------------
-- المناطق والراوترات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS areas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  notes       VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_areas_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مواقع الشبكة: الأبراج والمحطات ونقاط التوزيع
CREATE TABLE IF NOT EXISTS sites (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  type        ENUM('tower','station','office','box','other') NOT NULL DEFAULT 'tower',
  area_id     INT UNSIGNED NULL,
  lat         DECIMAL(10,7) NULL,
  lng         DECIMAL(10,7) NULL,
  height_m    SMALLINT UNSIGNED NULL,
  address     VARCHAR(255) NULL,
  notes       VARCHAR(500) NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_sites_area (area_id),
  CONSTRAINT fk_sites_area FOREIGN KEY (area_id) REFERENCES areas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الروابط بين المواقع (لاسلكي، فايبر، كابل)
CREATE TABLE IF NOT EXISTS site_links (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_a      INT UNSIGNED NOT NULL,
  site_b      INT UNSIGNED NOT NULL,
  type        ENUM('wireless','fiber','cable','other') NOT NULL DEFAULT 'wireless',
  capacity    VARCHAR(40)  NULL,
  label       VARCHAR(120) NULL,
  notes       VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_links_a (site_a),
  KEY idx_links_b (site_b),
  CONSTRAINT fk_links_a FOREIGN KEY (site_a) REFERENCES sites (id) ON DELETE CASCADE,
  CONSTRAINT fk_links_b FOREIGN KEY (site_b) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS routers (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(100) NOT NULL,
  area_id            INT UNSIGNED NULL,
  site_id            INT UNSIGNED NULL,
  lat                DECIMAL(10,7) NULL,
  lng                DECIMAL(10,7) NULL,
  host               VARCHAR(255) NOT NULL,
  api_port           SMALLINT UNSIGNED NOT NULL DEFAULT 8728,
  api_ssl            TINYINT(1)   NOT NULL DEFAULT 0,
  api_username       VARCHAR(64)  NOT NULL,
  api_password       TEXT         NOT NULL,
  router_key         CHAR(32)     NOT NULL,
  wan_interface      VARCHAR(64)  NULL,
  hotspot_dir        VARCHAR(120) NULL,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  notes              VARCHAR(500) NULL,
  status             ENUM('unknown','online','offline') NOT NULL DEFAULT 'unknown',
  status_changed_at  DATETIME     NULL,
  fail_count         INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at       DATETIME     NULL,
  last_polled_at     DATETIME     NULL,
  next_poll_at       DATETIME     NULL,
  last_error         VARCHAR(255) NULL,
  identity           VARCHAR(100) NULL,
  board_name         VARCHAR(100) NULL,
  model              VARCHAR(100) NULL,
  serial_number      VARCHAR(60)  NULL,
  version            VARCHAR(60)  NULL,
  ros_major          TINYINT UNSIGNED NULL,
  architecture       VARCHAR(40)  NULL,
  uptime_seconds     INT UNSIGNED NULL,
  cpu_load           TINYINT UNSIGNED NULL,
  cpu_count          TINYINT UNSIGNED NULL,
  memory_total       BIGINT UNSIGNED NULL,
  memory_free        BIGINT UNSIGNED NULL,
  hdd_total          BIGINT UNSIGNED NULL,
  hdd_free           BIGINT UNSIGNED NULL,
  temperature        DECIMAL(5,1) NULL,
  voltage            DECIMAL(5,1) NULL,
  active_hotspot     INT UNSIGNED NULL,
  active_ppp         INT UNSIGNED NULL,
  rx_bps             BIGINT UNSIGNED NULL,
  tx_bps             BIGINT UNSIGNED NULL,
  info_synced_at     DATETIME     NULL,
  leases_synced_at   DATETIME     NULL,
  users_synced_at    DATETIME     NULL,
  metrics_at         DATETIME     NULL,
  voltage_warn       DECIMAL(5,1) NULL,
  voltage_crit       DECIMAL(5,1) NULL,
  power_at           DATETIME     NULL,
  power_state        VARCHAR(10)  NULL,
  created_at         DATETIME     NOT NULL,
  updated_at         DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_routers_key (router_key),
  KEY idx_routers_area (area_id),
  KEY idx_routers_site (site_id),
  KEY idx_routers_poll (is_active, next_poll_at),
  CONSTRAINT fk_routers_area FOREIGN KEY (area_id) REFERENCES areas (id) ON DELETE SET NULL,
  CONSTRAINT fk_routers_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_routers (
  employee_id  INT UNSIGNED NOT NULL,
  router_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (employee_id, router_id),
  KEY idx_er_router (router_id),
  CONSTRAINT fk_er_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
  CONSTRAINT fk_er_router   FOREIGN KEY (router_id)   REFERENCES routers (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS router_metrics (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  router_id       INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL,
  online          TINYINT(1)   NOT NULL,
  cpu_load        TINYINT UNSIGNED NULL,
  memory_used_pct TINYINT UNSIGNED NULL,
  active_hotspot  INT UNSIGNED NULL,
  active_ppp      INT UNSIGNED NULL,
  rx_bps          BIGINT UNSIGNED NULL,
  tx_bps          BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_metrics_router_time (router_id, created_at),
  KEY idx_metrics_time (created_at),
  CONSTRAINT fk_metrics_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مهام تنفيذ على الراوتر (تُنفَّذ فوراً، وتُعاد تلقائياً عند تعذّر الاتصال)
CREATE TABLE IF NOT EXISTS router_tasks (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  router_id   INT UNSIGNED NOT NULL,
  action      VARCHAR(40)  NOT NULL,
  target      VARCHAR(80)  NULL,
  payload     TEXT         NULL,
  status      ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(255) NULL,
  run_after   DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL,
  done_at     DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_rt_pending (status, router_id, run_after),
  KEY idx_rt_created (created_at),
  CONSTRAINT fk_rt_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- عمليات طويلة تُنفَّذ على دفعات (رفع بطاقات للراوتر، استيراد من الراوتر...)
CREATE TABLE IF NOT EXISTS jobs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type         VARCHAR(40)  NOT NULL,
  title        VARCHAR(160) NOT NULL,
  payload      MEDIUMTEXT   NULL,
  state        MEDIUMTEXT   NULL,
  status       ENUM('pending','running','done','failed','cancelled') NOT NULL DEFAULT 'pending',
  progress     INT UNSIGNED NOT NULL DEFAULT 0,
  total        INT UNSIGNED NOT NULL DEFAULT 0,
  errors       INT UNSIGNED NOT NULL DEFAULT 0,
  message      VARCHAR(500) NULL,
  locked_until DATETIME     NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NULL,
  finished_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_status (status, id),
  CONSTRAINT fk_jobs_employee FOREIGN KEY (created_by) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- فئات البطاقات
-- ---------------------------------------------------------------------------
-- is_custom: باقة خاصة بحساب واحد من «الحسابات الخاصة»، لا تظهر مع باقات الكروت
CREATE TABLE IF NOT EXISTS categories (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(100) NOT NULL,
  price                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  color                 VARCHAR(7)   NOT NULL DEFAULT '#2453d6',
  time_limit            INT UNSIGNED NULL,
  validity              INT UNSIGNED NULL,
  data_limit            BIGINT UNSIGNED NULL,
  rate_up               VARCHAR(20)  NULL,
  rate_down             VARCHAR(20)  NULL,
  burst_rate_up         VARCHAR(20)  NULL,
  burst_rate_down       VARCHAR(20)  NULL,
  burst_threshold_up    VARCHAR(20)  NULL,
  burst_threshold_down  VARCHAR(20)  NULL,
  burst_time            SMALLINT UNSIGNED NULL,
  shared_users          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  lock_mac              TINYINT(1)   NOT NULL DEFAULT 1,
  idle_timeout          INT UNSIGNED NULL,
  show_on_hotspot       TINYINT(1)   NOT NULL DEFAULT 1,
  low_stock             INT UNSIGNED NULL,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  is_custom             TINYINT(1)   NOT NULL DEFAULT 0,
  sort                  INT          NOT NULL DEFAULT 0,
  notes                 VARCHAR(255) NULL,
  created_at            DATETIME     NOT NULL,
  updated_at            DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name),
  KEY idx_categories_custom (is_custom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_routers (
  category_id  INT UNSIGNED NOT NULL,
  router_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (category_id, router_id),
  KEY idx_cr_router (router_id),
  CONSTRAINT fk_cr_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_router   FOREIGN KEY (router_id)   REFERENCES routers (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بصمة إعدادات ملفات السرعة (Profiles) المطبّقة على كل راوتر
CREATE TABLE IF NOT EXISTS router_profiles (
  router_id   INT UNSIGNED NOT NULL,
  kind        ENUM('category','plan','plan_ppp') NOT NULL,
  ref_id      INT UNSIGNED NOT NULL,
  version     INT UNSIGNED NOT NULL,
  synced_at   DATETIME     NOT NULL,
  PRIMARY KEY (router_id, kind, ref_id),
  CONSTRAINT fk_rp_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الموزعون والمبيعات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS distributors (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(120) NOT NULL,
  phone             VARCHAR(30)  NULL,
  address           VARCHAR(255) NULL,
  area_id           INT UNSIGNED NULL,
  discount_percent  DECIMAL(5,2) NOT NULL DEFAULT 0,
  balance           DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  notes             VARCHAR(255) NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_distributors_area (area_id),
  CONSTRAINT fk_distributors_area FOREIGN KEY (area_id) REFERENCES areas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120) NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  source       ENUM('generated','imported','router','custom') NOT NULL,
  quantity     INT UNSIGNED NOT NULL DEFAULT 0,
  options      TEXT         NULL,
  notes        VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_batches_category (category_id),
  CONSTRAINT fk_batches_category FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_batches_employee FOREIGN KEY (created_by)  REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أجهزة MikroTik المرتبطة بدفعة كروت: الكروت تُرفع إليها وتعمل عليها فقط (بلا صفوف = كل الأجهزة)
CREATE TABLE IF NOT EXISTS batch_routers (
  batch_id   INT UNSIGNED NOT NULL,
  router_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (batch_id, router_id),
  KEY idx_br_router (router_id),
  CONSTRAINT fk_br_batch  FOREIGN KEY (batch_id)  REFERENCES batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_br_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type              ENUM('distributor','direct') NOT NULL,
  distributor_id    INT UNSIGNED NULL,
  category_id       INT UNSIGNED NOT NULL,
  quantity          INT UNSIGNED NOT NULL,
  unit_price        DECIMAL(12,2) NOT NULL,
  discount_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0,
  total             DECIMAL(12,2) NOT NULL,
  paid              DECIMAL(12,2) NOT NULL DEFAULT 0,
  customer_name     VARCHAR(120) NULL,
  notes             VARCHAR(255) NULL,
  status            ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
  employee_id       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL,
  cancelled_at      DATETIME     NULL,
  cancelled_by      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_sales_created (created_at),
  KEY idx_sales_distributor (distributor_id),
  KEY idx_sales_category (category_id),
  KEY idx_sales_employee (employee_id),
  CONSTRAINT fk_sales_distributor FOREIGN KEY (distributor_id) REFERENCES distributors (id),
  CONSTRAINT fk_sales_category    FOREIGN KEY (category_id)    REFERENCES categories (id),
  CONSTRAINT fk_sales_employee    FOREIGN KEY (employee_id)    REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distributor_payments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  distributor_id  INT UNSIGNED NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  method          VARCHAR(40)  NULL,
  notes           VARCHAR(255) NULL,
  employee_id     INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_dp_distributor (distributor_id, created_at),
  KEY idx_dp_created (created_at),
  CONSTRAINT fk_dp_distributor FOREIGN KEY (distributor_id) REFERENCES distributors (id) ON DELETE CASCADE,
  CONSTRAINT fk_dp_employee    FOREIGN KEY (employee_id)    REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- البطاقات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cards (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id         INT UNSIGNED NOT NULL,
  category_id      INT UNSIGNED NOT NULL,
  username         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  password         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  short_code       VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  short_password   VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  ext_serial       VARCHAR(64)  NULL,
  status           ENUM('new','sold','active','expired','depleted') NOT NULL DEFAULT 'new',
  is_disabled      TINYINT(1)   NOT NULL DEFAULT 0,
  disabled_reason  VARCHAR(255) NULL,
  printed_at       DATETIME     NULL,
  print_count      INT UNSIGNED NOT NULL DEFAULT 0,
  distributor_id   INT UNSIGNED NULL,
  sale_id          INT UNSIGNED NULL,
  sold_at          DATETIME     NULL,
  sale_price       DECIMAL(12,2) NULL,
  first_login_at   DATETIME     NULL,
  last_login_at    DATETIME     NULL,
  expires_at       DATETIME     NULL,
  ended_at         DATETIME     NULL,
  used_seconds     INT UNSIGNED NOT NULL DEFAULT 0,
  used_bytes_in    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  used_bytes_out   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bonus_time       INT UNSIGNED NOT NULL DEFAULT 0,
  bonus_data       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bonus_validity   INT UNSIGNED NOT NULL DEFAULT 0,
  bound_mac        VARCHAR(17)  NULL,
  last_mac         VARCHAR(17)  NULL,
  last_ip          VARCHAR(45)  NULL,
  last_router_id   INT UNSIGNED NULL,
  notes            VARCHAR(255) NULL,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cards_username (username),
  UNIQUE KEY uq_cards_short (short_code),
  KEY idx_cards_batch (batch_id),
  KEY idx_cards_category_status (category_id, status),
  KEY idx_cards_state (status, is_disabled),
  KEY idx_cards_cat_state (category_id, status, is_disabled),
  KEY idx_cards_status (status),
  KEY idx_cards_distributor (distributor_id),
  KEY idx_cards_sale (sale_id),
  KEY idx_cards_expires (expires_at),
  KEY idx_cards_first_login (first_login_at),
  KEY idx_cards_ended (ended_at),
  KEY idx_cards_bound_mac (bound_mac),
  KEY idx_cards_last_mac (last_mac),
  KEY idx_cards_ext_serial (ext_serial),
  CONSTRAINT fk_cards_batch       FOREIGN KEY (batch_id)       REFERENCES batches (id),
  CONSTRAINT fk_cards_category    FOREIGN KEY (category_id)    REFERENCES categories (id),
  CONSTRAINT fk_cards_distributor FOREIGN KEY (distributor_id) REFERENCES distributors (id) ON DELETE SET NULL,
  CONSTRAINT fk_cards_sale        FOREIGN KEY (sale_id)        REFERENCES sales (id) ON DELETE SET NULL,
  CONSTRAINT fk_cards_router      FOREIGN KEY (last_router_id) REFERENCES routers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وجود البطاقة على كل راوتر وعدّاداتها هناك
CREATE TABLE IF NOT EXISTS card_routers (
  card_id          INT UNSIGNED NOT NULL,
  router_id        INT UNSIGNED NOT NULL,
  state            ENUM('present','removed') NOT NULL DEFAULT 'present',
  uptime           INT UNSIGNED NOT NULL DEFAULT 0,
  bytes_in         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_out        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  carry_uptime     INT UNSIGNED NOT NULL DEFAULT 0,
  carry_bytes_in   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  carry_bytes_out  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  provisioned_at   DATETIME     NULL,
  synced_at        DATETIME     NULL,
  PRIMARY KEY (card_id, router_id),
  KEY idx_cardr_router_state (router_id, state),
  CONSTRAINT fk_cardr_card   FOREIGN KEY (card_id)   REFERENCES cards (id)   ON DELETE CASCADE,
  CONSTRAINT fk_cardr_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الباقات والمشتركون
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(100) NOT NULL,
  service               ENUM('ppp','hotspot') NOT NULL DEFAULT 'ppp',
  price                 DECIMAL(12,2) NOT NULL DEFAULT 0,
  duration_days         SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  rate_up               VARCHAR(20)  NULL,
  rate_down             VARCHAR(20)  NULL,
  burst_rate_up         VARCHAR(20)  NULL,
  burst_rate_down       VARCHAR(20)  NULL,
  burst_threshold_up    VARCHAR(20)  NULL,
  burst_threshold_down  VARCHAR(20)  NULL,
  burst_time            SMALLINT UNSIGNED NULL,
  data_limit            BIGINT UNSIGNED NULL,
  fup_rate_up           VARCHAR(20)  NULL,
  fup_rate_down         VARCHAR(20)  NULL,
  shared_users          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  local_address         VARCHAR(45)  NULL,
  address_pool          VARCHAR(64)  NULL,
  dns_server            VARCHAR(100) NULL,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  sort                  INT          NOT NULL DEFAULT 0,
  notes                 VARCHAR(255) NULL,
  created_at            DATETIME     NOT NULL,
  updated_at            DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscribers (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name          VARCHAR(120) NOT NULL,
  phone              VARCHAR(30)  NULL,
  phone2             VARCHAR(30)  NULL,
  national_id        VARCHAR(30)  NULL,
  address            VARCHAR(255) NULL,
  area_id            INT UNSIGNED NULL,
  router_id          INT UNSIGNED NULL,
  site_id            INT UNSIGNED NULL,
  lat                DECIMAL(10,7) NULL,
  lng                DECIMAL(10,7) NULL,
  username           VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  password           VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  plan_id            INT UNSIGNED NOT NULL,
  status             ENUM('active','suspended') NOT NULL DEFAULT 'active',
  suspend_reason     VARCHAR(255) NULL,
  expires_at         DATETIME     NULL,
  static_ip          VARCHAR(45)  NULL,
  lock_mac           TINYINT(1)   NOT NULL DEFAULT 0,
  bound_mac          VARCHAR(17)  NULL,
  rate_up            VARCHAR(20)  NULL,
  rate_down          VARCHAR(20)  NULL,
  balance            DECIMAL(12,2) NOT NULL DEFAULT 0,
  period_started_at  DATETIME     NULL,
  period_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  fup_active         TINYINT(1)   NOT NULL DEFAULT 0,
  router_state       ENUM('none','enabled','disabled') NOT NULL DEFAULT 'none',
  last_online_at     DATETIME     NULL,
  last_mac           VARCHAR(17)  NULL,
  last_ip            VARCHAR(45)  NULL,
  last_router_id     INT UNSIGNED NULL,
  notes              VARCHAR(500) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL,
  updated_at         DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscribers_username (username),
  KEY idx_subscribers_plan (plan_id),
  KEY idx_subscribers_area (area_id),
  KEY idx_subscribers_router (router_id),
  KEY idx_subscribers_site (site_id),
  KEY idx_subscribers_expires (expires_at),
  KEY idx_subscribers_phone (phone),
  KEY idx_subscribers_name (full_name),
  CONSTRAINT fk_subscribers_plan   FOREIGN KEY (plan_id)        REFERENCES plans (id),
  CONSTRAINT fk_subscribers_area   FOREIGN KEY (area_id)        REFERENCES areas (id)   ON DELETE SET NULL,
  CONSTRAINT fk_subscribers_router FOREIGN KEY (router_id)      REFERENCES routers (id) ON DELETE SET NULL,
  CONSTRAINT fk_subscribers_site   FOREIGN KEY (site_id)        REFERENCES sites (id)   ON DELETE SET NULL,
  CONSTRAINT fk_subscribers_lastr  FOREIGN KEY (last_router_id) REFERENCES routers (id) ON DELETE SET NULL,
  CONSTRAINT fk_subscribers_emp    FOREIGN KEY (created_by)     REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriber_transactions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscriber_id  INT UNSIGNED NOT NULL,
  type           ENUM('renewal','payment','charge','discount','refund','compensation') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  plan_id        INT UNSIGNED NULL,
  period_from    DATETIME     NULL,
  period_to      DATETIME     NULL,
  method         VARCHAR(40)  NULL,
  notes          VARCHAR(255) NULL,
  employee_id    INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_st_subscriber (subscriber_id, created_at),
  KEY idx_st_created (created_at),
  CONSTRAINT fk_st_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE CASCADE,
  CONSTRAINT fk_st_plan       FOREIGN KEY (plan_id)       REFERENCES plans (id) ON DELETE SET NULL,
  CONSTRAINT fk_st_employee   FOREIGN KEY (employee_id)   REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وجود مشترك الهوتسبوت/PPP على كل راوتر وعدّاداته هناك
CREATE TABLE IF NOT EXISTS subscriber_routers (
  subscriber_id    INT UNSIGNED NOT NULL,
  router_id        INT UNSIGNED NOT NULL,
  state            ENUM('present','removed') NOT NULL DEFAULT 'present',
  uptime           INT UNSIGNED NOT NULL DEFAULT 0,
  bytes_in         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_out        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  provisioned_at   DATETIME     NULL,
  synced_at        DATETIME     NULL,
  PRIMARY KEY (subscriber_id, router_id),
  KEY idx_subr_router_state (router_id, state),
  CONSTRAINT fk_subr_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE CASCADE,
  CONSTRAINT fk_subr_router     FOREIGN KEY (router_id)     REFERENCES routers (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- سجل أسماء الدخول الموحَّد: يمنع تداخل اسم بطاقة مع رقم مختصر أو مشترك
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logins (
  login       VARCHAR(64)  NOT NULL,
  owner_type  ENUM('card','card_short','subscriber') NOT NULL,
  owner_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (login),
  KEY idx_logins_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- ---------------------------------------------------------------------------
-- الجلسات وسجل الدخول
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  router_id        INT UNSIGNED NULL,
  service          ENUM('hotspot','ppp') NOT NULL,
  username         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  card_id          INT UNSIGNED NULL,
  subscriber_id    INT UNSIGNED NULL,
  mac              VARCHAR(17)  NULL,
  ip               VARCHAR(45)  NULL,
  active_ref       VARCHAR(20)  NULL,
  login_by         VARCHAR(30)  NULL,
  started_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NOT NULL,
  stopped_at       DATETIME     NULL,
  uptime           INT UNSIGNED NOT NULL DEFAULT 0,
  bytes_in         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_out        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  stop_reason      VARCHAR(40)  NULL,
  rate_down        BIGINT UNSIGNED NULL,
  rate_up          BIGINT UNSIGNED NULL,
  heavy_since      DATETIME     NULL,
  heavy_alerted    TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sessions_open (stopped_at, router_id),
  KEY idx_sessions_router_user (router_id, username, stopped_at),
  KEY idx_sessions_card (card_id, stopped_at),
  KEY idx_sessions_subscriber (subscriber_id, started_at),
  KEY idx_sessions_username (username),
  KEY idx_sessions_mac (mac),
  KEY idx_sessions_started (started_at),
  CONSTRAINT fk_sessions_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at     DATETIME     NOT NULL,
  router_id      INT UNSIGNED NULL,
  source         ENUM('portal','router') NOT NULL,
  event          ENUM('check','login','logout') NOT NULL,
  login          VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  mac            VARCHAR(17)  NULL,
  ip             VARCHAR(45)  NULL,
  result         ENUM('accept','reject','info') NOT NULL,
  reason         VARCHAR(40)  NULL,
  card_id        INT UNSIGNED NULL,
  subscriber_id  INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_ll_created (created_at),
  KEY idx_ll_login (login),
  KEY idx_ll_card (card_id),
  KEY idx_ll_subscriber (subscriber_id),
  KEY idx_ll_mac (mac, created_at),
  KEY idx_ll_router (router_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- دليل الأجهزة: MAC ← اسم الجهاز وآخر ظهور
CREATE TABLE IF NOT EXISTS devices (
  mac         VARCHAR(17)  NOT NULL,
  hostname    VARCHAR(128) NULL,
  last_ip     VARCHAR(45)  NULL,
  router_id   INT UNSIGNED NULL,
  first_seen  DATETIME     NOT NULL,
  last_seen   DATETIME     NOT NULL,
  PRIMARY KEY (mac),
  KEY idx_devices_hostname (hostname),
  KEY idx_devices_last_seen (last_seen),
  CONSTRAINT fk_devices_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_macs (
  mac         VARCHAR(17)  NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (mac),
  CONSTRAINT fk_blocked_employee FOREIGN KEY (created_by) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- التنبيهات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type         VARCHAR(40)  NOT NULL,
  level        ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  title        VARCHAR(160) NOT NULL,
  message      VARCHAR(500) NULL,
  router_id    INT UNSIGNED NULL,
  ref          VARCHAR(80)  NULL,
  is_read      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_alerts_unread (is_read, created_at),
  KEY idx_alerts_ref (ref, created_at),
  CONSTRAINT fk_alerts_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- قوالب طباعة البطاقات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_templates (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(100) NOT NULL,
  paper           VARCHAR(20)  NOT NULL DEFAULT 'A4',
  paper_width     DECIMAL(6,1) NOT NULL DEFAULT 210.0,
  paper_height    DECIMAL(6,1) NOT NULL DEFAULT 297.0,
  cols            TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `rows`          TINYINT UNSIGNED NOT NULL DEFAULT 10,
  margin_top      DECIMAL(5,1) NOT NULL DEFAULT 8.0,
  margin_right    DECIMAL(5,1) NOT NULL DEFAULT 8.0,
  margin_bottom   DECIMAL(5,1) NOT NULL DEFAULT 8.0,
  margin_left     DECIMAL(5,1) NOT NULL DEFAULT 8.0,
  gap_x           DECIMAL(5,1) NOT NULL DEFAULT 2.0,
  gap_y           DECIMAL(5,1) NOT NULL DEFAULT 2.0,
  design          MEDIUMTEXT   NOT NULL,
  category_id     INT UNSIGNED NULL,
  is_default      TINYINT(1)   NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_pt_category (category_id),
  CONSTRAINT fk_pt_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_pt_employee FOREIGN KEY (created_by)  REFERENCES employees (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- المخزون
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  category      ENUM('router','antenna','switch','ap','cable','connector','power','tool','other') NOT NULL DEFAULT 'other',
  unit          ENUM('piece','meter','box','roll') NOT NULL DEFAULT 'piece',
  sku           VARCHAR(60)  NULL,
  track_serial  TINYINT(1)   NOT NULL DEFAULT 0,
  quantity      DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_qty       DECIMAL(12,2) NULL,
  unit_cost     DECIMAL(12,2) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_items_name (name),
  KEY idx_inv_items_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الأجهزة المرقّمة (رقم تسلسلي/MAC) ومكان كل جهاز
CREATE TABLE IF NOT EXISTS inventory_units (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id        INT UNSIGNED NOT NULL,
  serial         VARCHAR(80)  NULL,
  mac            VARCHAR(17)  NULL,
  status         ENUM('stock','installed','faulty','lost') NOT NULL DEFAULT 'stock',
  site_id        INT UNSIGNED NULL,
  subscriber_id  INT UNSIGNED NULL,
  router_id      INT UNSIGNED NULL,
  installed_at   DATETIME     NULL,
  notes          VARCHAR(255) NULL,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_units_serial (item_id, serial),
  KEY idx_inv_units_status (status),
  KEY idx_inv_units_mac (mac),
  KEY idx_inv_units_site (site_id),
  KEY idx_inv_units_sub (subscriber_id),
  CONSTRAINT fk_inv_units_item FOREIGN KEY (item_id)       REFERENCES inventory_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_units_site FOREIGN KEY (site_id)       REFERENCES sites (id)           ON DELETE SET NULL,
  CONSTRAINT fk_inv_units_sub  FOREIGN KEY (subscriber_id) REFERENCES subscribers (id)     ON DELETE SET NULL,
  CONSTRAINT fk_inv_units_rtr  FOREIGN KEY (router_id)     REFERENCES routers (id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حركات المخزون: إدخال، صرف/تركيب، إرجاع، تالف، تسوية
CREATE TABLE IF NOT EXISTS inventory_movements (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id        INT UNSIGNED NOT NULL,
  unit_id        INT UNSIGNED NULL,
  type           ENUM('in','out','return','damage','adjust') NOT NULL,
  qty            DECIMAL(12,2) NOT NULL,
  balance        DECIMAL(12,2) NOT NULL,
  unit_cost      DECIMAL(12,2) NULL,
  site_id        INT UNSIGNED NULL,
  subscriber_id  INT UNSIGNED NULL,
  receiver       VARCHAR(120) NULL,
  notes          VARCHAR(255) NULL,
  employee_id    INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_inv_mov_item (item_id, created_at),
  KEY idx_inv_mov_created (created_at),
  CONSTRAINT fk_inv_mov_item FOREIGN KEY (item_id)       REFERENCES inventory_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_mov_unit FOREIGN KEY (unit_id)       REFERENCES inventory_units (id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_mov_site FOREIGN KEY (site_id)       REFERENCES sites (id)           ON DELETE SET NULL,
  CONSTRAINT fk_inv_mov_sub  FOREIGN KEY (subscriber_id) REFERENCES subscribers (id)     ON DELETE SET NULL,
  CONSTRAINT fk_inv_mov_emp  FOREIGN KEY (employee_id)   REFERENCES employees (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الأجهزة الموثوقة للدخول بالبصمة من التطبيقات
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_devices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id   INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  platform      VARCHAR(20)  NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  created_at    DATETIME     NOT NULL,
  last_used_at  DATETIME     NULL,
  last_ip       VARCHAR(45)  NULL,
  revoked_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_devices_token (token_hash),
  KEY idx_devices_employee (employee_id),
  CONSTRAINT fk_devices_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رموز دخول تطبيق الجوال (تُحفظ مُجزّأة فقط)
CREATE TABLE IF NOT EXISTS app_tokens (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id     INT UNSIGNED NOT NULL,
  token_hash      CHAR(64)     NOT NULL,
  name            VARCHAR(120) NOT NULL,
  platform        VARCHAR(20)  NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL,
  last_used_at    DATETIME     NULL,
  last_ip         VARCHAR(45)  NULL,
  expires_at      DATETIME     NOT NULL,
  revoked_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_tokens_hash (token_hash),
  KEY idx_app_tokens_employee (employee_id),
  CONSTRAINT fk_app_tokens_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ربط المشتركين ببوت تيليجرام للزبائن
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telegram_links (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id         VARCHAR(32)  NOT NULL,
  subscriber_id   INT UNSIGNED NOT NULL,
  linked_at       DATETIME     NOT NULL,
  reminded_for    DATETIME     NULL,
  expired_for     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tg_link (chat_id, subscriber_id),
  KEY idx_tg_link_sub (subscriber_id),
  CONSTRAINT fk_tg_link_sub FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الرسائل النصية (TweetSMS)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sms_messages (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind           ENUM('manual','auto','test') NOT NULL DEFAULT 'manual',
  audience       VARCHAR(255) NULL,
  body           TEXT         NOT NULL,
  personalized   TINYINT(1)   NOT NULL DEFAULT 0,
  segments       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  recipients     INT UNSIGNED NOT NULL DEFAULT 0,
  sent           INT UNSIGNED NOT NULL DEFAULT 0,
  failed         INT UNSIGNED NOT NULL DEFAULT 0,
  status         ENUM('queued','sending','done','failed') NOT NULL DEFAULT 'queued',
  scheduled_at   DATETIME     NULL,
  error          VARCHAR(255) NULL,
  employee_id    INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL,
  finished_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_sms_created (created_at),
  KEY idx_sms_status (status),
  CONSTRAINT fk_sms_emp FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_requests (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id     INT UNSIGNED NOT NULL,
  numbers        INT UNSIGNED NOT NULL,
  ok             TINYINT(1)   NOT NULL DEFAULT 0,
  code           INT          NULL,
  description    VARCHAR(255) NULL,
  raw            TEXT         NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_smsreq_msg (message_id),
  CONSTRAINT fk_smsreq_msg FOREIGN KEY (message_id) REFERENCES sms_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_recipients (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id     INT UNSIGNED NOT NULL,
  subscriber_id  INT UNSIGNED NULL,
  name           VARCHAR(120) NULL,
  phone          VARCHAR(15)  NOT NULL,
  body           TEXT         NULL,
  status         ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  code           INT          NULL,
  request_id     BIGINT UNSIGNED NULL,
  updated_at     DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_smsrcp_msg (message_id, status),
  KEY idx_smsrcp_phone (phone),
  KEY idx_smsrcp_sub (subscriber_id),
  CONSTRAINT fk_smsrcp_msg FOREIGN KEY (message_id) REFERENCES sms_messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_smsrcp_sub FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_templates (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(80)  NOT NULL,
  body           TEXT         NOT NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- منع تكرار الرسائل التلقائية لنفس الحدث
CREATE TABLE IF NOT EXISTS sms_auto (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscriber_id  INT UNSIGNED NOT NULL,
  kind           ENUM('remind','expired','renew') NOT NULL,
  ref            DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_auto (subscriber_id, kind, ref),
  CONSTRAINT fk_sms_auto_sub FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- مراقبة البطاريات والطاقة الشمسية: سجل قراءات الجهد والحرارة لكل جهاز
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS router_power (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  router_id    INT UNSIGNED NOT NULL,
  created_at   DATETIME     NOT NULL,
  voltage      DECIMAL(5,1) NOT NULL,
  temperature  DECIMAL(5,1) NULL,
  PRIMARY KEY (id),
  KEY idx_power_router_time (router_id, created_at),
  KEY idx_power_time (created_at),
  CONSTRAINT fk_power_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- سجل الانقطاعات وتعويض المشتركين
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outages (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  router_id          INT UNSIGNED NULL,
  area_id            INT UNSIGNED NULL,
  title              VARCHAR(160) NOT NULL,
  cause              ENUM('unknown','power','fault','damage','maintenance') NOT NULL DEFAULT 'unknown',
  source             ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  started_at         DATETIME     NOT NULL,
  ended_at           DATETIME     NULL,
  affected           INT UNSIGNED NOT NULL DEFAULT 0,
  notes              VARCHAR(500) NULL,
  compensated_at     DATETIME     NULL,
  compensated_days   SMALLINT UNSIGNED NULL,
  compensated_count  INT UNSIGNED NULL,
  compensated_by     INT UNSIGNED NULL,
  compensated_auto   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL,
  updated_at         DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_outages_router (router_id, ended_at),
  KEY idx_outages_started (started_at),
  CONSTRAINT fk_outages_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE SET NULL,
  CONSTRAINT fk_outages_area FOREIGN KEY (area_id) REFERENCES areas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outage_compensations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  outage_id       INT UNSIGNED NOT NULL,
  subscriber_id   INT UNSIGNED NOT NULL,
  days            SMALLINT UNSIGNED NOT NULL,
  old_expires_at  DATETIME     NULL,
  new_expires_at  DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outcomp (outage_id, subscriber_id),
  KEY idx_outcomp_subscriber (subscriber_id),
  CONSTRAINT fk_outcomp_outage FOREIGN KEY (outage_id) REFERENCES outages (id) ON DELETE CASCADE,
  CONSTRAINT fk_outcomp_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- المصاريف وصافي الربح (الإصدار 2.3.0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category     VARCHAR(30)  NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  spent_on     DATE         NOT NULL,
  description  VARCHAR(255) NULL,
  site_id      INT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_expenses_date (spent_on),
  KEY idx_expenses_category (category, spent_on),
  CONSTRAINT fk_expenses_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partners (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  share       DECIMAL(5,2) NOT NULL,
  phone       VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- مهام الصيانة (الإصدار 2.3.0)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_tasks (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title          VARCHAR(160) NOT NULL,
  type           ENUM('install','repair','maintenance','other') NOT NULL DEFAULT 'repair',
  priority       ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  status         ENUM('new','in_progress','done','cancelled') NOT NULL DEFAULT 'new',
  assignee_id    INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,
  router_id      INT UNSIGNED NULL,
  subscriber_id  INT UNSIGNED NULL,
  location       VARCHAR(255) NULL,
  description    TEXT         NULL,
  due_on         DATE         NULL,
  started_at     DATETIME     NULL,
  done_at        DATETIME     NULL,
  result         VARCHAR(500) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_mt_status (status, due_on),
  KEY idx_mt_assignee (assignee_id, status),
  CONSTRAINT fk_mt_assignee FOREIGN KEY (assignee_id) REFERENCES employees (id) ON DELETE SET NULL,
  CONSTRAINT fk_mt_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL,
  CONSTRAINT fk_mt_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE SET NULL,
  CONSTRAINT fk_mt_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_parts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id      INT UNSIGNED NOT NULL,
  item_id      INT UNSIGNED NOT NULL,
  qty          DECIMAL(12,2) NOT NULL,
  unit_cost    DECIMAL(12,2) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_mp_task (task_id),
  CONSTRAINT fk_mp_task FOREIGN KEY (task_id) REFERENCES maintenance_tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_mp_item FOREIGN KEY (item_id) REFERENCES inventory_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فحص الكابلات ومنافذ Ethernet: كل فحص يدوي أو دوري مع نتيجته وقراءة العدّادات للمقارنة في الفحص التالي
CREATE TABLE IF NOT EXISTS cable_checks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  router_id    INT UNSIGNED NOT NULL,
  port         VARCHAR(60)  NOT NULL,
  level        ENUM('ok','warn','bad') NOT NULL DEFAULT 'ok',
  summary      VARCHAR(255) NOT NULL,
  result       MEDIUMTEXT   NULL,
  snapshot     TEXT         NULL,
  length_m     SMALLINT UNSIGNED NULL,
  source       ENUM('manual','auto','deep') NOT NULL DEFAULT 'manual',
  employee_id  INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cc_port (router_id, port, id),
  KEY idx_cc_created (created_at),
  CONSTRAINT fk_cc_router FOREIGN KEY (router_id) REFERENCES routers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- إعلانات صفحة الهوتسبوت: عروض الشبكة وإعلانات محلات المنطقة على صفحة الدخول
-- routers: أرقام أجهزة MikroTik مفصولة بفواصل (فارغ = كل الأجهزة)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hotspot_ads (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  title        VARCHAR(120)  NOT NULL,
  body         VARCHAR(500)  NULL,
  advertiser   VARCHAR(120)  NULL,
  image        VARCHAR(255)  NULL,
  link         VARCHAR(255)  NULL,
  phone        VARCHAR(30)   NULL,
  routers      VARCHAR(1000) NULL,
  starts_on    DATE          NULL,
  ends_on      DATE          NULL,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  sort         INT           NOT NULL DEFAULT 0,
  views        INT UNSIGNED  NOT NULL DEFAULT 0,
  clicks       INT UNSIGNED  NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED  NULL,
  created_at   DATETIME      NOT NULL,
  updated_at   DATETIME      NULL,
  PRIMARY KEY (id),
  KEY idx_hotspot_ads_active (is_active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- تحديد سرعة مستهلك (كرت أو مشترك) مؤقتاً أو حتى الرفع: يُطبَّق بملف سرعة مخفّض على جهاز MikroTik
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS speed_caps (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind        ENUM('card','subscriber') NOT NULL,
  ref_id      INT UNSIGNED NOT NULL,
  username    VARCHAR(64)  NOT NULL,
  rate_down   VARCHAR(20)  NOT NULL,
  rate_up     VARCHAR(20)  NOT NULL,
  reason      VARCHAR(160) NULL,
  auto        TINYINT(1)   NOT NULL DEFAULT 0,
  ends_at     DATETIME     NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  removed_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_speed_caps_ref (kind, ref_id, removed_at),
  KEY idx_speed_caps_open (removed_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
