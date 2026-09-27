<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    /**
     * A small form exercising several field types, so a test that only needs "a form" still
     * reaches the select and checkbox branches of validation and rendering.
     */
    public function definition(): array
    {
        return [
            'key' => 'form-'.$this->faker->unique()->numberBetween(1000, 999999),
            'title' => ['fa' => 'فرم همکاری', 'en' => 'Partnership form'],
            'is_active' => true,
            'fields' => [
                [
                    'key' => 'name',
                    'type' => 'text',
                    'required' => true,
                    'label' => ['fa' => 'نام', 'en' => 'Name'],
                ],
                [
                    'key' => 'email',
                    'type' => 'email',
                    'required' => true,
                    'label' => ['fa' => 'ایمیل', 'en' => 'Email'],
                ],
                [
                    'key' => 'topic',
                    'type' => 'select',
                    'required' => true,
                    'label' => ['fa' => 'زمینه', 'en' => 'Topic'],
                    'options' => [
                        ['value' => 'sales', 'label' => ['fa' => 'فروش', 'en' => 'Sales']],
                        ['value' => 'support', 'label' => ['fa' => 'پشتیبانی', 'en' => 'Support']],
                    ],
                ],
                [
                    'key' => 'details',
                    'type' => 'textarea',
                    'required' => false,
                    'max_length' => 500,
                    'label' => ['fa' => 'توضیحات', 'en' => 'Details'],
                ],
                [
                    'key' => 'consent',
                    'type' => 'checkbox',
                    'required' => true,
                    'label' => ['fa' => 'با شرایط موافقم', 'en' => 'I agree to the terms'],
                ],
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
