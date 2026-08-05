<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Models;

use App\Domains\Identity\Models\Staff;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\GuarantorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A guarantor offered against a loan application.
 *
 * Identity evidence attaches as a Document against this record, via the same
 * polymorphic relation a merchant's or a business's documents use. The class
 * name matters here beyond style: DocumentService derives the document-owner
 * type a record accepts from its class basename, lowercased — "Guarantor"
 * is what makes `DocumentOwnerType::Guarantor` resolve, the same way
 * "Merchant" and "Business" already do for their own domains.
 *
 * @property int $id
 * @property int $loan_application_id
 * @property string $full_name
 * @property Money|null $monthly_income
 */
class Guarantor extends Model
{
    /** @use HasFactory<GuarantorFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'loan_application_guarantors';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'relationship',
        'address',
        'id_type',
        'id_number',
        'employer',
        'occupation',
        'monthly_income',
    ];

    /**
     * @return BelongsTo<LoanApplication, $this>
     */
    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_income' => MoneyCast::class,
        ];
    }
}
