-- MyInvoice.cz — iDoklad ID mapping columns
-- Adds optional idoklad_id to entities imported from iDoklad API.
-- Enables: exact dedup by ID, future bidirectional sync, traceability.
--
-- All columns are nullable (NULL = entity was NOT imported from iDoklad).
-- Indexed but not UNIQUE (same iDoklad tenant could theoretically import
-- to multiple supplier scopes, though that edge case is unlikely).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- Clients / contacts
ALTER TABLE clients
    ADD COLUMN idoklad_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'iDoklad Contact.Id'
        AFTER supplier_id,
    ADD INDEX idx_clients_idoklad (supplier_id, idoklad_id);

-- Issued invoices + credit notes (both live in `invoices` table)
ALTER TABLE invoices
    ADD COLUMN idoklad_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'iDoklad IssuedInvoice.Id or CreditNote.Id'
        AFTER supplier_id,
    ADD INDEX idx_invoices_idoklad (supplier_id, idoklad_id);

-- Received / purchase invoices
ALTER TABLE purchase_invoices
    ADD COLUMN idoklad_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'iDoklad ReceivedInvoice.Id'
        AFTER supplier_id,
    ADD INDEX idx_pi_idoklad (supplier_id, idoklad_id);
