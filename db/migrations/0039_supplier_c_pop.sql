-- Migrace 0039: Číslo popisné (c_pop) jako samostatné pole v supplier
-- Oddělení názvu ulice od čísla popisného — EPO VetaP používá tax_c_pop (DPH-specifické),
-- ale pro fakturační adresu (PDF faktury, obálky) chceme c_pop v základních údajích.
-- AresClient.normalize() nově vrací street = jen nazevUlice, c_pop = cisloDomovni[/cisloOrientacni].

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS c_pop VARCHAR(10) NULL AFTER street;
