<?php
namespace Tests\Feature;
use App\Data\DateRange;
use App\Models\Participant;
use App\Models\VoiceRecording;
use App\Services\DashboardStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DashboardStatisticsTest extends TestCase {
 use RefreshDatabase;
 public function test_gender_and_recording_statistics_are_computed(): void {
  CarbonImmutable::setTestNow('2026-09-21 12:00:00 Asia/Tashkent');
  $m=Participant::factory()->create(['gender'=>'MALE','created_at'=>'2026-09-21 02:00:00','recording_count'=>2]);
  Participant::factory()->create(['gender'=>'FEMALE','created_at'=>'2026-09-21 03:00:00']);
  VoiceRecording::factory()->count(2)->for($m)->create(['telegram_received_at'=>'2026-09-21 04:00:00']);
  $range=DateRange::fromFilters(['preset'=>'today']); $s=app(DashboardStatisticsService::class);
  $this->assertSame(['MALE'=>1,'FEMALE'=>1], $s->getGenderDistribution($range));
  $this->assertSame([2], $s->getRecordingsByDay($range));
  CarbonImmutable::setTestNow();
 }
}
