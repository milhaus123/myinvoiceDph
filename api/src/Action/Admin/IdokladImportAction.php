<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/idoklad-import
 *
 * Spustí import z iDoklad API přímo z UI.
 * Credentials jsou uloženy v supplier.idoklad_client_id / idoklad_client_secret.
 *
 * Body (JSON):
 *   years[]    int[]    Roky k importu (výchozí: aktuální rok ± 1)
 *   sections[] string[] contacts|invoices|credit-notes|purchases (výchozí: vše)
 *   dry_run    bool     Jen preview, bez zápisů do DB
 *
 * Odpověď: { stats: {...}, log: string[] }
 */
final class IdokladImportAction
{
    private const TOKEN_URL  = 'https://app.idoklad.cz/identity/server/connect/token';
    private const API_BASE   = 'https://api.idoklad.cz/v3';
    private const PAGE_SIZE  = 300;

    public function __construct(
        private readonly Connection     $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher      $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Pouze admin
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $pdo = $this->db->pdo();

        // Načti credentials z DB
        $sup = $pdo->prepare('SELECT idoklad_client_id, idoklad_client_secret FROM supplier WHERE id = ?');
        $sup->execute([$supplierId]);
        $creds = $sup->fetch(\PDO::FETCH_ASSOC);

        $clientId     = trim((string)($creds['idoklad_client_id']     ?? ''));
        $clientSecret = trim((string)($creds['idoklad_client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return Json::error($response, 'missing_credentials',
                'iDoklad credentials nejsou nastaveny. Vyplňte Client ID a Client Secret v Nastavení → iDoklad.', 400);
        }

        // Parametry importu
        $body    = (array)($request->getParsedBody() ?? []);
        $dryRun  = (bool)($body['dry_run'] ?? false);
        $years   = array_map('intval', (array)($body['years']    ?? []));
        $sections = (array)($body['sections'] ?? []);

        if (empty($years)) {
            $y = (int)date('Y');
            $years = [$y - 1, $y, $y + 1];
        }

        // Log import start
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        error_log(sprintf(
            '[IdokladImport] Started: user=%d, supplier=%d, dry_run=%s, years=%s, sections=%s, ip=%s',
            $user['id'] ?? 0,
            $supplierId,
            $dryRun ? 'true' : 'false',
            implode(',', $years),
            implode(',', $sections) ?: 'all',
            $ip
        ));

        // ── Non-dry_run: dispatch background job (avoids Cloudflare 502 timeout) ──
        if (!$dryRun) {
            // Sestavení params pro kontrolu duplicit
            $importParams = json_encode([
                'years'    => $years,
                'sections' => $sections,
            ], JSON_UNESCAPED_UNICODE);

            // Kontrola: existuje running/queued job se stejnými parametry?
            $dupStmt = $pdo->prepare(
                "SELECT id, status FROM idoklad_import_jobs
                  WHERE supplier_id = ? AND status IN ('queued','running')
                    AND params LIKE ?
                  ORDER BY id DESC LIMIT 1"
            );
            $dupStmt->execute([$supplierId, '%' . substr($importParams, 0, 100) . '%']);
            $existingJob = $dupStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingJob) {
                error_log(sprintf(
                    '[IdokladImport] Duplicate prevented: job_id=%d, status=%s, supplier=%d',
                    $existingJob['id'],
                    $existingJob['status'],
                    $supplierId
                ));
                return Json::error($response, 'duplicate_import',
                    "Import se stejnými parametry již běží (job #{$existingJob['id']}, stav: {$existingJob['status']}). Počkejte na jeho dokončení.",
                    409);
            }

            $jobRow = $pdo->prepare(
                "INSERT INTO idoklad_import_jobs (supplier_id, admin_id, status, params) VALUES (?, ?, 'queued', ?)"
            );
            $jobRow->execute([
                $supplierId,
                (int)($user['id'] ?? 0),
                json_encode([
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'years'         => $years,
                    'sections'      => $sections,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $jobId = (int)$pdo->lastInsertId();

            // Launch detached PHP process — returns immediately
            $workerPath = realpath(dirname(__DIR__, 3) . '/bin/idoklad-import-worker.php');
            $phpBinary = PHP_BINARY;
            $logFile = sys_get_temp_dir() . '/idoklad-worker-' . $jobId . '.log';
            
            if ($workerPath === false) {
                error_log('[IdokladImport] Worker not found: ' . $workerPath);
            } elseif (DIRECTORY_SEPARATOR === '/') {
                // Linux / macOS — use nohup + & (disown not available in Alpine/busybox)
                $cmd = sprintf(
                    'nohup php %s --job-id=%d > %s 2>&1 &',
                    escapeshellarg($workerPath),
                    $jobId,
                    escapeshellarg($logFile)
                );
                $output = shell_exec($cmd);
                error_log('[IdokladImport] Launched worker job=' . $jobId . ', cmd=' . $cmd);
            } else {
                // Windows (IIS FastCGI)
                $cmd = sprintf('"%s" "%s" --job-id=%d', $phpBinary, $workerPath, $jobId);
                $desc = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $proc = proc_open('cmd.exe /C start "" /B ' . $cmd, $desc, $pipes);
                if (is_resource($proc)) {
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }
                    proc_close($proc);
                    error_log('[IdokladImport] Launched worker job=' . $jobId . ' via proc_open');
                } else {
                    error_log('[IdokladImport] Failed to launch worker job=' . $jobId);
                }
            }

            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log(
                'idoklad.import_queued', (int)($user['id'] ?? 0), null, null,
                ['job_id' => $jobId, 'years' => $years],
                $ip, $request->getHeaderLine('User-Agent')
            );

            error_log('[IdokladImport] Job queued: job_id=' . $jobId);
            return Json::ok($response, [
                'job_id'  => $jobId,
                'status'  => 'queued',
                'message' => 'Import běží na pozadí. Použijte /api/admin/idoklad-import/status?job_id=' . $jobId,
            ]);
        }

        // Dry-run — inline execution with full logging
        error_log('[IdokladImport] Dry-run started: user=' . ($user['id'] ?? 0) . ', supplier=' . $supplierId);

        $runAll         = empty($sections);
        $runContacts    = $runAll || in_array('contacts',     $sections, true);
        $runInvoices    = $runAll || in_array('invoices',     $sections, true);
        $runCreditNotes = $runAll || in_array('credit-notes', $sections, true);
        $runPurchases   = $runAll || in_array('purchases',    $sections, true);

        // Číselníky
        $adminId = (int)($user['id'] ?? 0);

        $vatByCode = [];
        foreach ($pdo->query("SELECT id, code, rate_percent FROM vat_rates")->fetchAll(\PDO::FETCH_ASSOC) as $vr) {
            $vatByCode[$vr['code']] = ['id' => (int)$vr['id'], 'rate' => (float)$vr['rate_percent']];
        }
        foreach (['CZ-21', 'CZ-12', 'CZ-0'] as $req) {
            if (!isset($vatByCode[$req])) {
                return Json::error($response, 'missing_vat_rate', "Sazba $req chybí v vat_rates.", 500);
            }
        }

        $currencyId = (int)$pdo->query(
            "SELECT id FROM currencies WHERE code='CZK' AND supplier_id={$supplierId} LIMIT 1"
        )->fetchColumn();
        if (!$currencyId) {
            $currencyId = (int)$pdo->query(
                "SELECT id FROM currencies WHERE supplier_id={$supplierId} ORDER BY id LIMIT 1"
            )->fetchColumn();
        }
        $countryId = (int)$pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        if (!$countryId) {
            $countryId = (int)$pdo->query("SELECT id FROM countries ORDER BY id LIMIT 1")->fetchColumn();
        }

        $vatRateToCode = static function (float $rate): string {
            $r = (int)round($rate);
            if ($r >= 20) return 'CZ-21';
            if ($r >= 9)  return 'CZ-12';
            return 'CZ-0';
        };

        // Import může trvat minuty — odblokujeme PHP a ignorujeme předčasné zavření spojení.
        // Cloudflare 502 timeout nelze vyřešit přímo zde; server-side date filter níže
        // radikálně zkracuje dobu přenosu (stahujeme jen vybrané roky, ne vše).
        set_time_limit(0);
        ignore_user_abort(true);

        // ── iDoklad API ────────────────────────────────────────────────────────
        try {
            $token = $this->getToken($clientId, $clientSecret);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'auth_failed', 'iDoklad OAuth selhalo: ' . $e->getMessage(), 502);
        }

        $log   = [];
        $stats = [
            'contacts_new' => 0, 'contacts_exist' => 0,
            'clients_new'  => 0, 'clients_exist'  => 0,
            'invoices_new' => 0, 'invoices_skip'  => 0,
            'credit_notes_new' => 0, 'credit_notes_skip' => 0,
            'purchases_new' => 0, 'purchases_skip'  => 0,
        ];
        $clientCache = [];

        // Server-side date filter — stahujeme od iDokladu jen vybrané roky,
        // nikoliv celou historii. Dramaticky zkrátí počet HTTP stránek a dobu requestu.
        $dateFilter = null;
        if (!empty($years)) {
            $min = min($years);
            $max = max($years);
            $dateFilter = "DateOfIssue~gte~'{$min}-01-01'~and~DateOfIssue~lte~'{$max}-12-31'";
        }

        // Vždy stáhni kontakty pro cache
        // Poznámka: IssuedInvoiceCorrections je správný endpoint pro dobropisy v API v3
        try {
            $allContacts    = $this->fetchAll('Contacts', $token, 'Id');  // Use Id instead of CompanyName (API rejected CompanyName:asc)
            $allInvoices    = $runInvoices    ? $this->filterYears($this->fetchAll('IssuedInvoices',        $token, 'DocumentNumber', $dateFilter), $years) : [];
            $allCreditNotes = $runCreditNotes ? $this->filterYears($this->fetchAll('IssuedInvoiceCorrections', $token, 'DocumentNumber', $dateFilter), $years) : [];
            $allPurchases   = $runPurchases   ? $this->filterYears($this->fetchAll('ReceivedInvoices',      $token, 'DocumentNumber', $dateFilter), $years) : [];
        } catch (\RuntimeException $e) {
            return Json::error($response, 'api_fetch_failed', 'Stahování dat z iDokladu selhalo: ' . $e->getMessage(), 502);
        }
        $log[] = 'Kontaktů staženo: ' . count($allContacts);
        $log[] = 'Vydaných faktur: ' . count($allInvoices);
        $log[] = 'Dobropisů: ' . count($allCreditNotes);
        $log[] = 'Přijatých faktur: ' . count($allPurchases);

        $pdo->beginTransaction();
        try {

        // 1. Kontakty
        if ($runContacts) {
            foreach ($allContacts as $contact) {
                $idId = (int)($contact['Id'] ?? 0);
                $cn   = trim($contact['CompanyName'] ?? trim(($contact['FirstName'] ?? '') . ' ' . ($contact['Surname'] ?? '')));
                if ($cn === '') continue;
                $ic  = trim($contact['IdentificationNumber']    ?? '');
                $dic = trim($contact['VatIdentificationNumber'] ?? '');
                $addr = []; foreach ($contact['Addresses'] ?? [] as $a) { if (empty($addr)) $addr = $a; if ((int)($a['AddressType'] ?? -1) === 1) { $addr = $a; break; } }
                $street = trim($addr['Street'] ?? ''); $city = trim($addr['City'] ?? ''); $zip = trim($addr['PostalCode'] ?? '');
                $email  = trim($contact['Email'] ?? ''); $phone = trim($contact['MobilePhone'] ?? ($contact['Phone'] ?? ''));

                // Dedup: 1) by iDoklad ID (fastest, exact)  2) business-key fallback
                $found = false;
                if ($idId > 0) {
                    $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                    $st->execute([$supplierId, $idId]);
                    $found = $st->fetchColumn();
                }
                if (!$found) {
                    if ($ic !== '') { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1"); $st->execute([$supplierId, $ic]); }
                    else            { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1"); $st->execute([$supplierId, $cn]); }
                    $found = $st->fetchColumn();
                    // Back-fill idoklad_id on existing row if we found it by business-key
                    if ($found && $idId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE clients SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$idId, $found]);
                    }
                }

                if ($found) { $stats['contacts_exist']++; if ($idId > 0) $clientCache[$idId] = (int)$found; continue; }

                $stats['contacts_new']++;
                $log[] = "[KONTAKT+] $cn (IČ=$ic)";
                if (!$dryRun) {
                    $st = $pdo->prepare("INSERT INTO clients (supplier_id,idoklad_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
                    $st->execute([$supplierId, $idId ?: null, $cn, $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
                    if ($idId > 0) $clientCache[$idId] = (int)$pdo->lastInsertId();
                } else { if ($idId > 0) $clientCache[$idId] = 0; }
            }
        }

        // 2. Vydané faktury
        if ($runInvoices) {
            foreach ($allInvoices as $inv) {
                $varsymbol = trim((string)($inv['VariableSymbol'] ?? $inv['DocumentNumber']));
                $invIdokladId = (int)($inv['Id'] ?? 0);
                // Dedup: 1) iDoklad ID  2) varsymbol fallback
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
                    if ($existId && $invIdokladId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE invoices SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$invIdokladId, $existId]);
                    }
                }
                if ($existId) { $stats['invoices_skip']++; $log[] = "[SKIP faktura] $varsymbol (#$existId)"; continue; }

                $clientId = $this->upsertClient($pdo, $supplierId, $countryId, $currencyId, $inv['PartnerAddress'] ?? [], (int)($inv['PartnerId'] ?? 0), $clientCache, $stats, $dryRun);
                [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($inv);
                $prices  = $inv['Prices'] ?? [];
                $vatItems = $this->parseVatItems($prices, $vatRateToCode);
                $desc    = $this->itemDesc($inv, 'Faktura');
                $stats['invoices_new']++;
                $log[] = "[FAKTURA] $varsymbol $iDate " . number_format((float)($prices['TotalWithVat'] ?? 0), 2) . " Kč  $status";

                if (!$dryRun) {
                    $st = $pdo->prepare("INSERT INTO invoices (supplier_id,idoklad_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,'invoice',?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st->execute([$supplierId, $invIdokladId ?: null, $varsymbol, $clientId, $iDate, $tDate, $dDate, (float)($prices['TotalWithoutVat'] ?? 0), (float)($prices['TotalVat'] ?? 0), (float)($prices['TotalWithVat'] ?? 0), $status, $paidAt, $currencyId, $adminId]);
                    $this->insertInvoiceItems($pdo, (int)$pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
                }
            }
        }

        // 3. Dobropisy
        if ($runCreditNotes) {
            foreach ($allCreditNotes as $cn) {
                $varsymbol = trim((string)($cn['VariableSymbol'] ?? $cn['DocumentNumber']));
                $cnIdokladId = (int)($cn['Id'] ?? 0);
                // Dedup: 1) iDoklad ID  2) varsymbol fallback
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
                    if ($existId && $cnIdokladId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE invoices SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$cnIdokladId, $existId]);
                    }
                }
                if ($existId) { $stats['credit_notes_skip']++; $log[] = "[SKIP dobropis] $varsymbol (#$existId)"; continue; }

                $clientId = $this->upsertClient($pdo, $supplierId, $countryId, $currencyId, $cn['PartnerAddress'] ?? [], (int)($cn['PartnerId'] ?? 0), $clientCache, $stats, $dryRun);
                [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($cn);
                $prices   = $cn['Prices'] ?? [];
                $vatItems = $this->parseVatItems($prices, $vatRateToCode);
                foreach ($vatItems as &$vi) { $vi['base'] = -abs($vi['base']); $vi['vat'] = -abs($vi['vat']); $vi['tot'] = -abs($vi['tot']); } unset($vi);
                $desc = $this->itemDesc($cn, 'Dobropis');
                $twv  = -abs((float)($prices['TotalWithVat'] ?? 0));
                $stats['credit_notes_new']++;
                $log[] = "[DOBROPIS] $varsymbol $iDate " . number_format($twv, 2) . " Kč";

                if (!$dryRun) {
                    $corrVs = trim((string)($cn['CorrectedDocumentVariableSymbol'] ?? $cn['InvoiceVariableSymbol'] ?? ''));
                    $parentId = null;
                    if ($corrVs !== '') { $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND varsymbol=? AND invoice_type='invoice' LIMIT 1"); $st->execute([$supplierId, $corrVs]); $parentId = $st->fetchColumn() ?: null; }
                    $st = $pdo->prepare("INSERT INTO invoices (supplier_id,idoklad_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,'credit_note',?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st->execute([$supplierId, $cnIdokladId ?: null, $varsymbol, $parentId, $clientId, $iDate, $tDate, $dDate, -abs((float)($prices['TotalWithoutVat'] ?? 0)), -abs((float)($prices['TotalVat'] ?? 0)), $twv, $status, $paidAt, $currencyId, $adminId]);
                    $this->insertInvoiceItems($pdo, (int)$pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
                }
            }
        }

        // 4. Přijaté faktury
        if ($runPurchases) {
            foreach ($allPurchases as $pi) {
                $invNum = trim((string)($pi['DocumentNumber'] ?? ($pi['VariableSymbol'] ?? '')));
                $piIdokladId = (int)($pi['Id'] ?? 0);
                [$iDate] = $this->parseDates($pi);
                $vendorId = $this->upsertClient($pdo, $supplierId, $countryId, $currencyId, $pi['PartnerAddress'] ?? [], (int)($pi['PartnerId'] ?? 0), $clientCache, $stats, $dryRun);
                // Dedup: 1) iDoklad ID  2) invoice_number + year-month fallback
                $existId = false;
                if ($piIdokladId > 0) {
                    $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                    $st->execute([$supplierId, $piIdokladId]);
                    $existId = $st->fetchColumn();
                }
                if (!$existId) {
                    $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND invoice_number=? AND DATE_FORMAT(issue_date,'%Y-%m')=DATE_FORMAT(?,'%Y-%m') LIMIT 1");
                    $st->execute([$supplierId, $invNum, $iDate]);
                    $existId = $st->fetchColumn();
                    if ($existId && $piIdokladId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE purchase_invoices SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$piIdokladId, $existId]);
                    }
                }
                if ($existId) { $stats['purchases_skip']++; $log[] = "[SKIP nákup] $invNum (#$existId)"; continue; }

                [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($pi);
                $prices   = $pi['Prices'] ?? [];
                $vatItems = $this->parseVatItems($prices, $vatRateToCode);
                $desc     = $this->itemDesc($pi, 'Přijatá faktura');
                $stats['purchases_new']++;
                $log[] = "[NÁKUP] $invNum $iDate " . number_format((float)($prices['TotalWithVat'] ?? 0), 2) . " Kč  $status";

                if (!$dryRun) {
                    $pAddr = $pi['PartnerAddress'] ?? [];
                    $snap  = json_encode(['company_name' => trim($pAddr['CompanyName'] ?? ''), 'ic' => trim($pAddr['IdentificationNumber'] ?? ''), 'dic' => trim($pAddr['VatIdentificationNumber'] ?? ''), 'street' => trim($pAddr['Street'] ?? ''), 'city' => trim($pAddr['City'] ?? ''), 'zip' => trim($pAddr['PostalCode'] ?? ''), 'country' => 'CZ'], JSON_UNESCAPED_UNICODE);
                    $st = $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,idoklad_id,invoice_number,issue_date,tax_date,due_date,received_at,currency_id,document_kind,total_without_vat,total_vat,total_with_vat,status,paid_at,supplier_snapshot,created_by) VALUES (?,?,?,?,?,?,?,?,'invoice',?,?,?,?,?,?,?)");
                    $st->execute([$supplierId, $piIdokladId ?: null, $invNum, $iDate, $tDate, $dDate, $iDate, $currencyId, (float)($prices['TotalWithoutVat'] ?? 0), (float)($prices['TotalVat'] ?? 0), (float)($prices['TotalWithVat'] ?? 0), $status === 'issued' ? 'received' : $status, $paidAt, $snap, $adminId]);
                    $piId = (int)$pdo->lastInsertId();
                    $stItem = $pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?)");
                    foreach ($vatItems as $idx => $s) { $stItem->execute([$piId, $desc, $s['base'], $vatByCode[$s['code']]['id'], $s['rate'], $s['base'], $s['vat'], $s['tot'], $idx]); }
                }
            }
        }

            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[IdokladImport] ERROR: ' . $e->getMessage());
            return Json::error($response, 'import_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        if (!$dryRun) {
            $this->logger->log('idoklad.imported', $adminId, null, null, ['stats' => $stats, 'years' => $years], $ip, $request->getHeaderLine('User-Agent'));
        }

        error_log(sprintf(
            '[IdokladImport] Completed: user=%d, supplier=%d, dry_run=%s, stats=%s',
            $user['id'] ?? 0,
            $supplierId,
            $dryRun ? 'true' : 'false',
            json_encode($stats)
        ));

        return Json::ok($response, ['stats' => $stats, 'log' => $log, 'dry_run' => $dryRun]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getToken(string $clientId, string $secret): string
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials', 'scope' => 'idoklad_api', 'client_id' => $clientId, 'client_secret' => $secret]),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200) throw new \RuntimeException("HTTP $code: " . substr((string)$body, 0, 200));
        $data = json_decode((string)$body, true);
        if (empty($data['access_token'])) throw new \RuntimeException('Chybí access_token.');
        return $data['access_token'];
    }

    private function fetchAll(string $endpoint, string $token, string $sortField = 'DocumentNumber', ?string $filter = null): array
    {
        $page = 1; $all = [];
        do {
            // Note: iDoklad API v3 doesn't support Sort via URL params - removed 'sort' parameter
            $params = ['pageSize' => self::PAGE_SIZE, 'page' => $page];
            if ($filter !== null) {
                $params['filter'] = $filter;
            }
            $url = self::API_BASE . '/' . $endpoint . '?' . http_build_query($params);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", "Accept: application/json"]]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code !== 200) throw new \RuntimeException("API $code [$endpoint]");
            $resp  = json_decode((string)$body, true);
            $data  = $resp['Data'] ?? $resp;
            $items = $data['Items'] ?? [];
            $all   = array_merge($all, $items);
            $total = (int)($data['TotalItems'] ?? count($all));
            $page++;
        } while (count($all) < $total);
        return $all;
    }

    private function filterYears(array $items, array $years): array
    {
        return array_values(array_filter($items, fn($i) => in_array((int)substr((string)($i['DateOfIssue'] ?? ''), 0, 4), $years, true)));
    }

    private function parseDates(array $inv): array
    {
        $d = fn(string $raw): string => ($raw === '' || substr($raw, 0, 4) === '1753') ? date('Y-m-d') : substr($raw, 0, 10);
        $iDate = $d((string)($inv['DateOfIssue']    ?? ''));
        $tDate = $d((string)($inv['DateOfTaxing']   ?? $inv['DateOfIssue'] ?? ''));
        $dDate = $d((string)($inv['DateOfMaturity'] ?? $inv['DateOfIssue'] ?? ''));
        $pRaw  = (string)($inv['DateOfPayment'] ?? '');
        $paidAt = ($inv['PaymentStatus'] === 1 && $pRaw !== '' && substr($pRaw, 0, 4) !== '1753') ? $d($pRaw) : null;
        return [$iDate, $tDate, $dDate, $paidAt, $paidAt ? 'paid' : 'issued'];
    }

    private function parseVatItems(array $prices, callable $vrc): array
    {
        $items = [];
        foreach ($prices['VatRateSummary'] ?? [] as $s) {
            $base = (float)($s['TotalWithoutVat'] ?? 0); $vat = (float)($s['TotalVat'] ?? 0); $tot = (float)($s['TotalWithVat'] ?? 0); $rate = (float)($s['VatRate'] ?? 0);
            if ($base == 0.0 && $vat == 0.0) continue;
            $items[] = ['rate' => $rate, 'code' => $vrc($rate), 'base' => $base, 'vat' => $vat, 'tot' => $tot];
        }
        if (empty($items)) {
            $base = (float)($prices['TotalWithoutVat'] ?? 0); $vat = (float)($prices['TotalVat'] ?? 0); $tot = (float)($prices['TotalWithVat'] ?? 0);
            $rate = ($base != 0.0 && $vat != 0.0) ? (float)round($vat / $base * 100) : 21.0;
            $items[] = ['rate' => $rate, 'code' => $vrc($rate), 'base' => $base, 'vat' => $vat, 'tot' => $tot];
        }
        return $items;
    }

    private function itemDesc(array $inv, string $default): string
    {
        $desc = trim((string)($inv['Description'] ?? ''));
        foreach ($inv['Items'] ?? [] as $it) {
            if ($it['IsTaxMovement'] ?? false) { $n = trim((string)preg_replace('/\s+/', ' ', $it['Name'] ?? '')); if ($n !== '') return $n; }
        }
        return $desc !== '' ? $desc : $default;
    }

    private function insertInvoiceItems(\PDO $pdo, int $id, array $vatItems, array $vatByCode, string $desc): void
    {
        $st = $pdo->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?)");
        $multi = count($vatItems) > 1;
        foreach ($vatItems as $idx => $s) {
            $itemDesc = ($multi && $s['code'] === 'CZ-0') ? 'Místní poplatek za pobyt' : $desc;
            $st->execute([$id, $itemDesc, $s['base'], $vatByCode[$s['code']]['id'], $s['rate'], $s['base'], $s['vat'], $s['tot'], $idx]);
        }
    }

    private function upsertClient(\PDO $pdo, int $supplierId, int $countryId, int $currencyId, array $addr, int $idId, array &$cache, array &$stats, bool $dryRun): int
    {
        if ($idId > 0 && isset($cache[$idId])) return $cache[$idId];
        $cn  = trim($addr['CompanyName'] ?? trim(($addr['FirstName'] ?? '') . ' ' . ($addr['Surname'] ?? '')));
        if ($cn === '') $cn = 'Neznámý';
        $ic = trim($addr['IdentificationNumber'] ?? '');
        if ($ic !== '') { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1"); $st->execute([$supplierId, $ic]); }
        else            { $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1"); $st->execute([$supplierId, $cn]); }
        $found = $st->fetchColumn();
        if ($found) { $stats['clients_exist']++; if ($idId > 0) $cache[$idId] = (int)$found; return (int)$found; }
        $stats['clients_new']++;
        if ($dryRun) { if ($idId > 0) $cache[$idId] = 0; return 0; }
        $st = $pdo->prepare("INSERT INTO clients (supplier_id,company_name,ic,dic,street,city,zip,country_id,main_email,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,'','cs',?)");
        $st->execute([$supplierId, $cn, $ic ?: null, trim($addr['VatIdentificationNumber'] ?? '') ?: null, trim($addr['Street'] ?? ''), trim($addr['City'] ?? ''), trim($addr['PostalCode'] ?? ''), $countryId, $currencyId]);
        $newId = (int)$pdo->lastInsertId();
        if ($idId > 0) $cache[$idId] = $newId;
        return $newId;
    }
}
