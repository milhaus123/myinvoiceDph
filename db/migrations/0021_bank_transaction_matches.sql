-- MyInvoice.cz — Issue #9: Bank Transaction Import + Payment Matching
-- Add matched_purchase_invoice_id to bank_transactions + bank_transaction_matches audit table

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
-- 1. Add matched_purchase_invoice_id to bank_transactions
-- ==========================================================================
ALTER TABLE bank_transactions
    ADD COLUMN matched_purchase_invoice_id BIGINT UNSIGNED NULL AFTER matched_invoice_id,
    ADD CONSTRAINT fk_bt_purchase_invoice
        FOREIGN KEY (matched_purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL;

-- ==========================================================================
-- 2. bank_transaction_matches — audit/history table for payment matching
-- Tracks every match/unmatch event so we have a full audit trail.
-- The current "live" match is also stored directly on bank_transactions for
-- query performance (idx_bt_match queries).
-- ==========================================================================
CREATE TABLE IF NOT EXISTS bank_transaction_matches (
    id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Which bank transaction this record refers to
    bank_transaction_id        BIGINT UNSIGNED NOT NULL,

    -- What it was matched to (one of these two, never both)
    invoice_id                 BIGINT UNSIGNED NULL,
    purchase_invoice_id        BIGINT UNSIGNED NULL,

    -- Match metadata
    match_type                 ENUM('auto_exact', 'auto_partial', 'manual', 'unmatched', 'ignored') NOT NULL,
    match_amount               DECIMAL(14,2) NULL,           -- actual amount on the transaction
    expected_amount            DECIMAL(14,2) NULL,           -- amount_to_pay on the invoice
    amount_diff                DECIMAL(14,2) NULL,           -- |match_amount - expected_amount|

    -- Who / when
    matched_by                 BIGINT UNSIGNED NULL,
    created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Active flag — only the latest non-unmatched record should have active=1
    is_active                  TINYINT(1) NOT NULL DEFAULT 0,

    CONSTRAINT fk_btm_tx        FOREIGN KEY (bank_transaction_id)  REFERENCES bank_transactions(id)       ON DELETE CASCADE,
    CONSTRAINT fk_btm_invoice  FOREIGN KEY (invoice_id)           REFERENCES invoices(id)                 ON DELETE CASCADE,
    CONSTRAINT fk_btm_pinvoice FOREIGN KEY (purchase_invoice_id)   REFERENCES purchase_invoices(id)       ON DELETE CASCADE,
    CONSTRAINT fk_btm_user     FOREIGN KEY (matched_by)           REFERENCES users(id)                    ON DELETE SET NULL,

    -- At most one active match per transaction
    UNIQUE KEY uq_btm_active (bank_transaction_id, is_active),

    -- Indexes for lookup
    KEY idx_btm_invoice  (invoice_id, is_active),
    KEY idx_btm_pinvoice (purchase_invoice_id, is_active),
    KEY idx_btm_type     (match_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
