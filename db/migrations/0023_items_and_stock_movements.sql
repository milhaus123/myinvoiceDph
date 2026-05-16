-- MyInvoice.cz — Inventories / Stock Management (Issue #12)
--
-- SKLAD = items (položky ke sledování) + stock_movements (pohyby na skladě).
-- Řádky jsou per-supplier (multi-tenant).

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------
-- items — skladové položky
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       TINYINT UNSIGNED NOT NULL,
  sku               VARCHAR(64) NOT NULL,
  name              VARCHAR(255) NOT NULL,
  description       TEXT NULL,
  unit              VARCHAR(20) NOT NULL DEFAULT 'ks',  -- ks, h, kg, m, l …
  stock_quantity    DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  min_stock_alert   DECIMAL(15,4) NOT NULL DEFAULT 0.0000,  -- hlídání minima
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_items_supplier_sku  (supplier_id, sku),
  KEY idx_items_supplier            (supplier_id),
  CONSTRAINT fk_items_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- stock_movements — sledování všech pohybů na skladě
-- -----------------------------------------------------------------------
-- reference_type: 'purchase_invoice' | 'invoice' | 'adjustment' | 'return' | 'initial'
-- reference_id:   FK na příslušnou tabulku (NULL pro manuální adjustaci)
-- quantity:       KLADÉ číslo = přírůstek / úbytek (podle movement_type)
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id         INT UNSIGNED NOT NULL,
  supplier_id     TINYINT UNSIGNED NOT NULL,
  movement_type   ENUM('stock_in','stock_out','adjustment') NOT NULL,
  quantity        DECIMAL(15,4) NOT NULL,   -- kladná hodnota, směr určen movement_type
  stock_before    DECIMAL(15,4) NOT NULL,
  stock_after     DECIMAL(15,4) NOT NULL,
  reference_type VARCHAR(40) NULL,          -- např. 'purchase_invoice', 'invoice', 'adjustment'
  reference_id    INT UNSIGNED NULL,        -- FK na reference_type tabulku
  note            VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_sm_item        (item_id),
  KEY idx_sm_supplier    (supplier_id, created_at),
  KEY idx_sm_reference   (reference_type, reference_id),
  CONSTRAINT fk_sm_item     FOREIGN KEY (item_id)     REFERENCES items(id)     ON DELETE CASCADE,
  CONSTRAINT fk_sm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
