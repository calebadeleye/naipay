<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Models;

use App\Domains\LoanProducts\Data\LoanTerms;
use App\Domains\LoanProducts\Enums\FeeType;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Enums\TenorUnit;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\LoanProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A loan product.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Money $minimum_amount
 * @property Money $maximum_amount
 * @property InterestMethod $interest_method
 * @property string $interest_rate
 * @property RepaymentFrequency $repayment_frequency
 */
class LoanProduct extends Model
{
    /** @use HasFactory<LoanProductFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'loan_products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'minimum_amount',
        'maximum_amount',
        'minimum_tenor',
        'maximum_tenor',
        'default_tenor',
        'tenor_unit',
        'interest_method',
        'interest_rate',
        'interest_period',
        'repayment_frequency',
        'processing_fee_type',
        'processing_fee_value',
        'insurance_fee_type',
        'insurance_fee_value',
        'late_payment_penalty_type',
        'late_payment_penalty_value',
        'grace_period_days',
        'requires_guarantor',
        'minimum_guarantors',
        'requires_collateral',
        'status',
        'display_order',
    ];

    /**
     * @param  Builder<LoanProduct>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Builds the terms for a specific loan under this product.
     *
     * The single place a product turns into something the calculator can
     * price, so no caller assembles terms by hand and gets a field wrong.
     */
    public function termsFor(Money $principal, int $tenor, Carbon $disbursementDate, ?Carbon $firstRepaymentDate = null): LoanTerms
    {
        return new LoanTerms(
            principal: $principal,
            interestMethod: $this->interest_method,
            interestRate: $this->interest_rate,
            frequency: $this->repayment_frequency,
            tenor: $tenor,
            disbursementDate: $disbursementDate,
            gracePeriodDays: $this->grace_period_days,
            firstRepaymentDate: $firstRepaymentDate,
        );
    }

    /**
     * Total upfront fees for a given principal.
     *
     * Percentage fees are taken on the principal; fixed fees are taken as
     * stated.
     */
    public function upfrontFeesFor(Money $principal): Money
    {
        $total = Money::zero($principal->currency());

        foreach ([
            [$this->processing_fee_type, $this->processing_fee_value],
            [$this->insurance_fee_type, $this->insurance_fee_value],
        ] as [$type, $value]) {
            if (! $type instanceof FeeType || ! $type->isCharged() || $value === null) {
                continue;
            }

            $total = $total->plus(match ($type) {
                FeeType::Fixed => $value,
                FeeType::Percentage => $principal->percentageOf($value->toDecimalString()),
                FeeType::None => Money::zero($principal->currency()),
            });
        }

        return $total;
    }

    /**
     * Whether an amount is within this product's limits.
     */
    public function acceptsAmount(Money $amount): bool
    {
        return $amount->greaterThanOrEqualTo($this->minimum_amount)
            && $amount->lessThanOrEqualTo($this->maximum_amount);
    }

    public function acceptsTenor(int $tenor): bool
    {
        return $tenor >= $this->minimum_tenor && $tenor <= $this->maximum_tenor;
    }

    /**
     * Plain-language summary for the product picker: "20% flat, repaid every
     * working day over 10 to 90 days."
     */
    public function summary(): string
    {
        $rate = rtrim(rtrim($this->interest_rate, '0'), '.');
        $method = mb_strtolower($this->interest_method->label());
        $noun = $this->repayment_frequency->noun();
        $unit = $this->tenor_unit->label();

        return "{$rate}% {$method}, repaid every {$noun} over {$this->minimum_tenor} to {$this->maximum_tenor} "
            .mb_strtolower($unit).'.';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_amount' => MoneyCast::class,
            'maximum_amount' => MoneyCast::class,
            'processing_fee_value' => MoneyCast::class,
            'insurance_fee_value' => MoneyCast::class,
            'late_payment_penalty_value' => MoneyCast::class,
            'interest_method' => InterestMethod::class,
            'repayment_frequency' => RepaymentFrequency::class,
            'tenor_unit' => TenorUnit::class,
            'processing_fee_type' => FeeType::class,
            'insurance_fee_type' => FeeType::class,
            'late_payment_penalty_type' => FeeType::class,
            'requires_guarantor' => 'boolean',
            'requires_collateral' => 'boolean',
            'minimum_tenor' => 'integer',
            'maximum_tenor' => 'integer',
            'default_tenor' => 'integer',
            'grace_period_days' => 'integer',
            'minimum_guarantors' => 'integer',
        ];
    }
}
