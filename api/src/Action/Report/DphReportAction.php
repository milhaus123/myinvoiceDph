<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/reports/dph
 *
 * Combined DPH (VAT) report for Czech tax.
 * Combines issued invoices (výstupní DPH) and received invoices (vstupní DPH)
 * grouped by VAT rate (21%, 15%, 10%) for a given period.
 *
 * Query params:
 *   - year int          (required, e.g. 2026)
 *   - month int         (optional, 1-12; if omitted, whole year)
 *   - date_from string  (optional, YYYY-MM-DD, overrides year/month)
 *   - date_to string    (optional, YYYY-MM-DD, overrides year/month)
 *   - format string     (optional, "json" | "csv", default "json")
 */
final class DphReportAction
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepo,
        private readonly PurchaseInvoiceRepository $purchaseInvoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        // Resolve period
        [$dateFrom, $dateTo] = $this->resolvePeriod($q);

        // Fetch VAT summaries
        $issuedSummary = $this->invoiceRepo->getVatSummary($dateFrom, $dateTo, $supplierId);
        $receivedSummary = $this->purchaseInvoiceRepo->getVatSummary($dateFrom, $dateTo, $supplierId);

        // Build report
        $report = $this->buildReport($issuedSummary, $receivedSummary, $dateFrom, $dateTo);

        // CSV export
        if (($q['format'] ?? '') === 'csv') {
            return $this->writeCsv($response, $report);
        }

        return Json::ok($response, $report);
    }

    /**
     * @param array<string, mixed> $q
     * @return array{string, string} [dateFrom, dateTo]
     */
    private function resolvePeriod(array $q): array
    {
        if (!empty($q['date_from']) && !empty($q['date_to'])) {
            return [
                (string) $q['date_from'],
                (string) $q['date_to'],
            ];
        }

        $year = (int) ($q['year'] ?? date('Y'));
        $month = isset($q['month']) ? (int) $q['month'] : null;

        if ($month !== null && $month >= 1 && $month <= 12) {
            $dateFrom = sprintf('%04d-%02d-01', $year, $month);
            $dateTo = sprintf('%04d-%02d-31', $year, $month);
            // Last day of month
            $dateTo = date('Y-m-t', strtotime($dateTo));
        } else {
            $dateFrom = sprintf('%04d-01-01', $year);
            $dateTo = sprintf('%04d-12-31', $year);
        }

        return [$dateFrom, $dateTo];
    }

    /**
     * @param array<int, array{rate: float, base: float, vat: float}> $issued
     * @param array<int, array{rate: float, base: float, vat: float}> $received
     * @return array<string, mixed>
     */
    private function buildReport(array $issued, array $received, string $dateFrom, string $dateTo): array
    {
        // Czech DPH rates in descending order
        $dphRates = [21.0, 15.0, 10.0];

        $issuedByRate = $this->indexByRate($issued);
        $receivedByRate = $this->indexByRate($received);

        $issuedRows = [];
        $receivedRows = [];
        $totalOutputVat = 0.0;
        $totalInputVat = 0.0;

        foreach ($dphRates as $rate) {
            $iss = $issuedByRate[$rate] ?? ['base' => 0.0, 'vat' => 0.0];
            $rec = $receivedByRate[$rate] ?? ['base' => 0.0, 'vat' => 0.0];

            $issuedRows[] = [
                'rate' => $rate,
                'zaklad' => $iss['base'],
                'dph' => $iss['vat'],
            ];
            $receivedRows[] = [
                'rate' => $rate,
                'zaklad' => $rec['base'],
                'dph' => $rec['vat'],
            ];

            $totalOutputVat += $iss['vat'];
            $totalInputVat += $rec['vat'];
        }

        return [
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'issued' => [
                'label' => 'Výstupní DPH (vydané faktury)',
                'by_rate' => $issuedRows,
            ],
            'received' => [
                'label' => 'Vstupní DPH (přijaté faktury)',
                'by_rate' => $receivedRows,
            ],
            'totals' => [
                'output_vat' => round($totalOutputVat, 2),
                'input_vat' => round($totalInputVat, 2),
                'delta' => round($totalOutputVat - $totalInputVat, 2),
            ],
        ];
    }

    /**
     * @param array<int, array{rate: float, base: float, vat: float}> $items
     * @return array<float, array{base: float, vat: float}>
     */
    private function indexByRate(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[(float) $item['rate']] = [
                'base' => (float) $item['base'],
                'vat' => (float) $item['vat'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeCsv(Response $response, array $report): Response
    {
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)

        $period = $report['period'];
        fputcsv($fp, ['DPH Report — období: ' . $period['date_from'] . ' až ' . $period['date_to']], ';', '"', '\\');
        fputcsv($fp, [], ';', '"', '\\');

        // Header row
        fputcsv($fp, [
            'Typ', 'Sazba DPH %', 'Základ (Kč)', 'DPH (Kč)',
        ], ';', '"', '\\');

        // Issued (výstupní)
        fputcsv($fp, ['Výstupní DPH — vydané faktury'], ';', '"', '\\');
        foreach ($report['issued']['by_rate'] as $row) {
            fputcsv($fp, [
                '',
                (string) $row['rate'],
                number_format((float) $row['zaklad'], 2, '.', ''),
                number_format((float) $row['dph'], 2, '.', ''),
            ], ';', '"', '\\');
        }

        fputcsv($fp, [], ';', '"', '\\');

        // Received (vstupní)
        fputcsv($fp, ['Vstupní DPH — přijaté faktury'], ';', '"', '\\');
        foreach ($report['received']['by_rate'] as $row) {
            fputcsv($fp, [
                '',
                (string) $row['rate'],
                number_format((float) $row['zaklad'], 2, '.', ''),
                number_format((float) $row['dph'], 2, '.', ''),
            ], ';', '"', '\\');
        }

        fputcsv($fp, [], ';', '"', '\\');

        // Totals
        fputcsv($fp, ['Souhrn'], ';', '"', '\\');
        fputcsv($fp, ['Výstupní DPH celkem', '', '', number_format((float) $report['totals']['output_vat'], 2, '.', '')], ';', '"', '\\');
        fputcsv($fp, ['Vstupní DPH celkem', '', '', number_format((float) $report['totals']['input_vat'], 2, '.', '')], ';', '"', '\\');
        fputcsv($fp, ['Rozdíl (výstupní − vstupní)', '', '', number_format((float) $report['totals']['delta'], 2, '.', '')], ';', '"', '\\');

        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);

        $filename = 'dph-report-' . $period['date_from'] . '_' . $period['date_to'] . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
