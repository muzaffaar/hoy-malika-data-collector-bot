@props(['label', 'value', 'index' => 0])
<article class="kpi {{ $index === 1 ? 'kpi-featured' : '' }}"><p>{{ $label }}</p><strong>{{ is_numeric($value) ? number_format($value, is_float($value) ? 1 : 0) : $value }}</strong><span class="kpi-caption">{{ $index === 1 ? 'Original audio collected' : 'Selected period' }}</span></article>
