<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Generuje vydanou fakturu ze šablony pravidelného prodeje.
 *
 * Kroky:
 *   1. Vytvoří draft (klon šablony — client, project, currency, language,
 *      payment_method, reverse_charge, notes; položky se zkopírují).
 *   2. Pokud auto_issue=true: přechod draft → issued (status).
 *   3. Posune next_run_date na šabloně (PeriodicityCalculator) a updatuje
 *      last_run_date; pokud nové next > end_date, status='expired'.
 */
final class RecurringInvoiceGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly RecurringInvoiceRepository $templates,
        private readonly InvoiceRepository $invoices,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{
     *     invoice_id: int,
     *     issued: bool,
     *     new_next_run_date: ?string,
     *     template_status: string,
     * }
     */
    public function generate(int $templateId, ?string $forcedIssueDate = null, ?int $userId = null): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $issueDate = $forcedIssueDate ?? (string) $template['next_run_date'];
        $dueDate = date('Y-m-d', strtotime($issueDate . ' +' . ((int) ($template['payment_due_days'] ?? 14)) . ' days'));

        $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $dueDate, $userId);

        $issued = false;
        if (!empty($template['auto_issue'])) {
            $this->invoices->markStatus($invoiceId, 'issued');
            $issued = true;
        }

        $newNextRun = PeriodicityCalculator::nextRunDate(
            (string) $template['next_run_date'],
            (string) $template['frequency'],
            !empty($template['end_of_month']),
            $template['day_of_month'] ?? null,
        );
        $lastRun = (string) $template['next_run_date'];
        $newStatus = $template['status'];

        if (!empty($template['end_date']) && $newNextRun > (string) $template['end_date']) {
            $newStatus = 'expired';
        }

        $this->templates->advanceSchedule($templateId, $newNextRun, $lastRun, $newStatus);

        return [
            'invoice_id' => $invoiceId,
            'issued' => $issued,
            'new_next_run_date' => $newStatus === 'expired' ? null : $newNextRun,
            'template_status' => $newStatus,
        ];
    }

    private function createInvoiceFromTemplate(array $template, string $issueDate, string $dueDate, ?int $userId): int
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO invoices
                (client_id, project_id, invoice_number,
                 issue_date, tax_date, due_date, currency_id,
                 reverse_charge, language,
                 note_above_items, note_below_items,
                 payment_method, payment_due_days,
                 status, recurring_template_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            (int) $template['client_id'],
            $template['project_id'],
            '', // invoice_number — necháme prázdné (generuje se při issued)
            $issueDate,
            $issueDate, // tax_date = issue_date
            $dueDate,
            (int) $template['currency_id'],
            !empty($template['reverse_charge']) ? 1 : 0,
            (string) ($template['language'] ?? 'cs'),
            $template['note_above_items'] ?? null,
            $template['note_below_items'] ?? null,
            (string) ($template['payment_method'] ?? 'bank_transfer'),
            (int) ($template['payment_due_days'] ?? 14),
            'draft',
            (int) $template['id'],
            $userId ?? 0,
        ]);

        $invoiceId = (int) $pdo->lastInsertId();
        $this->invoices->replaceItems($invoiceId, $template['items']);

        return $invoiceId;
    }
}
