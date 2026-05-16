# MyInvoice — Feature Documentation

**Czech/English** — dokumentace pokrývá všechny implementované funkce.

---

## Obsah / Table of Contents

1. [Purchase Invoices (Nákupní faktury)](#1-purchase-invoices)
2. [DPH Reports (Daňová přiznání)](#2-dph-reports)
3. [Bank Transactions (Bankovní transakce)](#3-bank-transactions)
4. [Recurring Purchase Invoices (Opakované nákupní faktury)](#4-recurring-purchase-invoices)
5. [Installation / Setup](#5-installation--setup)

---

## 1. Purchase Invoices / Nákupní faktury

### What it does

Sleduje příchozí faktury od dodavatelů. Umožňuje evidenci, DPH výkaznictví a automatické párování s bankovními transakcemi.

### Database Tables

| Table | Popis |
|---|---|
| `purchase_invoices` | Hlavní záznam nákupní faktury |
| `purchase_invoice_items` | Položky řádků faktury |
| `purchase_invoice_counters` | Čítač pro varsymbol (PF-YYYYMM-NNNN) |

**`purchase_invoices` — key columns:**
- `id`, `varsymbol` (format: PF-YYYYMM-NNNN)
- `supplier_id` → `clients(id)` (dodavatel)
- `invoice_number` — číslo faktury od dodavatele
- `issue_date`, `tax_date` (DUZP), `due_date`, `received_at`
- `reverse_charge` — vlastní přenesení DPH
- `total_without_vat`, `total_vat`, `total_with_vat`
- `amount_to_pay` — computed: total_with_vat − advance_paid_amount
- `status` — `draft` → `received` → `booked` → `paid` / `cancelled`
- `recurring_template_id` — FK na šablonu opakované faktury

**`purchase_invoice_items` — key columns:**
- `purchase_invoice_id`, `description`, `quantity`, `unit`
- `unit_price_without_vat`, `vat_rate_id`, `vat_rate_snapshot`
- `total_without_vat`, `total_vat`, `total_with_vat`

### API Endpoints

| Method | Endpoint | Popis |
|---|---|---|
| `GET` | `/api/purchase-invoices` | Seznam s filtry |
| `POST` | `/api/purchase-invoices` | Vytvořit |
| `GET` | `/api/purchase-invoices/{id}` | Detail |
| `PUT` | `/api/purchase-invoices/{id}` | Upravit |
| `DELETE` | `/api/purchase-invoices/{id}` | Smazat |
| `PATCH` | `/api/purchase-invoices/{id}/status` | Změna stavu |
| `PATCH` | `/api/purchase-invoices/{id}/items` | Nastavit položky |
| `PATCH` | `/api/purchase-invoices/{id}/exchange-rate` | Směnný kurz |

### Web UI Pages

| Page | Soubor |
|---|---|
| InvoiceList | `web/src/pages/purchase-invoices/InvoiceList.vue` |
| InvoiceDetail | `web/src/pages/purchase-invoices/InvoiceDetail.vue` |
| InvoiceEditor | `web/src/pages/purchase-invoices/InvoiceEditor.vue` |

---

## 2. DPH Reports / Daňová přiznání

### What it does

Generuje souhrnné DPH sestavy a XML exporty pro EPO (Elektronické podání orgánům veřejné moci).

---

### 2.1 DphReportAction — `/api/reports/dph`

**What it does:** Kombinovaný DPH report (výstupní + vstupní DPH) agregovaný podle sazeb 21%, 15%, 10%.

**Database tables used:**
- `invoices` + `invoice_items` (výstupní DPH)
- `purchase_invoices` + `purchase_invoice_items` (vstupní DPH)

**API Endpoint:**
```
GET /api/reports/dph?year=2026&month=5
GET /api/reports/dph?year=2026               # celý rok
GET /api/reports/dph?date_from=2026-01-01&date_to=2026-06-30
GET /api/reports/dph?format=csv             # CSV export
```

**Response:**
```json
{
  "period": { "date_from": "2026-05-01", "date_to": "2026-05-31" },
  "issued": {
    "label": "Výstupní DPH (vydané faktury)",
    "by_rate": [
      { "rate": 21.0, "zaklad": 100000.00, "dph": 21000.00 },
      { "rate": 15.0, "zaklad": 5000.00, "dph": 750.00 },
      { "rate": 10.0, "zaklad": 2000.00, "dph": 200.00 }
    ]
  },
  "received": {
    "label": "Vstupní DPH (přijaté faktury)",
    "by_rate": [...]
  },
  "totals": {
    "output_vat": 21950.00,
    "input_vat": 15000.00,
    "delta": 6950.00
  }
}
```

**Vue UI:** `web/src/pages/reports/DphReport.vue` → volá `reportsApi.dphReport()`

---

### 2.2 KontrolniHlaseniAction — `/api/reports/kontrolni-hlaseni`

**What it does:** DPHKH1 v3 XML export — Kontrolní hlášení DPH, sekce B.1 (přijaté faktury).

**Database tables used:**
- `purchase_invoices` + `purchase_invoice_items`
- `clients` (dodavatelé — DIC, IC, adresa)
- `supplier` (naše firemní údaje pro hlavičku)

**API Endpoint:**
```
GET /api/reports/kontrolni-hlaseni?year=2026&month=5
GET /api/reports/kontrolni-hlaseni?year=2026           # celý rok
GET /api/reports/kontrolni-hlaseni?format=json        # pro debug
```

**XML formát:** DPHKH1 v3 pro EPO (služba MFČR)

**Vue UI:** `web/src/pages/reports/KontrolniHlaseni.vue` → volá `reportsApi.kontrolniHlaseni()`

---

### 2.3 DphPriznaniAction — `/api/reports/dphdp3`

**What it does:** DPHDP3 XML export — měsíční přiznání k DPH (sekce I: výstupní, sekce II: vstupní).

**Database tables used:**
- `invoices` + `invoice_items` (výstupní)
- `purchase_invoices` + `purchase_invoice_items` (vstupní)
- `supplier` (firemní údaje)

**API Endpoint:**
```
GET /api/reports/dphdp3?year=2026&month=5
GET /api/reports/dphdp3?year=2026&month=5&form_type=DPHDP3
GET /api/reports/dphdp3?year=2026&month=5&form_type=DPHDP4  # čtvrtletní
GET /api/reports/dphdp3?year=2026&month=5&form_type=DPHDP5  # pololetní
GET /api/reports/dphdp3?year=2026&month=5&form_type=DPHDP6  # roční
GET /api/reports/dphdp3?format=json
```

**Vue UI:** `web/src/pages/reports/DphPriznani.vue` → volá `reportsApi.dphPriznani()`

---

### 2.4 IncomeTaxReturnAction — `/api/reports/priznani-dani`

**What it does:** Roční přiznání k dani z příjmů — XML exporty pro EPO.

**Database tables used:**
- `invoices` + `invoice_items` (výnosy)
- `purchase_invoices` + `purchase_invoice_items` (náklady)
- `supplier` (firemní údaje)

**API Endpoints:**
```
GET /api/reports/priznani-dani?year=2026                        # fyzické osoby (DPFDP5)
GET /api/reports/priznani-dani?year=2026&type=DPFDP5         # FO
GET /api/reports/priznani-dani?year=2026&type=DPPDP9         # PO
GET /api/reports/priznani-dani?format=json
```

**Vue UI:** `web/src/pages/reports/IncomeTaxReturn.vue` → volá `reportsApi.incomeTaxReturn()`

---

## 3. Bank Transactions / Bankovní transakce

### What it does

Import bankovních výpisů (GPC/CSV), automatické párování plateb s fakturami (vydanými i nákupními), auditní tabulka.

### Database Tables

| Table | Popis |
|---|---|
| `bank_transactions` | Jednotlivé transakce |
| `bank_transaction_matches` | Auditní tabulka párování |
| `bank_statements` | Bankovní výpisy |

**`bank_transactions` — key columns:**
- `id`, `statement_id`, `posted_at`
- `amount`, `currency`
- `variable_symbol`, `constant_symbol`, `specific_symbol`
- `counterparty_account`, `counterparty_bank`, `counterparty_name`
- `matched_invoice_id`, `matched_purchase_invoice_id`
- `match_status` — `unmatched`, `auto_exact`, `auto_partial`, `manual`, `ignored`
- `matched_at`, `matched_by`

**`bank_transaction_matches` — audit table:**
- `bank_transaction_id`, `invoice_id` / `purchase_invoice_id`
- `match_type` — `auto_exact`, `auto_partial`, `manual`, `unmatched`, `ignored`
- `match_amount`, `expected_amount`, `amount_diff`
- `is_active` — 1 = aktuální, 0 = historický
- `matched_by`, `created_at`

### API Endpoints

| Method | Endpoint | Popis |
|---|---|---|
| `POST` | `/api/bank-transactions/import` | Import GPC/CSV souboru |
| `GET` | `/api/bank-transactions` | Seznam s filtry |
| `GET` | `/api/bank-transactions/unmatched` | Nepárované transakce |
| `POST` | `/api/bank-transactions/pair/{id}` | Ruční párování |
| `POST` | `/api/bank-transactions/auto-match` | Automatické párování |

### How it works — Matching Strategy

**Příchozí platby (amount > 0):**
1. Najde `purchase_invoice` se stejným VS, supplier scope, status `received`/`booked`
2. Pak hledá `invoice` se stejným VS, supplier scope, status `issued`/`sent`/`reminded`

**Automatické párování:**
- Exact match (rozdíl < 0.01 Kč): označí jako `paid`
- Partial match (rozdíl ≤ 1 Kč): flag `auto_partial`, ruční rozhodnutí

**`StatementMatcher` service:** `api/src/Service/Bank/StatementMatcher.php`
- `match(int $transactionId)` — hlavní matchovací logika
- `recordMatchAudit()` — zápis do `bank_transaction_matches`

**`BankTransactionAction` service:** `api/src/Action/Bank/BankTransactionAction.php`
- `import()` — import GPC/CSV
- `list()` — seznam s JOINy na matched faktury
- `unmatched()` — seznam nepárovaných s kandidáty
- `pair()` — ruční párování
- `autoMatch()` — dávkové automatické párování

### Web UI Pages

| Page | Soubor |
|---|---|
| StatementList | `web/src/pages/bank/StatementList.vue` |
| StatementDetail | `web/src/pages/bank/StatementDetail.vue` |

**Web API:** `web/src/api/bank.ts` → `bankApi`

### Known Issues

1. **OpenAPI mismatch:** `/bank-transactions/{id}/match` v `openapi.yaml` má pouze `invoice_id`, ale implementace (`BankTransactionAction::pair()`) podporuje i `purchase_invoice_id`. OpenAPI spec je třeba aktualizovat.
2. **Web UI `matchManual`:** `web/src/api/bank.ts` matchManual() neposílá `purchase_invoice_id` — frontendová podpora není implementována.

---

## 4. Recurring Purchase Invoices / Opakované nákupní faktury

### What it does

Automatické generování nákupních faktur podle šablony (měsíčně, čtvrtletně, pololetně, ročně). Např. pravidelné platby za nájem, energie, předplatné.

### Database Tables

| Table | Popis |
|---|---|
| `recurring_purchase_invoice_templates` | Šablony opakovaných faktur |
| `recurring_purchase_invoice_template_items` | Položky šablony |

**`recurring_purchase_invoice_templates` — key columns:**
- `id`, `supplier_id` → `clients(id)`
- `name` — display name (např. "Nájem Kancelář")
- `frequency` — `monthly`, `quarterly`, `semi_annually`, `annually`
- `day_of_month` — 1–28 (nebo NULL pro poslední den)
- `end_of_month` — 1 = poslední den měsíce
- `anchor_date` — datum zahájení
- `end_date` — datum ukončení (NULL = neomezeně)
- `next_run_date`, `last_run_date`
- `auto_issue` — 1 = rovnou vystavit, 0 = nechat draft
- `status` — `active`, `paused`, `expired`
- `recurring_template_id` na `purchase_invoices` — vazba z vygenerované faktury zpět na šablonu

### API Endpoints

| Method | Endpoint | Popis |
|---|---|---|
| `GET` | `/api/recurring-purchase-invoices` | Seznam šablon |
| `POST` | `/api/recurring-purchase-invoices` | Vytvořit šablonu |
| `GET` | `/api/recurring-purchase-invoices/{id}` | Detail šablony |
| `PUT` | `/api/recurring-purchase-invoices/{id}` | Upravit šablonu |
| `DELETE` | `/api/recurring-purchase-invoices/{id}` | Smazat šablonu |
| `POST` | `/api/recurring-purchase-invoices/{id}/pause` | Pozastavit |
| `POST` | `/api/recurring-purchase-invoices/{id}/resume` | Znovu aktivovat |
| `POST` | `/api/recurring-purchase-invoices/generate` | Generovat všechny splněné |
| `POST` | `/api/recurring-purchase-invoices/{id}/run-now` | Spustit nyní |
| `GET` | `/api/recurring-purchase-invoices/next-runs` | Nadcházející termíny |
| `GET` | `/api/recurring-purchase-invoices/{id}/invoices` | Vygenerované faktury |

### Core Services

**`RecurringPurchaseInvoiceGenerator`:** `api/src/Service/Invoice/RecurringPurchaseInvoiceGenerator.php`
- `generate(int $templateId, ?string $forcedIssueDate, int $userId)` — generuje fakturu ze šablony
- Po vygenerování aktualizuje `next_run_date` a `last_run_date`

**`PeriodicityCalculator`:** `api/src/Service/Invoice/PeriodicityCalculator.php`
- `upcomingRuns()` — vypočítá nadcházející termíny generování

### Web UI Pages

⚠️ **Vue UI pages for recurring purchase invoices DO NOT exist yet** (as of this writing).
- `web/src/pages/purchase-invoices/` contains only: `InvoiceList.vue`, `InvoiceDetail.vue`, `InvoiceEditor.vue`
- No `RecurringPurchaseInvoiceList.vue` or similar

### Known Issues

1. **No Vue UI:** Chybí frontendové stránky pro správu opakovaných faktur. Nutné vytvořit.
2. **OpenAPI not updated:** `api/openapi.yaml` neobsahuje endpoints pro recurring-purchase-invoices.
3. **Web API not updated:** `web/src/api/` nemá soubor pro recurring purchase invoice API.

---

## 5. Installation / Setup

### Lokální spuštění

```bash
# 1. Klonovat / stáhnout projekt
cd /tmp/myinvoice-dev

# 2. Zkopírovat konfiguraci
cp cfg.sample.php cfg.php
# Upravit cfg.php — databáze, SMTP, klíče

# 3. Docker (doporučeno pro lokální vývoj)
docker-compose up -d
# API běží na http://localhost:8080

# 4. Nebo bez Dockeru — PHP built-in server
cd api/public
php -S localhost:8080 router.php

# 5. Web frontend (Vue)
cd web
pnpm install
pnpm dev        # vývojový server
pnpm build      # produkční build
```

### Database Migrations

```bash
# Spustit migrace (pokud není použit Docker entrypoint)
mysql -u root -p myinvoice < db/migrations/0020_purchase_invoices.sql
mysql -u root -p myinvoice < db/migrations/0021_bank_transaction_matches.sql
mysql -u root -p myinvoice < db/migrations/0022_recurring_purchase_invoices.sql
```

### Důležité konstanty

| Parametr | Hodnota |
|---|---|
| API base URL | `http://localhost:8080/api/v1/` |
| Web URL | `http://localhost:5173/` (dev) |
| Database charset | `utf8mb4` |
| Invoice varsymbol | `PF-YYYYMM-NNNN` (nákupní) |
| OpenAPI spec | `api/openapi.yaml` |

### Development Notes

- **PHP 8.2+** required
- **MariaDB/MySQL 8+** s `utf8mb4_unicode_ci`
- **Composer 2** for PHP dependencies
- **pnpm** for web frontend
- **Node 20+** for web build

---

## Changelog / Issue References

| Issue | Feature | Migration |
|---|---|---|
| #1 | Purchase invoices foundation | `0020_purchase_invoices.sql` |
| #9 | Bank transaction import + payment matching | `0021_bank_transaction_matches.sql` |
| #10 | Recurring purchase invoices | `0022_recurring_purchase_invoices.sql` |

---

*Generated: 2026-05-16*
