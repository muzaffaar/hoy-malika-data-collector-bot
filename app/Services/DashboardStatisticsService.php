<?php

namespace App\Services;

use App\Data\DateRange;
use App\Models\Participant;
use App\Models\VoiceRecording;
use Illuminate\Support\Facades\DB;

class DashboardStatisticsService
{
    private function participants(DateRange $range)
    {
        return $range->apply(Participant::whereNull('deletion_requested_at'));
    }

    private function recordings(DateRange $range)
    {
        return $range->apply(VoiceRecording::whereNull('deletion_requested_at'), 'telegram_received_at');
    }

    public function getOverview(DateRange $range): array
    {
        $p = $this->participants($range);
        $r = $this->recordings($range);
        $participants = (clone $p)->count();
        $recordings = (clone $r)->count();
        $today = DateRange::fromFilters(['preset' => 'today']);

        return ['Participants' => $participants, 'Voice recordings' => $recordings,
            'Participants today' => (clone $p)->where('created_at', '>=', $today->from)->where('created_at', '<', $today->until)->count(),
            'Recordings today' => (clone $r)->where('telegram_received_at', '>=', $today->from)->where('telegram_received_at', '<', $today->until)->count(),
            'Male participants' => (clone $p)->where('gender', 'MALE')->count(), 'Female participants' => (clone $p)->where('gender', 'FEMALE')->count(),
            'Average contributions¹' => round((clone $p)->avg('recording_count') ?? 0, 1),
            'Target completed¹' => (clone $p)->where('recording_count', '>=', config('dataset.target'))->count(),
            'Hard negative recordings' => (clone $r)->where('sample_type', 'HARD_NEGATIVE')->count(),
            'Pending backup files' => (clone $r)->where(fn ($q) => $q->whereNotIn('backup_status', ['COMPLETED', 'DISABLED'])->orWhereNotIn('local_sync_status', ['COMPLETED', 'DISABLED']))->count(),
            'Local sync pending' => (clone $r)->whereNotIn('local_sync_status', ['COMPLETED', 'DISABLED'])->count(),
            'Drive sync pending' => (clone $r)->whereNotIn('backup_status', ['COMPLETED', 'DISABLED'])->count(),
            'Dataset size' => number_format((clone $r)->sum('file_size_bytes') / 1048576, 1).' MB'];
    }

    private function dayExpression(string $column): string
    {
        // Tashkent has a fixed UTC+05 offset. Production uses PostgreSQL; SQLite is used in tests.
        return DB::connection()->getDriverName() === 'pgsql' ? "DATE({$column} AT TIME ZONE 'Asia/Tashkent')" : "date({$column}, '+5 hours')";
    }

    private function series($query, DateRange $range, string $column): array
    {
        $expr = $this->dayExpression($column);
        $counts = $query->selectRaw("{$expr} as day, count(*) as total")->groupByRaw($expr)->pluck('total', 'day');

        return array_map(fn ($day) => (int) ($counts[$day] ?? 0), $range->dates());
    }

    public function getRecordingsByDay(DateRange $range): array
    {
        return $this->series($this->recordings($range), $range, 'telegram_received_at');
    }

    public function getParticipantsByDay(DateRange $range): array
    {
        return $this->series($this->participants($range), $range, 'created_at');
    }

    public function getGenderDistribution(DateRange $range): array
    {
        return $this->participants($range)->whereNotNull('gender')->selectRaw('gender, count(*) as total')->groupBy('gender')->pluck('total', 'gender')->all();
    }

    public function getGenderDistributionByDay(DateRange $range): array
    {
        return ['MALE' => $this->series($this->participants($range)->where('gender', 'MALE'), $range, 'created_at'), 'FEMALE' => $this->series($this->participants($range)->where('gender', 'FEMALE'), $range, 'created_at')];
    }

    public function getAgeDistribution(DateRange $range): array
    {
        return $this->participants($range)->whereNotNull('age_range')->selectRaw('age_range, count(*) as total')->groupBy('age_range')->pluck('total', 'age_range')->all();
    }

    public function getContributionDistribution(DateRange $range): array
    {
        $result = [];
        foreach (['0' => [0, 0], '1–5' => [1, 5], '6–10' => [6, 10], '11–20' => [11, 20], '21–50' => [21, 50], '51+' => [51, 2147483647]] as $label => [$min,$max]) {
            $result[$label] = $this->participants($range)->whereBetween('recording_count', [$min, $max])->count();
        }

        return $result;
    }

    public function getBackupStatistics(DateRange $range): array
    {
        return $this->recordings($range)->selectRaw('backup_status, count(*) as total')->groupBy('backup_status')->pluck('total', 'backup_status')->all();
    }

    public function charts(DateRange $range): array
    {
        return ['days' => $range->dates(), 'recordings' => $this->getRecordingsByDay($range), 'participants' => $this->getParticipantsByDay($range), 'gender' => $this->getGenderDistribution($range), 'genderByDay' => $this->getGenderDistributionByDay($range), 'age' => $this->getAgeDistribution($range), 'contributions' => $this->getContributionDistribution($range)];
    }
}
