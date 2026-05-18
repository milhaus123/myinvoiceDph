<?php

declare(strict_types=1);

namespace MyInvoice\Service;

/**
 * @deprecated Tento soubor byl vytvořen automaticky (Copilot) a obsahuje chyby:
 *   - nesprávné názvy sloupců: `invoice_number` (má být `varsymbol`),
 *     `taxing_date` (má být `tax_date`), `description`/`variable_symbol`/
 *     `constant_symbol` (sloupce neexistují v tabulce invoices)
 *   - re-introdukoval deprecated `curl_close()` volání
 *   - třída nebyla nikde použita (IdokladImportAction ji neimportuje)
 *
 * Import logika žije ve dvou správných místech:
 *   - dry_run=true  → IdokladImportAction::__invoke() (inline)
 *   - dry_run=false → api/bin/idoklad-import-worker.php (background job)
 *
 * TODO: Smazat: git rm api/src/Service/IdokladImportService.php
 */
