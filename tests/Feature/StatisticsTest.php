<?php

namespace Tests\Feature;

use App\Data\DateRange;
use App\Models\Participant;
use App\Models\VoiceRecording;
use App\Services\DashboardStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tashkent_day_boundaries_gender_age_and_contributions(): void
    {
        Participant::factory()->create(['created_at' => '2026-09-20 18:59:59', 'gender' => 'MALE']);
        $p = Participant::factory()->create(['created_at' => '2026-09-20 19:00:00', 'gender' => 'FEMALE', 'age_range' => '25–34', 'recording_count' => 7]);
        Participant::factory()->create(['created_at' => '2026-09-21 18:59:59', 'gender' => 'MALE', 'age_range' => '18–24', 'recording_count' => 20]);
        Participant::factory()->create(['created_at' => '2026-09-21 19:00:00', 'gender' => 'FEMALE']);
        VoiceRecording::factory()->create(['participant_id' => $p->id, 'telegram_received_at' => '2026-09-20 19:00:00']);
        VoiceRecording::factory()->create(['participant_id' => $p->id, 'telegram_received_at' => '2026-09-21 19:00:00']);
        $range = DateRange::fromFilters(['from' => '2026-09-21', 'to' => '2026-09-21']);
        $s = app(DashboardStatisticsService::class);
        $this->assertEquals(['FEMALE' => 1, 'MALE' => 1], $s->getGenderDistribution($range));
        $this->assertSame([1], $s->getRecordingsByDay($range));
        $this->assertSame([2], $s->getParticipantsByDay($range));
        $this->assertSame(['MALE' => [1], 'FEMALE' => [1]], $s->getGenderDistributionByDay($range));
        $this->assertEquals(['18–24' => 1, '25–34' => 1], $s->getAgeDistribution($range));
        $this->assertSame(1, $s->getContributionDistribution($range)['6–10']);
    }
}
