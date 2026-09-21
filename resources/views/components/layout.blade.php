@props(['title' => 'Overview'])
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ $title }} · Hoy Malika</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body x-data="{ menu: false }">
<a href="#main" class="skip-link">Skip to content</a>
<div class="app-shell">
<div x-show="menu" x-cloak class="mobile-overlay" @click="menu = false"></div>
<aside class="sidebar" :class="{ 'is-open': menu }" @keydown.escape.window="menu = false">
    <a class="brand" href="{{ route('dashboard') }}"><span class="brand-icon">m<span>ı</span></span><span>hoy, malika<span class="brand-sub">VOICE DATA STUDIO</span></span></a>
    <div class="workspace"><span class="workspace-dot"></span><div>Wake word collection<small>Uzbek · Positive samples</small></div></div>
    <p class="nav-label">WORKSPACE</p>
    <nav aria-label="Main navigation">
    @foreach(['dashboard' => ['◫', 'Overview'], 'participants' => ['♧', 'Participants'], 'recordings' => ['≋', 'Recordings'], 'system' => ['⌘', 'System health']] as $route => [$icon, $label])
    <a href="{{ route($route) }}" class="nav-item {{ request()->routeIs($route, rtrim($route, 's')) ? 'active' : '' }}"><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
    @endforeach
    </nav>
    <div class="sidebar-bottom"><div class="private-note"><span>◈</span><strong>Originals, protected.</strong><p>Private storage. Verified copies.<br>Every voice accounted for.</p></div><div class="admin-profile"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><div>{{ auth()->user()->name }}<small>Administrator</small></div><form method="post" action="{{ route('logout') }}">@csrf<button class="logout" aria-label="Sign out" title="Sign out">↗</button></form></div></div>
</aside>
<div class="main-shell"><header class="topbar"><button class="menu-button" @click="menu = !menu" aria-label="Toggle navigation" :aria-expanded="menu">☰</button><span>Workspace <span class="breadcrumb">/</span> <strong>{{ $title }}</strong></span><span class="timezone">Asia / Tashkent <span class="tiny-dot"></span> UTC+05</span></header>
<main id="main"><div class="page-heading"><div><p class="eyebrow">HOY, MALIKA / DATASET OPERATIONS</p><h1>{{ $title }}</h1></div><span class="project-badge">UZBEK · uz-UZ</span></div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
{{ $slot }}
<footer>Hoy Malika Data Studio <span>Original voice data · Private workspace</span></footer>
</main></div></div></body></html>
