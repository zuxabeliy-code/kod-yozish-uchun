-- ADI BURGER 2.0 — MySQL sxemasi (MySQL 5.7+ / MariaDB 10.3+)
-- Qayta ishga tushirish xavfsiz: hamma jadval IF NOT EXISTS bilan yaratiladi.
-- Pul qiymatlari so'mda, butun son (INT/BIGINT) — kasr xatolari bo'lmaydi.

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  pin_salt CHAR(32) NOT NULL,
  pin_hash CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin panelga login/parol bilan kiradigan hisoblar (har biri xodimga bog'langan)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  icon VARCHAR(16) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  price INT UNSIGNED NOT NULL DEFAULT 0,
  image VARCHAR(255) NULL,
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  in_pos TINYINT(1) NOT NULL DEFAULT 1,
  in_bot TINYINT(1) NOT NULL DEFAULT 1,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_cat (category_id, sort),
  CONSTRAINT fk_products_cat FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dining_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  zone VARCHAR(50) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tg_id BIGINT NULL UNIQUE,
  tg_username VARCHAR(64) NULL,
  name VARCHAR(100) NULL,
  phone VARCHAR(20) NULL,
  last_address VARCHAR(255) NULL,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  app_version VARCHAR(20) NULL,
  last_seen_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  status_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  number INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  opening_cash BIGINT NOT NULL DEFAULT 0,
  expected_cash BIGINT NULL,
  counted_cash BIGINT NULL,
  report_json TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_shifts_status (status, opened_at),
  CONSTRAINT fk_shifts_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_shifts_dev FOREIGN KEY (device_id) REFERENCES devices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: buyurtma jarayoni; pay_status: to'lov; printer holati alohida (print_jobs)
CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  number VARCHAR(20) NOT NULL,
  source ENUM('pos','telegram') NOT NULL DEFAULT 'pos',
  type ENUM('table','takeaway','phone','delivery') NOT NULL DEFAULT 'takeaway',
  table_id INT UNSIGNED NULL,
  bill_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
  shift_id INT UNSIGNED NULL,
  employee_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  customer_name VARCHAR(100) NULL,
  phone VARCHAR(20) NULL,
  address VARCHAR(255) NULL,
  comment VARCHAR(500) NULL,
  status ENUM('pending_pay','new','accepted','ready','done','rejected','cancelled') NOT NULL DEFAULT 'accepted',
  pay_status ENUM('unpaid','checking','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  pay_method ENUM('cash','card','hamyon','mixed') NULL,
  items_total BIGINT NOT NULL DEFAULT 0,
  delivery_fee BIGINT NOT NULL DEFAULT 0,
  total BIGINT NOT NULL DEFAULT 0,
  paid_total BIGINT NOT NULL DEFAULT 0,
  rev INT UNSIGNED NOT NULL DEFAULT 1,
  kitchen_needed TINYINT(1) NOT NULL DEFAULT 0,
  reject_reason VARCHAR(255) NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  closed_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_status (status, updated_at),
  KEY idx_orders_created (created_at),
  KEY idx_orders_shift (shift_id),
  KEY idx_orders_updated (updated_at),
  CONSTRAINT fk_orders_table FOREIGN KEY (table_id) REFERENCES dining_tables(id),
  CONSTRAINT fk_orders_shift FOREIGN KEY (shift_id) REFERENCES shifts(id),
  CONSTRAINT fk_orders_emp FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_orders_dev FOREIGN KEY (device_id) REFERENCES devices(id),
  CONSTRAINT fk_orders_cust FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Narx va nom buyurtma paytidagi holatda saqlanadi (narx snapshoti)
CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  price INT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  sent_qty INT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_qty INT UNSIGNED NOT NULL DEFAULT 0,
  comment VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_items_order (order_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oshxonaga yuborilgandan keyin bekor qilingan taomlar tarixi
CREATE TABLE IF NOT EXISTS item_cancels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  price INT UNSIGNED NOT NULL DEFAULT 0,
  qty INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  employee_id INT UNSIGNED NULL,
  shift_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_cancels_created (created_at),
  CONSTRAINT fk_cancels_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_cancels_item FOREIGN KEY (order_item_id) REFERENCES order_items(id),
  CONSTRAINT fk_cancels_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
  shift_id INT UNSIGNED NULL,
  method ENUM('cash','terminal','click','card_transfer','hamyon') NOT NULL,
  amount BIGINT NOT NULL,
  received BIGINT NULL,
  change_amount BIGINT NOT NULL DEFAULT 0,
  employee_id INT UNSIGNED NULL,
  status ENUM('ok','void') NOT NULL DEFAULT 'ok',
  created_at DATETIME NOT NULL,
  KEY idx_payments_created (created_at),
  KEY idx_payments_shift (shift_id),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_pay_shift FOREIGN KEY (shift_id) REFERENCES shifts(id),
  CONSTRAINT fk_pay_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deliveries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL UNIQUE,
  address VARCHAR(255) NOT NULL,
  fee BIGINT NOT NULL DEFAULT 0,
  status ENUM('waiting','on_way','delivered','cancelled') NOT NULL DEFAULT 'waiting',
  delivered_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_deliv_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kassadagi printer agenti navbatining serverdagi nusxasi (hisobot va diagnostika uchun)
CREATE TABLE IF NOT EXISTS print_jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  order_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  kind ENUM('kitchen','customer','cancel','zreport','test','report') NOT NULL,
  status ENUM('pending','sending','sent','error','unknown','reprinted') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  reprint_of CHAR(36) NULL,
  created_at DATETIME NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pj_status (status),
  CONSTRAINT fk_pj_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_pj_dev FOREIGN KEY (device_id) REFERENCES devices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offline sinxronlash jurnali: har bir amal bitta noyob op_uuid bilan bir marta bajariladi
CREATE TABLE IF NOT EXISTS sync_operations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  op_uuid CHAR(36) NOT NULL UNIQUE,
  device_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('applied','failed') NOT NULL,
  error VARCHAR(500) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  client_time DATETIME NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sync_status (status),
  CONSTRAINT fk_sync_dev FOREIGN KEY (device_id) REFERENCES devices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_checks (
  code VARCHAR(40) NOT NULL PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  status ENUM('ok','warn','error','unchecked') NOT NULL DEFAULT 'unchecked',
  message VARCHAR(500) NULL,
  details TEXT NULL,
  advice VARCHAR(1000) NULL,
  checked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS error_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  component VARCHAR(40) NOT NULL,
  code VARCHAR(60) NOT NULL,
  message VARCHAR(500) NOT NULL,
  details TEXT NULL,
  advice VARCHAR(1000) NULL,
  occurrences INT UNSIGNED NOT NULL DEFAULT 1,
  fix_status ENUM('open','fixed','ignored') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fixed_at DATETIME NULL,
  KEY idx_err_status (fix_status, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  data TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hamyon_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id VARCHAR(64) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
  amount BIGINT NOT NULL,
  pay_amount BIGINT NOT NULL,
  card VARCHAR(40) NOT NULL DEFAULT '',
  status ENUM('pending','paid','cancel') NOT NULL DEFAULT 'pending',
  expire_at INT UNSIGNED NOT NULL,
  checked_at INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  KEY idx_hamyon_status (status, expire_at),
  CONSTRAINT fk_hamyon_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Botda "Karta" to'lovi: mijoz yuklagan to'lov cheki rasmi
CREATE TABLE IF NOT EXISTS card_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  file VARCHAR(100) NOT NULL,
  status ENUM('checking','approved','rejected') NOT NULL DEFAULT 'checking',
  note VARCHAR(255) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cr_order (order_id),
  CONSTRAINT fk_cr_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Telegram xabarlari ikki marta qayta ishlanmasligi uchun (Telegram sekin javobda xabarni qayta yuboradi)
CREATE TABLE IF NOT EXISTS tg_updates (
  update_id BIGINT NOT NULL PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tgu_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
