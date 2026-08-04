<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Businesses\Enums\CategoryStatus;
use App\Domains\Businesses\Models\BusinessCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessCategory>
 */
final class BusinessCategoryFactory extends Factory
{
    protected $model = BusinessCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'parent_id' => null,
            'status' => CategoryStatus::Active,
            'display_order' => fake()->numberBetween(1, 500),
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => CategoryStatus::Inactive]);
    }

    public function childOf(BusinessCategory $parent): self
    {
        return $this->state(fn (): array => ['parent_id' => $parent->id]);
    }
}
