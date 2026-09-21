<?php

namespace Database\Factories;

use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    public function definition(): array
    {
        return ['telegram_user_id' => fake()->unique()->numberBetween(10000000, 900000000), 'first_name' => fake()->firstName(), 'telegram_username' => null,
            'gender' => fake()->randomElement(['MALE', 'FEMALE']), 'age_range' => fake()->randomElement(array_keys(config('dataset.age_ranges'))),
            'onboarding_state' => 'READY_FOR_RECORDINGS', 'consent_given' => true, 'consent_at' => now(), 'consent_version' => config('dataset.consent_version'),
            'first_interaction_at' => now(), 'last_interaction_at' => now()];
    }
}
