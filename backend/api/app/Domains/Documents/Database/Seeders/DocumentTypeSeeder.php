<?php

declare(strict_types=1);

namespace App\Domains\Documents\Database\Seeders;

use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The document types Naipay asks for.
 *
 * Reference data, idempotent, run on every deploy. Matched on slug so renaming
 * a type's display label never orphans the documents filed under it.
 *
 * Only two types are marked required — a photograph and one government
 * identity document. Nigerian microfinance serves a large informal segment, and
 * demanding a utility bill or a bank statement from a market trader would block
 * exactly the merchants this product exists to reach. Everything else is
 * requested where relevant rather than mandated.
 */
final class DocumentTypeSeeder extends Seeder
{
    /**
     * name, category, required, has expiry, needs a document number.
     *
     * @var array<string, array<int, array{0: string, 1: string, 2: bool, 3: bool, 4: bool}>>
     */
    private const TYPES = [
        DocumentOwnerType::Merchant->value => [
            ['Passport Photograph', 'identity', true, false, false],
            ['National Identification Number Slip', 'identity', true, false, true],
            ['Bank Verification Number Evidence', 'identity', false, false, true],
            ["Voter's Card", 'identity', false, false, true],
            ['Driver’s Licence', 'identity', false, true, true],
            ['International Passport', 'identity', false, true, true],
            ['Proof of Address', 'address', false, false, false],
            ['Utility Bill', 'address', false, false, false],
            ['Bank Statement', 'financial', false, false, false],
            ['Guarantor Identification', 'guarantor', false, false, true],
        ],

        DocumentOwnerType::Business->value => [
            ['CAC Certificate', 'registration', false, false, true],
            ['CAC Status Report', 'registration', false, true, true],
            ['Business Name Registration', 'registration', false, false, true],
            ['Tax Identification Number', 'registration', false, false, true],
            ['Business Address Evidence', 'address', false, false, false],
            ['Shop Photograph', 'premises', false, false, false],
            ['Inventory Photograph', 'premises', false, false, false],
            ['Sales Record', 'financial', false, false, false],
            ['Purchase Invoice', 'financial', false, false, false],
            ['Bank Statement', 'financial', false, false, false],
            ['Lease Agreement', 'premises', false, true, false],
            ['Trade Association Identification', 'other', false, true, true],
        ],

        DocumentOwnerType::Loan->value => [
            ['Signed Loan Agreement', 'agreement', true, false, false],
            ['Loan Application Form', 'agreement', false, false, false],
            ['Field Verification Report', 'assessment', false, false, false],
            ['Credit Assessment Report', 'assessment', false, false, false],
        ],

        DocumentOwnerType::Guarantor->value => [
            ['Guarantor Identification', 'identity', true, false, true],
            ['Guarantor Passport Photograph', 'identity', false, false, false],
            ['Guarantor Consent Form', 'agreement', true, false, false],
            ['Guarantor Proof of Address', 'address', false, false, false],
        ],

        DocumentOwnerType::Collateral->value => [
            ['Collateral Photograph', 'evidence', false, false, false],
            ['Ownership Document', 'evidence', true, false, true],
            ['Valuation Report', 'valuation', false, true, false],
        ],

        DocumentOwnerType::Repayment->value => [
            ['Payment Evidence', 'evidence', false, false, false],
            ['Bank Transfer Receipt', 'evidence', false, false, true],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::TYPES as $appliesTo => $types) {
                $order = 0;

                foreach ($types as [$name, $category, $isRequired, $hasExpiry, $needsNumber]) {
                    $order += 10;

                    // Scoped by owner so "Bank Statement" can exist for both a
                    // merchant and a business without colliding.
                    $slug = Str::slug($appliesTo.'-'.$name);

                    $type = DocumentType::query()->firstOrNew(['slug' => $slug]);

                    $type->name = $name;
                    $type->applies_to = $appliesTo;
                    $type->category = $category;
                    $type->is_required = $isRequired;
                    $type->has_expiry = $hasExpiry;
                    $type->requires_document_number = $needsNumber;
                    $type->display_order = $order;

                    // Status only on creation: a type compliance deliberately
                    // withdrew must not be revived by a deploy.
                    if (! $type->exists) {
                        $type->status = 'active';
                    }

                    $type->save();
                }
            }
        });
    }
}
