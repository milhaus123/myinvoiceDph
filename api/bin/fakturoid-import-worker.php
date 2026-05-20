#!/usr/bin/env php
<?php

/**
 * Fakturoid Import Worker
 *
 * Spouštěn na pozadí z FakturoidImportAction pro dry_run=false importy.
 * Zpracuje job z tabulky fakturoid_import_jobs a aktualizuje jeho stav.
 *
 * Použití: php fakturoid-import-worker.php --job-id=N
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Tento skript lze spustit pouze z příkazové řádky.\n");
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
    fwrite(STDERR, "Použití: php fakturoid-import-worker.php --job-id=N\n");
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

// ── Načti job ─────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM fakturoid_import_jobs WHERE id = ?");
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
$pdo->prepare("UPDATE fakturoid_import_jobs SET status='running', updated_at=NOW() WHERE id=?")
    ->execute([$jobId]);

// ── Parametry jobu ────────────────────────────────────────────────────────────
$params       = json_decode((string) $job['params'], true);
$supplierId   = (int) $job['supplier_id'];
$adminId      = (int) $job['admin_id'];
$clientId     = (string) ($params['client_id']     ?? '');
$clientSecret = (string) ($params['client_secret'] ?? '');
$slug         = (string) ($params['slug']          ?? '');
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

    // OAuth2 token
    $token = fakturoidWorkerGetToken($clientId, $clientSecret);

    $log   = [];
    $stats = [
        'contacts_new'     => 0, 'contacts_exist'    => 0,
        'clients_new'      => 0, 'clients_exist'     => 0,
        'invoices_new'     => 0, 'invoices_skip'     => 0,
        'credit_notes_new' => 0, 'credit_notes_skip' => 0,
        'purchases_new'    => 0, 'purchases_skip'    => 0,
    ];
    $clientCache = []; // fakturoid subject_id → local clients.id

    // Stáhni data
    $allSubjects    = $runContacts ? fakturoidWorkerFetchAll($slug, 'subjects',  $token) : [];
    $allInvoicesRaw = ($runInvoices || $runCreditNotes)
        ? fakturoidWorkerFilterYears(fakturoidWorkerFetchAll($slug, 'invoices', $token), $years)
        : [];
    $allPurchases = $runPurchases
        ? fakturoidWorkerFilterYears(fakturoidWorkerFetchAll($slug, 'expenses', $token), $years)
        : [];

    // Rozděl faktury na standardní a dobropisy
    $allInvoices    = [];
    $allCreditNotes = [];
    foreach ($allInvoicesRaw as $item) {
        $docType = (string)($item['document_type'] ?? 'invoice');
        if ($docType === 'correction') {
            $allCreditNotes[] = $item;
        } elseif ($docType !== 'proforma') {
            $allInvoices[] = $item;
        }
    }

    $log[] = 'Kontaktů staženo: '   . count($allSubjects);
    $log[] = 'Vydaných faktur: '     . count($allInvoices);
    $log[] = 'Dobropisů: '           . count($allCreditNotes);
    $log[] = 'Přijatých faktur: '    . count($allPurchases);

    $pdo->beginTransaction();

    // ── 1. Kontakty ───────────────────────────────────────────────────────────
    if ($runContacts) {
        foreach ($allSubjects as $subject) {
            $fId    = (int)($subject['id'] ?? 0);
            $cn     = trim((string)($subject['name'] ?? ''));
            if ($cn === '') continue;
            $ic     = trim((string)($subject['registration_no'] ?? ''));
            $dic    = trim((string)($subject['vat_no']          ?? ''));
            $street = trim((string)($subject['street'] ?? ''));
            $city   = trim((string)($subject['city']   ?? ''));
            $zip    = trim((string)($subject['zip']    ?? ''));
            $email  = trim((string)($subject['email']  ?? ''));
            $phone  = trim((string)($subject['phone']  ?? ''));

            $found = false;
            if ($fId > 0) {
                $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND fakturoid_id=? LIMIT 1");
                $st->execute([$supplierId, $fId]);
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
                if ($found && $fId > 0) {
                    $pdo->prepare("UPDATE clients SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $found]);
                }
            }

            if ($found) {
                $stats['contacts_exist']++;
                if ($fId > 0) $clientCache[$fId] = (int)$found;
                continue;
            }

            $stats['contacts_new']++;
            $log[] = "[KONTAKT+] $cn (IČ=$ic)";
            $st = $pdo->prepare("INSERT INTO clients (supplier_id,fakturoid_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
            $st->execute([$supplierId, $fId ?: null, $cn, $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
            if ($fId > 0) $clientCache[$fId] = (int)$pdo->lastInsertId();
        }
    }

    // ── 2. Vydané faktury ─────────────────────────────────────────────────────
    if ($runInvoices) {
        foreach ($allInvoices as $inv) {
            $varsymbol = trim((string)($inv['variable_symbol'] ?? $inv['number'] ?? ''));
            $fId       = (int)($inv['id'] ?? 0);

            $existId = false;
            if ($fId > 0) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND fakturoid_id=? LIMIT 1");
                $st->execute([$supplierId, $fId]);
                $existId = $st->fetchColumn();
            }
            if (!$existId && $varsymbol !== '') {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND varsymbol=? AND invoice_type='invoice' LIMIT 1");
                $st->execute([$supplierId, $varsymbol]);
                $existId = $st->fetchColumn();
                if ($existId && $fId > 0) {
                    $pdo->prepare("UPDATE invoices SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $existId]);
                }
            }
            if ($existId) { $stats['invoices_skip']++; $log[] = "[SKIP faktura] $varsymbol (#$existId)"; continue; }

            $cliId    = fakturoidWorkerUpsertClientFromInvoice($pdo, $supplierId, $countryId, $currencyId, $inv, $clientCache, $stats);
            [$iDate, $tDate, $dDate, $paidAt, $status] = fakturoidWorkerParseDates($inv);
            $vatItems = fakturoidWorkerParseVatItems($inv['lines'] ?? [], $vatRateToCode);
            $desc     = fakturoidWorkerItemDesc($inv);
            [$totalBase, $totalVat, $totalWithVat] = fakturoidWorkerParseTotals($inv);
            $stats['invoices_new']++;
            $log[] = "[FAKTURA] $varsymbol $iDate " . number_format($totalWithVat, 2) . " Kč  $status";

            try {
                $st = $pdo->prepare("INSERT INTO invoices (supplier_id,fakturoid_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$supplierId, $fId ?: null, $varsymbol, 'invoice', $cliId, $iDate, $tDate, $dDate, $totalBase, $totalVat, $totalWithVat, $status, $paidAt, $currencyId, $adminId]);
                fakturoidWorkerInsertInvoiceItems($pdo, (int)$pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    $stats['invoices_skip']++; $log[] = "[SKIP faktura - duplikát] $varsymbol ($iDate)"; continue;
                }
                throw $e;
            }
        }
    }

    // ── 3. Dobropisy ──────────────────────────────────────────────────────────
    if ($runCreditNotes) {
        foreach ($allCreditNotes as $cn) {
            $varsymbol = trim((string)($cn['variable_symbol'] ?? $cn['number'] ?? ''));
            $fId       = (int)($cn['id'] ?? 0);

            $existId = false;
            if ($fId > 0) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND fakturoid_id=? AND invoice_type='credit_note' LIMIT 1");
                $st->execute([$supplierId, $fId]);
                $existId = $st->fetchColumn();
            }
            if (!$existId && $varsymbol !== '') {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND varsymbol=? AND invoice_type='credit_note' LIMIT 1");
                $st->execute([$supplierId, $varsymbol]);
                $existId = $st->fetchColumn();
                if ($existId && $fId > 0) {
                    $pdo->prepare("UPDATE invoices SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $existId]);
                }
            }
            if ($existId) { $stats['credit_notes_skip']++; $log[] = "[SKIP dobropis] $varsymbol (#$existId)"; continue; }

            $cliId    = fakturoidWorkerUpsertClientFromInvoice($pdo, $supplierId, $countryId, $currencyId, $cn, $clientCache, $stats);
            [$iDate, $tDate, $dDate, $paidAt, $status] = fakturoidWorkerParseDates($cn);
            $vatItems = fakturoidWorkerParseVatItems($cn['lines'] ?? [], $vatRateToCode);
            foreach ($vatItems as &$vi) { $vi['base'] = -abs($vi['base']); $vi['vat'] = -abs($vi['vat']); $vi['tot'] = -abs($vi['tot']); }
            unset($vi);
            $desc = fakturoidWorkerItemDesc($cn);
            [, , $rawTot] = fakturoidWorkerParseTotals($cn);
            $twv   = -abs($rawTot);
            [$rb, $rv] = [fakturoidWorkerParseTotals($cn)[0], fakturoidWorkerParseTotals($cn)[1]];
            $tbase = -abs($rb); $tvat = -abs($rv);
            $stats['credit_notes_new']++;
            $log[] = "[DOBROPIS] $varsymbol $iDate " . number_format($twv, 2) . " Kč";

            // Najdi původní fakturu
            $parentId = null;
            $relatedId = (int)($cn['related_id'] ?? 0);
            if ($relatedId > 0) {
                $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND fakturoid_id=? AND invoice_type='invoice' LIMIT 1");
                $st->execute([$supplierId, $relatedId]);
                $parentId = $st->fetchColumn() ?: null;
            }

            try {
                $st = $pdo->prepare("INSERT INTO invoices (supplier_id,fakturoid_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$supplierId, $fId ?: null, $varsymbol, 'credit_note', $parentId, $cliId, $iDate, $tDate, $dDate, $tbase, $tvat, $twv, $status, $paidAt, $currencyId, $adminId]);
                fakturoidWorkerInsertInvoiceItems($pdo, (int)$pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    $stats['credit_notes_skip']++; $log[] = "[SKIP dobropis - duplikát] $varsymbol ($iDate)"; continue;
                }
                throw $e;
            }
        }
    }

    // ── 4. Přijaté faktury ────────────────────────────────────────────────────
    if ($runPurchases) {
        foreach ($allPurchases as $exp) {
            $invNum = trim((string)($exp['original_number'] ?? $exp['number'] ?? ''));
            $fId    = (int)($exp['id'] ?? 0);
            [$iDateCheck] = fakturoidWorkerParseDates($exp);

            $existId = false;
            if ($fId > 0) {
                $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND fakturoid_id=? LIMIT 1");
                $st->execute([$supplierId, $fId]);
                $existId = $st->fetchColumn();
            }
            if (!$existId) {
                $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND invoice_number=? AND DATE_FORMAT(issue_date,'%Y-%m')=DATE_FORMAT(?,'%Y-%m') LIMIT 1");
                $st->execute([$supplierId, $invNum, $iDateCheck]);
                $existId = $st->fetchColumn();
                if ($existId && $fId > 0) {
                    $pdo->prepare("UPDATE purchase_invoices SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $existId]);
                }
            }
            if ($existId) { $stats['purchases_skip']++; $log[] = "[SKIP nákup] $invNum (#$existId)"; continue; }

            $vendorId = fakturoidWorkerUpsertVendor($pdo, $supplierId, $countryId, $currencyId, $exp, $clientCache, $stats);
            [$iDate, $tDate, $dDate, $paidAt, $status] = fakturoidWorkerParseDates($exp);
            $vatItems = fakturoidWorkerParseVatItems($exp['lines'] ?? [], $vatRateToCode);
            $desc     = fakturoidWorkerItemDesc($exp);
            [$totalBase, $totalVat, $totalWithVat] = fakturoidWorkerParseTotals($exp);
            $log[] = "[NÁKUP] $invNum $iDate " . number_format($totalWithVat, 2) . " Kč  $status";

            $snap = json_encode([
                'company_name' => trim($exp['supplier_name']            ?? ''),
                'ic'           => trim($exp['supplier_registration_no'] ?? ''),
                'dic'          => trim($exp['supplier_vat_no']          ?? ''),
                'street'       => trim($exp['supplier_street'] ?? ''),
                'city'         => trim($exp['supplier_city']   ?? ''),
                'zip'          => trim($exp['supplier_zip']    ?? ''),
                'country'      => trim($exp['supplier_country'] ?? 'CZ'),
            ], JSON_UNESCAPED_UNICODE);
            $piStatus = $status === 'issued' ? 'received' : $status;

            try {
                $st = $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,fakturoid_id,invoice_number,issue_date,tax_date,due_date,received_at,currency_id,document_kind,total_without_vat,total_vat,total_with_vat,status,paid_at,supplier_snapshot,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$supplierId, $fId ?: null, $invNum, $iDate, $tDate, $dDate, $iDate, $currencyId, 'invoice', $totalBase, $totalVat, $totalWithVat, $piStatus, $paidAt, $snap, $adminId]);
                $piId = (int)$pdo->lastInsertId();
                $stats['purchases_new']++;
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    $stats['purchases_skip']++; $log[] = "[SKIP nákup - duplikát] $invNum ($iDate)"; continue;
                }
                throw $e;
            }

            $reverseCharge = !empty($exp['transferred_tax_liability']);
            $stItem = $pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,vat_classification,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?,?)");
            foreach ($vatItems as $idx => $s) {
                $classification = fakturoidWorkerVatClassificationPurchases($s['rate'], $reverseCharge);
                $code = $s['code'];
                if (!isset($vatByCode[$code])) $code = 'CZ-0';
                $stItem->execute([$piId, $desc, $s['base'], $vatByCode[$code]['id'], $s['rate'], $classification, $s['base'], $s['vat'], $s['tot'], $idx]);
            }
        }
    }

    $pdo->commit();

    // ── Job dokončen ──────────────────────────────────────────────────────────
    $pdo->prepare("UPDATE fakturoid_import_jobs SET status='done', result=?, log=?, updated_at=NOW() WHERE id=?")
        ->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), json_encode($log, JSON_UNESCAPED_UNICODE), $jobId]);

    echo "Job $jobId dokončen. Nových faktur: {$stats['invoices_new']}, nákupů: {$stats['purchases_new']}\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable) {}
    }
    $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    $pdo->prepare("UPDATE fakturoid_import_jobs SET status='failed', error=?, updated_at=NOW() WHERE id=?")
        ->execute([$msg, $jobId]);
    fwrite(STDERR, "Job $jobId selhal: $msg\n");
    exit(1);
}

// ══ Helper funkce ═════════════════════════════════════════════════════════════

const FAKTUROID_API_BASE  = 'https://app.fakturoid.cz/api/v3/accounts';
const FAKTUROID_TOKEN_URL = 'https://app.fakturoid.cz/api/v3/oauth/token';
const FAKTUROID_UA        = 'MyInvoiceDph/1.0 (support@myinvoice.cz)';
const FAKTUROID_PAGE_SIZE = 40;

function fakturoidWorkerGetToken(string $clientId, string $secret): string
{
    $ch = curl_init(FAKTUROID_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERPWD        => $clientId . ':' . $secret,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . FAKTUROID_UA,
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err)      throw new \RuntimeException("cURL token chyba: $err");
    if ($code !== 200) throw new \RuntimeException("Token HTTP $code: " . substr((string)$body, 0, 200));
    $data = json_decode((string)$body, true);
    if (empty($data['access_token'])) throw new \RuntimeException('access_token chybí v odpovědi: ' . substr((string)$body, 0, 200));
    return (string)$data['access_token'];
}

function fakturoidWorkerFetchAll(string $slug, string $endpoint, string $token): array
{
    $page    = 1;
    $results = [];
    do {
        $url = FAKTUROID_API_BASE . '/' . $slug . '/' . $endpoint . '.json?page=' . $page;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'User-Agent: ' . FAKTUROID_UA,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body  = curl_exec($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err)      throw new \RuntimeException("cURL $endpoint chyba: $err");
        if ($code >= 400) throw new \RuntimeException("API $endpoint HTTP $code: " . substr((string)$body, 0, 300));
        $items = json_decode((string)$body, true);
        if (!is_array($items) || empty($items)) break;
        foreach ($items as $item) $results[] = $item;
        $page++;
    } while (count($items) >= FAKTUROID_PAGE_SIZE);
    return $results;
}

function fakturoidWorkerFilterYears(array $items, array $years): array
{
    if (empty($years)) return $items;
    return array_values(array_filter($items, static function (array $item) use ($years): bool {
        $dateStr = $item['issued_on'] ?? $item['received_on'] ?? $item['created_at'] ?? '';
        if (!$dateStr) return false;
        return in_array((int)substr((string)$dateStr, 0, 4), $years, true);
    }));
}

function fakturoidWorkerParseDates(array $doc): array
{
    $d = static function (?string $s): ?string {
        if (!$s) return null;
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
        return null;
    };
    $iDate  = $d($doc['issued_on']   ?? $doc['received_on'] ?? null) ?? date('Y-m-d');
    $tDate  = $d($doc['taxable_fulfillment_due'] ?? null) ?? $iDate;
    $dDate  = $d($doc['due_on'] ?? null) ?? $iDate;
    $paidAt = $d($doc['paid_on'] ?? null);
    $fStatus = strtolower((string)($doc['status'] ?? 'open'));
    $isPaid     = ($fStatus === 'paid')      || $paidAt !== null;
    $isCancelled = ($fStatus === 'cancelled');
    $localStatus = $isCancelled ? 'cancelled' : ($isPaid ? 'paid' : 'issued');
    return [$iDate, $tDate, $dDate, $paidAt, $localStatus];
}

function fakturoidWorkerParseVatItems(array $lines, callable $vatRateToCode): array
{
    $grouped = [];
    foreach ($lines as $line) {
        $rate = (float)($line['vat_rate'] ?? 0);
        $code = $vatRateToCode($rate);
        $base = isset($line['amount'])     ? (float)$line['amount']     : round((float)($line['quantity'] ?? 1) * (float)($line['unit_price'] ?? 0), 4);
        $vat  = isset($line['vat_amount']) ? (float)$line['vat_amount'] : round($base * $rate / 100, 4);
        $tot  = isset($line['total'])      ? (float)$line['total']      : $base + $vat;
        if (!isset($grouped[$code])) $grouped[$code] = ['code' => $code, 'rate' => $rate, 'base' => 0.0, 'vat' => 0.0, 'tot' => 0.0];
        $grouped[$code]['base'] += $base;
        $grouped[$code]['vat']  += $vat;
        $grouped[$code]['tot']  += $tot;
    }
    if (empty($grouped)) {
        $grouped['CZ-0'] = ['code' => 'CZ-0', 'rate' => 0.0, 'base' => 0.0, 'vat' => 0.0, 'tot' => 0.0];
    }
    return array_values($grouped);
}

function fakturoidWorkerParseTotals(array $doc): array
{
    $base = (float)($doc['subtotal']  ?? $doc['total_without_vat'] ?? 0);
    $vat  = (float)($doc['total_vat'] ?? 0);
    $tot  = (float)($doc['total']     ?? $base + $vat);
    return [$base, $vat, $tot];
}

function fakturoidWorkerItemDesc(array $doc): string
{
    foreach ($doc['lines'] ?? [] as $line) {
        $n = trim($line['name'] ?? '');
        if ($n !== '') return $n;
    }
    return trim($doc['private_note'] ?? $doc['description'] ?? 'Faktura');
}

function fakturoidWorkerUpsertClientFromInvoice(\PDO $pdo, int $supplierId, int $countryId, int $currencyId, array $inv, array &$cache, array &$stats): int
{
    $subjectId = (int)($inv['subject_id'] ?? 0);
    if ($subjectId > 0 && isset($cache[$subjectId])) return $cache[$subjectId];
    $cn     = trim((string)($inv['client_name']            ?? ''));
    $ic     = trim((string)($inv['client_registration_no'] ?? ''));
    $dic    = trim((string)($inv['client_vat_no']          ?? ''));
    $street = trim((string)($inv['client_street'] ?? ''));
    $city   = trim((string)($inv['client_city']   ?? ''));
    $zip    = trim((string)($inv['client_zip']    ?? ''));
    $email  = trim((string)($inv['client_email']  ?? ''));
    $phone  = trim((string)($inv['client_phone']  ?? ''));
    if ($cn === '' && $ic === '') return 0;
    $found = false;
    if ($subjectId > 0) { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND fakturoid_id=? LIMIT 1"); $st->execute([$supplierId, $subjectId]); $found = $st->fetchColumn(); }
    if (!$found) {
        if ($ic !== '') { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1"); $st->execute([$supplierId, $ic]); }
        else            { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1"); $st->execute([$supplierId, $cn]); }
        $found = $st->fetchColumn();
        if ($found && $subjectId > 0) $pdo->prepare("UPDATE clients SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$subjectId, $found]);
    }
    if ($found) { $stats['clients_exist']++; if ($subjectId > 0) $cache[$subjectId] = (int)$found; return (int)$found; }
    $stats['clients_new']++;
    $st = $pdo->prepare("INSERT INTO clients (supplier_id,fakturoid_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
    $st->execute([$supplierId, $subjectId ?: null, $cn ?: 'Bez názvu', $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
    $newId = (int)$pdo->lastInsertId();
    if ($subjectId > 0) $cache[$subjectId] = $newId;
    return $newId;
}

function fakturoidWorkerUpsertVendor(\PDO $pdo, int $supplierId, int $countryId, int $currencyId, array $exp, array &$cache, array &$stats): int
{
    $subjectId = (int)($exp['subject_id'] ?? 0);
    if ($subjectId > 0 && isset($cache[$subjectId])) return $cache[$subjectId];
    $cn     = trim((string)($exp['supplier_name']            ?? ''));
    $ic     = trim((string)($exp['supplier_registration_no'] ?? ''));
    $dic    = trim((string)($exp['supplier_vat_no']          ?? ''));
    $street = trim((string)($exp['supplier_street'] ?? ''));
    $city   = trim((string)($exp['supplier_city']   ?? ''));
    $zip    = trim((string)($exp['supplier_zip']    ?? ''));
    if ($cn === '' && $ic === '') return 0;
    $found = false;
    if ($subjectId > 0) { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND fakturoid_id=? LIMIT 1"); $st->execute([$supplierId, $subjectId]); $found = $st->fetchColumn(); }
    if (!$found) {
        if ($ic !== '') { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1"); $st->execute([$supplierId, $ic]); }
        else            { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1"); $st->execute([$supplierId, $cn]); }
        $found = $st->fetchColumn();
        if ($found && $subjectId > 0) $pdo->prepare("UPDATE clients SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$subjectId, $found]);
    }
    if ($found) { $stats['clients_exist']++; if ($subjectId > 0) $cache[$subjectId] = (int)$found; return (int)$found; }
    $stats['clients_new']++;
    $st = $pdo->prepare("INSERT INTO clients (supplier_id,fakturoid_id,company_name,ic,dic,street,city,zip,country_id,main_email,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,'','cs',?)");
    $st->execute([$supplierId, $subjectId ?: null, $cn ?: 'Bez názvu', $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $currencyId]);
    $newId = (int)$pdo->lastInsertId();
    if ($subjectId > 0) $cache[$subjectId] = $newId;
    return $newId;
}

function fakturoidWorkerInsertInvoiceItems(\PDO $pdo, int $invoiceId, array $vatItems, array $vatByCode, string $desc): void
{
    $st = $pdo->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,vat_classification,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?,?)");
    foreach ($vatItems as $idx => $s) {
        $code = $s['code'];
        if (!isset($vatByCode[$code])) $code = 'CZ-0';
        $classification = fakturoidWorkerVatClassificationSales($s['rate']);
        $st->execute([$invoiceId, $desc, $s['base'], $vatByCode[$code]['id'], $s['rate'], $classification, $s['base'], $s['vat'], $s['tot'], $idx]);
    }
}

function fakturoidWorkerVatClassificationSales(float $rate, bool $reverseCharge = false): string
{
    if ($reverseCharge) return '25';
    $r = (int)round($rate);
    if ($r > 0) return '01-02';
    return '50';
}

function fakturoidWorkerVatClassificationPurchases(float $rate, bool $reverseCharge = false): string
{
    if ($reverseCharge) return '10-11';
    $r = (int)round($rate);
    if ($r > 0) return '40-41';
    return '0P';
}
