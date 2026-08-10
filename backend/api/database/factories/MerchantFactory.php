<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Security\BlindIndex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
final class MerchantFactory extends Factory
{
    /**
     * The password every generated activated account shares, so tests can
     * sign in without each one inventing its own.
     */
    public const PASSWORD = 'Naipay-Test-Pass1!';

    protected $model = Merchant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_number' => 'NPM-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-65 years', '-20 years'),
            'gender' => fake()->randomElement(['male', 'female']),
            'phone' => '080'.fake()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'residential_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['Lagos', 'FCT', 'Oyo', 'Kano', 'Rivers']),
            'country' => 'Nigeria',
            'onboarding_status' => OnboardingStatus::Draft,
            'merchant_status' => MerchantStatus::Inactive,
            'kyc_status' => KycStatus::NotStarted,
        ];
    }

    /**
     * Sets an identity number together with its blind index — the two must
     * always move together, or duplicate detection silently stops working.
     */
    public function withBvn(?string $bvn = null): self
    {
        // Generated inside the closure so it runs per model. Resolving the
        // default once would give every merchant in a ->count(3) the same
        // number, which the unique blind index rightly refuses.
        return $this->state(function () use ($bvn): array {
            $number = $bvn ?? '22'.fake()->unique()->numerify('#########');

            return [
                'bvn' => $number,
                'bvn_index' => BlindIndex::hash($number, Merchant::BVN_INDEX_DOMAIN),
            ];
        });
    }

    public function withNin(?string $nin = null): self
    {
        return $this->state(function () use ($nin): array {
            $number = $nin ?? '84'.fake()->unique()->numerify('#########');

            return [
                'nin' => $number,
                'nin_index' => BlindIndex::hash($number, Merchant::NIN_INDEX_DOMAIN),
            ];
        });
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => [
            'onboarding_status' => OnboardingStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    public function pendingApproval(): self
    {
        return $this->state(fn (): array => [
            'onboarding_status' => OnboardingStatus::PendingApproval,
            'kyc_status' => KycStatus::Verified,
            'submitted_at' => now(),
            'verified_at' => now(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'onboarding_status' => OnboardingStatus::Approved,
            'merchant_status' => MerchantStatus::Active,
            'kyc_status' => KycStatus::Verified,
            'submitted_at' => now(),
            'verified_at' => now(),
            'approved_at' => now(),
        ]);
    }

    /**
     * A portal account that has completed activation and can sign in.
     */
    public function withPassword(): self
    {
        return $this->state(fn (): array => [
            'password' => self::PASSWORD,
            'activated_at' => now(),
        ]);
    }
}
