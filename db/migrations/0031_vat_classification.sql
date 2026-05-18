-- MyInvoice — Členění DPH (VAT Classification)
-- Potřebné pro DPH přiznání a kontrolní hlášení (§ 101a ZDPH).
--
-- Přidává:
--   1. vat_classifications — číselník řádků DPH přiznání
--   2. invoice_items.vat_classification — FK/kód na číselník
--   3. purchase_invoice_items.vat_classification — totéž pro přijaté faktury

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
-- 1. Číselník členění DPH
-- ==========================================================================
CREATE TABLE IF NOT EXISTS vat_classifications (
    code            VARCHAR(10) NOT NULL PRIMARY KEY,
    label_cs        VARCHAR(120) NOT NULL,
    label_en        VARCHAR(120) NOT NULL DEFAULT '',
    applies_to      ENUM('sales','purchases','both') NOT NULL DEFAULT 'both',
    -- Číslo řádku v DAP DPH (formulář 25_5405)
    dap_row         SMALLINT UNSIGNED NOT NULL COMMENT 'Řádek v DAP DPH',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    display_order   INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vat_classifications (code, label_cs, label_en, applies_to, dap_row, display_order) VALUES
-- Zdanitelná plnění — výstupy (vydané faktury)
('r1',  'Zdanitelná plnění 21 % (tuzemsko)',              'Taxable supplies 21% (domestic)',         'sales',     1,  10),
('r2',  'Zdanitelná plnění 12 % (tuzemsko)',              'Taxable supplies 12% (domestic)',         'sales',     2,  20),
('r0s', 'Osvobozená plnění s nárokem na odpočet',         'Exempt supplies with right to deduct',    'sales',    20,  30),
('r0n', 'Osvobozená plnění bez nároku na odpočet',        'Exempt supplies without deduction',       'sales',    50,  40),
-- Přenesená daňová povinnost (reverse charge) — výstupy
('r25a','Přenesená daň. povin. — dodavatel 21 %',         'Reverse charge supplier 21%',             'sales',    25,  50),
('r25b','Přenesená daň. povin. — dodavatel 12 %',         'Reverse charge supplier 12%',             'sales',    26,  60),
-- Přijetí ze zahraničí
('r3',  'Pořízení zboží z EU — 21 %',                    'Intra-community acquisition 21%',         'purchases',  3,  70),
('r4',  'Pořízení zboží z EU — 12 %',                    'Intra-community acquisition 12%',         'purchases',  4,  80),
('r5',  'Přijetí služby z EU — 21 %',                    'Intra-community services 21%',            'purchases',  5,  90),
('r6',  'Přijetí služby z EU — 12 %',                    'Intra-community services 12%',            'purchases',  6, 100),
-- Přenesená daňová povinnost — příjemce
('r10', 'Přenesená daň. povin. — příjemce 21 %',         'Reverse charge recipient 21%',            'purchases', 10, 110),
('r11', 'Přenesená daň. povin. — příjemce 12 %',         'Reverse charge recipient 12%',            'purchases', 11, 120),
-- Odpočet daně — vstupy (přijaté faktury)
('r40', 'Odpočet daně — tuzemsko 21 %',                  'Input VAT domestic 21%',                  'purchases', 40, 130),
('r41', 'Odpočet daně — tuzemsko 12 %',                  'Input VAT domestic 12%',                  'purchases', 41, 140),
('r43', 'Plnění bez DPH / osvobozeno',                   'Exempt / zero VAT input',                 'purchases', 43, 150)
ON DUPLICATE KEY UPDATE label_cs = VALUES(label_cs), label_en = VALUES(label_en), display_order = VALUES(display_order);

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
