@props(['value'])
@php($text = $value instanceof \BackedEnum ? $value->value : (string) $value)
<span class="status {{ in_array($text, ['COMPLETED', 'VALID', 'Healthy', 'Yes']) ? 'good' : (in_array($text, ['FAILED', 'CORRUPT']) ? 'bad' : 'pending') }}">{{ str_replace('_', ' ', $text) }}</span>
