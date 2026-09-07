<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Services\LoanPortfolioAnalyticsService;
use RuntimeException;

/**
 * Serialises a LoanPortfolioAnalyticsService::analyse() result to CSV.
 *
 * This class does no arithmetic — it only lays out figures that
 * {@see LoanPortfolioAnalyticsService} has
 * already computed, so an export can never disagree with the dashboard it was
 * taken from. Every monetary cell is the raw decimal string ("125000.50"), not
 * a formatted amount, so the file is spreadsheet-computable.
 */
final class PortfolioAnalyticsCsv
{
    public const SECTIONS = ['summary', 'status', 'products', 'officers', 'branches', 'aging', 'risk'];

    /**
     * @param  array<string, mixed>  $analytics  the analyse() payload
     */
    public function build(array $analytics, string $section): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open a temporary stream for CSV export.');
        }

        // Provenance block, so a saved file still says what it is.
        $filters = $analytics['filters'];
        $meta = $analytics['meta'];
        $this->row($handle, ['Report', 'Loan portfolio — '.$this->sectionLabel($section)]);
        $this->row($handle, ['Range', $filters['range_label'], $filters['date_from'].' to '.$filters['date_to']]);
        $this->row($handle, ['Stock as at', $meta['as_of']]);
        $this->row($handle, ['Currency', $meta['currency']]);
        $this->row($handle, ['Generated', $meta['generated_at']]);
        foreach ($this->activeFilterNotes($filters) as $note) {
            $this->row($handle, ['Filter', $note]);
        }
        $this->row($handle, []);

        match ($section) {
            'status' => $this->statusTable($handle, $analytics['by_status']),
            'products' => $this->breakdownTable($handle, 'Product', $analytics['by_product']),
            'officers' => $this->breakdownTable($handle, 'Loan officer', $analytics['by_loan_officer']),
            'branches' => $this->breakdownTable($handle, 'Branch', $analytics['by_branch']),
            'aging' => $this->agingTable($handle, $analytics['aging']['buckets']),
            'risk' => $this->riskTable($handle, $analytics['risk']),
            default => $this->summaryTable($handle, $analytics),
        };

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        // Excel opens UTF-8 correctly only with a BOM; the ₦ sign never appears
        // in a cell, but merchant and branch names can carry accents.
        return "\u{FEFF}".$csv;
    }

    public function filename(array $analytics, string $section): string
    {
        $filters = $analytics['filters'];

        return sprintf(
            'loan-portfolio-%s-%s_%s.csv',
            $section,
            $filters['date_from'],
            $filters['date_to'],
        );
    }

    // ── Sections ─────────────────────────────────────────────────────────

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $a
     */
    private function summaryTable($handle, array $a): void
    {
        $this->row($handle, ['Section', 'Metric', 'Value', 'Previous period', 'Change %']);

        $kpiLabels = [
            'total_disbursed' => 'Total disbursed',
            'outstanding_principal' => 'Outstanding principal',
            'current_receivable' => 'Current receivable',
            'total_collected' => 'Total collected',
            'overdue_amount' => 'Overdue amount',
            'active_loans' => 'Active loans',
            'active_borrowers' => 'Active borrowers',
            'portfolio_at_risk_30' => 'PAR 30',
        ];
        foreach ($kpiLabels as $key => $label) {
            $kpi = $a['kpis'][$key];
            $this->row($handle, [
                'KPI', $label, $kpi['value'], $kpi['previous'] ?? '', $this->number($kpi['change_pct']),
            ]);
        }

        foreach ([
            'outstanding_principal' => 'Outstanding principal',
            'outstanding_interest' => 'Outstanding interest',
            'outstanding_fees' => 'Outstanding fees',
            'current_receivable' => 'Current receivable',
            'contracted_receivable' => 'Contracted receivable',
        ] as $key => $label) {
            $this->row($handle, ['Receivable', $label, $a['receivable'][$key], '', '']);
        }

        $cp = $a['collection_performance'];
        $this->row($handle, ['Collection performance', 'Expected', $cp['expected'], '', '']);
        $this->row($handle, ['Collection performance', 'Collected', $cp['collected'], '', '']);
        $this->row($handle, ['Collection performance', 'Collection rate %', $this->number($cp['collection_rate']), '', '']);
        $this->row($handle, ['Collection performance', 'Overdue amount', $cp['overdue_amount'], '', '']);
        $this->row($handle, ['Collection performance', 'Overdue loans', (string) $cp['overdue_loan_count'], '', '']);

        $b = $a['borrowers'];
        foreach ([
            'total_borrowers' => 'Total borrowers',
            'active_borrowers' => 'Active borrowers',
            'new_borrowers' => 'New borrowers',
            'returning_borrowers' => 'Returning borrowers',
            'borrowers_with_overdue' => 'Borrowers with an overdue loan',
            'borrowers_with_multiple_active_loans' => 'Borrowers with multiple active loans',
        ] as $key => $label) {
            $this->row($handle, ['Borrowers', $label, (string) $b[$key], '', '']);
        }
        $this->row($handle, ['Borrowers', 'Repeat borrower rate %', $this->number($b['repeat_borrower_rate']), '', '']);
        $this->row($handle, ['Borrowers', 'Average outstanding per borrower', $b['average_outstanding_per_borrower'], '', '']);
        $this->row($handle, ['Borrowers', 'Average loan size', $b['average_loan_size'], '', '']);

        $p = $a['loan_performance'];
        $this->row($handle, ['Loan performance', 'Average loan amount', $p['average_loan_amount'], '', '']);
        $this->row($handle, ['Loan performance', 'Average outstanding loan', $p['average_outstanding_loan'], '', '']);
        $this->row($handle, ['Loan performance', 'Average tenure (days)', $this->number($p['average_loan_tenure_days']), '', '']);
        $this->row($handle, ['Loan performance', 'Active loans', (string) $p['active_loans'], '', '']);
        $this->row($handle, ['Loan performance', 'Completed loans', (string) $p['completed_loans'], '', '']);
        $this->row($handle, ['Loan performance', 'Loan completion rate %', $this->number($p['loan_completion_rate']), '', '']);
        $this->row($handle, ['Loan performance', 'Written-off loans', (string) $p['written_off_loans'], '', '']);
        $this->row($handle, ['Loan performance', 'Write-off rate %', $this->number($p['write_off_rate']), '', '']);

        $i = $a['interest_and_fees'];
        foreach ([
            'interest_contracted' => 'Interest contracted',
            'interest_collected' => 'Interest collected',
            'interest_outstanding' => 'Interest outstanding',
            'fees_contracted' => 'Fees contracted',
            'fees_collected' => 'Fees collected',
            'fees_outstanding' => 'Fees outstanding',
        ] as $key => $label) {
            $this->row($handle, ['Interest & fees', $label, $i[$key], '', '']);
        }

        $w = $a['write_off_and_recovery'];
        $this->row($handle, ['Write-off & recovery', 'Written-off amount', $w['written_off_amount'], '', '']);
        $this->row($handle, ['Write-off & recovery', 'Written-off loans', (string) $w['written_off_loans'], '', '']);
        $this->row($handle, ['Write-off & recovery', 'Recovered amount', $w['recovered_amount'], '', '']);
        $this->row($handle, ['Write-off & recovery', 'Recovery rate %', $this->number($w['recovery_rate']), '', '']);

        foreach ($a['risk'] as $band) {
            $this->row($handle, [
                'Portfolio at risk',
                'PAR '.$band['threshold_days'],
                $band['at_risk_amount'],
                '',
                $this->number($band['percentage_of_outstanding']),
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $rows
     */
    private function statusTable($handle, array $rows): void
    {
        $this->row($handle, [
            'Status', 'Loans', 'Outstanding principal', 'Outstanding interest',
            'Outstanding fees', 'Total receivable', '% of portfolio',
        ]);
        foreach ($rows as $row) {
            $this->row($handle, [
                $row['label'],
                (string) $row['loan_count'],
                $row['outstanding_principal'],
                $row['outstanding_interest'],
                $row['outstanding_fees'],
                $row['total_receivable'],
                $this->number($row['percentage_of_portfolio']),
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $rows
     */
    private function breakdownTable($handle, string $heading, array $rows): void
    {
        $this->row($handle, [
            $heading, 'Loans', 'Borrowers', 'Total disbursed', 'Outstanding principal',
            'Outstanding receivable', 'Collected', 'Overdue', 'PAR 30', 'Collection rate %',
        ]);
        foreach ($rows as $row) {
            $this->row($handle, [
                $row['name'],
                (string) $row['loans'],
                (string) $row['borrowers'],
                $row['total_disbursed'],
                $row['outstanding_principal'],
                $row['outstanding_receivable'],
                $row['collected'],
                $row['overdue'],
                $row['par30'],
                $this->number($row['collection_rate']),
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $buckets
     */
    private function agingTable($handle, array $buckets): void
    {
        $this->row($handle, [
            'Bucket', 'From (days)', 'To (days)', 'Loans',
            'Outstanding principal', 'Outstanding receivable', '% of portfolio',
        ]);
        foreach ($buckets as $bucket) {
            $this->row($handle, [
                $bucket['label'],
                (string) $bucket['from_days'],
                $bucket['to_days'] === null ? '' : (string) $bucket['to_days'],
                (string) $bucket['loan_count'],
                $bucket['outstanding_principal'],
                $bucket['outstanding_receivable'],
                $this->number($bucket['percentage_of_portfolio']),
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<array<string, mixed>>  $bands
     */
    private function riskTable($handle, array $bands): void
    {
        $this->row($handle, ['PAR band (days)', 'At-risk amount', '% of outstanding', 'Loans']);
        foreach ($bands as $band) {
            $this->row($handle, [
                (string) $band['threshold_days'],
                $band['at_risk_amount'],
                $this->number($band['percentage_of_outstanding']),
                (string) $band['loan_count'],
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param  resource  $handle
     * @param  list<string>  $cells
     */
    private function row($handle, array $cells): void
    {
        // Empty $escape: the backslash-escape mechanism is deprecated from PHP
        // 8.4 and disabling it keeps RFC 4180 quoting ("" for a literal quote).
        fputcsv($handle, $cells, ',', '"', '', "\r\n");
    }

    private function number(int|float|null $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function sectionLabel(string $section): string
    {
        return match ($section) {
            'status' => 'by status',
            'products' => 'by product',
            'officers' => 'loan officer performance',
            'branches' => 'by branch',
            'aging' => 'portfolio aging',
            'risk' => 'portfolio at risk',
            default => 'summary',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function activeFilterNotes(array $filters): array
    {
        $notes = [];
        foreach ([
            'loan_product_id' => 'Loan product ID',
            'status' => 'Loan status',
            'loan_officer_id' => 'Loan officer ID',
            'branch_id' => 'Branch ID',
            'borrower_id' => 'Borrower ID',
            'loan_id' => 'Loan ID',
            'disbursement_channel_id' => 'Disbursement channel ID',
            'repayment_status' => 'Repayment status',
            'min_days_past_due' => 'Min. days past due',
        ] as $key => $label) {
            if (($filters[$key] ?? null) !== null) {
                $notes[] = "{$label}: {$filters[$key]}";
            }
        }

        return $notes;
    }
}
