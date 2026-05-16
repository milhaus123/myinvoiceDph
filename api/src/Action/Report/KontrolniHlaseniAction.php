<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/reports/kontrolni-hlaseni
 *
 * DPHKH1 v3 XML export — kontrolní hlášení DPH, sekce B.1 (přijaté faktury).
 * Formát pro EPO (Elektronické podání orgánům veřejné moci).
 *
 * Query params:
 *   - year  int   (required, e.g. 2026)
 *   - month int   (optional, 1-12; if omitted, whole year)
 *   - format string (xml | json, default xml)
 */
final class KontrolniHlaseniAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $purchaseInvoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        // Resolve period
        [$dateFrom, $dateTo, $year, $month] = $this->resolvePeriod($q);

        // Fetch our supplier info (dic, ic, company, address)
        $ourInfo = $this->getOurSupplierInfo($supplierId);

        // Fetch purchase invoices for the period
        $invoices = $this->getPurchaseInvoices($dateFrom, $dateTo, $supplierId);

        // JSON debug mode
        if (($q['format'] ?? '') === 'json') {
            return $this->jsonResponse($response, $invoices, $ourInfo, $dateFrom, $dateTo);
        }

        // Build DPHKH1 v3 XML
        $xml = $this->buildXml($invoices, $ourInfo, $year, $month);

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="DPHKH1_B1_' . $year . ($month !== null ? sprintf('%02d', $month) : '') . '.xml"');
    }

    /**
     * @param array<string, mixed> $q
     * @return array{string, string, int, int|null}
     */
    private function resolvePeriod(array $q): array
    {
        $year = (int) ($q['year'] ?? date('Y'));
        $month = isset($q['month']) ? (int) $q['month'] : null;

        if ($month !== null && $month >= 1 && $month <= 12) {
            $dateFrom = sprintf('%04d-%02d-01', $year, $month);
            $dateTo = date('Y-m-t', strtotime($dateFrom));
        } else {
            $dateFrom = sprintf('%04d-01-01', $year);
            $dateTo = sprintf('%04d-12-31', $year);
        }

        return [$dateFrom, $dateTo, $year, $month];
    }

    /**
     * @return array{dic: string, ic: string, company_name: string, street: string, city: string, zip: string}
     */
    private function getOurSupplierInfo(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT dic, ic, company_name, street, city, zip
               FROM supplier WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'dic' => '',
                'ic' => '',
                'company_name' => '',
                'street' => '',
                'city' => '',
                'zip' => '',
            ];
        }

        return [
            'dic' => (string) ($row['dic'] ?? ''),
            'ic' => (string) ($row['ic'] ?? ''),
            'company_name' => (string) ($row['company_name'] ?? ''),
            'street' => (string) ($row['street'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'zip' => (string) ($row['zip'] ?? ''),
        ];
    }

    /**
     * Fetch purchase invoices for DPHKH1 B.1 section.
     * @return array<int, array{
     *   varsymbol: string,
     *   invoice_number: string,
     *   issue_date: string,
     *   tax_date: string|null,
     *   due_date: string,
     *   received_at: string,
     *   supplier_dic: string,
     *   supplier_ic: string,
     *   supplier_company_name: string,
     *   supplier_street: string,
     *   supplier_city: string,
     *   supplier_zip: string,
     *   reverse_charge: bool,
     *   items: array<int, array{rate: float, base: float, vat: float}>,
     *   total_without_vat: float,
     *   total_vat: float
     * }>
     */
    private function getPurchaseInvoices(string $dateFrom, string $dateTo, int $supplierId): array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT pi.id, pi.varsymbol, pi.invoice_number,
                    pi.issue_date, pi.tax_date, pi.due_date, pi.received_at,
                    pi.reverse_charge, pi.total_without_vat, pi.total_vat,
                    c.dic AS supplier_dic, c.ic AS supplier_ic,
                    c.company_name AS supplier_company_name,
                    c.street AS supplier_street,
                    c.city AS supplier_city,
                    c.zip AS supplier_zip,
                    pi.supplier_snapshot,
                    pi.own_snapshot
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.supplier_id
              WHERE pi.supplier_id = ?
                AND COALESCE(pi.tax_date, pi.issue_date) >= ?
                AND COALESCE(pi.tax_date, pi.issue_date) <= ?
                AND pi.status IN (?, ?, ?)
              ORDER BY COALESCE(pi.tax_date, pi.issue_date) ASC, pi.id ASC'
        );
        $stmt->execute([
            $supplierId,
            $dateFrom,
            $dateTo,
            'received',
            'booked',
            'paid',
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fetch items for each invoice
        $itemsStmt = $pdo->prepare(
            'SELECT vat_rate_snapshot AS rate,
                    total_without_vat AS base,
                    total_vat AS vat
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
              ORDER BY order_index ASC'
        );

        $result = [];
        foreach ($rows as $row) {
            $itemsStmt->execute([(int) $row['id']]);
            $items = $itemsStmt->fetchAll(\PDO::FETCH_ASSOC);

            $result[] = [
                'varsymbol' => (string) ($row['varsymbol'] ?? ''),
                'invoice_number' => (string) ($row['invoice_number'] ?? ''),
                'issue_date' => (string) ($row['issue_date'] ?? ''),
                'tax_date' => $row['tax_date'] !== null ? (string) $row['tax_date'] : (string) $row['issue_date'],
                'due_date' => (string) ($row['due_date'] ?? ''),
                'received_at' => (string) ($row['received_at'] ?? ''),
                'supplier_dic' => $this->normalizeDic((string) ($row['supplier_dic'] ?? '')),
                'supplier_ic' => (string) ($row['supplier_ic'] ?? ''),
                'supplier_company_name' => (string) ($row['supplier_company_name'] ?? ''),
                'supplier_street' => (string) ($row['supplier_street'] ?? ''),
                'supplier_city' => (string) ($row['supplier_city'] ?? ''),
                'supplier_zip' => (string) ($row['supplier_zip'] ?? ''),
                'reverse_charge' => (bool) $row['reverse_charge'],
                'items' => array_map(fn($i) => [
                    'rate' => (float) $i['rate'],
                    'base' => round((float) $i['base'], 2),
                    'vat' => round((float) $i['vat'], 2),
                ], $items),
                'total_without_vat' => round((float) $row['total_without_vat'], 2),
                'total_vat' => round((float) $row['total_vat'], 2),
            ];
        }

        return $result;
    }

    /**
     * Strip "CZ" prefix from DIC for DPHKH1 (expects DIC without country code).
     */
    private function normalizeDic(string $dic): string
    {
        if (str_starts_with($dic, 'CZ')) {
            return substr($dic, 2);
        }
        return $dic;
    }

    /**
     * Map VAT rate percent to DPHKH1 dan code.
     * 1=21%, 2=15%, 3=10%, 4=0%, 5=reverse charge
     */
    private function vatRateToDan(float $rate): int
    {
        return match (true) {
            $rate >= 20.5 && $rate <= 21.5 => 1,
            $rate >= 14.5 && $rate <= 15.5 => 2,
            $rate >= 9.5 && $rate <= 10.5 => 3,
            $rate >= -0.5 && $rate <= 0.5 => 4,
            default => 4,
        };
    }

    /**
     * @param array<int, array{
     *   varsymbol: string, invoice_number: string, issue_date: string, tax_date: string,
     *   due_date: string, received_at: string, supplier_dic: string, supplier_ic: string,
     *   supplier_company_name: string, supplier_street: string, supplier_city: string,
     *   supplier_zip: string, reverse_charge: bool,
     *   items: array<int, array{rate: float, base: float, vat: float}>, total_without_vat: float, total_vat: float
     * }> $invoices
     * @param array{dic: string, ic: string, company_name: string, street: string, city: string, zip: string} $ourInfo
     * @return array<string, mixed>
     */
    private function jsonResponse(Response $response, array $invoices, array $ourInfo, string $dateFrom, string $dateTo): Response
    {
        $data = [
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'submitter' => $ourInfo,
            'invoices' => $invoices,
        ];

        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * Build DPHKH1 v3 XML document.
     * @param array<int, array{
     *   varsymbol: string, invoice_number: string, issue_date: string, tax_date: string,
     *   due_date: string, received_at: string, supplier_dic: string, supplier_ic: string,
     *   supplier_company_name: string, supplier_street: string, supplier_city: string,
     *   supplier_zip: string, reverse_charge: bool,
     *   items: array<int, array{rate: float, base: float, vat: float}>, total_without_vat: float, total_vat: float
     * }> $invoices
     * @param array{dic: string, ic: string, company_name: string, street: string, city: string, zip: string} $ourInfo
     */
    private function buildXml(array $invoices, array $ourInfo, int $year, ?int $month): string
    {
        $ourDic = $ourInfo['dic'];
        $dicAttr = $ourDic !== '' ? 'dic="' . htmlspecialchars($ourDic, ENT_XML1) . '"' : '';

        $obd = $month !== null ? sprintf('%02d%02d', $year % 100, $month) : null;

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<dp:DPHKH1 xmlns:dp="http://info.money.cz/eet/DPHKH1/v3" verze="3.0" {$dicAttr} pz="1" op="1" oid="" nu="0" ndig="0" tel="0" dph_v="1">
  <dp:A>
    <dp:A1>{$this->xmlEsc($ourInfo['company_name'])}</dp:A1>
    <dp:A2>{$this->xmlEsc($ourInfo['street'])}</dp:A2>
    <dp:A3>{$this->xmlEsc($ourInfo['zip'])}</dp:A3>
    <dp:A4>{$this->xmlEsc($ourInfo['city'])}</dp:A4>
    <dp:A5>{$this->xmlEsc($ourInfo['dic'])}</dp:A5>
    <dp:A6>{$this->xmlEsc($ourInfo['ic'])}</dp:A6>
  </dp:A>
  <dp:B>
    <dp:B1>
XML;

        $rowNum = 1;
        foreach ($invoices as $inv) {
            $xml .= $this->buildB1Row($inv, $rowNum, $obd);
            $rowNum++;
        }

        // B.1 totals row (celkem)
        $xml .= $this->buildB1Celkem($invoices);

        $xml .= <<<XML
    </dp:B1>
  </dp:B>
</dp:DPHKH1>
XML;

        return $xml;
    }

    /**
     * Build a single B1Radek XML element.
     * @param array{
     *   varsymbol: string, invoice_number: string, issue_date: string, tax_date: string,
     *   due_date: string, received_at: string, supplier_dic: string, supplier_ic: string,
     *   supplier_company_name: string, supplier_street: string, supplier_city: string,
     *   supplier_zip: string, reverse_charge: bool,
     *   items: array<int, array{rate: float, base: float, vat: float}>, total_without_vat: float, total_vat: float
     * } $inv
     */
    private function buildB1Row(array $inv, int $rowNum, ?string $obd): string
    {
        $ra = $rowNum;
        $pp = '1'; // standard invoice
        $cu = $this->xmlEsc($inv['varsymbol'] ?: $inv['invoice_number']);
        $obdAttr = $obd !== null ? ' obd="' . $obd . '"' : '';
        $dppd = $inv['tax_date'];
        $dup = $inv['received_at'];
        $rdp = $inv['due_date'] ?: '';
        $fdic = $inv['supplier_dic'];
        $fn = $this->xmlEsc($inv['supplier_company_name']);
        $fb = $this->xmlEsc($inv['supplier_street']);
        $fc = $this->xmlEsc($inv['supplier_city']);
        $fp = $this->xmlEsc($inv['supplier_zip']);

        $xml = <<<XML
      <dp:B1Radek ra="{$ra}" pp="{$pp}"{$obdAttr} cu="{$cu}" dppd="{$dppd}" dup="{$dup}" rdp="{$rdp}" fdic="{$fdic}" fn="{$fn}" fb="{$fb}" fc="{$fc}" fp="{$fp}">
XML;

        // B1Zaklad per VAT rate
        // DPHKH1 rates: dan=1(21%), dan=2(15%), dan=3(10%), dan=4(0%), dan=5(RC)
        $rates = [1 => null, 2 => null, 3 => null, 4 => null];
        foreach ($inv['items'] as $item) {
            if ($inv['reverse_charge']) {
                $dan = 5;
            } else {
                $dan = $this->vatRateToDan($item['rate']);
            }
            $rates[$dan] = $item;
        }

        foreach ([1, 2, 3, 4] as $dan) {
            $item = $rates[$dan];
            if ($dan === 5) continue; // handled in 4 for RC
            $baz = $item !== null ? $this->fmt($item['base']) : '0.00';
            $dph = $item !== null ? $this->fmt($item['vat']) : '0.00';
            $xml .= <<<XML
        <dp:B1Zaklad dan="{$dan}" baz="{$baz}" dph="{$dph}/>
XML;
        }

        $xml .= "      </dp:B1Radek>\n";
        return $xml;
    }

    /**
     * Build B1Celkem summary row.
     * @param array<int, array{
     *   items: array<int, array{rate: float, base: float, vat: float}>, total_without_vat: float, total_vat: float
     * }> $invoices
     */
    private function buildB1Celkem(array $invoices): string
    {
        // Sum totals across all invoices per dan rate
        $totals = [1 => ['baz' => 0.0, 'dph' => 0.0], 2 => ['baz' => 0.0, 'dph' => 0.0], 3 => ['baz' => 0.0, 'dph' => 0.0], 4 => ['baz' => 0.0, 'dph' => 0.0]];

        foreach ($invoices as $inv) {
            foreach ($inv['items'] as $item) {
                $dan = $this->vatRateToDan($item['rate']);
                if (isset($totals[$dan])) {
                    $totals[$dan]['baz'] += $item['base'];
                    $totals[$dan]['dph'] += $item['vat'];
                }
            }
        }

        $xml = "      <dp:B1Celkem>\n";
        foreach ([1, 2, 3, 4] as $dan) {
            $baz = $this->fmt(round($totals[$dan]['baz'], 2));
            $dph = $this->fmt(round($totals[$dan]['dph'], 2));
            $xml .= <<<XML
        <dp:B1Zaklad dan="{$dan}" baz="{$baz}" dph="{$dph}"/>
XML;
        }
        $xml .= "      </dp:B1Celkem>\n";
        return $xml;
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function xmlEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
