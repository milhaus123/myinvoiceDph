-- MyInvoice.cz — Purchase Invoice Document Kinds
--
-- Adds document_kind column to purchase_invoices to support filtering
-- by document type (invoice, receipt, credit note, payment).
--
-- document_kind: invoice | receipt | credit_note | payment

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS document_kind
    ENUM('invoice', 'receipt', 'credit_note', 'payment')
    NOT NULL DEFAULT 'invoice'
    AFTER status;
