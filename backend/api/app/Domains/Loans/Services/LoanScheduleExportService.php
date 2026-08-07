<?php

declare(strict_types=1);

namespace App\Domains\Loans\Services;

use App\Domains\Loans\Models\Loan;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a loan's repayment schedule as a PDF, for download or emailing to
 * the client.
 *
 * Dompdf renders HTML/CSS, not the React components the admin console uses —
 * `resources/views/loans/schedule-pdf.blade.php` is a deliberately plain,
 * standalone document rather than a reuse of any frontend markup.
 */
final class LoanScheduleExportService
{
    public function render(Loan $loan): string
    {
        $loan->loadMissing(['scheduleEntries', 'merchant', 'business']);

        return Pdf::loadView('loans.schedule-pdf', ['loan' => $loan])->output();
    }

    public function filename(Loan $loan): string
    {
        return "{$loan->loan_reference}-schedule.pdf";
    }
}
