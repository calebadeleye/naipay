<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Documents\Enums\DocumentVerificationStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'documentable_type' => Merchant::class,
            'documentable_id' => Merchant::factory(),
            'document_type_id' => DocumentType::factory(),
            'file_path' => 'merchants/1/'.Str::uuid().'.pdf',
            'file_name' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 240_000,
            'checksum' => hash('sha256', Str::random(32)),
            'verification_status' => DocumentVerificationStatus::Pending,
            'version' => 1,
            'is_current' => true,
        ];
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'verification_status' => DocumentVerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function expiringOn(string $date): self
    {
        return $this->state(fn (): array => ['expires_at' => $date]);
    }
}
