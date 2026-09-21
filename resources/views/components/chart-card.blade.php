@props(['title', 'subtitle', 'id'])
<section class="card chart-card"><div class="card-heading"><div><h2>{{ $title }}</h2><p>{{ $subtitle }}</p></div><span class="chart-dots" aria-hidden="true">···</span></div><div class="chart-container"><canvas id="{{ $id }}" aria-label="{{ $title }}" role="img"></canvas></div></section>
