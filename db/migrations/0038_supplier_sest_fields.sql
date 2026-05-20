-- MyInvoice — DPH export: pole pro sestavitele (sest_*) v DPHDP3 VetaP
--
-- EPO/MFČR rozlišuje v VetaP mezi plátcem (jmeno/prijmeni/titul)
-- a sestavitelem přiznání (sest_jmeno/sest_prijmeni/sest_telef).
-- Pro OSVČ, kde plátce = sestavitel, jsou hodnoty totožné.
-- Sloužící jako fallback na tax_jmeno/tax_prijmeni/tax_telef (migrace 0032).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS tax_sest_jmeno      VARCHAR(60)   NULL
        COMMENT 'Jméno sestavitele přiznání (sest_jmeno) — prázdné = fallback na tax_jmeno'
        AFTER tax_stat,

    ADD COLUMN IF NOT EXISTS tax_sest_prijmeni   VARCHAR(60)   NULL
        COMMENT 'Příjmení sestavitele přiznání (sest_prijmeni) — prázdné = fallback na tax_prijmeni'
        AFTER tax_sest_jmeno,

    ADD COLUMN IF NOT EXISTS tax_sest_telef      VARCHAR(30)   NULL
        COMMENT 'Telefon sestavitele přiznání (sest_telef) — prázdné = fallback na tax_telef'
        AFTER tax_sest_prijmeni;
