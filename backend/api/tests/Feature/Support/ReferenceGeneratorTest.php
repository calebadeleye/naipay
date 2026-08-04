<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_produces_the_documented_reference_formats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        $generator = $this->generator();

        $this->assertSame('NPM-000001', $generator->next('merchant'));
        $this->assertSame('NPB-000001', $generator->next('business'));
        $this->assertSame('NPA-2026-000001', $generator->next('loan_application'));
        $this->assertSame('NPL-2026-000001', $generator->next('loan'));
        $this->assertSame('NPR-2026-000001', $generator->next('repayment'));
        $this->assertSame('NPT-2026-000001', $generator->next('receipt'));
        $this->assertSame('NPJ-2026-000001', $generator->next('journal'));
        $this->assertSame('NPV-2026-000001', $generator->next('reversal'));
    }

    #[Test]
    public function it_increments_within_a_sequence(): void
    {
        $generator = $this->generator();

        $this->assertSame('NPM-000001', $generator->next('merchant'));
        $this->assertSame('NPM-000002', $generator->next('merchant'));
        $this->assertSame('NPM-000003', $generator->next('merchant'));
    }

    #[Test]
    public function sequences_are_independent_of_one_another(): void
    {
        $generator = $this->generator();

        $generator->next('merchant');
        $generator->next('merchant');

        // The business sequence must not have been advanced by merchant
        // allocations.
        $this->assertSame('NPB-000001', $generator->next('business'));
    }

    #[Test]
    public function annually_scoped_sequences_reset_each_year(): void
    {
        $generator = $this->generator();

        Carbon::setTestNow(Carbon::parse('2026-12-31 23:00:00'));
        $this->assertSame('NPL-2026-000001', $generator->next('loan'));
        $this->assertSame('NPL-2026-000002', $generator->next('loan'));

        Carbon::setTestNow(Carbon::parse('2027-01-01 01:00:00'));
        $this->assertSame('NPL-2027-000001', $generator->next('loan'));

        // Returning to the earlier year continues that year's own counter
        // rather than colliding with it.
        $this->assertSame(
            'NPL-2026-000003',
            $generator->next('loan', Carbon::parse('2026-06-01 12:00:00')),
        );
    }

    #[Test]
    public function continuous_sequences_do_not_reset(): void
    {
        $generator = $this->generator();

        $this->assertSame('NPM-000001', $generator->next('merchant', Carbon::parse('2026-11-30')));
        $this->assertSame('NPM-000002', $generator->next('merchant', Carbon::parse('2027-01-02')));
    }

    #[Test]
    public function it_allocates_a_contiguous_batch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01'));

        $generator = $this->generator();

        $generator->next('merchant');

        $batch = $generator->nextBatch('merchant', 5);

        $this->assertSame([
            'NPM-000002',
            'NPM-000003',
            'NPM-000004',
            'NPM-000005',
            'NPM-000006',
        ], $batch);

        // A subsequent single allocation continues after the batch.
        $this->assertSame('NPM-000007', $generator->next('merchant'));
    }

    #[Test]
    public function a_batch_must_contain_at_least_one_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generator()->nextBatch('merchant', 0);
    }

    #[Test]
    public function it_rejects_an_unconfigured_sequence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generator()->next('not_a_real_sequence');
    }

    #[Test]
    public function it_pads_to_the_configured_width_and_grows_beyond_it(): void
    {
        // Seed the counter just below the padding boundary rather than
        // allocating a million references.
        DB::table('reference_sequences')->insert([
            'name' => 'merchant',
            'period' => 'ALL',
            'current_value' => 999_998,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $generator = $this->generator();

        $this->assertSame('NPM-999999', $generator->next('merchant'));

        // Past the padding width the reference simply gets longer; it is never
        // truncated back into a value that could collide.
        $this->assertSame('NPM-1000000', $generator->next('merchant'));
    }

    #[Test]
    public function concurrent_allocations_never_produce_a_duplicate(): void
    {
        // RefreshDatabase wraps each test in a transaction, which would make
        // every connection here invisible to the others. This test manages its
        // own schema state instead.
        $this->withoutTransaction(function (): void {
            $connectionCount = 8;
            $allocationsPer = 25;

            $connections = [];

            for ($i = 0; $i < $connectionCount; $i++) {
                // A distinct connection name forces a genuinely separate PDO
                // connection rather than a reused one.
                config(["database.connections.concurrent_{$i}" => config('database.connections.mysql')]);
                $connections[] = DB::connection("concurrent_{$i}");
            }

            $references = [];

            // Interleaved round-robin: each pass allocates once on every
            // connection, so allocations genuinely contend rather than running
            // one connection to completion before the next starts.
            for ($round = 0; $round < $allocationsPer; $round++) {
                foreach ($connections as $connection) {
                    $references[] = (new ReferenceGenerator($connection))->next('loan');
                }
            }

            $expected = $connectionCount * $allocationsPer;

            $this->assertCount($expected, $references);
            $this->assertCount(
                $expected,
                array_unique($references),
                'Concurrent allocation produced a duplicate reference.',
            );

            foreach ($connections as $index => $connection) {
                $connection->disconnect();
                config(["database.connections.concurrent_{$index}" => null]);
            }
        });
    }

    private function generator(): ReferenceGenerator
    {
        return new ReferenceGenerator(DB::connection());
    }

    /**
     * Runs a callback outside the test's wrapping transaction, restoring a
     * clean schema afterwards.
     */
    private function withoutTransaction(callable $callback): void
    {
        DB::rollBack();

        try {
            DB::table('reference_sequences')->delete();

            $callback();
        } finally {
            DB::table('reference_sequences')->delete();
            DB::beginTransaction();
        }
    }
}
