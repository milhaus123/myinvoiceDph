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
 * GET /api/reports/dphdp3
 *
 * DPHDP3 XML export — měsíční přiznání k DPH, sekce I (výstupní) a II (vstupní).
 * Formát pro EPO (Elektronické podání orgánům veřejné moci).
 *
 * Query params:
 *   - year   int  (required, e.g. 2026)
 *   - month  int  (required, 1-12)
 *   - format string (xml | json, default xml)
 */
final class DphPriznaniAction
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

        // Resolve period
        [$dateFrom, $dateTo, $year, $month] = $this->resolvePeriod($q);

        // Fetch our supplier info
        $ourInfo = $this->getOurSupplierInfo($supplierId);

        // Fetch VAT summaries
        $issuedVat = $this->invoiceRepo->getVatSummary($dateFrom, $dateTo, $supplierId);
        $receivedVat = $this->purchaseInvoiceRepo->getVatSummary($dateFrom, $dateTo, $supplierId);

        // JSON debug / machine-readable mode
        if (($q['format'] ?? '') === 'json') {
            return $this->jsonResponse($response, $issuedVat, $receivedVat, $ourInfo, $dateFrom, $dateTo, $year, $month);
        }

        // Build DPHDP3 XML
        $xml = $this->buildXml($issuedVat, $receivedVat, $ourInfo, $year, $month);

        $filename = 'DPHDP3_' . $year . sprintf('%02d', $month) . '.xml';
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @param array<string, mixed> $q
     * @return array{string, string, int, int}
     */
    private function resolvePeriod(array $q): array
    {
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));

        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo = date('Y-m-t', strtotime($dateFrom));

        return [$dateFrom, $dateTo, $year, $month];
    }

    /**
     * @return array{dic: string, ic: string|null, company_name: string, display_name: string|null,
     *   street: string, city: string, zip: string, country_iso: string}
     */
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
     * @param array<int, array{rate: float, base: float, vat: float}> $issuedVat
     * @param array<int, array{rate: float, base: float, vat: float}> $receivedVat
     * @param array{dic: string, ic: string|null, company_name: string, display_name: string|null,
     *   street: string, city: string, zip: string, country_iso: string} $ourInfo
     */
    private function jsonResponse(
        Response $response,
        array $issuedVat,
        array $receivedVat,
        array $ourInfo,
        string $dateFrom,
        string $dateTo,
        int $year,
        int $month,
    ): Response {
        $body = json_encode([
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'year' => $year, 'month' => $month],
            'submitter' => $ourInfo,
            'issued' => $issuedVat,
            'received' => $receivedVat,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * Index VAT summary by rate.
     *
     * @param array<int, array{rate: float, base: float, vat: float}> $items
     * @return array<float, array{base: float, vat: float}>
     */
    private function indexByRate(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $r = (float) $item['rate'];
            if (!isset($out[$r])) {
                $out[$r] = ['base' => 0.0, 'vat' => 0.0];
            }
            $out[$r]['base'] += (float) $item['base'];
            $out[$r]['vat'] += (float) $item['vat'];
        }
        return $out;
    }

    /**
     * Build the complete DPHDP3 XML document.
     *
     * @param array<int, array{rate: float, base: float, vat: float}> $issuedVat
     * @param array<int, array{rate: float, base: float, vat: float}> $receivedVat
     * @param array{dic: string, ic: string|null, company_name: string, display_name: string|null,
     *   street: string, city: string, zip: string, country_iso: string} $ourInfo
     */
    private function buildXml(array $issuedVat, array $receivedVat, array $ourInfo, int $year, int $month): string
    {
        $ourDic = $this->normalizeDic($ourInfo['dic']);

        // Index VAT by rate
        $issuedByRate = $this->indexByRate($issuedVat);
        $receivedByRate = $this->indexByRate($receivedVat);

        // Extract rates with 2-decimal rounding
        $out21 = $issuedByRate[21.0] ?? ['base' => 0.0, 'vat' => 0.0];
        $out15 = $issuedByRate[15.0] ?? ['base' => 0.0, 'vat' => 0.0];
        $out10 = $issuedByRate[10.0] ?? ['base' => 0.0, 'vat' => 0.0];
        $out0  = $issuedByRate[0.0]  ?? ['base' => 0.0, 'vat' => 0.0];

        $in21 = $receivedByRate[21.0] ?? ['base' => 0.0, 'vat' => 0.0];
        $in15 = $receivedByRate[15.0] ?? ['base' => 0.0, 'vat' => 0.0];
        $in10 = $receivedByRate[10.0] ?? ['base' => 0.0, 'vat' => 0.0];
        $in0  = $receivedByRate[0.0]  ?? ['base' => 0.0, 'vat' => 0.0];

        // Round values
        foreach (['out21', 'out15', 'out10', 'out0', 'in21', 'in15', 'in10', 'in0'] as $key) {
            $$key = [
                'base' => round($$key['base'], 2),
                'vat' => round($$key['vat'], 2),
            ];
        }

        // Total output and input VAT
        $totalOutVat = $out21['vat'] + $out15['vat'] + $out10['vat'];
        $totalInVat  = $in21['vat'] + $in15['vat'] + $in10['vat'];
        $danSou = round($totalOutVat - $totalInVat, 2);
        $trans = $danSou > 0 ? 'A' : 'N';

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

        // DPHDP3 container
        $writer->startElement('DPHDP3');

        // ---- VetaD: Header record ----
        $writer->startElement('VetaD');
        $writer->writeAttribute('dokument', 'DP3');
        $writer->writeAttribute('k_uladis', 'DPH');
        $writer->writeAttribute('typ_platce', 'P');
        $writer->writeAttribute('rok', (string) $year);
        $writer->writeAttribute('mesic', (string) $month);
        $writer->writeAttribute('trans', $trans);
        $writer->endElement(); // VetaD

        // ---- VetaP: Submitter info ----
        $writer->startElement('VetaP');
        $writer->writeAttribute('dic', $ourDic);
        $writer->writeAttribute('stat', $submitterCountry);
        $writer->writeAttribute('ulice', $submitterStreet);
        $writer->writeAttribute('naz_obce', $submitterCity);
        $writer->writeAttribute('psc', $submitterZip);
        $writer->endElement(); // VetaP

        // ---- Veta1: Output VAT (I. Zdanitelná plnění) ----
        $writer->startElement('Veta1');
        // 21% rate
        $writer->writeAttribute('dan23', $this->fmt($out21['vat']));
        $writer->writeAttribute('obrat23', $this->fmt($out21['base']));
        // 15% rate
        $writer->writeAttribute('dan5', $this->fmt($out15['vat']));
        $writer->writeAttribute('obrat5', $this->fmt($out15['base']));
        // 10% rate
        $writer->writeAttribute('dan10', $this->fmt($out10['vat']));
        $writer->writeAttribute('obrat10', $this->fmt($out10['base']));
        // 0% rate (exports, etc.)
        $writer->writeAttribute('dan0', $this->fmt($out0['vat']));
        $writer->writeAttribute('obrat0', $this->fmt($out0['base']));
        $writer->endElement(); // Veta1

        // ---- Veta2: Input VAT (II. Přijatá plnění) ----
        $writer->startElement('Veta2');
        // 21% rate
        $writer->writeAttribute('dan23', $this->fmt($in21['vat']));
        $writer->writeAttribute('obrat23', $this->fmt($in21['base']));
        // 15% rate
        $writer->writeAttribute('dan5', $this->fmt($in15['vat']));
        $writer->writeAttribute('obrat5', $this->fmt($in15['base']));
        // 10% rate
        $writer->writeAttribute('dan10', $this->fmt($in10['vat']));
        $writer->writeAttribute('obrat10', $this->fmt($in10['base']));
        // 0% rate
        $writer->writeAttribute('dan0', $this->fmt($in0['vat']));
        $writer->writeAttribute('obrat0', $this->fmt($in0['base']));
        $writer->endElement(); // Veta2

        // ---- Veta3: Summary totals ----
        $writer->startElement('Veta3');
        $writer->writeAttribute('dan_sou', $this->fmt($danSou));
        $writer->writeAttribute('dan_odp', $this->fmt(max(0, -$danSou)));
        $writer->endElement(); // Veta3

        $writer->endElement(); // DPHDP3
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
