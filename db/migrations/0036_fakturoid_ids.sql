-- MyInvoice.cz — Fakturoid ID mapping columns
-- Adds optional fakturoid_id to entities imported from Fakturoid API.
-- Enables: exact dedup by ID, traceability.
--
-- All columns are nullable (NULL = entity was NOT imported from Fakturoid).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- Clients / contacts
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS fakturoid_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Fakturoid Subject.id'
        AFTER idoklad_id,
    ADD INDEX IF NOT EXISTS idx_clients_fakturoid (supplier_id, fakturoid_id);

-- Issued invoices + credit notes (both live in `invoices` table)
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS fakturoid_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Fakturoid Invoice.id'
        AFTER idoklad_id,
    ADD INDEX IF NOT EXISTS idx_invoices_fakturoid (supplier_id, fakturoid_id);

-- Received / purchase invoices
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS fakturoid_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Fakturoid Expense.id'
        AFTER idoklad_id,
    ADD INDEX IF NOT EXISTS idx_pi_fakturoid (supplier_id, fakturoid_id);
