-- iDoklad API credentials per supplier
-- Umožňuje import z iDokladu přímo z UI (Admin → Nastavení → iDoklad).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS idoklad_client_id     VARCHAR(100) NULL  AFTER pohoda_contract_code,
  ADD COLUMN IF NOT EXISTS idoklad_client_secret  VARCHAR(100) NULL  AFTER idoklad_client_id;
