<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\OnboardingState;
use App\Enums\SampleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gender' => Gender::class, 'onboarding_state' => OnboardingState::class, 'collection_mode' => SampleType::class, 'consent_given' => 'boolean', 'consent_at' => 'datetime', 'first_interaction_at' => 'datetime', 'last_interaction_at' => 'datetime', 'is_blocked' => 'boolean', 'deletion_requested_at' => 'datetime'];
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(VoiceRecording::class);
    }
}
