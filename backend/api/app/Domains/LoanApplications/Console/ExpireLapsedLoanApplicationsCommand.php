<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Console;

use App\Domains\LoanApplications\Services\LoanApplicationService;
use Illuminate\Console\Command;

/**
 * Marks applications still awaiting a decision past their validity window as
 * expired.
 *
 * Scheduled daily. Without it, an application nobody acted on sits in the
 * queue indefinitely instead of visibly lapsing, and staff have no signal
 * that the applicant needs to be asked to reapply.
 */
final class ExpireLapsedLoanApplicationsCommand extends Command
{
    protected $signature = 'naipay:loan-applications:expire-lapsed';

    protected $description = 'Mark loan applications past their validity window as expired.';

    public function handle(LoanApplicationService $applications): int
    {
        $count = $applications->expireLapsed();

        $this->info($count === 0
            ? 'No loan applications had lapsed.'
            : "Expired {$count} lapsed loan application(s).");

        return self::SUCCESS;
    }
}
