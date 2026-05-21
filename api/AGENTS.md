# MyInvoice.cz Backend Development

## PHP 8.5 + Slim 4 + ADR Pattern

### Tech Stack

- **PHP 8.5** minimum
- **Slim 4** — lightweight PHP framework
- **PHP-DI 7** — dependency injection
- **ADR Pattern** — Action-Domain-Responder
- **Monolog 3** — logging
- **Respect Validation 3** — input validation
- **mPDF 8.2** — PDF generation

### Project Structure

```
api/src/
├── Action/              # One class per endpoint
│   ├── Auth/            # Authentication actions
│   ├── Client/          # Client CRUD
│   ├── Project/         # Project CRUD
│   ├── Invoice/         # Invoice lifecycle
│   ├── WorkReport/      # Work reports
│   └── Admin/           # Admin actions
├── Domain/              # Entities
│   ├── Invoice.php
│   ├── Client.php
│   └── ...
├── Repository/          # PDO data access
│   ├── InvoiceRepository.php
│   └── ...
├── Service/            # Business logic
│   ├── Auth/           # Authentication services
│   ├── Invoice/        # Invoice calculations
│   ├── Pdf/            # PDF rendering
│   ├── Qr/             # QR payment generation
│   ├── Mail/           # Email sending
│   └── Ares/           # ARES/VIES lookups
├── Middleware/          # HTTP middleware
│   ├── IpAllowlistMiddleware.php
│   ├── AuthMiddleware.php
│   ├── CsrfMiddleware.php
│   └── ...
├── Infrastructure/      # Framework code
│   ├── Database/
│   └── Config/
├── Routes.php           # API route definitions
└── Bootstrap.php        # DI container setup
```

### Key Patterns

#### Action Classes

Each HTTP endpoint is a single invokable class:

```php
<?php
declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ListAction {
    public function __construct(
        private InvoiceRepository $repo,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $supplierId = $this->getSupplierId($request);
        $invoices = $this->repo->findBySupplier($supplierId);
        return $this->json($invoices);
    }
}
```

#### Multi-Supplier Scoping

Every data access must scope by `supplier_id`:

```php
public function findBySupplier(int $supplierId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT * FROM invoices WHERE supplier_id = :sid'
    );
    $stmt->execute(['sid' => $supplierId]);
    return $stmt->fetchAll();
}
```

#### Service Layer

Business logic lives in Services:

```php
// InvoiceCalculator handles all tax calculations
final class InvoiceCalculator {
    public function calculateTotals(Invoice $invoice): InvoiceTotals
    {
        // Calculate subtotal, DPH, total
    }
}
```

### Database

- **MariaDB 10.6+**
- **PDO** with prepared statements (no ORM)
- **Migrations** in `db/migrations/` (SQL files)

### Testing

```bash
cd api
vendor/bin/phpunit                 # All tests
vendor/bin/phpunit --filter=TestName  # Specific test
vendor/bin/phpstan analyse          # Static analysis
vendor/bin/php-cs-fixer fix --dry-run  # Style check
```

### Important Services

| Service | Purpose |
|---------|---------|
| `InvoiceCalculator` | Tax (DPH) calculations, totals |
| `InvoicePdfRenderer` | PDF generation with mPDF |
| `QrPaymentGenerator` | SPAYD (CZK) and SEPA EPC (EUR) QR codes |
| `Authenticator` | Login, password hashing, sessions |
| `BruteForceGuard` | Login attempt tracking |
| `AresClient` | ARES company lookup by IČO |
| `ViesClient` | VIES VAT validation |
| `GpcParser` | Bank statement import |
