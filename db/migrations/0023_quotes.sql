-- MyInvoice.cz — Cenové nabídky (Quotes)
--
-- Rozšiřuje invoices o invoice_type = 'quote'.
-- Status nabídky je sledován v novém sloupci quote_status.
-- Sloupce quote_sent_at, quote_approved_at, quote_rejected_at,
-- quote_approved_by_email, quote_valid_until, quote_converted_to_invoice_id.
--
-- quote_status: draft | sent | approved | rejected | converted
-- Převod na fakturu: quote_converted_to_invoice_id

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Přidat 'quote' do invoice_type ENUM
-- ==========================================================================
ALTER TABLE invoices
  MODIFY COLUMN invoice_type
    ENUM('invoice','proforma','credit_note','cancellation','quote')
    NOT NULL DEFAULT 'invoice';

-- ==========================================================================
-- 2. Sloupce pro cenové nabídky
-- ==========================================================================
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_status
    ENUM('draft','sent','approved','rejected','converted')
    NOT NULL DEFAULT 'draft'
    AFTER status;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_sent_at
    TIMESTAMP NULL
    AFTER quote_status;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_approved_at
    TIMESTAMP NULL
    AFTER quote_sent_at;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_rejected_at
    TIMESTAMP NULL
    AFTER quote_approved_at;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_rejection_reason
    TEXT NULL
    AFTER quote_rejected_at;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_approved_by_email
    VARCHAR(255) NULL
    AFTER quote_rejection_reason;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_valid_until
    DATE NULL
    AFTER quote_approved_by_email;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS quote_converted_to_invoice_id
    BIGINT UNSIGNED NULL
    AFTER quote_valid_until;

-- ==========================================================================
-- 3. Indexy pro quotes
-- ==========================================================================
ALTER TABLE invoices
  ADD KEY IF NOT EXISTS idx_inv_quote_status (quote_status);

ALTER TABLE invoices
  ADD KEY IF NOT EXISTS idx_inv_quote_valid_until (quote_valid_until);

ALTER TABLE invoices
  ADD KEY IF NOT EXISTS idx_inv_quote_converted (quote_converted_to_invoice_id);

-- FK na cílovou fakturu (při převodu)
ALTER TABLE invoices
  DROP FOREIGN KEY IF EXISTS fk_inv_quote_invoice;

ALTER TABLE invoices
  ADD CONSTRAINT fk_inv_quote_invoice
    FOREIGN KEY (quote_converted_to_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL;

-- ==========================================================================
-- 4. Po převodu na fakturu označit quote jako converted
-- ==========================================================================
-- (Toto se děje v QuoteRepository při převodu, ne migrací)
