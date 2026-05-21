-- MyInvoice.cz — EET sessions (Elektronická evidence tržeb)
-- Issue #11: EET 2.0 implementation
--
-- EET is mandatory for cash transactions in Czech Republic.
-- Each EET session records the submission of a receipt to the EET server.
-- FIK = Fiskální identifikační kód (Fiscal Identification Code) returned by EET server.
-- PKP = Podpisový kód poptávky (Signature of request) - computed locally
-- BKP = Bezpečnostní kód poptávky (Security code of request) - computed locally
--
-- Note: EET 2.0 launching January 2027 - new format requirements will apply.
-- The architecture supports both EET 3.0 (current) and EET 2.0 (future).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
-- eet_sessions
-- Stores EET receipt submissions linked to invoices
-- ==========================================================================

CREATE TABLE IF NOT EXISTS eet_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Link to the invoice this receipt belongs to
    invoice_id          BIGINT UNSIGNED NOT NULL,

    -- EET UUID (Globally Unique Identifier) - generated per receipt
    uuid                CHAR(36) NOT NULL,

    -- Date/time of the sale/tržba (when the payment was received)
    sale_date           DATETIME NOT NULL,

    -- Total amount in CZK (EET requires CZK)
    total               DECIMAL(12,2) NOT NULL,

    -- Payment mode: cash | card | transfer | other
    payment_mode        ENUM('cash', 'card', 'transfer', 'other') NOT NULL DEFAULT 'cash',

    -- EET 3.0: režim = 0 (standard), 1 (simplified), 2 (travel voucher), 3 (VIS)
    eet_mode            TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- Supplier's DIC (Daňové identifikační číslo / Tax ID)
    dic                 VARCHAR(20) NOT NULL,

    -- Evidence: 1 (receipt), 2 (cash register), 3 (ticket sales), 4 (other)
    evidence_mode       TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- FIK returned by EET server (Fiscal Identification Code)
    -- This is the primary confirmation from the EET server
    fik                 VARCHAR(255) NULL,

    -- PKP (Podpisový kód poptávky) - computed from request data
    -- Used for offline/error scenarios
    pkp                 VARCHAR(512) NULL,

    -- BKP (Bezpečnostní kód poptávky) - hash of PKP
    bkp                 VARCHAR(64) NULL,

    -- EET server response status
    status              ENUM('pending', 'confirmed', 'error', 'offline_fallback') NOT NULL DEFAULT 'pending',

    -- Error code and message if server returned error
    error_code          VARCHAR(50) NULL,
    error_message       VARCHAR(500) NULL,

    -- Timestamp when we received confirmation from EET server
    confirmed_at        TIMESTAMP NULL,

    -- When the record was created
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Supplier scope for multi-tenant isolation
    supplier_id         TINYINT UNSIGNED NOT NULL,

    CONSTRAINT fk_eet_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_eet_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id),

    UNIQUE KEY uq_eet_uuid (uuid),
    KEY idx_eet_invoice (invoice_id),
    KEY idx_eet_status  (status),
    KEY idx_eet_sale_date (sale_date),
    KEY idx_eet_supplier  (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- Add payment_type to invoices for EET eligibility tracking
-- Issue #11: Track which invoices require EET (cash payments)
-- ==========================================================================

ALTER TABLE invoices
    ADD COLUMN payment_type ENUM('bank_transfer', 'cash', 'card', 'other') NULL DEFAULT NULL AFTER status,
    ADD INDEX idx_inv_payment_type (payment_type);
