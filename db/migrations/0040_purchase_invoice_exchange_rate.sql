-- MyInvoice — kurz pro přijaté faktury v cizí měně
--
-- Kód (PurchaseInvoiceRepository::find/setExchangeRate, editor přijaté faktury)
-- s těmito sloupci už počítal, ale migrace chyběla. Bez kurzu se cizoměnové
-- přijaté faktury sčítaly do DPH přiznání / kontrolního hlášení jako CZK.
--
-- exchange_rate      = CZK za 1 jednotku cizí měny (kurz ČNB ke dni DUZP/DPPD,
--                      nebo kurz uvedený na daňovém dokladu)
-- exchange_rate_date = datum kurzu

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS exchange_rate      DECIMAL(14,6) NULL AFTER currency_id,
  ADD COLUMN IF NOT EXISTS exchange_rate_date DATE          NULL AFTER exchange_rate;
