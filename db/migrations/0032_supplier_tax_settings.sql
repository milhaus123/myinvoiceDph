-- MyInvoice — DPH export: nastavení supplier pro DPHDP3 a DPHKH1 (EPO MF ČR)
--
-- Přidává na tabulku `supplier` pole potřebná pro vyplnění VetaD a VetaP
-- v XML exportech DAP DPH (DPHDP3) a Kontrolního hlášení (DPHKH1).
--
-- Kódy c_ufo / c_pracufo najde plátce na: https://epodatelna.mfcr.cz/
-- Kód c_okec = NACE / OKÉČ kód hlavní podnikatelské činnosti (dle registrace na FÚ).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

ALTER TABLE supplier
    -- Finanční úřad (územní pracoviště)
    ADD COLUMN IF NOT EXISTS tax_ufo        VARCHAR(10)   NULL
        COMMENT 'Kód územního finančního orgánu (c_ufo) — 3–4 číslice, např. 463'
        AFTER commercial_register,

    ADD COLUMN IF NOT EXISTS tax_pracufo    VARCHAR(10)   NULL
        COMMENT 'Kód pracovního místa UFO (c_pracufo), např. 3203'
        AFTER tax_ufo,

    -- NACE / OKÉČ hlavní činnosti (dle registrace)
    ADD COLUMN IF NOT EXISTS tax_okec       VARCHAR(10)   NULL
        COMMENT 'NACE/OKÉČ kód hlavní podnikatelské činnosti, např. 631000'
        AFTER tax_pracufo,

    -- Typ plátce: P = právnická osoba, F = fyzická osoba podnikající
    ADD COLUMN IF NOT EXISTS tax_typ_platce CHAR(1)       NULL
        COMMENT 'P = PO (s.r.o., a.s., …) / F = FO (živnostník, OSVČ)'
        AFTER tax_okec,

    -- Typ datové schránky / způsob podání
    ADD COLUMN IF NOT EXISTS tax_typ_ds     CHAR(1)       NULL
        COMMENT 'F = FO / P = PO — ovlivňuje typ_ds v VetaP'
        AFTER tax_typ_platce,

    -- Osobní údaje (pro OSVČ a FO)
    ADD COLUMN IF NOT EXISTS tax_titul      VARCHAR(30)   NULL
        COMMENT 'Titul před jménem (Bc., Ing., Dr., …)'
        AFTER tax_typ_ds,

    ADD COLUMN IF NOT EXISTS tax_jmeno      VARCHAR(60)   NULL
        COMMENT 'Jméno (křestní) — povinné pro FO'
        AFTER tax_titul,

    ADD COLUMN IF NOT EXISTS tax_prijmeni   VARCHAR(60)   NULL
        COMMENT 'Příjmení — povinné pro FO'
        AFTER tax_jmeno,

    -- Adresní pole — oddělené číslo popisné (EPO ho odděluje od ulice)
    ADD COLUMN IF NOT EXISTS tax_c_pop      VARCHAR(10)   NULL
        COMMENT 'Číslo popisné / orientační (c_pop) — odděleno od názvu ulice'
        AFTER tax_prijmeni,

    -- Kontaktní údaje pro podání
    ADD COLUMN IF NOT EXISTS tax_email      VARCHAR(100)  NULL
        COMMENT 'Email pro VetaP (pokud se liší od supplier.email)'
        AFTER tax_c_pop,

    ADD COLUMN IF NOT EXISTS tax_telef      VARCHAR(30)   NULL
        COMMENT 'Telefon pro VetaP ve formátu +420XXXXXXXXX'
        AFTER tax_email,

    -- Stát v textové formě pro VetaP (EPO chce velká písmena)
    ADD COLUMN IF NOT EXISTS tax_stat       VARCHAR(60)   NULL
        COMMENT 'Stát pro VetaP, např. ČESKÁ REPUBLIKA'
        AFTER tax_telef;
