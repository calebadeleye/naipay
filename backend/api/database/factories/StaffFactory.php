<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Enums\StaffStatus;
use App\Domains\Identity\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Staff>
 */
final class StaffFactory extends Factory
{
    /**
     * The password every generated account shares, so tests can sign in
     * without each one inventing its own.
     */
    public const PASSWORD = 'Naipay-Test-Pass1!';

    protected $model = Staff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'staff_number' => 'NPS-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'middle_name' => null,
            'email' => Str::lower($firstName.'.'.$lastName.fake()->unique()->numberBetween(1, 99999).'@naitalk.com'),
            'username' => null,
            'phone' => '080'.fake()->numerify('########'),
            'job_title' => fake()->jobTitle(),
            'password' => self::PASSWORD,
            // Ready to use by default. A factory account that had to change its
            // password first would make every test set that up.
            'must_change_password' => false,
            'password_changed_at' => now(),
            'status' => StaffStatus::Active,
            'failed_login_attempts' => 0,
        ];
    }

    public function pendingActivation(): self
    {
        return $this->state(fn (): array => [
            'status' => StaffStatus::PendingActivation,
            'must_change_password' => true,
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => StaffStatus::Suspended]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['status' => StaffStatus::Disabled]);
    }

    public function mustChangePassword(): self
    {
        return $this->state(fn (): array => ['must_change_password' => true]);
    }

    public function locked(int $minutes = 30): self
    {
        return $this->state(fn (): array => [
            'failed_login_attempts' => (int) config('naipay.security.max_login_attempts', 5),
            'locked_until' => now()->addMinutes($minutes),
        ]);
    }

    /**
     * An account with two-factor already enrolled and confirmed.
     */
    public function withTwoFactor(string $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'): self
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
