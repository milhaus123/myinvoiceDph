-- Fakturoid API credentials per supplier
-- Umožňuje import z Fakturoid přímo z UI (Admin → Nastavení → Fakturoid).
-- API v3 používá OAuth2 Client Credentials + account slug v URL.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS fakturoid_client_id     VARCHAR(200) NULL  AFTER idoklad_client_secret,
  ADD COLUMN IF NOT EXISTS fakturoid_client_secret  VARCHAR(200) NULL  AFTER fakturoid_client_id,
  ADD COLUMN IF NOT EXISTS fakturoid_slug           VARCHAR(100) NULL  AFTER fakturoid_client_secret;
