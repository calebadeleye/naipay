<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentType>
 */
final class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'applies_to' => DocumentOwnerType::Merchant,
            'category' => 'identity',
            'is_required' => false,
            'has_expiry' => false,
            'requires_document_number' => false,
            'display_order' => 10,
            'status' => 'active',
        ];
    }

    public function forOwner(DocumentOwnerType $owner): self
    {
        return $this->state(fn (): array => ['applies_to' => $owner]);
    }

    public function required(): self
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    public function expiring(): self
    {
        return $this->state(fn (): array => ['has_expiry' => true]);
    }

    public function needingDocumentNumber(): self
    {
        return $this->state(fn (): array => ['requires_document_number' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
