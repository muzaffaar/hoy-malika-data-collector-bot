import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
window.Alpine = Alpine;
Alpine.start();
const source = document.getElementById('dashboard-data');
if (source) {
    const d = JSON.parse(source.textContent);
    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#8a998f';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 7;
    const green = '#4b8867', light = '#b8cfad';
    const base = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } }, y: { beginAtZero: true, border: { display: false }, grid: { color: '#eff3ef' }, ticks: { precision: 0 } } } };
    const draw = (id, type, labels, datasets, options = {}) => new Chart(document.getElementById(id), { type, data: { labels, datasets }, options: { ...base, ...options } });
    const days = d.days.map(s => s.slice(5).replace('-', '/'));
    draw('recordings-chart', 'line', days, [{ label: 'Recordings', data: d.recordings, borderColor: green, backgroundColor: '#eaf3ed', fill: true, tension: .35, pointRadius: 2, borderWidth: 2 }]);
    draw('participants-chart', 'bar', days, [{ label: 'New participants', data: d.participants, backgroundColor: light, borderRadius: 3, maxBarThickness: 22 }]);
    draw('gender-chart', 'doughnut', ['Male', 'Female'], [{ data: [d.gender.MALE || 0, d.gender.FEMALE || 0], backgroundColor: [green, light], borderWidth: 5, borderColor: '#fff', hoverOffset: 4 }], { cutout: '73%', scales: {}, plugins: { legend: { display: true, position: 'right' } } });
    const male = d.genderByDay.MALE, female = d.genderByDay.FEMALE;
    const share = (a, b) => a.map((n,i) => (n + b[i]) > 0 ? +(100*n/(n+b[i])).toFixed(1) : 0);
    draw('gender-daily-chart', 'bar', days, [{ label: 'Male %', data: share(male, female), backgroundColor: green, borderRadius: 2, maxBarThickness: 24 }, { label: 'Female %', data: share(female, male), backgroundColor: light, borderRadius: 2, maxBarThickness: 24 }], { plugins: { legend: { display: true, position: 'bottom' } }, scales: { x: { ...base.scales.x, stacked: true }, y: { ...base.scales.y, stacked: true, max: 100, ticks: { callback: n => n + '%' } } } });
    draw('age-chart', 'bar', Object.keys(d.age), [{ label: 'Participants', data: Object.values(d.age), backgroundColor: green, borderRadius: 4, maxBarThickness: 38 }]);
    draw('contributions-chart', 'bar', Object.keys(d.contributions), [{ label: 'Participants', data: Object.values(d.contributions), backgroundColor: light, borderRadius: 4, maxBarThickness: 38 }]);
}

// One shared player for every [data-audio-src] button, so only one recording plays at a time.
const player = new Audio();
player.preload = 'none';
let active = null;
const idle = (button) => {
    if (!button) return;
    button.classList.remove('is-playing', 'is-loading', 'is-error');
    button.textContent = '▶';
    button.title = 'Play original';
};
player.addEventListener('ended', () => { idle(active); active = null; });
player.addEventListener('error', () => {
    if (!active) return;
    active.classList.remove('is-loading', 'is-playing');
    active.classList.add('is-error');
    active.textContent = '!';
    active.title = 'Cannot play: file missing, failed integrity check, or unsupported by this browser. Use Download original.';
});
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-audio-src]');
    if (!button) return;
    if (active === button && !player.paused) {
        player.pause();
        idle(button);
        active = null;
        return;
    }
    idle(active);
    active = button;
    button.classList.add('is-loading');
    player.src = button.dataset.audioSrc;
    try {
        await player.play();
        button.classList.remove('is-loading');
        button.classList.add('is-playing');
        button.textContent = '⏸';
        button.title = 'Pause';
    } catch {
        // Failures are reported by the player's error event; an interrupted play() by a newer click is expected.
    }
});
