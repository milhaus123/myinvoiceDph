<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use PDO;

/**
 * Sdílená logika importu z iDoklad API.
 *
 * Používá se jak z IdokladImportAction (dry_run přes HTTP),
 * tak z idoklad-import-worker.php (background CLI job).
 */
final class IdokladImportService
{
    private const TOKEN_URL = 'https://app.idoklad.cz/identity/server/connect/token';
    private const API_BASE  = 'https://api.idoklad.cz/v3';
    private const PAGE_SIZE = 300;

    /**
     * Spustí import a vrátí [ 'stats' => [...], 'log' => [...] ].
     *
     * @param  PDO    $pdo
     * @param  int    $supplierId
     * @param  string $clientId
     * @param  string $clientSecret
     * @param  int[]  $years
     * @param  string[] $sections  contacts|invoices|credit-notes|purchases; prázdné = vše
     * @param  bool   $dryRun
     * @return array{stats: array<string,int>, log: string[]}
     */
    public function run(
        PDO    $pdo,
        int    $supplierId,
        string $clientId,
        string $clientSecret,
        array  $years,
        array  $sections,
        bool   $dryRun,
    ): array {
        set_time_limit(0);

        $runAll         = empty($sections);
        $runContacts    = $runAll || in_array('contacts',     $sections, true);
        $runInvoices    = $runAll || in_array('invoices',     $sections, true);
        $runCreditNotes = $runAll || in_array('credit-notes', $sections, true);
        $runPurchases   = $runAll || in_array('purchases',    $sections, true);

        // Číselníky
        $vatByCode = [];
        foreach ($pdo->query("SELECT id, code, rate_percent FROM vat_rates")->fetchAll(PDO::FETCH_ASSOC) as $vr) {
            $vatByCode[$vr['code']] = ['id' => (int)$vr['id'], 'rate' => (float)$vr['rate_percent']];
        }
        $vatRateClosureByPercent = function (float $rate) use ($vatByCode): string {
            foreach ($vatByCode as $code => $vr) {
                if (abs($vr['rate'] - $rate) < 0.01) return (string)$code;
            }
            return 'CZ-21';
        };

        $row = $pdo->prepare("SELECT currency_default_id FROM supplier WHERE id = ?")->execute([$supplierId])
            ? $pdo->query("SELECT currency_default_id FROM supplier WHERE id = $supplierId")->fetchColumn()
            : 0;
        // proper fetch
        $supStmt = $pdo->prepare("SELECT currency_default_id FROM supplier WHERE id = ?");
        $supStmt->execute([$supplierId]);
        $currencyId = (int)($supStmt->fetchColumn() ?: 0);

        $currStmt = $pdo->query("SELECT id FROM currencies WHERE code='CZK' LIMIT 1");
        if ($currencyId === 0) {
            $currencyId = (int)($currStmt ? $currStmt->fetchColumn() : 0);
        }

        $ctryStmt = $pdo->query("SELECT id FROM countries WHERE code='CZ' LIMIT 1");
        $countryId = (int)($ctryStmt ? $ctryStmt->fetchColumn() : 0);

        $stats = [
            'contacts_new' => 0, 'contacts_exist' => 0, 'contacts_backfill' => 0,
            'invoices_new' => 0, 'invoices_exist' => 0,
            'credit_notes_new' => 0, 'credit_notes_exist' => 0,
            'purchases_new' => 0, 'purchases_exist' => 0,
            'clients_new' => 0, 'clients_exist' => 0,
        ];
        $log = [];

        $token       = $this->getToken($clientId, $clientSecret);
        $minYear     = min($years);
        $maxYear     = max($years);
        $dateFilter  = "DateOfIssue~gt~{$minYear}-01-01T00:00:00|DateOfIssue~lt~" . ($maxYear + 1) . "-01-01T00:00:00";

        // ── Contacts ──────────────────────────────────────────────────────────
        $clientCache = [];

        if ($runContacts) {
            $contacts = $this->fetchAll('Contacts', $token, 'CompanyName');
            $log[] = 'Contacts fetched: ' . count($contacts);

            if (!$dryRun) $pdo->beginTransaction();
            try {
                foreach ($contacts as $c) {
                    $idId = (int)($c['Id'] ?? 0);
                    $cn   = trim($c['CompanyName'] ?? trim(($c['FirstName'] ?? '') . ' ' . ($c['Surname'] ?? '')));
                    if ($cn === '') $cn = 'Neznámý';
                    $ic   = trim($c['IdentificationNumber'] ?? '');

                    // Dedup: primárně idoklad_id
                    if ($idId > 0) {
                        $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                        $st->execute([$supplierId, $idId]);
                        if ($found = $st->fetchColumn()) {
                            $stats['contacts_exist']++;
                            $clientCache[$idId] = (int)$found;
                            continue;
                        }
                    }
                    // Fallback dedup: ic / company_name
                    if ($ic !== '') {
                        $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1");
                        $st->execute([$supplierId, $ic]);
                    } else {
                        $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1");
                        $st->execute([$supplierId, $cn]);
                    }
                    if ($found = $st->fetchColumn()) {
                        $stats['contacts_exist']++;
                        if ($idId > 0) {
                            // Back-fill idoklad_id
                            if (!$dryRun) {
                                $pdo->prepare("UPDATE clients SET idoklad_id=? WHERE id=? AND idoklad_id IS NULL")->execute([$idId, (int)$found]);
                            }
                            $stats['contacts_backfill']++;
                            $clientCache[$idId] = (int)$found;
                        }
                        continue;
                    }

                    $stats['contacts_new']++;
                    if (!$dryRun) {
                        $st = $pdo->prepare("INSERT INTO clients (supplier_id,idoklad_id,company_name,ic,dic,street,city,zip,country_id,main_email,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,?,'','cs',?)");
                        $st->execute([
                            $supplierId, $idId ?: null, $cn,
                            $ic ?: null,
                            trim($c['VatIdentificationNumber'] ?? '') ?: null,
                            trim($c['Street'] ?? ''), trim($c['City'] ?? ''), trim($c['PostalCode'] ?? ''),
                            $countryId, $currencyId,
                        ]);
                        $newId = (int)$pdo->lastInsertId();
                        if ($idId > 0) $clientCache[$idId] = $newId;
                    }
                }
                if (!$dryRun) $pdo->commit();
            } catch (\Throwable $e) {
                if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $log[] = sprintf('Contacts: %d new, %d exist, %d backfill', $stats['contacts_new'], $stats['contacts_exist'], $stats['contacts_backfill']);
        }

        // ── Invoices ──────────────────────────────────────────────────────────
        if ($runInvoices) {
            $invoices = $this->filterYears($this->fetchAll('IssuedInvoices', $token, 'DocumentNumber', $dateFilter), $years);
            $log[] = 'Invoices fetched: ' . count($invoices);

            if (!$dryRun) $pdo->beginTransaction();
            try {
                foreach ($invoices as $inv) {
                    $idId = (int)($inv['Id'] ?? 0);
                    $num  = trim((string)($inv['DocumentNumber'] ?? ''));

                    if ($idId > 0) {
                        $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                        $st->execute([$supplierId, $idId]);
                        if ($st->fetchColumn()) { $stats['invoices_exist']++; continue; }
                    }
                    if ($num !== '') {
                        $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND invoice_number=? LIMIT 1");
                        $st->execute([$supplierId, $num]);
                        if ($st->fetchColumn()) { $stats['invoices_exist']++; continue; }
                    }

                    $addr    = $inv['PurchaserAddress'] ?? [];
                    $idocId  = (int)($addr['ContactId'] ?? 0);
                    $clientId2 = $this->upsertClient($pdo, $supplierId, $countryId, $currencyId, $addr, $idocId, $clientCache, $stats, $dryRun);

                    [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($inv);
                    $prices   = $inv['Prices'] ?? [];
                    $vatItems = $this->parseVatItems($prices, $vatRateClosureByPercent);
                    $desc     = $this->itemDesc($inv, $num);

                    $stats['invoices_new']++;
                    if (!$dryRun) {
                        $st = $pdo->prepare("INSERT INTO invoices (supplier_id,idoklad_id,client_id,invoice_number,invoice_type,status,issue_date,taxing_date,due_date,paid_at,total_without_vat,total_vat,total_with_vat,currency_id,description,variable_symbol,constant_symbol) VALUES (?,?,?,?,'issued',?,?,?,?,?,?,?,?,?,?,?,?)");
                        $totalBase = array_sum(array_column($vatItems, 'base'));
                        $totalVat  = array_sum(array_column($vatItems, 'vat'));
                        $totalWith = array_sum(array_column($vatItems, 'tot'));
                        $st->execute([
                            $supplierId, $idId ?: null, $clientId2 ?: null, $num,
                            $status, $iDate, $tDate, $dDate, $paidAt,
                            $totalBase, $totalVat, $totalWith,
                            $currencyId, $desc,
                            trim((string)($inv['VariableSymbol'] ?? '')),
                            trim((string)($inv['ConstantSymbol'] ?? '')),
                        ]);
                        $invId = (int)$pdo->lastInsertId();
                        $this->insertInvoiceItems($pdo, $invId, $vatItems, $vatByCode, $desc);
                    }
                }
                if (!$dryRun) $pdo->commit();
            } catch (\Throwable $e) {
                if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $log[] = sprintf('Invoices: %d new, %d exist', $stats['invoices_new'], $stats['invoices_exist']);
        }

        // ── Credit Notes ──────────────────────────────────────────────────────
        if ($runCreditNotes) {
            $creditNotes = $this->filterYears($this->fetchAll('IssuedCreditNotes', $token, 'DocumentNumber', $dateFilter), $years);
            $log[] = 'Credit notes fetched: ' . count($creditNotes);

            if (!$dryRun) $pdo->beginTransaction();
            try {
                foreach ($creditNotes as $inv) {
                    $idId = (int)($inv['Id'] ?? 0);
                    $num  = trim((string)($inv['DocumentNumber'] ?? ''));

                    if ($idId > 0) {
                        $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                        $st->execute([$supplierId, $idId]);
                        if ($st->fetchColumn()) { $stats['credit_notes_exist']++; continue; }
                    }
                    if ($num !== '') {
                        $st = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND invoice_number=? LIMIT 1");
                        $st->execute([$supplierId, $num]);
                        if ($st->fetchColumn()) { $stats['credit_notes_exist']++; continue; }
                    }

                    $addr      = $inv['PurchaserAddress'] ?? [];
                    $idocId    = (int)($addr['ContactId'] ?? 0);
                    $clientId2 = $this->upsertClient($pdo, $supplierId, $countryId, $currencyId, $addr, $idocId, $clientCache, $stats, $dryRun);

                    [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($inv);
                    $prices   = $inv['Prices'] ?? [];
                    $vatItems = $this->parseVatItems($prices, $vatRateClosureByPercent);
                    $desc     = $this->itemDesc($inv, $num);

                    $stats['credit_notes_new']++;
                    if (!$dryRun) {
                        $st = $pdo->prepare("INSERT INTO invoices (supplier_id,idoklad_id,client_id,invoice_number,invoice_type,status,issue_date,taxing_date,due_date,paid_at,total_without_vat,total_vat,total_with_vat,currency_id,description,variable_symbol,constant_symbol) VALUES (?,?,?,?,'credit_note',?,?,?,?,?,?,?,?,?,?,?,?)");
                        $totalBase = array_sum(array_column($vatItems, 'base'));
                        $totalVat  = array_sum(array_column($vatItems, 'vat'));
                        $totalWith = array_sum(array_column($vatItems, 'tot'));
                        $st->execute([
                            $supplierId, $idId ?: null, $clientId2 ?: null, $num,
                            $status, $iDate, $tDate, $dDate, $paidAt,
                            $totalBase, $totalVat, $totalWith,
                            $currencyId, $desc,
                            trim((string)($inv['VariableSymbol'] ?? '')),
                            trim((string)($inv['ConstantSymbol'] ?? '')),
                        ]);
                        $invId = (int)$pdo->lastInsertId();
                        $this->insertInvoiceItems($pdo, $invId, $vatItems, $vatByCode, $desc);
                    }
                }
                if (!$dryRun) $pdo->commit();
            } catch (\Throwable $e) {
                if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $log[] = sprintf('Credit notes: %d new, %d exist', $stats['credit_notes_new'], $stats['credit_notes_exist']);
        }

        // ── Purchases ─────────────────────────────────────────────────────────
        if ($runPurchases) {
            $purchases = $this->filterYears($this->fetchAll('ReceivedInvoices', $token, 'DocumentNumber', $dateFilter), $years);
            $log[] = 'Purchases fetched: ' . count($purchases);

            if (!$dryRun) $pdo->beginTransaction();
            try {
                foreach ($purchases as $inv) {
                    $idId = (int)($inv['Id'] ?? 0);
                    $num  = trim((string)($inv['DocumentNumber'] ?? ''));

                    if ($idId > 0) {
                        $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND idoklad_id=? LIMIT 1");
                        $st->execute([$supplierId, $idId]);
                        if ($st->fetchColumn()) { $stats['purchases_exist']++; continue; }
                    }
                    if ($num !== '') {
                        $st = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND invoice_number=? LIMIT 1");
                        $st->execute([$supplierId, $num]);
                        if ($st->fetchColumn()) { $stats['purchases_exist']++; continue; }
                    }

                    $addr      = $inv['SupplierAddress'] ?? [];
                    $idocId    = (int)($addr['ContactId'] ?? 0);
                    $clientId2 = $this->upsertClient($pdo, $supplierId, $countryId, $currencyId, $addr, $idocId, $clientCache, $stats, $dryRun);

                    [$iDate, $tDate, $dDate, $paidAt, $status] = $this->parseDates($inv);
                    $prices   = $inv['Prices'] ?? [];
                    $vatItems = $this->parseVatItems($prices, $vatRateClosureByPercent);
                    $desc     = $this->itemDesc($inv, $num);

                    $stats['purchases_new']++;
                    if (!$dryRun) {
                        $totalBase = array_sum(array_column($vatItems, 'base'));
                        $totalVat  = array_sum(array_column($vatItems, 'vat'));
                        $totalWith = array_sum(array_column($vatItems, 'tot'));
                        $st = $pdo->prepare("INSERT INTO purchase_invoices (supplier_id,idoklad_id,client_id,invoice_number,status,issue_date,taxing_date,due_date,paid_at,total_without_vat,total_vat,total_with_vat,currency_id,description,variable_symbol,constant_symbol) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $st->execute([
                            $supplierId, $idId ?: null, $clientId2 ?: null, $num,
                            $status, $iDate, $tDate, $dDate, $paidAt,
                            $totalBase, $totalVat, $totalWith,
                            $currencyId, $desc,
                            trim((string)($inv['VariableSymbol'] ?? '')),
                            trim((string)($inv['ConstantSymbol'] ?? '')),
                        ]);
                    }
                }
                if (!$dryRun) $pdo->commit();
            } catch (\Throwable $e) {
                if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $log[] = sprintf('Purchases: %d new, %d exist', $stats['purchases_new'], $stats['purchases_exist']);
        }

        return ['stats' => $stats, 'log' => $log];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getToken(string $clientId, string $secret): string
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'scope'         => 'idoklad_api',
                'client_id'     => $clientId,
                'client_secret' => $secret,
            ]),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new \RuntimeException("HTTP $code: " . substr((string)$body, 0, 200));
        $data = json_decode((string)$body, true);
        if (empty($data['access_token'])) throw new \RuntimeException('Chybí access_token.');
        return $data['access_token'];
    }

    private function fetchAll(string $endpoint, string $token, string $sortField = 'DocumentNumber', ?string $filter = null): array
    {
        $page = 1;
        $all  = [];
        do {
            $params = ['pageSize' => self::PAGE_SIZE, 'page' => $page, 'sort' => "$sortField~asc"];
            if ($filter !== null) {
                $params['filter'] = $filter;
            }
            $url = self::API_BASE . '/' . $endpoint . '?' . http_build_query($params);
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
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
        return array_values(array_filter(
            $items,
            fn($i) => in_array((int)substr((string)($i['DateOfIssue'] ?? ''), 0, 4), $years, true),
        ));
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

    /** @param callable(float):string $vrc maps vat % → code */
    private function parseVatItems(array $prices, callable $vrc): array
    {
        $items = [];
        foreach ($prices['VatRateSummary'] ?? [] as $s) {
            $base = (float)($s['TotalWithoutVat'] ?? 0);
            $vat  = (float)($s['TotalVat']        ?? 0);
            $tot  = (float)($s['TotalWithVat']    ?? 0);
            $rate = (float)($s['VatRate']         ?? 0);
            if ($base == 0.0 && $vat == 0.0) continue;
            $items[] = ['rate' => $rate, 'code' => $vrc($rate), 'base' => $base, 'vat' => $vat, 'tot' => $tot];
        }
        if (empty($items)) {
            $base = (float)($prices['TotalWithoutVat'] ?? 0);
            $vat  = (float)($prices['TotalVat']        ?? 0);
            $tot  = (float)($prices['TotalWithVat']    ?? 0);
            $rate = ($base != 0.0 && $vat != 0.0) ? (float)round($vat / $base * 100) : 21.0;
            $items[] = ['rate' => $rate, 'code' => $vrc($rate), 'base' => $base, 'vat' => $vat, 'tot' => $tot];
        }
        return $items;
    }

    private function itemDesc(array $inv, string $default): string
    {
        $desc = trim((string)($inv['Description'] ?? ''));
        foreach ($inv['Items'] ?? [] as $it) {
            if ($it['IsTaxMovement'] ?? false) {
                $n = trim((string)preg_replace('/\s+/', ' ', $it['Name'] ?? ''));
                if ($n !== '') return $n;
            }
        }
        return $desc !== '' ? $desc : $default;
    }

    private function insertInvoiceItems(PDO $pdo, int $id, array $vatItems, array $vatByCode, string $desc): void
    {
        $st = $pdo->prepare(
            "INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,?,1.000,'ks',?,?,?,?,?,?,?)"
        );
        $multi = count($vatItems) > 1;
        foreach ($vatItems as $idx => $s) {
            $itemDesc = ($multi && $s['code'] === 'CZ-0') ? 'Místní poplatek za pobyt' : $desc;
            $st->execute([$id, $itemDesc, $s['base'], $vatByCode[$s['code']]['id'], $s['rate'], $s['base'], $s['vat'], $s['tot'], $idx]);
        }
    }

    private function upsertClient(PDO $pdo, int $supplierId, int $countryId, int $currencyId, array $addr, int $idId, array &$cache, array &$stats, bool $dryRun): int
    {
        if ($idId > 0 && isset($cache[$idId])) return $cache[$idId];
        $cn = trim($addr['CompanyName'] ?? trim(($addr['FirstName'] ?? '') . ' ' . ($addr['Surname'] ?? '')));
        if ($cn === '') $cn = 'Neznámý';
        $ic = trim($addr['IdentificationNumber'] ?? '');
        if ($ic !== '') {
            $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND ic=? LIMIT 1");
            $st->execute([$supplierId, $ic]);
        } else {
            $st = $pdo->prepare("SELECT id FROM clients WHERE supplier_id=? AND company_name=? AND (ic IS NULL OR ic='') LIMIT 1");
            $st->execute([$supplierId, $cn]);
        }
        $found = $st->fetchColumn();
        if ($found) {
            $stats['clients_exist']++;
            if ($idId > 0) $cache[$idId] = (int)$found;
            return (int)$found;
        }
        $stats['clients_new']++;
        if ($dryRun) {
            if ($idId > 0) $cache[$idId] = 0;
            return 0;
        }
        $st = $pdo->prepare("INSERT INTO clients (supplier_id,company_name,ic,dic,street,city,zip,country_id,main_email,language,currency_default_id) VALUES (?,?,?,?,?,?,?,?,'','cs',?)");
        $st->execute([
            $supplierId, $cn,
            $ic ?: null,
            trim($addr['VatIdentificationNumber'] ?? '') ?: null,
            trim($addr['Street'] ?? ''), trim($addr['City'] ?? ''), trim($addr['PostalCode'] ?? ''),
            $countryId, $currencyId,
        ]);
        $newId = (int)$pdo->lastInsertId();
        if ($idId > 0) $cache[$idId] = $newId;
        return $newId;
    }
}
