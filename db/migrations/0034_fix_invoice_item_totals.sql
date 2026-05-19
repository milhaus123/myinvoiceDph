-- MyInvoice — oprava nulových součtů v invoice_items (vydané faktury)
-- Bug: invoice_items.total_without_vat / total_vat / total_with_vat mohly zůstat na 0
-- pokud InvoiceCalculator::recompute() nebyl zavolán (zejm. pro starší záznamy nebo
-- při mimořádném selhání).
--
-- Tato migrace je bezpečná: upraví POUZE položky kde unit_price_without_vat > 0
-- ale total_without_vat = 0 — tedy jednoznačný příznak, že přepočet neproběhl.
-- Položky s cenou 0 (záměrně nulové) se nedotýkáme.
--
-- Viz analogická migrace: 0033_fix_purchase_invoice_item_totals.sql

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Přepočítej per-item totals pro vydané faktury
--    (bez reverse_charge — ty mají total_vat = 0 záměrně)
-- ==========================================================================
UPDATE invoice_items ii
   JOIN invoices i ON i.id = ii.invoice_id
   SET ii.total_without_vat = ROUND(ii.quantity * ii.unit_price_without_vat, 2),
       ii.total_vat         = ROUND(
                                  ROUND(ii.quantity * ii.unit_price_without_vat, 2)
                                  * (ii.vat_rate_snapshot / 100),
                              2),
       ii.total_with_vat    = ROUND(ii.quantity * ii.unit_price_without_vat, 2)
                              + ROUND(
                                  ROUND(ii.quantity * ii.unit_price_without_vat, 2)
                                  * (ii.vat_rate_snapshot / 100),
                              2)
 WHERE ii.total_without_vat = 0
   AND ii.unit_price_without_vat > 0
   AND i.reverse_charge = 0;

-- ==========================================================================
-- 2. Reverse charge faktury: total_vat = 0 je správně, ale total_without_vat
--    musí být vyplněn (přepočítáme pouze základ a total_with_vat = základ)
-- ==========================================================================
UPDATE invoice_items ii
   JOIN invoices i ON i.id = ii.invoice_id
   SET ii.total_without_vat = ROUND(ii.quantity * ii.unit_price_without_vat, 2),
       ii.total_vat         = 0,
       ii.total_with_vat    = ROUND(ii.quantity * ii.unit_price_without_vat, 2)
 WHERE ii.total_without_vat = 0
   AND ii.unit_price_without_vat > 0
   AND i.reverse_charge = 1;

-- ==========================================================================
-- 3. Synchronizuj hlavičkové součty faktury
--    (jen pro faktury kde total_vat = 0 ale položky mají nenulový součet)
-- ==========================================================================
UPDATE invoices i
   JOIN (
       SELECT invoice_id,
              SUM(total_without_vat) AS sum_base,
              SUM(total_vat)         AS sum_vat,
              SUM(total_with_vat)    AS sum_with
         FROM invoice_items
        GROUP BY invoice_id
   ) s ON s.invoice_id = i.id
   SET i.total_without_vat = s.sum_base,
       i.total_vat         = s.sum_vat,
       i.total_with_vat    = s.sum_with
 WHERE i.total_vat = 0
   AND s.sum_vat > 0;
