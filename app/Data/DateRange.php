<?php

namespace App\Data;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DateRange
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $until) {}

    public static function fromFilters(array $filters): self
    {
        $today = CarbonImmutable::now(config('dataset.display_timezone'))->startOfDay();
        [$start, $end] = match ($filters['preset'] ?? '30days') {
            'today' => [$today, $today], 'yesterday' => [$today->subDay(), $today->subDay()],
            '7days' => [$today->subDays(6), $today], 'month' => [$today->startOfMonth(), $today],
            default => [$today->subDays(29), $today],
        };
        if (! empty($filters['from'])) {
            $start = CarbonImmutable::parse($filters['from'], config('dataset.display_timezone'))->startOfDay();
        }
        if (! empty($filters['to'])) {
            $end = CarbonImmutable::parse($filters['to'], config('dataset.display_timezone'))->startOfDay();
        }
        if ($end < $start || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose a range of up to 366 days.']);
        }

        return new self($start->utc(), $end->addDay()->utc());
    }

    public function apply($query, string $column = 'created_at')
    {
        return $query->where($column, '>=', $this->from)->where($column, '<', $this->until);
    }

    public function dates(): array
    {
        $days = [];
        for ($d = $this->from->timezone(config('dataset.display_timezone')); $d < $this->until; $d = $d->addDay()) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }
}
