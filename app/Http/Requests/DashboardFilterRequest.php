<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'preset' => ['nullable', Rule::in(['today', 'yesterday', '7days', '30days', 'month', 'custom'])],
            'search' => ['nullable', 'string', 'max:100'], 'gender' => ['nullable', Rule::in(['MALE', 'FEMALE'])],
            'age' => ['nullable', Rule::in(array_keys(config('dataset.age_ranges')))],
            'min_recordings' => ['nullable', 'integer', 'min:0'], 'max_recordings' => ['nullable', 'integer', 'min:0'],
            'participant_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['created_at', 'recording_count', 'id'])], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'sync' => ['nullable', Rule::in(['PENDING', 'COMPLETED', 'FAILED', 'DISABLED', 'UPLOADING', 'RETRYING'])]];
    }
}
