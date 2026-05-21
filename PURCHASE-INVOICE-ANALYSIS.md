# Purchase Invoice Analysis — MyInvoice DPH Fork

> **Date:** 2026-05-16
> **Goal:** Add purchase/received invoice support (přijaté faktury) to MyInvoice for DPH reporting and control statement (kontrolní hlášení)

---

## 1. Current Invoice Data Model

The system currently only supports **issued invoices** (faktury vydané) — documents the company sends to clients.

### Existing `invoices` table (simplified)

```sql
invoices
├── id, varsymbol, invoice_type ENUM('invoice','proforma','credit_note','cancellation')
├── client_id → (the customer receiving the invoice)
├── project_id → (optional, links to a project)
├── issue_date, tax_date (DUZP), due_date
├── currency, reverse_charge
├── client_snapshot JSON, supplier_snapshot JSON, bank_snapshot JSON
├── total_without_vat, total_vat, total_with_vat, rounding (denormalized)
├── status ENUM('draft','issued','sent','reminded','paid','cancelled')
├── created_by → users(id)
```

### `invoice_items` table

```sql
invoice_items
├── invoice_id → invoices(id)
├── description, quantity, unit, unit_price_without_vat
├── vat_rate_id → vat_rates(id), vat_rate_snapshot
├── total_without_vat, total_vat, total_with_vat (denormalized)
```

### Key characteristics of current model

- **Issued invoice:** `client` = the customer (B2C/B2B buyer), `supplier` = the company running MyInvoice
- `invoice_type`: `invoice` (standard), `proforma` (advance), `credit_note` (reversal), `cancellation` (void)
- All amounts are **positive** (what the client pays the company)
- VAT is what the company **collects** from clients → output VAT
- Snapshots ensure invoice PDF remains accurate even if client/supplier data changes

---

## 2. What Are Purchase Invoices?

**Purchase invoices (přijaté faktury)** are documents the company **receives from suppliers** — money flowing **out** of the company.

| | Issued Invoice (vydaná) | Purchase Invoice (přijatá) |
|---|---|---|
| Direction | Company → Client (money in) | Supplier → Company (money out) |
| Counterparty | `clients` table | A **new or existing supplier** (could be in `clients` or new table) |
| Amount sign | Positive (income) | Positive (expense) |
| DPH role | Company **collects** VAT (output VAT) | Company **deducts** VAT (input VAT) |
| VAT reporting | Správné přiznání DPH — plnění | Správné přiznání DPH — nárok |
| Control statement | Yes — reported in řádek A.5 | Yes — reported in řádek B.1 |

For Czech DPH, **both issued and received invoices** must be reported in:
1. **Daňové přiznání k DPH** (VAT return) — lines 1–47
2. **Kontrolní hlášení** (Control statement) — rows A.5 (issued) and B.1 (received)

---

## 3. Database Schema Changes Needed

### Option A: Extend `invoices` with `invoice_direction` flag

```sql
ALTER TABLE invoices ADD COLUMN invoice_direction ENUM('issued','received') NOT NULL DEFAULT 'issued';
```

**Pros:** Single table, simpler queries for combined VAT reporting
**Cons:** Mixes two fundamentally different business concepts; `client_id` becomes ambiguous (for received: who? the supplier? a new `supplier_id` field needed?)

### Option B: New `purchase_invoices` table (parallel to `invoices`)

**Recommended.** Mirrors `invoices` structure but for received invoices.

```sql
CREATE TABLE purchase_invoices (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  varsymbol           VARCHAR(20) NULL,           -- company's internal ref (optional)
  supplier_id         BIGINT UNSIGNED NOT NULL,   -- NEW: the supplier we received from
  -- mirror of invoice fields:
  issue_date          DATE NOT NULL,              -- date we received the invoice
  tax_date            DATE NULL,                  -- DUZP
  due_date            DATE NOT NULL,
  currency            CHAR(3) NOT NULL DEFAULT 'CZK',
  reverse_charge      TINYINT(1) NOT NULL DEFAULT 0,
  language            ENUM('cs','en') NOT NULL DEFAULT 'cs',
  note_above_items    TEXT NULL,
  note_below_items    TEXT NULL,
  -- snapshots (supplier here = the supplier sending us the invoice)
  supplier_snapshot   JSON NOT NULL,              -- supplier's company data at receipt
  own_snapshot        JSON NOT NULL,              -- our company data (for our records)
  -- totals (denormalized)
  total_without_vat   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_with_vat      DECIMAL(12,2) NOT NULL DEFAULT 0,
  rounding            DECIMAL(6,2) NOT NULL DEFAULT 0,
  -- status (simplified — received invoices don't need sent/approval flow)
  status              ENUM('draft','received','booked','paid','cancelled') NOT NULL DEFAULT 'draft',
  received_at         DATE NOT NULL,              -- when we got the invoice
  booked_at           TIMESTAMP NULL,             -- when it was booked into accounting
  paid_at             DATE NULL,
  -- attachment
  pdf_path            VARCHAR(255) NULL,
  -- metadata
  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pi_supplier (supplier_id, issue_date DESC),
  KEY idx_pi_status   (status, due_date),
  CONSTRAINT fk_pi_supplier FOREIGN KEY (supplier_id) REFERENCES clients(id)  -- or new suppliers table
) ENGINE=InnoDB;
```

```sql
CREATE TABLE purchase_invoice_items (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_invoice_id      BIGINT UNSIGNED NOT NULL,
  description              TEXT NOT NULL,
  quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit                     VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat  DECIMAL(12,2) NOT NULL,
  vat_rate_id              INT UNSIGNED NOT NULL,
  vat_rate_snapshot        DECIMAL(5,2) NOT NULL,
  total_without_vat        DECIMAL(12,2) NOT NULL,
  total_vat                DECIMAL(12,2) NOT NULL,
  total_with_vat           DECIMAL(12,2) NOT NULL,
  order_index              INT NOT NULL DEFAULT 0,
  KEY idx_pii_invoice (purchase_invoice_id, order_index),
  CONSTRAINT fk_pii_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_pii_vat FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB;
```

```sql
-- Counter for received invoices (separate from issued invoice counters)
CREATE TABLE purchase_invoice_counters (
  year_month   CHAR(6) NOT NULL,           -- "202605"
  last_number  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (year_month)
) ENGINE=InnoDB;
```

### Key design decisions

1. **`supplier_id` vs `client_id`:** Since suppliers could overlap with existing clients (if a supplier is also a customer), consider a **new `suppliers` table** separate from `clients`, or reuse `clients` with a type flag. For simplicity, reusing `clients` with a flag is acceptable — the existing client lookup (ARES/VIES) already handles supplier identification.

2. **No proforma/credit_note types:** Purchase invoices don't need proforma (advance) or cancellation (that's a payment reversal). Just `draft` → `received` → `booked` → `paid` / `cancelled`.

3. **Separate counters:** Purchase invoice numbers are separate from issued invoice numbers (could use format `PF/YYYYMM/NNNN` for "Přijatá faktura").

4. **Tax date (DUZP):** Critical for DPH reporting — must be captured accurately.

---

## 4. API Endpoints Needed

### New Endpoints (RESTful, parallel to invoice endpoints)

```
# Purchase Invoices
GET    /api/purchase-invoices                      List (with filters: year, month, supplier_id, status, unpaid_only)
POST   /api/purchase-invoices                      Create draft
GET    /api/purchase-invoices/{id}                Detail (with items, totals, vat_breakdown)
PUT    /api/purchase-invoices/{id}                Update draft
DELETE /api/purchase-invoices/{id}                Delete draft (only draft)
POST   /api/purchase-invoices/{id}/mark-received  Mark as received (status: draft → received)
POST   /api/purchase-invoices/{id}/book            Mark as booked (received → booked)
POST   /api/purchase-invoices/{id}/mark-paid       Mark as paid (booked → paid)
POST   /api/purchase-invoices/{id}/cancel          Cancel

GET    /api/purchase-invoices/{id}/pdf             Download PDF
POST   /api/purchase-invoices/{id}/attachments      Upload attachment

# DPH Reporting
GET    /api/reports/dph                           DPH report (issued + received, by period)
GET    /api/reports/dph/export                    Export DPH report (XML/CSV for Flora/ABCD)
GET    /api/reports/control-statement             Control statement data (B.1 rows)
GET    /api/reports/control-statement/export      Control statement XML

# Suppliers (if separate from clients)
GET    /api/suppliers                             List suppliers
POST   /api/suppliers                             Create supplier
GET    /api/suppliers/{id}                        Supplier detail
PUT    /api/suppliers/{id}                        Update supplier
DELETE /api/suppliers/{id}                        Delete supplier
```

### Request/Response Shapes

**POST /api/purchase-invoices**
```json
{
  "supplier_id": 42,
  "invoice_number": "FA-2026-001",        // supplier's invoice number
  "issue_date": "2026-05-10",             // date on supplier's invoice
  "tax_date": "2026-05-10",               // DUZP
  "due_date": "2026-05-24",
  "currency": "CZK",
  "reverse_charge": false,
  "language": "cs",
  "items": [
    {
      "description": "Konzultační služby",
      "quantity": 10,
      "unit": "h",
      "unit_price_without_vat": 1500,
      "vat_rate_id": 1
    }
  ]
}
```

**GET /api/purchase-invoices/{id}**
```json
{
  "id": 1,
  "varsymbol": "PF-2026050001",
  "supplier": { "id": 42, "company_name": "ACME s.r.o.", ... },
  "invoice_number": "FA-2026-001",
  "issue_date": "2026-05-10",
  "tax_date": "2026-05-10",
  "due_date": "2026-05-24",
  "currency": "CZK",
  "status": "booked",
  "items": [...],
  "totals": { "without_vat": 15000, "vat": 3150, "with_vat": 18150 },
  "vat_breakdown": [ { "rate": 21.00, "base": 15000, "vat": 3150 } ],
  "snapshots": { "supplier_snapshot": {...}, "own_snapshot": {...} },
  "received_at": "2026-05-12",
  "booked_at": "2026-05-13",
  "paid_at": null
}
```

---

## 5. UI Pages to Create/Modify

### New Pages (Vue 3, TypeScript)

| Route | Component | Description |
|---|---|---|
| `/purchase-invoices` | `PurchaseInvoiceList.vue` | List received invoices, filters, bulk actions |
| `/purchase-invoices/new` | `PurchaseInvoiceEditor.vue` | Create new received invoice |
| `/purchase-invoices/:id` | `PurchaseInvoiceDetail.vue` | View/edit received invoice |
| `/reports/dph` | `DphReport.vue` | DPH VAT return report |
| `/reports/control-statement` | `ControlStatement.vue` | Control statement (kontrolní hlášení) data |

### Modifications to Existing

| File | Change |
|---|---|
| `router/index.ts` | Add routes for purchase invoices + reports |
| `api/invoices.ts` | (no change needed — keep issued invoices separate) |
| `Dashboard.vue` | Could show purchase invoice summary (unpaid received) |
| Sidebar/navigation | Add "Přijaté faktury" nav item |

### Key UI Features Needed

- **Purchase Invoice Editor:** Similar to existing `InvoiceEditor.vue` but with:
  - Supplier selector (with ARES lookup to pre-fill supplier data)
  - Invoice number from supplier (not internal varsymbol)
  - `tax_date` (DUZP) prominently shown — critical for DPH
  - VAT breakdown by rate (CZ-21, CZ-12, CZ-0, reverse charge)
  - Ability to attach scanned/email PDF of the received invoice

- **DPH Report:** Period selector (month/year), shows:
  - Table of issued invoices (output VAT, řádky A.1–A.5)
  - Table of received invoices (input VAT, řádky B.1–B.2)
  - Calculated DPH payable = output VAT - input VAT
  - Export button (XML for accounting systems)

- **Control Statement:** Monthly/quarterly, rows B.1 (received invoices above 10,000 CZK with VAT)

---

## 6. DPH (VAT) Implications

### Czech DPH Basics

| Concept | Description |
|---|---|
| **Výstupní DPH** | DPH collected on issued invoices — company owes to tax authority |
| **Vstupní DPH** | DPH paid on received invoices — company can deduct |
| **DPH k platbě** | DPH payable = output VAT - input VAT |
| **Kontrolní hlášení** | Monthly/quarterly report listing all invoices > 10,000 CZK with VAT |

### How MyInvoice Currently Handles DPH

- `vat_rates` table has CZ rates (21%, 12%, 0%, reverse charge)
- Invoice items store `vat_rate_snapshot` (immutable after issue)
- API returns `vat_breakdown` grouped by rate for easy reporting
- Existing `reports` don't yet exist — this fork adds them

### What Needs to Change for DPH Support

1. **DPH Return Report (`/api/reports/dph`):**
   - For a given period (month): sum all issued invoices' `total_vat` grouped by rate
   - Sum all received invoices' `total_vat` grouped by rate
   - Calculate `DPH k platbě = sum(output_vat) - sum(input_vat)`
   - Show in Czech tax form structure (řádek 1–47 mapping)

2. **Control Statement (`/api/reports/control-statement`):**
   - **Row A.5:** All issued invoices (in the period) where total_with_vat > 10,000 CZK
   - **Row B.1:** All received invoices (in the period) where total_with_vat > 10,000 CKV
   - Each row needs: supplier/customer ID (IČO/DIC), invoice number, date, total with VAT, VAT amount
   - Export format: XML (schema from GFŘ)

3. **Reverse Charge** (for B2B with foreign suppliers):
   - When `reverse_charge = true`: output VAT = 0, input VAT = 0 (the recipient self-assesses)
   - Display clearly in both issued and received invoice views

### Key DPH Implementation Notes

- Period for DPH reporting = **calendar month** (měsíc) or **quarter** (čtvrtletí) depending on company size
- Tax date (`tax_date` = DUZP) determines which period an invoice belongs to
- `reverse_charge` flag is critical for B2B foreign transactions
- The control statement threshold is **10,000 CZK including VAT** per invoice

---

## 7. Implementation Priority

### Phase 1: Data Model (this fork)
1. Create `purchase_invoices` + `purchase_invoice_items` tables
2. Create `purchase_invoice_counters` table
3. Create `PurchaseInvoiceRepository` (parallel to `InvoiceRepository`)
4. Create migration script

### Phase 2: API (CRUD)
1. `POST /api/purchase-invoices` — create draft
2. `GET /api/purchase-invoices` — list with filters
3. `GET /api/purchase-invoices/{id}` — detail
4. `PUT /api/purchase-invoices/{id}` — update draft
5. `DELETE /api/purchase-invoices/{id}` — delete draft
6. Status transitions: `mark-received`, `book`, `mark-paid`, `cancel`

### Phase 3: UI
1. `PurchaseInvoiceList.vue` — list page with filters
2. `PurchaseInvoiceEditor.vue` — create/edit form
3. `PurchaseInvoiceDetail.vue` — view page
4. Navigation — add sidebar link
5. Supplier ARES lookup for purchase invoices

### Phase 4: DPH Reporting
1. `GET /api/reports/dph` — DPH return data for a period
2. `DphReport.vue` — DPH report UI with export
3. `GET /api/reports/control-statement` — control statement data
4. `ControlStatement.vue` — control statement UI with XML export

---

## 8. Key Files to Copy/Adapt as Templates

| Existing File | New File | Notes |
|---|---|---|
| `api/src/Action/Invoice/ListInvoicesAction.php` | `ListPurchaseInvoicesAction.php` | Adapt for purchase_invoices |
| `api/src/Action/Invoice/CreateInvoiceAction.php` | `CreatePurchaseInvoiceAction.php` | Adapt for purchase_invoices |
| `api/src/Repository/InvoiceRepository.php` | `PurchaseInvoiceRepository.php` | Parallel data access |
| `api/src/Service/Invoice/InvoiceCalculator.php` | `PurchaseInvoiceCalculator.php` | Same calculation logic |
| `web/src/pages/invoices/InvoiceList.vue` | `PurchaseInvoiceList.vue` | Copy, adapt for purchase invoices |
| `web/src/pages/invoices/InvoiceEditor.vue` | `PurchaseInvoiceEditor.vue` | Copy, adapt |
| `web/src/api/invoices.ts` | `api/purchaseInvoices.ts` | New API client |
| `web/src/router/index.ts` | — | Add new routes |

---

## 9. Open Questions / Future Considerations

1. **Supplier = Client?** Should suppliers be stored in `clients` table (with type distinction) or a new `suppliers` table? Current thinking: reuse `clients` with `is_supplier=True` flag since ARES/VIES lookup already works for them.

2. **Bank statement matching:** When importing bank statements, can received invoices be auto-matched like issued invoices? Yes — `bank_transactions.matched_invoice_id` could get a `matched_purchase_invoice_id` too, or we extend the match to cover both.

3. **PDF generation:** Do we need a PDF template for received invoices? Probably — for printing/archiving the company's received invoice record. Could mirror the issued invoice PDF template.

4. **Credit notes for purchase invoices:** If a supplier sends a credit note (dobropis), how should it be modeled? Likely as a `credit_note` type within `purchase_invoices` (with negative amounts), linked to the original received invoice.

5. **Recurring purchase invoices:** Not needed for initial implementation — treat each received invoice as one-time.
