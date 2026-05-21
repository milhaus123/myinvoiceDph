/* MyInvoice.cz — Cash Register / Pokladna (Issue #16) */

SET NAMES utf8mb4;

/* cash_register_movements — hotovostni pohyby v pokladne */
CREATE TABLE IF NOT EXISTS cash_register_movements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     TINYINT UNSIGNED NOT NULL,
  movement_type   ENUM('income','expense') NOT NULL,
  amount         DECIMAL(15,2) NOT NULL COMMENT 'kladna castka v mene fakturace',
  currency_id    INT UNSIGNED NOT NULL COMMENT 'FK na currencies',
  description     VARCHAR(500) NOT NULL DEFAULT '',
  category       VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'kategorie: jidlo, doprava, material …',
  client_id      BIGINT UNSIGNED NULL COMMENT 'volitelny FK na clients',
  project_id     BIGINT UNSIGNED NULL COMMENT 'volitelny FK na projects',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_crm_supplier      (supplier_id),
  KEY idx_crm_type          (movement_type),
  KEY idx_crm_category      (category),
  KEY idx_crm_client        (client_id),
  KEY idx_crm_project       (project_id),
  KEY idx_crm_created       (created_at),
  CONSTRAINT fk_crm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_crm_client   FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE SET NULL,
  CONSTRAINT fk_crm_project  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* cash_register_categories — uzivatelsky definovane kategorie */
CREATE TABLE IF NOT EXISTS cash_register_categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id TINYINT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_crc_supplier_name (supplier_id, name),
  CONSTRAINT fk_crc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* defaultni kategorie */
INSERT INTO cash_register_categories (supplier_id, name) VALUES
  (1, 'Jidlo'),
  (1, 'Doprava'),
  (1, 'Material'),
  (1, 'Sluzby'),
  (1, 'Kancelar'),
  (1, 'Telefon'),
  (1, 'Internet'),
  (1, 'Pohosteni'),
  (1, 'Cestovne'),
  (1, 'Ostatni');
