#!/usr/bin/env php
<?php

/**
 * iDoklad Import Worker
 *
 * Spouštěn na pozadí z IdokladImportAction pro dry_run=false importy.
 * Zpracuje job z tabulky idoklad_import_jobs a aktualizuje jeho stav.
 *
 * Použití: php idoklad-import-worker.php --job-id=N
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Tenhle skript lze spustit pouze z příkazové řádky.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

// ── Parsuj argumenty ──────────────────────────────────────────────────────────
$jobId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--job-id=(\d+)$/', $arg, $m)) {
        $jobId = (int) $m[1];
    }
}
if (!$jobId) {
    fwrite(STDERR, "Použití: php idoklad-import-worker.php --job-id=N\n");
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

// ── Načti job ─────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM idoklad_import_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    fwrite(STDERR, "Job $jobId nebyl nalezen.\n");
    exit(1);
}
if ($job['status'] !== 'queued') {
    fwrite(STDERR, "Job $jobId má status '{$job['status']}', přeskakuji.\n");
    exit(0);
}

// ── Status → running ──────────────────────────────────────────────────────────
$pdo->prepare("UPDATE idoklad_import_jobs SET status='running', updated_at=NOW() WHERE id=?")
    ->execute([$jobId]);

// ── Parametry jobu ────────────────────────────────────────────────────────────
$params       = json_decode((string) $job['params'], true);
$supplierId   = (int) $job['supplier_id'];
$adminId      = (int) $job['admin_id'];
$clientId     = (string) ($params['client_id']     ?? '');
$clientSecret = (string) ($params['client_secret'] ?? '');
$years        = array_map('intval', (array) ($params['years']    ?? []));
$sections     = (array) ($params['sections'] ?? []);

if (empty($years)) {
    $y     = (int) date('Y');
    $years = [$y - 1, $y, $y + 1];
}

$runAll         = empty($sections);
$runContacts    = $runAll || in_array('contacts',     $sections, true);
$runInvoices    = $runAll || in_array('invoices',     $sections, true);
$runCreditNotes = $runAll || in_array('credit-notes', $sections, true);
$runPurchases   = $runAll || in_array('purchases',    $sections, true);

// ── Spusť import ──────────────────────────────────────────────────────────────
try {
    // Číselníky
    $vatByCode = [];
    foreach ($pdo->query("SELECT id, code, rate_percent FROM vat_rates")->fetchAll(PDO::FETCH_ASSOC) as $vr) {
        $vatByCode[$vr['code']] = ['id' => (int) $vr['id'], 'rate' => (float) $vr['rate_percent']];
    }

    $vatRateToCode = static function (float $rate): string {
        $r = (int) round($rate);
        if ($r >= 20) return 'CZ-21';
        if ($r >= 9)  return 'CZ-12';
        return 'CZ-0';
    };

    $currencyId = (int) $pdo->query(
        "SELECT id FROM currencies WHERE code='CZK' AND supplier_id={$supplierId} LIMIT 1"
    )->fetchColumn();
    if (!$currencyId) {
        $currencyId = (int) $pdo->query(
            "SELECT id FROM currencies WHERE supplier_id={$supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn();
    }
    $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
    if (!$countryId) {
        $countryId = (int) $pdo->query("SELECT id FROM countries ORDER BY id LIMIT 1")->fetchColumn();
    }

    // iDoklad API token
    $token = workerGetToken($clientId, $clientSecret);

    $log   = [];
    $stats = [
        'contacts_new'     => 0, 'contacts_exist'    => 0,
        'clients_new'      => 0, 'clients_exist'     => 0,
        'invoices_new'     => 0, 'invoices_skip'     => 0,
        'credit_notes_new' => 0, 'credit_notes_skip' => 0,
        'purchases_new'    => 0, 'purchases_skip'    => 0,
    ];
    $clientCache = [];

    // Server-side date filter — stahujeme jen vybrané roky
    // iDoklad API doesn't support complex date filters with AND, so we fetch all
    // and filter locally using workerFilterYears()
    $dateFilter = null;

    // Stáhni data z iDokladu (bez date filteru, filtrujeme lokálně)
    $allContacts    = workerFetchAll('Contacts',           $token, 'Id');  // Use Id instead of CompanyName (API rejected CompanyName:asc)
    $allIssued     = $runInvoices    ? workerFilterYears(workerFetchAll('IssuedInvoices',    $token, 'DocumentNumber', null), $years) : [];
    $allPurchases  = $runPurchases   ? workerFilterYears(workerFetchAll('ReceivedInvoices',  $token, 'DocumentNumber', null), $years) : [];

    // Rozděl IssuedInvoices na faktury a dobropisy podle InvoiceType
    // InvoiceType: 0 = Invoice, 1 = RegularInvoice, 2 = Proforma, 3 = CreditNote
    $allInvoices    = [];
    $allCreditNotes = [];
    foreach ($allIssued as $item) {
        $invoiceType = (int) ($item['InvoiceType'] ?? 0);
        if ($invoiceType === 3) {
            $allCreditNotes[] = $item;
        } else {
            $allInvoices[] = $item;
        }
    }

    $log[] = 'Kontaktů staženo: ' . count($allContacts);
    $log[] = 'Vydaných faktur: '  . count($allInvoices);
    $log[] = 'Dobropisů: '        . count($allCreditNotes);
    $log[] = 'Přijatých faktur: ' . count($allPurchases);

    $pdo->beginTransaction();

    // ── 1. Kontakty ───────────────────────────────────────────────────────────
    if ($runContacts) {
        foreach ($allContacts as $contact) {
            $idId = (int) ($contact['Id'] ?? 0);
            $cn   = trim($contact['CompanyName'] ?? trim(($contact['FirstName'] ?? '') . ' ' . ($contact['Surname'] ?? '')));
            if ($cn === '') continue;
            $ic  = trim($contact['IdentificationNumber']    ?? '');
            $dic = trim($contact['VatIdentificationNumber'] ?? '');
            $addr = [];
            foreach ($contact['Addresses'] ?? [] as $a) {
                if (empty($addr)) $addr = $a;
                if ((int) ($a['AddressType'] ?? -1) === 1) { $addr = $a; break; }
            }
            $street = trim($addr['Street']     ?? '');
            $city   = trim($addr['City']       ?? '');
            $zip    = trim($addr['PostalCode']  ?? '');
            $email  = trim($contact['Email']   ?? '');
            $phone  = trim($contact['MobilePhone'] ?? ($contact['Phone'] ?? ''));

            // Dedup
            $found = false;
            if ($idId > 0) {
                $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                $st->execute([$supplierId, $idId]);
                $found = $st->fetchColumn();
            }
            if (!$found) {
                if ($ic !== '') {
                    $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1");
                    $st->execute([$supplierId, $ic]);
                } else {
                    $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1");
                    $st->execute([$supplierId, $cn]);
                }
                $found = $st->fetchColumn();
                if ($found && $idId > 0) {
                    $pdo->prepare("UPDATE clients SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$idId, $found]);
                }
            }

            if ($found) {
                $stats['contacts_exist']++;
                if ($idId > 0) $clientCache[$idId] = (int) $found;
                continue;
            }

            $stats['contacts_new']++;
            $log[] = "[KONTAKT+] $cn (IČ=$ic)";
            $st = $pdo->prepare("INSERT INTO clients (supplier_id,idoklad_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
            $st->execute([$supplierId, $idId ?: null, $cn, $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
            if ($idId > 0) $clientCache[$idId] = (int) $pdo->lastInsertId();
        }
    }

    // ── 2. Vydané faktury ─────────────────────────────────────────────────────
    if ($runInvoices) {
        foreach ($allInvoices as $inv) {
            $varsymbol    = trim((string) ($inv['VariableSymbol'] ?? $inv['DocumentNumber']));
            $invIdokladId = (int) ($inv['Id'] ?? 0);

            $existId = false;
            if ($invIdokladId > 0) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                $st->execute([$supplierId, $invIdokladId]);
                $existId = $st->fetchColumn();
            }
            if (!$existId) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND varsymbol=? LIMIT 1");
                $st->execute([$supplierId, $varsymbol]);
                $existId = $st->fetchColumn();
                if ($existId && $invIdokladId > 0) {
                    $pdo->prepare("UPDATE invoices SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$invIdokladId, $existId]);
                }
            }
            if ($existId) { $stats['invoices_skip']++; $log[] = "[SKIP faktura] $varsymbol (#$existId)"; continue; }

            $cliId    = workerUpsertClient($pdo, $supplierId, $countryId, $currencyId, $inv['PartnerAddress'] ?? [], (int) ($inv['PartnerId'] ?? 0), $clientCache, $stats);
            [$iDate, $tDate, $dDate, $paidAt, $status] = workerParseDates($inv);
            $prices   = $inv['Prices'] ?? [];
            $vatItems = workerParseVatItems($prices, $vatRateToCode);
            $desc     = workerItemDesc($inv, 'Faktura');
            $stats['invoices_new']++;
            $log[] = "[FAKTURA] $varsymbol $iDate " . number_format((float) ($prices['TotalWithVat'] ?? 0), 2) . " Kč  $status";

            $st = $pdo->prepare("INSERT INTO invoices (supplier_id,idoklad_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$supplierId, $invIdokladId ?: null, $varsymbol, 'invoice', $cliId, $iDate, $tDate, $dDate, (float) ($prices['TotalWithoutVat'] ?? 0), (float) ($prices['TotalVat'] ?? 0), (float) ($prices['TotalWithVat'] ?? 0), $status, $paidAt, $currencyId, $adminId]);
            workerInsertInvoiceItems($pdo, (int) $pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
        }
    }

    // ── 3. Dobropisy ──────────────────────────────────────────────────────────
    if ($runCreditNotes) {
        foreach ($allCreditNotes as $cn) {
            $varsymbol   = trim((string) ($cn['VariableSymbol'] ?? $cn['DocumentNumber']));
            $cnIdokladId = (int) ($cn['Id'] ?? 0);

            $existId = false;
            if ($cnIdokladId > 0) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND idoklad_id=? AND invoice_type='credit_note' LIMIT 1");
                $st->execute([$supplierId, $cnIdokladId]);
                $existId = $st->fetchColumn();
            }
            if (!$existId) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND varsymbol=? AND invoice_type='credit_note' LIMIT 1");
                $st->execute([$supplierId, $varsymbol]);
                $existId = $st->fetchColumn();
                if ($existId && $cnIdokladId > 0) {
                    $pdo->prepare("UPDATE invoices SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$cnIdokladId, $existId]);
                }
            }
            if ($existId) { $stats['credit_notes_skip']++; $log[] = "[SKIP dobropis] $varsymbol (#$existId)"; continue; }

            $cliId    = workerUpsertClient($pdo, $supplierId, $countryId, $currencyId, $cn['PartnerAddress'] ?? [], (int) ($cn['PartnerId'] ?? 0), $clientCache, $stats);
            [$iDate, $tDate, $dDate, $paidAt, $status] = workerParseDates($cn);
            $prices   = $cn['Prices'] ?? [];
            $vatItems = workerParseVatItems($prices, $vatRateToCode);
            foreach ($vatItems as &$vi) { $vi['base'] = -abs($vi['base']); $vi['vat'] = -abs($vi['vat']); $vi['tot'] = -abs($vi['tot']); }
            unset($vi);
            $desc = workerItemDesc($cn, 'Dobropis');
            $twv  = -abs((float) ($prices['TotalWithVat'] ?? 0));
            $stats['credit_notes_new']++;
            $log[] = "[DOBROPIS] $varsymbol $iDate " . number_format($twv, 2) . " Kč";

            $corrVs   = trim((string) ($cn['CorrectedDocumentVariableSymbol'] ?? $cn['InvoiceVariableSymbol'] ?? ''));
            $parentId = null;
            if ($corrVs !== '') {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND varsymbol=? AND invoice_type='invoice' LIMIT 1");
                $st->execute([$supplierId, $corrVs]);
                $parentId = $st->fetchColumn() ?: null;
            }
            $st = $pdo->prepare("INSERT INTO invoices (supplier_id,idoklad_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$supplierId, $cnIdokladId ?: null, $varsymbol, 'credit_note', $parentId, $cliId, $iDate, $tDate, $dDate, -abs((float) ($prices['TotalWithoutVat'] ?? 0)), -abs((float) ($prices['TotalVat'] ?? 0)), $twv, $status, $paidAt, $currencyId, $adminId]);
            workerInsertInvoiceItems($pdo, (int) $pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
        }
    }

    // ── 4. Přijaté faktury ────────────────────────────────────────────────────
    if ($runPurchases) {
        foreach ($allPurchases as $pi) {
            $invNum      = trim((string) ($pi['DocumentNumber'] ?? ($pi['VariableSymbol'] ?? '')));
            $piIdokladId = (int) ($pi['Id'] ?? 0);
            [$iDateCheck] = workerParseDates($pi);

            $existId = false;
            if ($piIdokladId > 0) {
                $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                $st->execute([$supplierId, $piIdokladId]);
                $existId = $st->fetchColumn();
            }
            if (!$existId) {
                $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND invoice_number=? AND DATE_FORMAT(issue_date,'%Y-%m')=DATE_FORMAT(?,'%Y-%m') LIMIT 1");
                $st->execute([$supplierId, $invNum, $iDateCheck]);
                $existId = $st->fetchColumn();
                if ($existId && $piIdokladId > 0) {
                    $pdo->prepare("UPDATE purchase_invoices SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$piIdokladId, $existId]);
                }
            }
            if ($existId) { $stats['purchases_skip']++; $log[] = "[SKIP nákup] $invNum (#$existId)"; continue; }

            $vendorId = workerUpsertClient($pdo, $supplierId, $countryId, $currencyId, $pi['PartnerAddress'] ?? [], (int) ($pi['PartnerId'] ?? 0), $clientCache, $stats);
            [$iDate, $tDate, $dDate, $paidAt, $status] = workerParseDates($pi);
            $prices   = $pi['Prices'] ?? [];
            $vatItems = workerParseVatItems($prices, $vatRateToCode);
            $desc     = workerItemDesc($pi, 'Přijatá faktura');
            $stats['purchases_new']++;
            $log[] = "[NÁKUP] $invNum $iDate " . number_format((float) ($prices['TotalWithVat'] ?? 0), 2) . " Kč  $status";

            $pAddr = $pi['PartnerAddress'] ?? [];
            $snap  = json_encode([
                'company_name' => trim($pAddr['CompanyName']         ?? ''),
                'ic'           => trim($pAddr['IdentificationNumber'] ?? ''),
                'dic'          => trim($pAddr['VatIdentificationNumber'] ?? ''),
                'street'       => trim($pAddr['Street']    ?? ''),
                'city'         => trim($pAddr['City']      ?? ''),
                'zip'          => trim($pAddr['PostalCode'] ?? ''),
                'country'      => 'CZ',
            ], JSON_UNESCAPED_UNICODE);

            $piStatus = $status === 'issued' ? 'received' : $status;
            $st = $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,idoklad_id,invoice_number,issue_date,tax_date,due_date,received_at,currency_id,document_kind,total_without_vat,total_vat,total_with_vat,status,paid_at,supplier_snapshot,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$vendorId, $piIdokladId ?: null, $invNum, $iDate, $tDate, $dDate, $iDate, $currencyId, 'invoice', (float) ($prices['TotalWithoutVat'] ?? 0), (float) ($prices['TotalVat'] ?? 0), (float) ($prices['TotalWithVat'] ?? 0), $piStatus, $paidAt, $snap, $adminId]);
            $piId   = (int) $pdo->lastInsertId();
            $reverseCharge = !empty($pi['IsReverseCharge']) || !empty($pi['ReverseCharge']);
            $stItem = $pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,vat_classification,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?,?)");
            foreach ($vatItems as $idx => $s) {
                $classification = workerVatClassificationPurchases($s['rate'], $reverseCharge);
                $stItem->execute([$piId, $desc, $s['base'], $vatByCode[$s['code']]['id'], $s['rate'], $classification, $s['base'], $s['vat'], $s['tot'], $idx]);
            }
        }
    }

    $pdo->commit();

    // ── Job dokončen ──────────────────────────────────────────────────────────
    $pdo->prepare("UPDATE idoklad_import_jobs SET status='done', result=?, log=?, updated_at=NOW() WHERE id=?")
        ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), json_encode($log, JSON_UNESCAPED_UNICODE), $jobId]);

    echo "Job $jobId dokončen. Nových faktur: {$stats['invoices_new']}, nákupů: {$stats['purchases_new']}\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable) {}
    }
    $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    $pdo->prepare("UPDATE idoklad_import_jobs SET status='failed', error=?, updated_at=NOW() WHERE id=?")
        ->execute([$msg, $jobId]);
    fwrite(STDERR, "Job $jobId selhal: $msg\n");
    exit(1);
}

// ══ Helper funkce (kopie z IdokladImportAction – standalone pro CLI) ══════════

function workerGetToken(string $clientId, string $secret): string
{
    $ch = curl_init('https://app.idoklad.cz/identity/server/connect/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'scope'         => 'idoklad_api',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) throw new \RuntimeException("cURL token chyba: $err");
    $data = json_decode((string) $body, true);
    if (empty($data['access_token'])) throw new \RuntimeException('Token chybí v odpovědi: ' . substr((string) $body, 0, 200));
    return (string) $data['access_token'];
}

function workerFetchAll(string $endpoint, string $token, string $sortBy = 'Id', ?string $filter = null): array
{
    $page    = 1;
    $results = [];
    do {
        $qs = http_build_query(array_filter([
            'Page'     => $page,
            'PageSize' => 300,
            // Note: iDoklad API v3 doesn't support Sort via URL params
            'Filter'   => $filter,
        ], static fn ($v) => $v !== null && $v !== ''));
        $url = 'https://api.idoklad.cz/v3/' . $endpoint . '?' . $qs;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) throw new \RuntimeException("cURL $endpoint chyba: $err");
        if ($code >= 400) throw new \RuntimeException("API $endpoint vrátilo HTTP $code: " . substr((string) $body, 0, 300));
        $data = json_decode((string) $body, true);
        $items = $data['Data']['Items'] ?? $data['Items'] ?? [];
        foreach ($items as $item) $results[] = $item;
        $totalPages = (int) ($data['Data']['TotalPages'] ?? $data['TotalPages'] ?? 1);
        $page++;
    } while ($page <= $totalPages);
    return $results;
}

function workerFilterYears(array $items, array $years): array
{
    if (empty($years)) return $items;
    return array_filter($items, static function (array $item) use ($years): bool {
        $dateStr = $item['DateOfIssue'] ?? $item['DocumentDate'] ?? '';
        if (!$dateStr) return true;
        $year = (int) substr((string) $dateStr, 0, 4);
        return in_array($year, $years, true);
    });
}

function workerParseDates(array $doc): array
{
    $parseDate = static function (?string $s): ?string {
        if (!$s) return null;
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
        return null;
    };
    // DateOfIssue is often null for ReceivedInvoices - use DateOfAccountingEvent as fallback
    $iDate  = $parseDate($doc['DateOfIssue'] ?? $doc['DocumentDate'] ?? $doc['DateOfAccountingEvent'] ?? null);
    // If still null, try to extract year from DocumentNumber (e.g. "DF20170002" → 2017-01-01)
    if (!$iDate && !empty($doc['DocumentNumber']) && preg_match('/(20\d{2})/', (string) $doc['DocumentNumber'], $ym)) {
        $iDate = $ym[1] . '-01-01';
    }
    $iDate = $iDate ?: date('Y-m-d');
    $tDate  = $parseDate($doc['DateOfTaxing'] ?? null) ?? $iDate;
    $dDate  = $parseDate($doc['DateOfPayment'] ?? $doc['DueDate']      ?? null) ?? $iDate;
    $paidAt = null;
    $status = 'issued';
    if (!empty($doc['Payments'])) {
        foreach ($doc['Payments'] as $pay) {
            if (!empty($pay['DateOfPayment'])) {
                $paidAt = $parseDate($pay['DateOfPayment']);
                $status = 'paid';
                break;
            }
        }
    }
    // iDoklad API v3: PaymentStatus je int: 0=Unpaid, 1=Overpaid, 2=Paid, 3=Cancelled, 4=Underpaid
    if ($status !== 'paid' && isset($doc['PaymentStatus'])) {
        $ps = (int) $doc['PaymentStatus'];
        if ($ps === 1 || $ps === 2) { // Overpaid nebo Paid
            $status = 'paid';
            // Pokud máme DateOfLastPayment, použijeme ho jako paid_at
            if (empty($paidAt) && !empty($doc['DateOfLastPayment'])) {
                $paidAt = $parseDate((string) $doc['DateOfLastPayment']);
            }
        }
    }
    return [$iDate, $tDate, $dDate, $paidAt, $status];
}

/**
 * Mapování sazby DPH na kód členění DPH pro vydané faktury (výstupy).
 * Kódy odpovídají číselníku MFin ČR — stejné jako iDoklad, Pohoda, Flexibee.
 *
 * @param float $rate          Sazba DPH v procentech (0, 12, 21)
 * @param bool  $reverseCharge Přenesená daňová povinnost dodavatele (§ 92a)
 * @param bool  $euPartner     Partner z jiného státu EU
 * @param bool  $nonEuPartner  Partner mimo EU (třetí země)
 */
function workerVatClassificationSales(
    float $rate,
    bool $reverseCharge = false,
    bool $euPartner = false,
    bool $nonEuPartner = false,
): string {
    if ($reverseCharge) return '25';    // A.1 KH, ř. 25 DAP DPH — PDP dodavatel § 92a
    if ($nonEuPartner)  return '22';    // ř. 22 DAP DPH — vývoz zboží § 66
    if ($euPartner)     return '20';    // souhrnné hlášení, ř. 20 — dodání zboží do EU § 64
    $r = (int) round($rate);
    if ($r > 0) return '01-02';        // A.4/A.5 KH, ř. 1/2 DAP — tuzemské zdanitelné plnění
    return '50';                        // ř. 50 DAP DPH — osvobozeno bez nároku na odpočet
}

/**
 * Mapování sazby DPH na kód členění DPH pro přijaté faktury (vstupy).
 * Kódy odpovídají číselníku MFin ČR — stejné jako iDoklad, Pohoda, Flexibee.
 *
 * @param float $rate          Sazba DPH v procentech (0, 12, 21)
 * @param bool  $reverseCharge Přenesená daňová povinnost příjemce (§ 92a)
 * @param bool  $euPartner     Partner z jiného státu EU
 * @param bool  $nonEuPartner  Partner mimo EU (třetí země)
 */
function workerVatClassificationPurchases(
    float $rate,
    bool $reverseCharge = false,
    bool $euPartner = false,
    bool $nonEuPartner = false,
): string {
    if ($reverseCharge) return '10-11'; // B.1 KH, ř. 10/11 DAP — PDP příjemce § 92a
    if ($euPartner)     return '03-04'; // A.2 KH, ř. 3/4 DAP — pořízení zboží z EU § 16
    if ($nonEuPartner)  return '42';    // ř. 42 DAP DPH — odpočet, dovoz zboží
    $r = (int) round($rate);
    if ($r > 0) return '40-41';        // B.2/B.3 KH, ř. 40/41 DAP — tuzemský odpočet daně
    return '0P';                        // Bez vlivu na DPH (osvobozeno, neplátce, ...)
}

function workerParseVatItems(array $prices, callable $vatRateToCode): array
{
    $vatItems = [];
    foreach ($prices['VatRateSummary'] ?? [] as $vs) {
        $rate = (float) ($vs['VatRate'] ?? 0);
        $code = $vatRateToCode($rate);
        $base = (float) ($vs['TotalWithoutVat'] ?? 0);
        $vat  = (float) ($vs['TotalVat']        ?? 0);
        $tot  = (float) ($vs['TotalWithVat']    ?? 0);
        if (!isset($vatItems[$code])) $vatItems[$code] = ['code' => $code, 'rate' => $rate, 'base' => 0.0, 'vat' => 0.0, 'tot' => 0.0];
        $vatItems[$code]['base'] += $base;
        $vatItems[$code]['vat']  += $vat;
        $vatItems[$code]['tot']  += $tot;
    }
    if (empty($vatItems)) {
        $base = (float) ($prices['TotalWithoutVat'] ?? 0);
        $vat  = (float) ($prices['TotalVat']        ?? 0);
        $tot  = (float) ($prices['TotalWithVat']    ?? 0);
        $vatItems['CZ-0'] = ['code' => 'CZ-0', 'rate' => 0.0, 'base' => $base, 'vat' => $vat, 'tot' => $tot];
    }
    return array_values($vatItems);
}

function workerItemDesc(array $doc, string $fallback): string
{
    foreach ($doc['Items'] ?? [] as $item) {
        $n = trim($item['Name'] ?? '');
        if ($n !== '') return $n;
    }
    return trim($doc['Description'] ?? $doc['Note'] ?? $fallback);
}

function workerInsertInvoiceItems(\PDO $pdo, int $invoiceId, array $vatItems, array $vatByCode, string $desc, bool $reverseCharge = false): void
{
    $st = $pdo->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,vat_classification,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?,?)");
    foreach ($vatItems as $idx => $s) {
        $classification = workerVatClassificationSales($s['rate'], $reverseCharge);
        $st->execute([$invoiceId, $desc, $s['base'], $vatByCode[$s['code']]['id'], $s['rate'], $classification, $s['base'], $s['vat'], $s['tot'], $idx]);
    }
}

/**
 * @param array<int,int> $clientCache
 * @param array<string,int> $stats
 */
function workerUpsertClient(\PDO $pdo, int $supplierId, int $countryId, int $currencyId, array $addr, int $partnerId, array &$clientCache, array &$stats): ?int
{
    if ($partnerId > 0 && isset($clientCache[$partnerId])) {
        return $clientCache[$partnerId] ?: null;
    }

    $cn  = trim($addr['CompanyName']         ?? '');
    $ic  = trim($addr['IdentificationNumber'] ?? '');
    $dic = trim($addr['VatIdentificationNumber'] ?? '');
    $street = trim($addr['Street']    ?? '');
    $city   = trim($addr['City']      ?? '');
    $zip    = trim($addr['PostalCode'] ?? '');
    $email  = trim($addr['Email']     ?? '');
    $phone  = trim($addr['Phone']     ?? '');

    if ($cn === '' && $ic === '') return null;

    // Dedup
    $found = false;
    if ($partnerId > 0) {
        $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
        $st->execute([$supplierId, $partnerId]);
        $found = $st->fetchColumn();
    }
    if (!$found) {
        if ($ic !== '') {
            $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1");
            $st->execute([$supplierId, $ic]);
        } else {
            $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1");
            $st->execute([$supplierId, $cn]);
        }
        $found = $st->fetchColumn();
        if ($found && $partnerId > 0) {
            $pdo->prepare("UPDATE clients SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$partnerId, $found]);
        }
    }

    if ($found) {
        $stats['clients_exist']++;
        if ($partnerId > 0) $clientCache[$partnerId] = (int) $found;
        return (int) $found;
    }

    $stats['clients_new']++;
    $st = $pdo->prepare("INSERT INTO clients (supplier_id,idoklad_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
    $st->execute([$supplierId, $partnerId ?: null, $cn ?: 'Bez názvu', $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
    $newId = (int) $pdo->lastInsertId();
    if ($partnerId > 0) $clientCache[$partnerId] = $newId;
    return $newId;
}
