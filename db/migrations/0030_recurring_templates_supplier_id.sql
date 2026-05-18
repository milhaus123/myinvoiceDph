-- MyInvoice.cz — Opravná migrace: supplier_id v recurring_invoice_templates
--
-- Tabulka vznikla v 0025_recurring_invoices.sql bez sloupce supplier_id.
-- Migrace 0021_recurring_invoices.sql ho sice definuje v CREATE TABLE,
-- ale ta byla no-op (tabulka už existovala). Výsledek: SummaryAction
-- selhal s "Unknown column 'supplier_id' in WHERE".
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS + UPDATE WHERE IS NULL + MODIFY.

SET NAMES utf8mb4;

-- 1. Přidat sloupec — nullable kvůli backfillu před NOT NULL constraintem
ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS supplier_id TINYINT UNSIGNED NULL AFTER id;

-- 2. Backfill ze spřažené tabulky clients (client_id → clients.supplier_id).
--    client_id je NOT NULL FK → clients, clients.supplier_id je NOT NULL,
--    takže každý řádek dostane hodnotu.
UPDATE recurring_invoice_templates rit
  JOIN clients c ON c.id = rit.client_id
  SET rit.supplier_id = c.supplier_id
WHERE rit.supplier_id IS NULL;

-- 3. Přepnout na NOT NULL (bezpečné po backfillu).
ALTER TABLE recurring_invoice_templates
  MODIFY COLUMN supplier_id TINYINT UNSIGNED NOT NULL;

-- 4. Index pro rychlý lookup per-supplier (pokud chybí).
ALTER TABLE recurring_invoice_templates
  ADD KEY IF NOT EXISTS idx_rit_supplier (supplier_id);

-- 5. FK constraint (DROP IF EXISTS + ADD — MariaDB neumí ADD CONSTRAINT IF NOT EXISTS).
ALTER TABLE recurring_invoice_templates
  DROP FOREIGN KEY IF EXISTS fk_rit_supplier;

ALTER TABLE recurring_invoice_templates
  ADD CONSTRAINT fk_rit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id);
