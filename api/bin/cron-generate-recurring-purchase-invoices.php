<?php

declare(strict_types=1);

/**
 * Cron — vygeneruje nákupní faktury z aktivních šablon pravidelného nákupu.
 *
 * Použití:
 *   php api/bin/cron-generate-recurring-purchase-invoices.php
 *   php api/bin/cron-generate-recurring-purchase-invoices.php --dry-run
 *
 * Pro každou šablonu kde:
 *   - status = 'active'
 *   - next_run_date <= CURDATE()
 *   - (end_date IS NULL OR next_run_date <= end_date)
 *
 * Vygeneruje nákupní fakturu (klon šablony + items). Podle per-šablona flagu
 * auto_issue rovnou přechod draft → received. Posune next_run_date o jeden
 * cyklus; pokud nový datum překročí end_date, šablona dostane status='expired'.
 *
 * Catch-up: pokud cron neběžel několik dní, generuje jen JEDNU fakturu
 * (aktuální cyklus) a posune o 1 krok. Backlog se odbavuje den po dni.
 *
 * Pozn.: nákupní faktury se neodesílají e-mailem (jsou přijaté, ne vydané).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\PurchaseInvoiceTemplateRepository;
use MyInvoice\Service\Invoice\RecurringPurchaseInvoiceGenerator;

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$app = Bootstrap::buildApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "Container not available.\n");
    exit(1);
}

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

/** @var PurchaseInvoiceTemplateRepository $repo */
$repo = $container->get(PurchaseInvoiceTemplateRepository::class);
/** @var RecurringPurchaseInvoiceGenerator $generator */
$generator = $container->get(RecurringPurchaseInvoiceGenerator::class);

$startedAt = microtime(true);

$candidates = $repo->findDue();
$report = [
    'dry_run'    => $dryRun,
    'candidates' => count($candidates),
    'generated'  => 0,
    'issued'     => 0,
    'errors'     => 0,
];

echo "[" . date('Y-m-d H:i:s') . "] cron-generate-recurring-purchase-invoices"
    . ($dryRun ? ' --dry-run' : '') . " — found " . count($candidates) . " templates\n";

if (empty($candidates)) {
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  (nothing to do, {$ms} ms)\n";
    $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.generate_recurring_purchase', ?)")
        ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);
    exit(0);
}

if ($dryRun) {
    foreach ($candidates as $t) {
        printf(
            "  [DRY] #%d \"%s\" supplier=%s freq=%s next=%s auto_issue=%d\n",
            (int) $t['id'],
            (string) $t['name'],
            (string) ($t['supplier_company_name'] ?? '?'),
            (string) $t['frequency'],
            (string) $t['next_run_date'],
            $t['auto_issue'] ? 1 : 0,
        );
    }
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  ({$ms} ms — DRY RUN, nic se nevytvořilo)\n";
    exit(0);
}

foreach ($candidates as $t) {
    $tplId = (int) $t['id'];
    try {
        $r = $generator->generate($tplId, null, null);
        $report['generated']++;
        if ($r['issued']) $report['issued']++;
        printf(
            "  ✓ #%d \"%s\" → invoice_id=%d %s (next: %s%s)\n",
            $tplId,
            (string) $t['name'],
            $r['invoice_id'],
            $r['issued'] ? '[received]' : '[draft]',
            $r['new_next_run_date'] ?? '—',
            $r['template_status'] === 'expired' ? ', EXPIRED' : '',
        );
    } catch (\Throwable $e) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ #%d \"%s\" — %s\n", $tplId, (string) $t['name'], $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): generated={$report['generated']}, issued={$report['issued']}, errors={$report['errors']}\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.generate_recurring_purchase', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);
