-- MyInvoice — Členění DPH (VAT Classification)
-- Kódy vychází z číselníku MFin ČR pro DAP DPH (formulář 25_5412) a Kontrolní hlášení (25_5564).
-- Totožné s kódy používanými v iDokladu, Pohodě, Flexibee a dalším českém účetním softwaru.
--
-- Přidává:
--   1. vat_classifications — číselník kódů členění DPH
--   2. invoice_items.vat_classification — FK/kód (vydané faktury)
--   3. purchase_invoice_items.vat_classification — FK/kód (přijaté faktury)

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
-- 1. Číselník členění DPH
-- ==========================================================================
CREATE TABLE IF NOT EXISTS vat_classifications (
    code            VARCHAR(10)  NOT NULL PRIMARY KEY,
    label_cs        VARCHAR(160) NOT NULL,
    label_en        VARCHAR(160) NOT NULL DEFAULT '',
    applies_to      ENUM('sales','purchases','both') NOT NULL DEFAULT 'both',
    -- Řádek v DAP DPH (formulář 25_5412); 0 = nevstupuje do přiznání
    dap_row         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Řádek v DAP DPH',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    display_order   INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Odstraní případné staré záznamy z předchozí verze migrace (kódy r1, r2, r40 …)
DELETE FROM vat_classifications WHERE code REGEXP '^r[0-9]';

-- --------------------------------------------------------------------------
-- Výstupy (vydané faktury / sales)
-- --------------------------------------------------------------------------
INSERT INTO vat_classifications (code, label_cs, label_en, applies_to, dap_row, display_order) VALUES
-- Bez vlivu na DPH
('0U',     'Plnění bez vlivu na DPH (vydané)',                    'No VAT impact (sales)',                             'sales',   0,  10),
-- Tuzemská zdanitelná plnění — A.4 / A.5 kontrolního hlášení, ř. 1/2 DAP DPH
('01-02',  'Zdanitelné plnění tuzemsko (ř. 1/2)',                 'Domestic taxable supply (row 1/2)',                 'sales',   1,  20),
('01-02c', 'Zdanitelné plnění – cestovní služba § 89 (ř. 1/2)',  'Taxable supply – travel service § 89 (row 1/2)',    'sales',   1,  30),
('01-02p', 'Zdanitelné plnění – použité zboží (ř. 1/2)',         'Taxable supply – used goods (row 1/2)',             'sales',   1,  40),
-- Dodání zboží / služeb do zahraničí — souhrnné hlášení, ř. 20–22 DAP DPH
('20',     'Dodání zboží do EU § 64 (ř. 20)',                    'Intra-EU supply of goods § 64 (row 20)',            'sales',  20,  50),
('21',     'Třístranný obchod – prostřední osoba (ř. 21)',       'Triangular trade – intermediary (row 21)',          'sales',  21,  60),
('22',     'Vývoz zboží § 66 (ř. 22)',                           'Export of goods § 66 (row 22)',                     'sales',  22,  70),
-- Služby do EU — souhrnné hlášení, ř. 21 DAP DPH
('31',     'Poskytnutí služby do EU § 9 (ř. 21)',                'Supply of services to EU § 9 (row 21)',             'sales',  21,  80),
-- Přenesená daňová povinnost — dodavatel, A.1 kontrolního hlášení, ř. 25 DAP DPH
('25',     'PDP dodavatel § 92a (ř. 25)',                        'Reverse charge supplier § 92a (row 25)',            'sales',  25,  90),
-- Investiční zlato — A.3 kontrolního hlášení, ř. 26 DAP DPH
('26z',    'Investiční zlato § 101c (ř. 26)',                    'Investment gold § 101c (row 26)',                   'sales',  26, 100),
-- Osvobozená plnění bez nároku na odpočet — ř. 50 DAP DPH
('50',     'Osvobozené plnění bez nároku na odpočet (ř. 50)',   'Exempt supply without deduction (row 50)',           'sales',  50, 110),
-- Zálohy na tuzemské zdanitelné plnění — ř. 23 DAP DPH
('23',     'Zálohy na tuzemské plnění (ř. 23)',                'Domestic advance payments (row 23)',                  'sales',  23, 115),
-- Zálohy na vývoz zboží — ř. 24 DAP DPH
('24',     'Zálohy na vývoz zboží (ř. 24)',                    'Export advance payments (row 24)',                  'sales',  24, 116)

ON DUPLICATE KEY UPDATE
    label_cs = VALUES(label_cs),
    label_en = VALUES(label_en),
    dap_row  = VALUES(dap_row),
    display_order = VALUES(display_order);

-- --------------------------------------------------------------------------
-- Vstupy (přijaté faktury / purchases)
-- --------------------------------------------------------------------------
INSERT INTO vat_classifications (code, label_cs, label_en, applies_to, dap_row, display_order) VALUES
-- Bez vlivu na DPH
('0P',     'Plnění bez vlivu na DPH (přijaté)',                  'No VAT impact (purchases)',                         'purchases',  0, 120),
-- Pořízení zboží z EU — A.2 kontrolního hlášení, ř. 3/4 DAP DPH
('03-04',  'Pořízení zboží z EU § 16 (ř. 3/4)',                 'Intra-EU acquisition of goods § 16 (row 3/4)',      'purchases',  3, 130),
-- Přijetí služby ze zahraničí — A.2 kontrolního hlášení, ř. 5/6 DAP DPH
('05-06',  'Přijetí služby ze zahraničí § 24, 25 (ř. 5/6)',    'Receipt of service from abroad § 24, 25 (row 5/6)', 'purchases',  5, 140),
-- Třístranný obchod příjemce — A.2 kontrolního hlášení, ř. 9 DAP DPH
('09',     'Pořízení zboží, třístranný obchod (ř. 9)',           'Triangular trade acquisition (row 9)',               'purchases',  9, 150),
-- Přenesená daňová povinnost — příjemce, B.1 kontrolního hlášení, ř. 10/11 DAP DPH
('10-11',  'PDP příjemce § 92a (ř. 10/11)',                     'Reverse charge recipient § 92a (row 10/11)',        'purchases', 10, 160),
-- Ostatní plnění s povinností přiznat daň — A.2 kontrolního hlášení, ř. 12/13 DAP DPH
('12-13',  'Ostatní plnění s povin. přiznat daň § 108 (ř. 12/13)', 'Other supplies with tax liability § 108 (row 12/13)', 'purchases', 12, 170),
-- Odpočet daně tuzemsko — B.2/B.3 kontrolního hlášení, ř. 40/41 DAP DPH
('40-41',  'Odpočet daně tuzemsko § 72 (ř. 40/41)',             'Input VAT domestic § 72 (row 40/41)',               'purchases', 40, 180),
('40-41k', 'Odpočet daně tuzemsko – krácený § 76 (ř. 40/41)',   'Input VAT domestic – partial deduction (row 40/41)', 'purchases', 40, 190),
('40-41m', 'Odpočet daně tuzemsko – majetek (ř. 40/41)',         'Input VAT domestic – capital assets (row 40/41)',   'purchases', 40, 200),
('40-41mk','Odpočet daně tuzemsko – majetek krácený (ř. 40/41)', 'Input VAT domestic – assets partial (row 40/41)',  'purchases', 40, 210),
-- Odpočet daně, dovoz zboží — ř. 42 DAP DPH
('42',     'Odpočet daně, dovoz zboží § 23 odst. 3 (ř. 42)',   'Input VAT, import of goods § 23 (row 42)',          'purchases', 42, 220),
('42m',    'Odpočet daně, dovoz zboží – majetek (ř. 42)',        'Input VAT, import – capital assets (row 42)',        'purchases', 42, 230),
-- Odpočet daně, ostatní — ř. 43 DAP DPH
('43',     'Odpočet daně, ostatní (ř. 43)',                      'Input VAT, other (row 43)',                          'purchases', 43, 240)

ON DUPLICATE KEY UPDATE
    label_cs = VALUES(label_cs),
    label_en = VALUES(label_en),
    dap_row  = VALUES(dap_row),
    display_order = VALUES(display_order);

-- ==========================================================================
-- 2. Přidat vat_classification do invoice_items (vydané faktury)
-- ==========================================================================
ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS vat_classification VARCHAR(10) NULL
        COMMENT 'Členění DPH (kód z vat_classifications)'
        AFTER vat_rate_snapshot;

-- ==========================================================================
-- 3. Přidat vat_classification do purchase_invoice_items (přijaté faktury)
-- ==========================================================================
ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS vat_classification VARCHAR(10) NULL
        COMMENT 'Členění DPH (kód z vat_classifications)'
        AFTER vat_rate_snapshot;
