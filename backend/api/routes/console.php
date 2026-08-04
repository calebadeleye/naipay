<?php

declare(strict_types=1);

use App\Domains\Documents\Console\ExpireLapsedDocumentsCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by a single `schedule:run` cron entry on the application server. Each
| task states why its timing matters; nothing here is scheduled by habit.
|
*/

/*
 * Expire lapsed documents shortly after midnight, so a document that expires
 * today stops satisfying its requirement on the day it lapses rather than
 * whenever someone next opens the record.
 *
 * `withoutOverlapping` because a large book could take longer than the gap
 * between runs, and two sweeps writing the same rows would double up the audit
 * entries.
 */
Schedule::command(ExpireLapsedDocumentsCommand::class)
    ->dailyAt('00:15')
    ->withoutOverlapping()
    ->onOneServer();
