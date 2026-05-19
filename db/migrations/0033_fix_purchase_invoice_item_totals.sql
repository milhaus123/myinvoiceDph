-- MyInvoice — oprava nulových součtů v purchase_invoice_items
-- Bug: CreatePurchaseInvoiceAction / UpdatePurchaseInvoiceAction / SetPurchaseInvoiceItemsAction
-- nevolaly PurchaseInvoiceCalculator::recompute(), takže total_without_vat / total_vat /
-- total_with_vat zůstávaly na DEFAULT 0 pro položky zadané přes UI (ne přes iDoklad import).
--
-- Fix v kódu:  PurchaseInvoiceCalculator.php + 3 action třídy
-- Tato migrace opravuje stávající data přímo v DB pomocí UPDATE.

SET NAMES utf8mb4;

-- Přepočítej per-item totals pro všechny položky kde unit_price_without_vat > 0
-- ale total_without_vat = 0 (příznak, že recompute() nebyl volán).
UPDATE purchase_invoice_items pii
   SET pii.total_without_vat = ROUND(pii.quantity * pii.unit_price_without_vat, 2),
       pii.total_vat         = ROUND(
                                   ROUND(pii.quantity * pii.unit_price_without_vat, 2)
                                   * (pii.vat_rate_snapshot / 100),
                               2),
       pii.total_with_vat    = ROUND(pii.quantity * pii.unit_price_without_vat, 2)
                               + ROUND(
                                   ROUND(pii.quantity * pii.unit_price_without_vat, 2)
                                   * (pii.vat_rate_snapshot / 100),
                               2)
 WHERE pii.total_without_vat = 0
   AND pii.unit_price_without_vat > 0;

-- Pro faktury s reverse_charge = 1: total_vat musí být 0 (jen základ)
UPDATE purchase_invoice_items pii
   JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
   SET pii.total_vat      = 0,
       pii.total_with_vat = pii.total_without_vat
 WHERE pi.reverse_charge = 1
   AND pii.total_without_vat > 0;

-- Aktualizuj i hlavičkové součty (total_without_vat, total_vat, total_with_vat na faktuře)
UPDATE purchase_invoices pi
   JOIN (
       SELECT purchase_invoice_id,
              SUM(total_without_vat) AS sum_base,
              SUM(total_vat)         AS sum_vat,
              SUM(total_with_vat)    AS sum_with
         FROM purchase_invoice_items
        GROUP BY purchase_invoice_id
   ) s ON s.purchase_invoice_id = pi.id
   SET pi.total_without_vat = s.sum_base,
       pi.total_vat         = s.sum_vat,
       pi.total_with_vat    = s.sum_with
 WHERE pi.total_vat = 0
   AND s.sum_vat > 0;
