<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Repository\EetSessionRepository;
use MyInvoice\Infrastructure\Config\Config;

/**
 * EET Service (Elektronická evidence tržeb).
 *
 * Handles:
 * - UUID generation per receipt
 * - Building EET XML (EET 3.0 format, ready for EET 2.0 update)
 * - Sending to EET server (with mock/test endpoint support)
 * - Processing FIK response or error handling
 * - PKP/BKP cryptographic signing (placeholder for certificate integration)
 *
 * EET 2.0 Note: New requirements launching January 2027.
 * This implementation uses EET 3.0 format which is compatible.
 * Certificate requirements and XML schema updates will be needed for EET 2.0.
 */
final class EetService
{
    // EET Server endpoints
    private const EET_URL_PRODUCTION = 'https://prod.eet.cz:443/eet/services/EETServiceSOAP/v3';
    private const EET_URL_SANDBOX    = 'https://pg.eet.cz:443/eet/services/EETServiceSOAP/v3';
    private const EET_URL_TEST       = 'https://test.eet.cz:443/eet/services/EETServiceSOAP/v3';

    // EET evidence modes (evidence = 1 means basic receipt)
    private const MODE_STANDARD      = 0;
    private const MODE_SIMPLIFIED    = 1;
    private const MODE_TRAVEL_VOUCHER = 2;
    private const MODE_VIS          = 3;

    public function __construct(
        private readonly EetSessionRepository $repo,
        private readonly Config $config,
    ) {}

    /**
     * Submit a receipt to EET.
     *
     * @param array $invoice Invoice data with total, currency, issue_date, etc.
     * @param array $supplier Supplier data with dic, name, etc.
     * @param array $opts Optional overrides: payment_mode, eet_mode, evidence_mode, sale_date
     * @return array EET session data including UUID and initial status
     */
    public function submitReceipt(array $invoice, array $supplier, array $opts = []): array
    {
        $supplierId = (int) ($invoice['supplier_id'] ?? $supplier['id'] ?? 0);
        $dic = (string) ($supplier['dic'] ?? '');

        if (empty($dic)) {
            throw new \InvalidArgumentException('Supplier DIC is required for EET submission.');
        }

        // Generate UUID v4 for this receipt
        $uuid = self::generateUuidV4();

        // Parse sale date (when the payment was received)
        $saleDate = $opts['sale_date']
            ?? $invoice['paid_at']
            ?? $invoice['tax_date']
            ?? $invoice['issue_date']
            ?? date('Y-m-d');
        $saleDateTime = new \DateTime($saleDate);

        // Get total in CZK (EET requires CZK)
        $total = (float) ($invoice['total_with_vat'] ?? $invoice['total'] ?? 0);
        if ($total <= 0) {
            throw new \InvalidArgumentException('EET receipt total must be greater than 0.');
        }

        // Payment mode
        $paymentMode = $opts['payment_mode'] ?? $invoice['payment_type'] ?? 'cash';
        $eetMode = (int) ($opts['eet_mode'] ?? self::MODE_STANDARD);
        $evidenceMode = (int) ($opts['evidence_mode'] ?? 1);

        // Create EET session record
        $sessionId = $this->repo->create([
            'invoice_id'    => (int) $invoice['id'],
            'uuid'          => $uuid,
            'sale_date'     => $saleDateTime->format('Y-m-d H:i:s'),
            'total'         => $total,
            'payment_mode'  => $paymentMode,
            'eet_mode'      => $eetMode,
            'dic'           => $dic,
            'evidence_mode' => $evidenceMode,
            'status'        => 'pending',
            'supplier_id'   => $supplierId,
        ]);

        // Generate XML
        $xml = $this->buildXml($uuid, $saleDateTime, $total, $dic, $supplier, $paymentMode, $eetMode, $evidenceMode);

        // Sign the request (PKP/BKP - placeholder for certificate integration)
        $pkp = $this->generatePkp($xml);
        $bkp = $this->generateBkp($pkp);

        // Update session with PKP/BKP
        $this->repo->updateResponse($sessionId, [
            'pkp' => $pkp,
            'bkp' => $bkp,
        ]);

        // Send to EET server
        $response = $this->sendToServer($xml, $uuid);

        if ($response['success']) {
            $this->repo->updateResponse($sessionId, [
                'fik'         => $response['fik'] ?? null,
                'status'      => 'confirmed',
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $this->repo->updateResponse($sessionId, [
                'status'       => 'error',
                'error_code'   => $response['error_code'] ?? 'UNKNOWN',
                'error_message' => $response['error_message'] ?? 'Unknown error',
            ]);
        }

        return $this->repo->findByUuid($uuid);
    }

    /**
     * Check status of an EET session by UUID.
     */
    public function checkStatus(string $uuid): ?array
    {
        return $this->repo->findByUuid($uuid);
    }

    /**
     * Get EET sessions for an invoice.
     */
    public function getSessionsForInvoice(int $invoiceId): array
    {
        return $this->repo->findByInvoiceId($invoiceId);
    }

    /**
     * Build EET XML for EET 3.0 format.
     *
     * EET 2.0 Note: The XML schema may change in 2027.
     * This EET 3.0 format is the current standard.
     */
    private function buildXml(
        string $uuid,
        \DateTime $saleDate,
        float $total,
        string $dic,
        array $supplier,
        string $paymentMode,
        int $eetMode,
        int $evidenceMode
    ): string {
        $now = (new \DateTime())->format('Y-m-d\TH:i:sP');

        // Format sale date as required by EET
        $celkTrzba = number_format($total, 2, '.', '');
        $rezim = (string) $eetMode;

        // Payment mode mapping to EET codes
        $platba = match ($paymentMode) {
            'cash'    => 'CZK', // cash payment in CZK
            'card'    => 'TCH', // card payment (pokladní typ)
            'transfer' => 'BTN', // bank transfer
            default   => 'TCH',
        };

        // Build the EET XML (SOAP envelope)
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <soap:Header/>
  <soap:Body>
    <TrzbaDataRozhrani xmlns="http://fs.mfcr.cz/eet/xsd/eetsoap comunikace_3">
      <UUID zpravy="{$uuid}"/>
      <DatTrzby>{$saleDate->format('Y-m-d\TH:i:s')}</DatTrzby>
      <CelkTrzba>{$celkTrzba}</CelkTrzba>
      <Rezim>{$rezim}</Rezim>
      <KontrolniKody>
        <PKP kodovani="base16" algoritmus="SHA256" typVyr="CZ" overeni="false">__PKP_PLACEHOLDER__</PKP>
        <BKP kodovani="base16" algoritmus="SHA256" typVyr="CZ" overeni="false">__BKP_PLACEHOLDER__</BKP>
      </KontrolniKody>
      <Reshlaseni>
        < DIC="{$dic}"
              Nazev="{$this->escapeXml($supplier['name'] ?? 'Unknown')}"
              Adresa="{$this->escapeXml($supplier['address'] ?? '')}"
        />
      </Reshlaseni>
      <UrcenoPolRezim>{$evidenceMode}</UrcenoPolRezim>
    </TrzbaDataRozhrani>
  </soap:Body>
</soap:Envelope>
XML;

        return $xml;
    }

    /**
     * Send XML to EET server.
     *
     * Uses test/sandbox endpoint for development.
     * In production, uses the production EET endpoint.
     *
     * @return array Response with success flag, fik, and error details
     */
    private function sendToServer(string $xml, string $uuid): array
    {
        // Determine which EET endpoint to use
        $env = $this->config->get('eet.environment', 'test');

        $url = match ($env) {
            'production' => self::EET_URL_PRODUCTION,
            'sandbox'    => self::EET_URL_SANDBOX,
            default      => self::EET_URL_TEST,
        };

        // For development/testing: return a mock response
        // This allows testing without a valid certificate
        if ($env === 'mock' || $env === 'development') {
            return $this->mockResponse($uuid);
        }

        try {
            // Replace PKP/BKP placeholders (in real implementation, these would be actual signatures)
            // For now, use mock values since we don't have certificate integration
            $xml = str_replace('__PKP_PLACEHOLDER__', 'MOCK_PKP_' . substr($uuid, 0, 8), $xml);
            $xml = str_replace('__BKP_PLACEHOLDER__', 'MOCK_BKP_' . substr($uuid, 0, 8), $xml);

            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "http://fs.mfcr.cz/eet/soap/comunikace"',
                ],
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => $env !== 'test',
                CURLOPT_SSL_VERIFYHOST => $env !== 'test' ? 2 : 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('cURL error: ' . $error);
            }

            return $this->parseServerResponse($response, $httpCode);
        } catch (\Throwable $e) {
            // On any error (network, SSL, etc.), fall back to mock response for development
            // In production, this should return an error instead
            return [
                'success'       => false,
                'error_code'    => 'CONNECTION_ERROR',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse EET server SOAP response.
     */
    private function parseServerResponse(string $response, int $httpCode): array
    {
        // Check for HTTP-level errors
        if ($httpCode >= 400) {
            return [
                'success'       => false,
                'error_code'    => (string) $httpCode,
                'error_message' => 'HTTP error from EET server: ' . $httpCode,
            ];
        }

        // Parse SOAP response to extract FIK
        // EET response format: <potvrzeniFik>FIK_CODE</potvrzeniFik> or <chyba>error info</chyba>
        if (preg_match('/<potvrzeniFik[^>]*>(.*?)<\/potvrzeniFik>/s', $response, $matches)) {
            $fik = trim($matches[1]);
            if (!empty($fik)) {
                return [
                    'success' => true,
                    'fik'     => $fik,
                ];
            }
        }

        // Check for error in response
        if (preg_match('/<chyba[^>]*>(.*?)<\/chyba>/s', $response, $matches)) {
            $errorText = strip_tags($matches[1]);
            $errorCode = 'EET_ERROR';
            if (preg_match('/kod="(\d+)"/', $response, $codeMatch)) {
                $errorCode = $codeMatch[1];
            }
            return [
                'success'       => false,
                'error_code'    => $errorCode,
                'error_message' => $errorText,
            ];
        }

        // No FIK or error found - might be a fault
        if (preg_match('/<soap:Fault[^>]*>.*?<faultstring[^>]*>(.*?)<\/faultstring>/s', $response, $matches)) {
            return [
                'success'       => false,
                'error_code'    => 'SOAP_FAULT',
                'error_message' => strip_tags($matches[1]),
            ];
        }

        return [
            'success'       => false,
            'error_code'    => 'PARSE_ERROR',
            'error_message' => 'Could not parse EET server response',
        ];
    }

    /**
     * Mock response for development/testing without EET certificate.
     */
    private function mockResponse(string $uuid): array
    {
        // Simulate a successful EET response with a mock FIK
        // Format: FIK-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX
        $mockFik = 'FIK-' . strtoupper(substr(md5($uuid), 0, 5)) . '-'
                 . strtoupper(substr(md5($uuid . 'a'), 0, 5)) . '-'
                 . strtoupper(substr(md5($uuid . 'b'), 0, 5)) . '-'
                 . strtoupper(substr(md5($uuid . 'c'), 0, 5)) . '-'
                 . strtoupper(substr(md5($uuid . 'd'), 0, 5)) . '-'
                 . strtoupper(substr(md5($uuid . 'e'), 0, 5));

        return [
            'success' => true,
            'fik'     => $mockFik,
        ];
    }

    /**
     * Generate PKP (Podpisový kód poptávky).
     *
     * In production, this would use the EET certificate to create
     * a cryptographic signature of the request data.
     *
     * Placeholder implementation: returns a hash of the XML.
     * Real implementation requires:
     * 1. Load EET certificate (.p12 or .pfx file)
     * 2. Create SHA256 hash of the data
     * 3. Sign with private key using RSA/SHA256
     * 4. Return base16-encoded signature
     */
    private function generatePkp(string $xml): string
    {
        // Placeholder: In production, this would be a proper certificate signature
        // For now, use a SHA256 hash as a placeholder
        return 'PKP:' . hash('sha256', $xml);
    }

    /**
     * Generate BKP (Bezpečnostní kód poptávky).
     *
     * BKP is a SHA256 hash of the PKP, encoded in base16.
     * This provides a shorter, human-readable code for verification.
     */
    private function generateBkp(string $pkp): string
    {
        return 'BKP:' . strtoupper(substr(hash('sha256', $pkp), 0, 32));
    }

    /**
     * Generate a UUID v4 string.
     */
    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        // Set version to 0100 (UUID version 4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10 (UUID variant)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Escape XML special characters.
     */
    private function escapeXml(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
