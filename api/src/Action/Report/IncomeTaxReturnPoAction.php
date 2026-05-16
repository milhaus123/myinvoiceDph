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
 * GET /api/reports/priznani-dani-prijmu/pravnicke-osoby
 *
 * DPPDP9 XML export — Roční přiznání k dani z příjmů právnických osob.
 * Formát pro EPO (Elektronické podání orgánům veřejné moci).
 *
 * Query params:
 *   - year   int  (required, e.g. 2026)
 *   - format string (xml | json, default xml)
 */
final class IncomeTaxReturnPoAction
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

        $incomeData = $this->getAnnualIncome($dateFrom, $dateTo, $supplierId);
        $expenseData = $this->getAnnualExpenses($dateFrom, $dateTo, $supplierId);
        $vatData = $this->getAnnualVatData($dateFrom, $dateTo, $supplierId);

        if (($q['format'] ?? '') === 'json') {
            return $this->jsonResponse($response, $incomeData, $expenseData, $vatData, $ourInfo, $year);
        }

        $xml = $this->buildXml($incomeData, $expenseData, $vatData, $ourInfo, $year);

        $filename = 'DPPDP9_' . $year . '.xml';
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function getOurSupplierInfo(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT s.dic, s.ic, s.company_name, s.display_name, s.street, s.city, s.zip,
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
                'street' => '', 'city' => '', 'zip' => '', 'country_iso' => 'CZ',
            ];
        }

        return [
            'dic' => (string) ($row['dic'] ?? ''),
            'ic' => $row['ic'] !== null ? (string) $row['ic'] : null,
            'company_name' => (string) ($row['company_name'] ?? ''),
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'street' => (string) ($row['street'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'zip' => (string) ($row['zip'] ?? ''),
            'country_iso' => (string) ($row['country_iso'] ?? 'CZ'),
        ];
    }

    /**
     * Total revenue from issued invoices
     */
    private function getAnnualIncome(string $dateFrom, string $dateTo, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ii.total_with_vat), 0) AS total_income
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status = 'issued'
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
     * Total expenses from purchase invoices
     */
    private function getAnnualExpenses(string $dateFrom, string $dateTo, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pi.total_with_vat), 0) AS total_expenses
               FROM purchase_invoices pi
              WHERE pi.supplier_id = ?
                AND pi.status IN ('received', 'paid')
                AND pi.invoice_date >= ?
                AND pi.invoice_date <= ?"
        );
        $stmt->execute([$supplierId, $dateFrom, $dateTo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_expenses' => (float) ($row['total_expenses'] ?? 0),
        ];
    }

    /**
     * Annual VAT data
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
     * Build the complete DPPDP9 XML document for companies.
     */
    private function buildXml(array $incomeData, array $expenseData, array $vatData, array $ourInfo, int $year): string
    {
        $totalIncome = round($incomeData['total_income'], 2);
        $totalExpenses = round($expenseData['total_expenses'], 2);

        // Tax base: revenue without VAT - expenses without VAT
        $incomeBase = round($vatData['out_base'], 2);
        $expenseBase = round($vatData['in_base'], 2);
        $taxBase = round($incomeBase - $expenseBase, 2);
        if ($taxBase < 0) {
            $taxBase = 0.0;
        }

        // Corporate tax rate: 19% (flat rate in Czech Republic)
        $tax = round($taxBase * 0.19, 2);

        $ourDic = $this->normalizeDic($ourInfo['dic']);
        $submitterStreet = $this->xe($ourInfo['street']);
        $submitterCity = $this->xe($ourInfo['city']);
        $submitterZip = $this->xe($ourInfo['zip']);
        $submitterCountry = $this->xe($ourInfo['country_iso']);
        $companyName = $this->xe($ourInfo['company_name']);

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        // Root: Pisemnost
        $writer->startElement('Pisemnost');
        $writer->writeAttribute('xmlns', 'http://adis.mfcr.cz/adis/eshop/intrg/flexmsg/DisObjekt');

        // DPPDP9 container
        $writer->startElement('DPPDP9');

        // ---- VetaD: Header record ----
        $writer->startElement('VetaD');
        $writer->writeAttribute('dokument', 'DPP');
        $writer->writeAttribute('k_uladis', 'DPP');
        $writer->writeAttribute('typ_platce', 'P');
        $writer->writeAttribute('rok', (string) $year);
        $writer->writeAttribute('typ_dapdpp', 'R'); // R = řádné
        $writer->writeAttribute('dapdpp_forma', '1'); // 1 = daňové přiznání
        $writer->writeAttribute('k_stat', $submitterCountry);
        $writer->writeAttribute('d_zjist', date('d.m.Y'));
        $writer->writeAttribute('d_uv', date('d.m.Y'));
        $writer->writeAttribute('uz_dle_mus', 'A'); // účetní závěrka dle magyar standards = no
        $writer->writeAttribute('uc_zav', 'A'); // účetní závěrka = yes
        $writer->writeAttribute('neschval_uz', 'N'); // neschvalování účetní závěrky
        $writer->writeAttribute('neaudit_uz', 'N'); // neauditovaná účetní závěrka
        $writer->writeAttribute('pril_uz', 'N'); // bez přílohy k účetní závěrce
        $writer->writeAttribute('typ_zo', 'R'); // R = řádné
        $writer->endElement(); // VetaD

        // ---- VetaP: Submitter (company) info ----
        $writer->startElement('VetaP');
        $writer->writeAttribute('dic', $ourDic);
        $writer->writeAttribute('stat', $submitterCountry);
        $writer->writeAttribute('ulice', $submitterStreet);
        $writer->writeAttribute('naz_obce', $submitterCity);
        $writer->writeAttribute('psc', $submitterZip);
        $writer->writeAttribute('zkrobchjm', $companyName); // zkrácený obchodní název
        $writer->endElement(); // VetaP

        // ---- VetaO: II.oddíl - Income and expenses ----
        $writer->startElement('VetaO');
        // kc_ii10_10 = total revenues (tržby)
        $writer->writeAttribute('kc_ii10_10', $this->fmt($totalIncome));
        // kc_ii20_20 = costs/expenses
        $writer->writeAttribute('kc_ii20_20', $this->fmt($totalExpenses));
        // kc_ii30_20 = financial income (we don't have)
        $writer->writeAttribute('kc_ii30_20', '0');
        // kc_ii40_30 = financial expenses (we don't have)
        $writer->writeAttribute('kc_ii40_30', '0');
        // kc_ii50_40 = extraordinary income (we don't have)
        $writer->writeAttribute('kc_ii50_40', '0');
        // kc_ii60_50 = extraordinary expenses (we don't have)
        $writer->writeAttribute('kc_ii60_50', '0');
        // Income tax base
        $writer->writeAttribute('kc_ii110_100', $this->fmt($taxBase));
        $writer->endElement(); // VetaO

        // ---- VetaT: Tax computation ----
        $writer->startElement('VetaT');
        $writer->writeAttribute('dan', $this->fmt($tax));
        $writer->writeAttribute('dan_splátky', '0');
        $writer->writeAttribute('uhruh_dan', '0');
        $writer->endElement(); // VetaT

        $writer->endElement(); // DPPDP9
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
