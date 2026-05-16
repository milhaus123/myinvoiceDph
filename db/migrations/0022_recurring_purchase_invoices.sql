-- MyInvoice.cz — Recurring Purchase Invoices (nakup)
--
-- Šablona pro pravidelné nákupní faktury (platby dodavatelům, nájem, energie, předplatné).
-- Struktura jeanalogická recurring_invoice_templates, ale cílí na nákup místo prodeje.
--
-- Periodicity: monthly / quarterly / semi_annually / annually.
-- Den vystavení: buď konkrétní den 1-28 (day_of_month), nebo flag end_of_month
-- = 1 (poslední den měsíce, dynamicky 28/29/30/31).
--
-- auto_issue=1 → cron rovnou vystaví; 0 = nechat draft.
-- auto_send_email NELZE u purchase invoices (je to příchozí faktura, neodesílá se).

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Šablona pravidelné nákupní faktury
-- ==========================================================================

CREATE TABLE IF NOT EXISTS recurring_purchase_invoice_templates (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     BIGINT UNSIGNED NOT NULL,              -- dodavatel (firma, od které faktury přicházejí)
  project_id      BIGINT UNSIGNED NULL,
  name            VARCHAR(200) NOT NULL,                  -- display name v adminu, např. "Nájem Kancelář"

  -- Periodicita
  frequency       ENUM('monthly','quarterly','semi_annually','annually') NOT NULL,
  day_of_month    TINYINT UNSIGNED NULL,                  -- 1-28; aplikuje se jen pokud end_of_month=0
  end_of_month    TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = poslední den měsíce

  -- Harmonogram
  anchor_date     DATE NOT NULL,
  end_date        DATE NULL,
  next_run_date   DATE NOT NULL,
  last_run_date   DATE NULL,

  -- Nákupní faktura — kopíruje se 1:1 na vygenerovanou fakturu
  currency_id     INT UNSIGNED NOT NULL,
  language        ENUM('cs','en') NOT NULL DEFAULT 'cs',
  payment_method  ENUM('bank_transfer','card','cash','other') NOT NULL DEFAULT 'bank_transfer',
  reverse_charge  TINYINT(1) NOT NULL DEFAULT 0,
  payment_due_days INT UNSIGNED NOT NULL DEFAULT 14,
  note_above_items TEXT NULL,
  note_below_items TEXT NULL,
  increment_month_in_descriptions TINYINT(1) NOT NULL DEFAULT 1,

  -- Cron chování
  auto_issue      TINYINT(1) NOT NULL DEFAULT 1,          -- 1 = po generování rovnou vystavit; 0 = nechat draft

  status          ENUM('active','paused','expired') NOT NULL DEFAULT 'active',

  created_by      BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_rpit_next_run (status, next_run_date),
  KEY idx_rpit_supplier (supplier_id),
  KEY idx_rpit_project  (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recurring_purchase_invoice_templates
  DROP FOREIGN KEY IF EXISTS fk_rpit_supplier,
  DROP FOREIGN KEY IF EXISTS fk_rpit_project,
  DROP FOREIGN KEY IF EXISTS fk_rpit_currency,
  DROP FOREIGN KEY IF EXISTS fk_rpit_user;

ALTER TABLE recurring_purchase_invoice_templates
  ADD CONSTRAINT fk_rpit_supplier FOREIGN KEY (supplier_id) REFERENCES clients(id),
  ADD CONSTRAINT fk_rpit_project  FOREIGN KEY (project_id)  REFERENCES projects(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rpit_currency FOREIGN KEY (currency_id)  REFERENCES currencies(id),
  ADD CONSTRAINT fk_rpit_user     FOREIGN KEY (created_by)   REFERENCES users(id);

-- ==========================================================================
-- 2. Položky šablony
-- ==========================================================================

CREATE TABLE IF NOT EXISTS recurring_purchase_invoice_template_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id     BIGINT UNSIGNED NOT NULL,
  description     VARCHAR(500) NOT NULL,
  quantity        DECIMAL(10,3) NOT NULL DEFAULT 1,
  unit            VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat DECIMAL(12,2) NOT NULL,
  vat_rate_id     INT UNSIGNED NOT NULL,
  order_index     INT NOT NULL DEFAULT 0,
  KEY idx_rpitm_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recurring_purchase_invoice_template_items
  DROP FOREIGN KEY IF EXISTS fk_rpitm_template,
  DROP FOREIGN KEY IF EXISTS fk_rpitm_vat;

ALTER TABLE recurring_purchase_invoice_template_items
  ADD CONSTRAINT fk_rpitm_template FOREIGN KEY (template_id) REFERENCES recurring_purchase_invoice_templates(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_rpitm_vat      FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id);

-- ==========================================================================
-- 3. Vazba z vygenerované nákupní faktury zpět na šablonu
-- ==========================================================================

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS recurring_template_id BIGINT UNSIGNED NULL AFTER project_id;

ALTER TABLE purchase_invoices
  ADD KEY IF NOT EXISTS idx_ppi_recurring (recurring_template_id);

ALTER TABLE purchase_invoices
  DROP FOREIGN KEY IF EXISTS fk_ppi_recurring;

ALTER TABLE purchase_invoices
  ADD CONSTRAINT fk_ppi_recurring FOREIGN KEY (recurring_template_id)
    REFERENCES recurring_purchase_invoice_templates(id) ON DELETE SET NULL;
