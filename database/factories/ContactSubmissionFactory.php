<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactSubmission;
use Database\Factories\Concerns\GeneratesPersianText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactSubmission>
 */
class ContactSubmissionFactory extends Factory
{
    use GeneratesPersianText;

    protected $model = ContactSubmission::class;

    public function definition(): array
    {
        return [
            // Faker's fa_IR DOES provide Persian person names (it is only the Lorem
            // provider that is missing), so names are realistic without help.
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'subject' => $this->persianTitle(50),
            'message' => $this->persianParagraph(400),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => [
            'read_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}
