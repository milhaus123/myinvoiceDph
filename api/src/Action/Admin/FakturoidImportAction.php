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
 * POST /api/admin/fakturoid-import
 *
 * Spustí import z Fakturoid API přímo z UI.
 * Credentials jsou uloženy v supplier.fakturoid_client_id / fakturoid_client_secret / fakturoid_slug.
 *
 * Body (JSON):
 *   years[]    int[]    Roky k importu (výchozí: aktuální rok ± 1)
 *   sections[] string[] contacts|invoices|credit-notes|purchases (výchozí: vše)
 *   dry_run    bool     Jen preview, bez zápisů do DB
 *
 * Odpověď (dry_run=true): { stats: {...}, log: string[], dry_run: true }
 * Odpověď (dry_run=false): { job_id: N, status: 'queued', message: '...' }
 */
final class FakturoidImportAction
{
    private const TOKEN_URL = 'https://app.fakturoid.cz/api/v3/oauth/token';
    private const API_BASE  = 'https://app.fakturoid.cz/api/v3/accounts';
    private const PAGE_SIZE = 40; // Fakturoid vrací max 40 záznamů na stránku
    private const USER_AGENT = 'MyInvoiceDph/1.0 (support@myinvoice.cz)';

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
        $sup = $pdo->prepare(
            'SELECT fakturoid_client_id, fakturoid_client_secret, fakturoid_slug FROM supplier WHERE id = ?'
        );
        $sup->execute([$supplierId]);
        $creds = $sup->fetch(\PDO::FETCH_ASSOC);

        $clientId     = trim((string)($creds['fakturoid_client_id']     ?? ''));
        $clientSecret = trim((string)($creds['fakturoid_client_secret'] ?? ''));
        $slug         = trim((string)($creds['fakturoid_slug']          ?? ''));

        if ($clientId === '' || $clientSecret === '' || $slug === '') {
            return Json::error($response, 'missing_credentials',
                'Fakturoid credentials nejsou nastaveny. Vyplňte Client ID, Client Secret a Slug účtu v Nastavení → Fakturoid.',
                400);
        }

        // Parametry importu
        $body     = (array)($request->getParsedBody() ?? []);
        $dryRun   = (bool)($body['dry_run'] ?? false);
        $years    = array_map('intval', (array)($body['years']    ?? []));
        $sections = (array)($body['sections'] ?? []);

        if (empty($years)) {
            $y     = (int)date('Y');
            $years = [$y - 1, $y, $y + 1];
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        error_log(sprintf(
            '[FakturoidImport] Started: user=%d, supplier=%d, slug=%s, dry_run=%s, years=%s, ip=%s',
            $user['id'] ?? 0,
            $supplierId,
            $slug,
            $dryRun ? 'true' : 'false',
            implode(',', $years),
            $ip
        ));

        // ── Non-dry_run: dispatch background job ─────────────────────────────────
        if (!$dryRun) {
            $importParams = json_encode(['years' => $years, 'sections' => $sections], JSON_UNESCAPED_UNICODE);

            // Kontrola duplicitního jobu
            $dupStmt = $pdo->prepare(
                "SELECT id, status FROM fakturoid_import_jobs
                  WHERE supplier_id = ? AND status IN ('queued','running')
                  ORDER BY id DESC LIMIT 1"
            );
            $dupStmt->execute([$supplierId]);
            $existingJob = $dupStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingJob) {
                return Json::error($response, 'duplicate_import',
                    "Import již běží (job #{$existingJob['id']}, stav: {$existingJob['status']}). Počkejte na jeho dokončení.",
                    409);
            }

            $jobRow = $pdo->prepare(
                "INSERT INTO fakturoid_import_jobs (supplier_id, admin_id, status, params) VALUES (?, ?, 'queued', ?)"
            );
            $jobRow->execute([
                $supplierId,
                (int)($user['id'] ?? 0),
                json_encode([
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'slug'          => $slug,
                    'years'         => $years,
                    'sections'      => $sections,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $jobId = (int)$pdo->lastInsertId();

            // Launch detached PHP worker
            $workerPath = realpath(dirname(__DIR__, 3) . '/bin/fakturoid-import-worker.php');
            $logFile    = sys_get_temp_dir() . '/fakturoid-worker-' . $jobId . '.log';

            if ($workerPath !== false) {
                if (DIRECTORY_SEPARATOR === '/') {
                    $cmd = sprintf(
                        'nohup php %s --job-id=%d > %s 2>&1 &',
                        escapeshellarg($workerPath),
                        $jobId,
                        escapeshellarg($logFile)
                    );
                    shell_exec($cmd);
                    error_log('[FakturoidImport] Launched worker job=' . $jobId);
                } else {
                    $phpBinary = PHP_BINARY;
                    $cmd       = sprintf('"%s" "%s" --job-id=%d', $phpBinary, $workerPath, $jobId);
                    $desc      = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                    $proc      = proc_open('cmd.exe /C start "" /B ' . $cmd, $desc, $pipes);
                    if (is_resource($proc)) {
                        foreach ($pipes as $pipe) { fclose($pipe); }
                        proc_close($proc);
                        error_log('[FakturoidImport] Launched worker job=' . $jobId . ' via proc_open');
                    }
                }
            } else {
                error_log('[FakturoidImport] Worker not found');
            }

            $this->logger->log(
                'fakturoid.import_queued', (int)($user['id'] ?? 0), null, null,
                ['job_id' => $jobId, 'years' => $years],
                $ip, $request->getHeaderLine('User-Agent')
            );

            return Json::ok($response, [
                'job_id'  => $jobId,
                'status'  => 'queued',
                'message' => 'Import běží na pozadí. Použijte /api/admin/fakturoid-import/status?job_id=' . $jobId,
            ]);
        }

        // ── Dry-run — inline execution ─────────────────────────────────────────
        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $token = $this->getToken($clientId, $clientSecret);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'auth_failed', 'Fakturoid OAuth selhalo: ' . $e->getMessage(), 502);
        }

        $log   = [];
        $stats = [
            'contacts_new'     => 0, 'contacts_exist'    => 0,
            'clients_new'      => 0, 'clients_exist'     => 0,
            'invoices_new'     => 0, 'invoices_skip'     => 0,
            'credit_notes_new' => 0, 'credit_notes_skip' => 0,
            'purchases_new'    => 0, 'purchases_skip'    => 0,
        ];

        $adminId = (int)($user['id'] ?? 0);

        // Číselníky
        $vatByCode = [];
        foreach ($pdo->query("SELECT id, code, rate_percent FROM vat_rates")->fetchAll(\PDO::FETCH_ASSOC) as $vr) {
            $vatByCode[$vr['code']] = ['id' => (int)$vr['id'], 'rate' => (float)$vr['rate_percent']];
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

        $runAll         = empty($sections);
        $runContacts    = $runAll || in_array('contacts',     $sections, true);
        $runInvoices    = $runAll || in_array('invoices',     $sections, true);
        $runCreditNotes = $runAll || in_array('credit-notes', $sections, true);
        $runPurchases   = $runAll || in_array('purchases',    $sections, true);

        // Stáhni data z Fakturoid API
        try {
            $allSubjects  = $runContacts  ? $this->fetchAll($slug, 'subjects',  $token) : [];
            $allInvoicesRaw = ($runInvoices || $runCreditNotes)
                ? $this->filterYears($this->fetchAll($slug, 'invoices', $token), $years)
                : [];
            $allPurchases = $runPurchases
                ? $this->filterYears($this->fetchAll($slug, 'expenses', $token), $years)
                : [];
        } catch (\RuntimeException $e) {
            return Json::error($response, 'api_fetch_failed', 'Stahování dat z Fakturoid selhalo: ' . $e->getMessage(), 502);
        }

        // Rozděl faktury na normální a dobropisy podle document_type
        // Fakturoid document_type: 'invoice', 'proforma', 'correction', 'tax_document'
        $allInvoices    = [];
        $allCreditNotes = [];
        foreach ($allInvoicesRaw as $item) {
            $docType = (string)($item['document_type'] ?? 'invoice');
            if ($docType === 'correction') {
                $allCreditNotes[] = $item;
            } elseif ($docType !== 'proforma') {
                // invoice, tax_document → standardní faktura
                $allInvoices[] = $item;
            }
            // proforma přeskočíme
        }

        $log[] = 'Kontaktů staženo: '   . count($allSubjects);
        $log[] = 'Vydaných faktur: '     . count($allInvoices);
        $log[] = 'Dobropisů: '           . count($allCreditNotes);
        $log[] = 'Přijatých faktur: '    . count($allPurchases);

        $clientCache = []; // fakturoid_id → local clients.id

        $pdo->beginTransaction();
        try {

        // 1. Kontakty
        if ($runContacts) {
            foreach ($allSubjects as $subject) {
                $fId = (int)($subject['id'] ?? 0);
                $cn  = trim((string)($subject['name'] ?? ''));
                if ($cn === '') continue;
                $ic  = trim((string)($subject['registration_no'] ?? ''));
                $dic = trim((string)($subject['vat_no'] ?? ''));
                $street = trim((string)($subject['street'] ?? ''));
                $city   = trim((string)($subject['city']   ?? ''));
                $zip    = trim((string)($subject['zip']    ?? ''));
                $email  = trim((string)($subject['email']  ?? ''));
                $phone  = trim((string)($subject['phone']  ?? ''));

                // Dedup: 1) fakturoid_id  2) IČ fallback  3) jméno fallback
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
                    if ($found && $fId > 0 && !$dryRun) {
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
                if (!$dryRun) {
                    $st = $pdo->prepare("INSERT INTO clients (supplier_id,fakturoid_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
                    $st->execute([$supplierId, $fId ?: null, $cn, $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
                    if ($fId > 0) $clientCache[$fId] = (int)$pdo->lastInsertId();
                } else {
                    if ($fId > 0) $clientCache[$fId] = 0;
                }
            }
        }

        // 2. Vydané faktury
        if ($runInvoices) {
            foreach ($allInvoices as $inv) {
                $varsymbol = trim((string)($inv['variable_symbol'] ?? $inv['number'] ?? ''));
                $fId       = (int)($inv['id'] ?? 0);

                // Dedup
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
                    if ($existId && $fId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE invoices SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $existId]);
                    }
                }
                if ($existId) { $stats['invoices_skip']++; $log[] = "[SKIP faktura] $varsymbol (#$existId)"; continue; }

                $clientId = $this->upsertClientFromInvoice($pdo, $supplierId, $countryId, $currencyId, $inv, $clientCache, $stats, $dryRun);
                [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($inv);
                $vatItems = $this->parseVatItems($inv['lines'] ?? [], $vatRateToCode);
                $desc     = $this->itemDesc($inv);
                [$totalBase, $totalVat, $totalWithVat] = $this->parseTotals($inv);
                $stats['invoices_new']++;
                $log[] = "[FAKTURA] $varsymbol $iDate " . number_format($totalWithVat, 2) . " Kč  $status";

                if (!$dryRun) {
                    $st = $pdo->prepare("INSERT INTO invoices (supplier_id,fakturoid_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st->execute([$supplierId, $fId ?: null, $varsymbol, 'invoice', $clientId, $iDate, $tDate, $dDate, $totalBase, $totalVat, $totalWithVat, $status, $paidAt, $currencyId, $adminId]);
                    $this->insertInvoiceItems($pdo, (int)$pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
                }
            }
        }

        // 3. Dobropisy
        if ($runCreditNotes) {
            foreach ($allCreditNotes as $cn) {
                $varsymbol = trim((string)($cn['variable_symbol'] ?? $cn['number'] ?? ''));
                $fId       = (int)($cn['id'] ?? 0);

                // Dedup
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
                    if ($existId && $fId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE invoices SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $existId]);
                    }
                }
                if ($existId) { $stats['credit_notes_skip']++; $log[] = "[SKIP dobropis] $varsymbol (#$existId)"; continue; }

                $clientId = $this->upsertClientFromInvoice($pdo, $supplierId, $countryId, $currencyId, $cn, $clientCache, $stats, $dryRun);
                [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($cn);
                $vatItems = $this->parseVatItems($cn['lines'] ?? [], $vatRateToCode);
                foreach ($vatItems as &$vi) { $vi['base'] = -abs($vi['base']); $vi['vat'] = -abs($vi['vat']); $vi['tot'] = -abs($vi['tot']); } unset($vi);
                $desc = $this->itemDesc($cn);
                [, , $totalWithVat] = $this->parseTotals($cn);
                $twv = -abs($totalWithVat);
                $tbase = -abs($this->parseTotals($cn)[0]);
                $tvat  = -abs($this->parseTotals($cn)[1]);
                $stats['credit_notes_new']++;
                $log[] = "[DOBROPIS] $varsymbol $iDate " . number_format($twv, 2) . " Kč";

                if (!$dryRun) {
                    // Najdi původní fakturu (linked_invoice_id z Fakturoid)
                    $parentId  = null;
                    $linkedVs  = trim((string)($cn['related_id'] ?? ''));
                    if ($linkedVs !== '') {
                        $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND fakturoid_id=? AND invoice_type='invoice' LIMIT 1");
                        $st->execute([$supplierId, $linkedVs]);
                        $parentId = $st->fetchColumn() ?: null;
                    }
                    $st = $pdo->prepare("INSERT INTO invoices (supplier_id,fakturoid_id,varsymbol,invoice_type,parent_invoice_id,client_id,issue_date,tax_date,due_date,total_without_vat,total_vat,total_with_vat,status,paid_at,currency_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st->execute([$supplierId, $fId ?: null, $varsymbol, 'credit_note', $parentId, $clientId, $iDate, $tDate, $dDate, $tbase, $tvat, $twv, $status, $paidAt, $currencyId, $adminId]);
                    $this->insertInvoiceItems($pdo, (int)$pdo->lastInsertId(), $vatItems, $vatByCode, $desc);
                }
            }
        }

        // 4. Přijaté faktury (expenses)
        if ($runPurchases) {
            foreach ($allPurchases as $exp) {
                $invNum = trim((string)($exp['original_number'] ?? $exp['number'] ?? ''));
                $fId    = (int)($exp['id'] ?? 0);
                [$iDateCheck] = $this->parseDates($exp);

                // Dedup
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
                    if ($existId && $fId > 0 && !$dryRun) {
                        $pdo->prepare("UPDATE purchase_invoices SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$fId, $existId]);
                    }
                }
                if ($existId) { $stats['purchases_skip']++; $log[] = "[SKIP nákup] $invNum (#$existId)"; continue; }

                $vendorId  = $this->upsertVendorFromExpense($pdo, $supplierId, $countryId, $currencyId, $exp, $clientCache, $stats, $dryRun);
                [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($exp);
                $vatItems = $this->parseVatItems($exp['lines'] ?? [], $vatRateToCode);
                $desc     = $this->itemDesc($exp);
                [$totalBase, $totalVat, $totalWithVat] = $this->parseTotals($exp);
                $stats['purchases_new']++;
                $log[] = "[NÁKUP] $invNum $iDate " . number_format($totalWithVat, 2) . " Kč  $status";

                if (!$dryRun) {
                    $snap = json_encode([
                        'company_name' => trim($exp['supplier_name']            ?? ''),
                        'ic'           => trim($exp['supplier_registration_no'] ?? ''),
                        'dic'          => trim($exp['supplier_vat_no']          ?? ''),
                        'street'       => trim($exp['supplier_street']          ?? ''),
                        'city'         => trim($exp['supplier_city']            ?? ''),
                        'zip'          => trim($exp['supplier_zip']             ?? ''),
                        'country'      => trim($exp['supplier_country']         ?? 'CZ'),
                    ], JSON_UNESCAPED_UNICODE);
                    $piStatus = $status === 'issued' ? 'received' : $status;
                    try {
                        $st = $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,fakturoid_id,invoice_number,issue_date,tax_date,due_date,received_at,currency_id,document_kind,total_without_vat,total_vat,total_with_vat,status,paid_at,supplier_snapshot,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $st->execute([$supplierId, $fId ?: null, $invNum, $iDate, $tDate, $dDate, $iDate, $currencyId, 'invoice', $totalBase, $totalVat, $totalWithVat, $piStatus, $paidAt, $snap, $adminId]);
                        $piId = (int)$pdo->lastInsertId();
                    } catch (\PDOException $e) {
                        if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                            $stats['purchases_skip']++; $log[] = "[SKIP nákup - duplikát] $invNum ($iDate)"; continue;
                        }
                        throw $e;
                    }
                    $reverseCharge = !empty($exp['transferred_tax_liability']);
                    $stItem = $pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,vat_classification,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?,?)");
                    foreach ($vatItems as $idx => $s) {
                        $classification = $this->vatClassificationPurchases($s['rate'], $reverseCharge);
                        $stItem->execute([$piId, $desc, $s['base'], $vatByCode[$s['code']]['id'], $s['rate'], $classification, $s['base'], $s['vat'], $s['tot'], $idx]);
                    }
                }
            }
        }

            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[FakturoidImport] ERROR: ' . $e->getMessage());
            return Json::error($response, 'import_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        if (!$dryRun) {
            $this->logger->log('fakturoid.imported', $adminId, null, null, ['stats' => $stats, 'years' => $years], $ip, $request->getHeaderLine('User-Agent'));
        }

        return Json::ok($response, ['stats' => $stats, 'log' => $log, 'dry_run' => $dryRun]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Získá OAuth2 Bearer token (Client Credentials flow).
     * Fakturoid používá HTTP Basic Auth na token endpointu.
     */
    private function getToken(string $clientId, string $secret): string
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ' . self::USER_AGENT,
            ],
            CURLOPT_USERPWD        => $clientId . ':' . $secret,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($err) throw new \RuntimeException("cURL chyba: $err");
        if ($code !== 200) throw new \RuntimeException("HTTP $code: " . substr((string)$body, 0, 200));
        $data = json_decode((string)$body, true);
        if (empty($data['access_token'])) throw new \RuntimeException('Chybí access_token v odpovědi.');
        return (string)$data['access_token'];
    }

    /**
     * Stáhne všechny záznamy z Fakturoid API (pagination po 40).
     * Pokračuje dokud odpověď není prázdné pole.
     */
    private function fetchAll(string $slug, string $endpoint, string $token): array
    {
        $page = 1;
        $all  = [];
        do {
            $url = self::API_BASE . '/' . $slug . '/' . $endpoint . '.json?page=' . $page;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            if ($err) throw new \RuntimeException("cURL $endpoint chyba: $err");
            if ($code >= 400) throw new \RuntimeException("API $endpoint vrátilo HTTP $code: " . substr((string)$body, 0, 300));
            $items = json_decode((string)$body, true);
            if (!is_array($items) || empty($items)) break;
            $all  = array_merge($all, $items);
            $page++;
        } while (count($items) >= self::PAGE_SIZE);
        return $all;
    }

    /**
     * Filtruje záznamy podle let (field issued_on nebo received_on).
     */
    private function filterYears(array $items, array $years): array
    {
        return array_values(array_filter($items, function (array $item) use ($years): bool {
            $dateStr = $item['issued_on'] ?? $item['received_on'] ?? $item['created_at'] ?? '';
            if (!$dateStr) return false;
            return in_array((int)substr((string)$dateStr, 0, 4), $years, true);
        }));
    }

    /**
     * Parsuje data z Fakturoid faktury/výdaje.
     * Fakturoid vrací data ve formátu YYYY-MM-DD (již bez časové zóny).
     */
    private function parseDates(array $doc): array
    {
        $d = static function (?string $s): ?string {
            if (!$s) return null;
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
            return null;
        };

        $iDate = $d($doc['issued_on']   ?? $doc['received_on'] ?? null) ?? date('Y-m-d');
        $tDate = $d($doc['taxable_fulfillment_due'] ?? null) ?? $iDate;
        $dDate = $d($doc['due_on'] ?? null) ?? $iDate;

        // Platba
        $paidAt = $d($doc['paid_on'] ?? null);
        // Fakturoid status: open, sent, overdue, paid, partially_paid, cancelled
        $fStatus = strtolower((string)($doc['status'] ?? 'open'));
        $isPaid  = ($fStatus === 'paid') || $paidAt !== null;
        $isCancelled = ($fStatus === 'cancelled');

        $localStatus = $isCancelled ? 'cancelled' : ($isPaid ? 'paid' : 'issued');

        return [$iDate, $tDate, $dDate, $paidAt, $localStatus];
    }

    /**
     * Grupuje řádkové položky (lines) podle sazby DPH.
     * Fakturoid vrací jednotlivé položky, my potřebujeme sumář po sazbách.
     */
    private function parseVatItems(array $lines, callable $vrc): array
    {
        $grouped = [];
        foreach ($lines as $line) {
            $rate = (float)($line['vat_rate'] ?? 0);
            $code = $vrc($rate);
            // Fakturoid: unit_price = cena za jednotku bez DPH, quantity = počet
            $qty   = (float)($line['quantity']   ?? 1);
            $unitP = (float)($line['unit_price']  ?? 0);
            $base  = round($qty * $unitP, 4);
            // Alternativně: pole amount (celkem bez DPH), vat_amount, total
            if (isset($line['amount'])) {
                $base = (float)$line['amount'];
            }
            $vat = isset($line['vat_amount']) ? (float)$line['vat_amount'] : round($base * $rate / 100, 4);
            $tot = isset($line['total'])      ? (float)$line['total']      : $base + $vat;

            if (!isset($grouped[$code])) {
                $grouped[$code] = ['rate' => $rate, 'code' => $code, 'base' => 0.0, 'vat' => 0.0, 'tot' => 0.0];
            }
            $grouped[$code]['base'] += $base;
            $grouped[$code]['vat']  += $vat;
            $grouped[$code]['tot']  += $tot;
        }

        if (empty($grouped)) {
            // Fallback: použij celkové součty z faktury (přijde v parseTotals)
            $grouped['CZ-0'] = ['rate' => 0.0, 'code' => 'CZ-0', 'base' => 0.0, 'vat' => 0.0, 'tot' => 0.0];
        }
        return array_values($grouped);
    }

    /**
     * Celkové součty faktury.
     * Fakturoid: subtotal = základ bez DPH, total_vat = DPH, total = celkem s DPH.
     */
    private function parseTotals(array $doc): array
    {
        $base = (float)($doc['subtotal']    ?? $doc['total_without_vat'] ?? 0);
        $vat  = (float)($doc['total_vat']   ?? 0);
        $tot  = (float)($doc['total']       ?? $base + $vat);
        return [$base, $vat, $tot];
    }

    /**
     * Popis položky — prioritně první řádek s názvem.
     */
    private function itemDesc(array $doc): string
    {
        foreach ($doc['lines'] ?? [] as $line) {
            $n = trim((string)($line['name'] ?? ''));
            if ($n !== '') return $n;
        }
        return trim((string)($doc['private_note'] ?? $doc['description'] ?? 'Faktura'));
    }

    /**
     * Najde nebo vytvoří klienta z údajů na vydané faktuře (client_* pole).
     */
    private function upsertClientFromInvoice(
        \PDO   $pdo,
        int    $supplierId,
        int    $countryId,
        int    $currencyId,
        array  $inv,
        array  &$cache,
        array  &$stats,
        bool   $dryRun
    ): int {
        $subjectId = (int)($inv['subject_id'] ?? 0);
        if ($subjectId > 0 && isset($cache[$subjectId])) return $cache[$subjectId];

        $cn  = trim((string)($inv['client_name']            ?? ''));
        $ic  = trim((string)($inv['client_registration_no'] ?? ''));
        $dic = trim((string)($inv['client_vat_no']          ?? ''));
        $street = trim((string)($inv['client_street'] ?? ''));
        $city   = trim((string)($inv['client_city']   ?? ''));
        $zip    = trim((string)($inv['client_zip']    ?? ''));
        $email  = trim((string)($inv['client_email']  ?? ''));
        $phone  = trim((string)($inv['client_phone']  ?? ''));

        if ($cn === '' && $ic === '') return 0;

        $found = false;
        if ($subjectId > 0) {
            $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND fakturoid_id=? LIMIT 1");
            $st->execute([$supplierId, $subjectId]);
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
            if ($found && $subjectId > 0 && !$dryRun) {
                $pdo->prepare("UPDATE clients SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$subjectId, $found]);
            }
        }

        if ($found) {
            $stats['clients_exist']++;
            if ($subjectId > 0) $cache[$subjectId] = (int)$found;
            return (int)$found;
        }

        $stats['clients_new']++;
        if ($dryRun) { if ($subjectId > 0) $cache[$subjectId] = 0; return 0; }
        $st = $pdo->prepare("INSERT INTO clients (supplier_id,fakturoid_id,company_name,ic,dic,street,city,zip,country_id,main_email,phone,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,'cs',?)");
        $st->execute([$supplierId, $subjectId ?: null, $cn ?: 'Bez názvu', $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $email, $phone ?: null, $currencyId]);
        $newId = (int)$pdo->lastInsertId();
        if ($subjectId > 0) $cache[$subjectId] = $newId;
        return $newId;
    }

    /**
     * Najde nebo vytvoří klienta z údajů na přijaté faktuře (supplier_* pole).
     */
    private function upsertVendorFromExpense(
        \PDO   $pdo,
        int    $supplierId,
        int    $countryId,
        int    $currencyId,
        array  $exp,
        array  &$cache,
        array  &$stats,
        bool   $dryRun
    ): int {
        $subjectId = (int)($exp['subject_id'] ?? 0);
        if ($subjectId > 0 && isset($cache[$subjectId])) return $cache[$subjectId];

        $cn  = trim((string)($exp['supplier_name']            ?? ''));
        $ic  = trim((string)($exp['supplier_registration_no'] ?? ''));
        $dic = trim((string)($exp['supplier_vat_no']          ?? ''));
        $street = trim((string)($exp['supplier_street'] ?? ''));
        $city   = trim((string)($exp['supplier_city']   ?? ''));
        $zip    = trim((string)($exp['supplier_zip']    ?? ''));

        if ($cn === '' && $ic === '') return 0;

        $found = false;
        if ($subjectId > 0) {
            $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND fakturoid_id=? LIMIT 1");
            $st->execute([$supplierId, $subjectId]);
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
            if ($found && $subjectId > 0 && !$dryRun) {
                $pdo->prepare("UPDATE clients SET fakturoid_id=? WHERE id=? AND fakturoid_id IS NULL")->execute([$subjectId, $found]);
            }
        }

        if ($found) {
            $stats['clients_exist']++;
            if ($subjectId > 0) $cache[$subjectId] = (int)$found;
            return (int)$found;
        }

        $stats['clients_new']++;
        if ($dryRun) { if ($subjectId > 0) $cache[$subjectId] = 0; return 0; }
        $st = $pdo->prepare("INSERT INTO clients (supplier_id,fakturoid_id,company_name,ic,dic,street,city,zip,country_id,main_email,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,'','cs',?)");
        $st->execute([$supplierId, $subjectId ?: null, $cn ?: 'Bez názvu', $ic ?: null, $dic ?: null, $street, $city, $zip, $countryId, $currencyId]);
        $newId = (int)$pdo->lastInsertId();
        if ($subjectId > 0) $cache[$subjectId] = $newId;
        return $newId;
    }

    private function insertInvoiceItems(\PDO $pdo, int $id, array $vatItems, array $vatByCode, string $desc): void
    {
        $st = $pdo->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,vat_classification,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?,?)");
        foreach ($vatItems as $idx => $s) {
            $code = $s['code'];
            if (!isset($vatByCode[$code])) $code = 'CZ-0';
            $classification = $this->vatClassificationSales($s['rate']);
            $st->execute([$id, $desc, $s['base'], $vatByCode[$code]['id'], $s['rate'], $classification, $s['base'], $s['vat'], $s['tot'], $idx]);
        }
    }

    private function vatClassificationSales(float $rate, bool $reverseCharge = false): string
    {
        if ($reverseCharge) return '25';
        $r = (int)round($rate);
        if ($r > 0) return '01-02';
        return '50';
    }

    private function vatClassificationPurchases(float $rate, bool $reverseCharge = false): string
    {
        if ($reverseCharge) return '10-11';
        $r = (int)round($rate);
        if ($r > 0) return '40-41';
        return '0P';
    }
}
