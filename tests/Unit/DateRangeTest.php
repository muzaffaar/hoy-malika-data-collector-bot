<?php
namespace Tests\Unit;
use App\Data\DateRange;
use Carbon\CarbonImmutable;
use Tests\TestCase;
class DateRangeTest extends TestCase {
 public function test_today_uses_tashkent_boundaries_in_utc(): void {
  CarbonImmutable::setTestNow('2026-09-21 12:00:00 Asia/Tashkent');
  $r=DateRange::fromFilters(['preset'=>'today']);
  $this->assertSame('2026-09-20 19:00:00',$r->from->format('Y-m-d H:i:s'));
  $this->assertSame('2026-09-21 19:00:00',$r->until->format('Y-m-d H:i:s'));
  $this->assertSame(['2026-09-21'],$r->dates()); CarbonImmutable::setTestNow();
 }
}
