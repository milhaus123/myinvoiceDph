# DB migrace — průvodce

Migrace jsou SQL skripty v adresáři `db/migrations/`. Každá migrace má číselný prefix
a je idempotentní — bezpečná ke spuštění opakovaně (používá `IF NOT EXISTS`, `IF EXISTS`
apod.).

---

## Jak spustit migrace

### Docker (doporučeno)

Migrace se spouštějí **automaticky při startu kontejneru** (`docker-entrypoint.sh`).
Pro ruční spuštění nebo kontrolu stavu:

```bash
# Spustit pending migrace
docker compose exec app php api/bin/migrate.php

# Zobrazit stav migrací (které proběhly, které čekají)
docker compose exec app php api/bin/migrate.php --status
```

### Nativní instalace

```bash
# Spustit pending migrace
php api/bin/migrate.php

# Stav migrací
php api/bin/migrate.php --status
```

### Prostředí pro produkci (docker-compose.production.yml)

```bash
docker compose -f docker-compose.production.yml exec app php api/bin/migrate.php
```

---

## Přehled migrací

Migrace jsou číslovány od `0001` výše. Nižší čísla pocházejí z upstream projektu
[radekhulan/myinvoice](https://github.com/radekhulan/myinvoice), vyšší čísla (od ~0020)
obsahují rozšíření specifická pro tento fork.

### Upstream migrace (0001–0019)

| Migrace | Co dělá |
|---------|---------|
| `0001_init.sql` | Počáteční schéma — všechny základní tabulky: `supplier`, `clients`, `projects`, `invoices`, `invoice_items`, `users`, `sessions`, `activity_log` aj. |
| `0002_work_report_approval.sql` | Schvalování výkazu práce zákazníkem — pole `approval_status`, `approval_token`, timestamps na `invoices` a `projects` |
| `0003_approval_token_ttl_and_reminders.sql` | TTL pro schvalovací token, cron reminder pro schvalování |
| `0004_exchange_rate.sql` | Cache denních kurzů ČNB — tabulka `exchange_rates` |
| `0005_exchange_rate_date.sql` | Přesný datum kurzu v `invoices` pro audit |
| `0006_supplier_commercial_register.sql` | Pole `commercial_register` na `supplier` — zápis v OR, zobrazuje se v PDF |
| `0007_maintenance_procedures.sql` | Stored procedures pro údržbu (`sp_recompute_client_revenue` apod.) |
| `0008_supplier_auto_send_reminders.sql` | Per-supplier přepínač automatických upomínek |
| `0009_client_auto_send_reminders.sql` | Per-klient přepínač automatických upomínek |
| `0010_client_hourly_rate.sql` | Default hodinová sazba na klientovi |
| `0011_invoice_pdf_history.sql` | Historie PDF souborů faktury — tabulka `invoice_pdf_history` |
| `0012_units_codebook.sql` | Číselník měrných jednotek — tabulka `units` |
| `0013_invoice_attachments.sql` | Přílohy k fakturám — tabulka `invoice_attachments` |
| `0014_supplier_invoice_numbering.sql` | Per-supplier konfigurace formátu čísla faktury |
| `0015_invoice_parent_cascade.sql` | ON DELETE CASCADE pro `invoices.parent_invoice_id` |
| `0016_email_branding.sql` | Per-supplier email branding (From jméno, Reply-To) |
| `0017_app_meta.sql` | Tabulka `app_meta` — key/value cache (verze, release notes) |
| `0018_work_report_project_nullable.sql` | `work_reports.project_id` nullable |
| `0019_api_tokens.sql` | Bearer (PAT) tokeny pro REST API — tabulka `api_tokens` |

### Rozšíření forknuté (0020+)

| Migrace | Co dělá |
|---------|---------|
| `0020_payment_method.sql` | Způsob úhrady na fakturách (`payment_method` pole) |
| `0020_purchase_invoices.sql` | **Přijaté faktury** — tabulky `purchase_invoices` a `purchase_invoice_items` (základ DPH evidence vstupního DPH) |
| `0021_bank_transaction_matches.sql` | Párování bankovních transakcí na přijaté faktury — pole `matched_purchase_invoice_id` |
| `0021_recurring_invoices.sql` | Pravidelné (opakované) faktury — tabulky `recurring_invoice_templates` a `recurring_invoice_items` |
| `0022_recurring_purchase_invoices.sql` | Opakované přijaté faktury — šablona pro pravidelné nákupy |
| `0022_supplier_embed_isdoc.sql` | Per-supplier přepínač vkládání ISDOC do PDF |
| `0023_revenue_vat_aware.sql` | Rozšíření revenue statistik o DPH sazby |
| `0024_purchase_invoice_document_kind.sql` | Typ dokladu na přijaté faktuře (`document_kind`) |
| `0025_cash_register_movements.sql` | Pokladní pohyby — tabulka `cash_register_movements` |
| `0025_recurring_invoices.sql` | Opravy a rozšíření opakovaných faktur |
| `0026_eet_sessions.sql` | EET sessions — tabulka `eet_sessions` |
| `0027_idoklad_credentials.sql` | **iDoklad API credentials** — sloupce `idoklad_client_id` a `idoklad_client_secret` na `supplier` |
| `0028_idoklad_ids.sql` | iDoklad ID na klientech a fakturách pro deduplikaci při importu |
| `0029_idoklad_import_jobs.sql` | Tabulka `idoklad_import_jobs` — sledování stavu background importů z iDokladu |
| `0030_idoklad_import_cancel.sql` | Sloupec `cancel_requested` — umožňuje zrušení běžícího importu |
| `0030_recurring_templates_supplier_id.sql` | `supplier_id` na šablonách opakovaných faktur |
| `0031_vat_classification.sql` | **Členění DPH** — číselník `vat_classifications` (27 kódů dle MF ČR) a sloupec `vat_classification` na `invoice_items` i `purchase_invoice_items` |
| `0032_supplier_tax_settings.sql` | **DPH/EPO nastavení dodavatele** — pole `tax_ufo`, `tax_pracufo`, `tax_okec`, `tax_typ_platce`, `tax_typ_ds`, `tax_titul`, `tax_jmeno`, `tax_prijmeni`, `tax_c_pop`, `tax_email`, `tax_telef`, `tax_stat` na `supplier` |
| `0033_fix_purchase_invoice_item_totals.sql` | Oprava výpočtu součtů na položkách přijatých faktur |
| `0034_fix_invoice_item_totals.sql` | Oprava výpočtu součtů na položkách vydaných faktur |
| `0034_migrate_legacy_vat_classification_codes.sql` | Migrace starých kódů DPH klasifikace na nový číselník |
| `0035_fakturoid_credentials.sql` | **Fakturoid API credentials** — sloupce `fakturoid_client_id`, `fakturoid_client_secret`, `fakturoid_slug` na `supplier` |
| `0036_fakturoid_ids.sql` | Fakturoid ID na klientech a fakturách pro deduplikaci |
| `0037_fakturoid_import_jobs.sql` | Tabulka `fakturoid_import_jobs` — background joby pro Fakturoid import |
| `0038_supplier_sest_fields.sql` | **Pole sestavitele přiznání** — `tax_sest_jmeno`, `tax_sest_prijmeni`, `tax_sest_telef` na `supplier` (pro DPH přiznání podávané jinou osobou) |
| `0039_supplier_c_pop.sql` | **Číslo popisné** — sloupec `c_pop` na `supplier` oddělený od názvu ulice (EPO adresní formát, plněno z ARES) |

---

## Poznámky k migraci

### Migrace 0020_purchase_invoices.sql

Vytváří základ pro evidenci přijatých faktur. Tabulka `purchase_invoices` obsahuje:
`supplier_id` (naše firma), `client_id` (dodavatel, uložen jako klient), `invoice_number`
(číslo dokladu dodavatele), `varsymbol`, `issue_date`, `tax_date`, `due_date`, `status`,
měny a součty.

### Migrace 0031_vat_classification.sql

Přidává číselník `vat_classifications` s 27 kódy členění DPH dle MF ČR. Kódy jsou
kompatibilní s iDokladem, Pohodou a Flexibee. Fallback logika: pokud položka nemá kód,
odvodí se automaticky ze sazby DPH.

### Migrace 0032_supplier_tax_settings.sql

Přidává všechna pole nutná pro generování DPH přiznání (DPHDP3) a Kontrolního hlášení
(DPHKH1) dle EPO specifikace MF ČR. Viz [docs/DPH_NASTAVENI.md](DPH_NASTAVENI.md)
pro popis jednotlivých polí.

### Migrace 0039_supplier_c_pop.sql

Odděluje číslo popisné od názvu ulice v tabulce `supplier`. AresClient.normalize()
od této migrace vrací `street` = jen název ulice a `c_pop` = číslo popisné
(případně `číslo_popisné/číslo_orientační`). Toto oddělení je nutné pro správné
vyplnění EPO adresních polí.

---

## Rollback

Migrace **nemají automatický rollback**. Pokud je potřeba vrátit změnu:

1. Identifikuj migraci podle čísla a popisu
2. Ručně spusť inverzní SQL (DROP COLUMN, DROP TABLE apod.)
3. Smaž záznam z tabulky `migrations` v databázi

```sql
-- Příklad: rollback migrace 0039
ALTER TABLE supplier DROP COLUMN IF EXISTS c_pop;
DELETE FROM migrations WHERE name = '0039_supplier_c_pop';
```

> ⚠️ Rollback může způsobit ztrátu dat. Vždy nejdřív zazálohuj databázi.
