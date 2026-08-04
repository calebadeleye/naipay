<?php

declare(strict_types=1);

namespace App\Domains\Documents\Console;

use App\Domains\Documents\Services\DocumentService;
use Illuminate\Console\Command;

/**
 * Marks verified documents whose expiry date has passed.
 *
 * Scheduled daily. Without it a lapsed identity document keeps satisfying its
 * KYC requirement indefinitely, and the book quietly becomes unverifiable.
 */
final class ExpireLapsedDocumentsCommand extends Command
{
    protected $signature = 'naipay:documents:expire-lapsed';

    protected $description = 'Mark verified documents whose expiry date has passed as expired.';

    public function handle(DocumentService $documents): int
    {
        $count = $documents->expireLapsedDocuments();

        $this->info($count === 0
            ? 'No documents had lapsed.'
            : "Expired {$count} lapsed document(s).");

        return self::SUCCESS;
    }
}
