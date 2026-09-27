<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactSubmission;
use App\Models\Form;
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
            // The seeded contact form, which the create_forms_table migration guarantees.
            'form_id' => fn (): int => Form::contact()->getKey(),

            // Faker's fa_IR DOES provide Persian person names (it is only the Lorem
            // provider that is missing), so names are realistic without help.
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),

            /*
             * Built from the resolved columns, so a test overriding `name` gets a payload that
             * says the same thing — the application copies these three FROM the payload, and a
             * row where the two disagree is one it could never have written.
             */
            'payload' => fn (array $attributes): array => [
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'],
                'subject' => $this->persianTitle(50),
                'message' => $this->persianParagraph(400),
            ],
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

    /**
     * A submission a spam check flagged (item 16).
     *
     * The reason is a real key from App\Services\Contact\SpamInspector rather than a
     * faker word, so a test asserting the panel renders the reason label is exercising a
     * value the application can actually produce.
     */
    public function spam(string $reason = 'honeypot'): static
    {
        return $this->state(fn (): array => [
            'is_spam' => true,
            'spam_reason' => $reason,
        ]);
    }
}
