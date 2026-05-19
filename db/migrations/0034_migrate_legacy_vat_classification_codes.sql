-- MyInvoice — migrace starých kódů členění DPH na kódy MFin ČR (formulář 25_5412)
--
-- ROOT CAUSE: invoice_items a purchase_invoice_items obsahovaly staré kódy
-- (r1, r2, r0s, r40, r43) z doby před migrací 0031_vat_classification.sql.
-- Migrace 0031 smazala tyto kódy z číselníku vat_classifications, ale data
-- v položkách faktur zůstala. DphPriznaniAction.php hledal kódy '01-02', '40-41'
-- atd. — staré kódy neodpovídaly ničemu → všechny DPH součty byly 0.
--
-- Mapování:
--   r1   → 01-02   (ř. 1 DAP DPH — zdanitelné tuzemsko 21 %)
--   r2   → 01-02   (ř. 2 DAP DPH — zdanitelné tuzemsko 12 %)
--   r0s  → NULL    (osvobozené, fallback na rate-based CASE v SQL)
--   r40  → 40-41   (ř. 40/41 DAP DPH — odpočet daně tuzemsko)
--   r43  → 43      (ř. 43 DAP DPH — odpočet daně ostatní)

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Vydané faktury (invoice_items)
-- ==========================================================================
UPDATE invoice_items
   SET vat_classification = CASE
         WHEN vat_classification IN ('r1', 'r2') THEN '01-02'
         WHEN vat_classification = 'r0s'         THEN NULL
         ELSE NULL   -- bezpečný fallback pro jakékoli jiné neznámé staré kódy
       END
 WHERE vat_classification REGEXP '^r[0-9]';

-- ==========================================================================
-- 2. Přijaté faktury (purchase_invoice_items)
-- ==========================================================================
UPDATE purchase_invoice_items
   SET vat_classification = CASE
         WHEN vat_classification = 'r40' THEN '40-41'
         WHEN vat_classification = 'r43' THEN '43'
         ELSE NULL   -- bezpečný fallback
       END
 WHERE vat_classification REGEXP '^r[0-9]';
