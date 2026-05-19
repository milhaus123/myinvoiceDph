<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/reports/priznani-dani-prijmu/fyzicke-osoby
 *
 * DPFDP5 XML export — Roční přiznání k dani z příjmů fyzických osob.
 * Formát pro EPO (Elektronické podání orgánům veřejné moci).
 *
 * Query params:
 *   - year   int  (required, e.g. 2026)
 *   - format string (xml | json, default xml)
 */
final class IncomeTaxReturnFoAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly PurchaseInvoiceRepository $purchaseInvoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $year = (int) ($q['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $dateFrom = sprintf('%04d-01-01', $year);
        $dateTo = sprintf('%04d-12-31', $year);

        $ourInfo = $this->getOurSupplierInfo($supplierId);

        // Aggregate annual income and expense data
        $incomeData = $this->getAnnualIncome($dateFrom, $dateTo, $supplierId);
        $expenseData = $this->getAnnualExpenses($dateFrom, $dateTo, $supplierId);
        $vatData = $this->getAnnualVatData($dateFrom, $dateTo, $supplierId);

        if (($q['format'] ?? '') === 'json') {
            return $this->jsonResponse($response, $incomeData, $expenseData, $vatData, $ourInfo, $year);
        }

        $xml = $this->buildXml($incomeData, $expenseData, $vatData, $ourInfo, $year);

        $body = json_encode([
            'xml_content' => $xml,
            'filename'    => 'MyInvoice_DanZPrijmu_FO_' . $year . '.xml',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    private function getOurSupplierInfo(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT s.dic, s.ic, s.company_name, s.display_name, s.street, s.city, s.zip,
                    s.first_name, s.last_name, s.email,
                    c.iso2 AS country_iso
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'dic' => '', 'ic' => null, 'company_name' => '', 'display_name' => null,
                'first_name' => '', 'last_name' => '', 'email' => '',
                'street' => '', 'city' => '', 'zip' => '', 'country_iso' => 'CZ',
            ];
        }

        return [
            'dic' => (string) ($row['dic'] ?? ''),
            'ic' => $row['ic'] !== null ? (string) $row['ic'] : null,
            'company_name' => (string) ($row['company_name'] ?? ''),
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'street' => (string) ($row['street'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'zip' => (string) ($row['zip'] ?? ''),
            'country_iso' => (string) ($row['country_iso'] ?? 'CZ'),
        ];
    }

    /**
     * Total revenue (sum of invoice totals with VAT, from issued invoices)
     * Maps to §7 ZD (příjmy z podnikání)
     */
    private function getAnnualIncome(string $dateFrom, string $dateTo, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ii.total_with_vat), 0) AS total_income
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.issue_date >= ?
                AND i.issue_date <= ?"
        );
        $stmt->execute([$supplierId, $dateFrom, $dateTo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_income' => (float) ($row['total_income'] ?? 0),
        ];
    }

    /**
     * Total expenses (sum of purchase invoice totals with VAT)
     * Maps to §7 ZD (výdaje)
     */
    private function getAnnualExpenses(string $dateFrom, string $dateTo, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pi.total_with_vat), 0) AS total_expenses
               FROM purchase_invoices pi
              WHERE pi.supplier_id = ?
                AND pi.status IN ('received', 'paid')
                AND pi.issue_date >= ?
                AND pi.issue_date <= ?"
        );
        $stmt->execute([$supplierId, $dateFrom, $dateTo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_expenses' => (float) ($row['total_expenses'] ?? 0),
        ];
    }

    /**
     * Annual VAT data for tax computation
     */
    private function getAnnualVatData(string $dateFrom, string $dateTo, int $supplierId): array
    {
        $issuedVat = $this->invoiceRepo->getVatSummary($dateFrom, $dateTo, $supplierId);
        $receivedVat = $this->purchaseInvoiceRepo->getVatSummary($dateFrom, $dateTo, $supplierId);

        $outVat = 0.0;
        $outBase = 0.0;
        foreach ($issuedVat as $item) {
            $outVat += (float) $item['vat'];
            $outBase += (float) $item['base'];
        }

        $inVat = 0.0;
        $inBase = 0.0;
        foreach ($receivedVat as $item) {
            $inVat += (float) $item['vat'];
            $inBase += (float) $item['base'];
        }

        return [
            'out_vat' => round($outVat, 2),
            'out_base' => round($outBase, 2),
            'in_vat' => round($inVat, 2),
            'in_base' => round($inBase, 2),
        ];
    }

    private function jsonResponse(
        Response $response,
        array $incomeData,
        array $expenseData,
        array $vatData,
        array $ourInfo,
        int $year,
    ): Response {
        $body = json_encode([
            'year' => $year,
            'submitter' => $ourInfo,
            'income' => $incomeData,
            'expenses' => $expenseData,
            'vat' => $vatData,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * Build the complete DPFDP5 XML document.
     * Focused on §7 (podnikání) with data from invoices.
     */
    private function buildXml(array $incomeData, array $expenseData, array $vatData, array $ourInfo, int $year): string
    {
        $totalIncome = round($incomeData['total_income'], 2);
        $totalExpenses = round($expenseData['total_expenses'], 2);

        // Tax base: income without VAT - expenses without VAT
        $incomeBase = round($vatData['out_base'], 2);
        $expenseBase = round($vatData['in_base'], 2);
        $taxBase = round($incomeBase - $expenseBase, 2);
        if ($taxBase < 0) {
            $taxBase = 0.0;
        }

        // Tax calculation (15% rate for tax base up to ~48x average wage, 23% above)
        $tax = 0.0;
        $avgWage = 46782.0 * 48; // approximately 2,245,536 for 2024
        if ($taxBase > 0) {
            $taxBaseLower = min($taxBase, $avgWage);
            $taxLower = round($taxBaseLower * 0.15, 2);
            $taxHigher = round(max(0, $taxBase - $avgWage) * 0.23, 2);
            $tax = round($taxLower + $taxHigher, 2);
        }

        $ourDic = $this->normalizeDic($ourInfo['dic']);
        $submitterStreet = $this->xe($ourInfo['street']);
        $submitterCity = $this->xe($ourInfo['city']);
        $submitterZip = $this->xe($ourInfo['zip']);
        $submitterCountry = $this->xe($ourInfo['country_iso']);

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        // Root: Pisemnost
        $writer->startElement('Pisemnost');
        $writer->writeAttribute('xmlns', 'http://adis.mfcr.cz/adis/eshop/intrg/flexmsg/DisObjekt');

        // DPFDP5 container
        $writer->startElement('DPFDP5');

        // ---- VetaD: Header record ----
        $writer->startElement('VetaD');
        $writer->writeAttribute('dokument', 'DPF');
        $writer->writeAttribute('k_uladis', 'DPF');
        $writer->writeAttribute('rok', (string) $year);
        $writer->writeAttribute('kod_popl', 'FO');
        $writer->writeAttribute('typ_dapdpp', 'R'); // R = řádné
        $writer->writeAttribute('dap_typ', '1'); // 1 = daňové přiznání
        $writer->writeAttribute('k_stat', $submitterCountry);
        $writer->writeAttribute('d_zjist', date('d.m.Y'));
        $writer->writeAttribute('uv_vyhl', 'A'); // auto-created
        $writer->endElement(); // VetaD

        // ---- VetaP: Submitter (taxpayer) info ----
        $writer->startElement('VetaP');
        $writer->writeAttribute('dic', $ourDic);
        $writer->writeAttribute('stat', $submitterCountry);
        $writer->writeAttribute('ulice', $submitterStreet);
        $writer->writeAttribute('naz_obce', $submitterCity);
        $writer->writeAttribute('psc', $submitterZip);
        $writer->writeAttribute('prijmeni', $this->xe($ourInfo['last_name']));
        $writer->writeAttribute('jmeno', $this->xe($ourInfo['first_name']));
        if ($ourInfo['email']) {
            $writer->writeAttribute('email', $this->xe($ourInfo['email']));
        }
        $writer->endElement(); // VetaP

        // ---- VetaP7: §7 Income from business (podnikání) ----
        $writer->startElement('VetaP7');
        $writer->writeAttribute('kc_prijmy7', $this->fmt($totalIncome));
        $writer->writeAttribute('kc_vydaje7', $this->fmt($totalExpenses));
        $writer->writeAttribute('kc_pojist7', '0');
        $writer->writeAttribute('da_zakl7', $this->fmt(max(0, $incomeBase - $expenseBase)));
        $writer->endElement(); // VetaP7

        // ---- VetaP10: Tax computation ----
        $writer->startElement('VetaP10');
        $writer->writeAttribute('da_zakl_dan', $this->fmt($taxBase));
        $writer->writeAttribute('dan', $this->fmt($tax));
        $writer->writeAttribute('uhruh_dan', '0');
        $writer->writeAttribute('dan_splátky', '0');
        $writer->endElement(); // VetaP10

        $writer->endElement(); // DPFDP5
        $writer->endElement(); // Pisemnost
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function fmt(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function normalizeDic(string $dic): string
    {
        $dic = strtoupper(trim($dic));
        if (str_starts_with($dic, 'CZ')) {
            return substr($dic, 2);
        }
        return $dic;
    }
}
