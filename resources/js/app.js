import Alpine from 'alpinejs';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

window.Alpine = Alpine;
Alpine.start();

let deferredInstallPrompt = null;

const isStandalone = () => (
    window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true
);

const installButtons = () => document.querySelectorAll('[data-pwa-install]');

const setInstallVisible = (visible) => {
    installButtons().forEach((button) => {
        button.classList.toggle('hidden', !visible);
    });
};

window.addEventListener('beforeinstallprompt', (event) => {
    if (isStandalone()) {
        return;
    }

    event.preventDefault();
    deferredInstallPrompt = event;
    setInstallVisible(true);
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    setInstallVisible(false);
});

document.addEventListener('click', async (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
        return;
    }

    const button = target.closest('[data-pwa-install]');

    if (!button || !deferredInstallPrompt) {
        return;
    }

    button.setAttribute('disabled', 'disabled');
    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice.catch(() => null);
    deferredInstallPrompt = null;
    setInstallVisible(false);
    button.removeAttribute('disabled');
});

document.addEventListener('input', (event) => {
    const input = event.target;

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    if (input.matches('[data-copy-target]')) {
        input.dataset.manual = 'true';
    }

    if (!input.matches('[data-copy-source="weight"]')) {
        return;
    }

    const group = input.dataset.copyGroup;

    if (!group) {
        return;
    }

    document.querySelectorAll(`[data-copy-target="${group}"]`).forEach((target) => {
        if (target instanceof HTMLInputElement && target.dataset.manual !== 'true') {
            target.value = input.value;
        }
    });
});

const chartColors = {
    grid: 'rgba(113, 113, 122, 0.22)',
    muted: '#a1a1aa',
    text: '#f4f4f5',
    working: '#a3e635',
    drops: '#60a5fa',
    total: '#f4f4f5',
};

const chartOptions = ({ stacked = false, horizontal = false } = {}) => ({
    indexAxis: horizontal ? 'y' : 'x',
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: {
            labels: {
                boxWidth: 10,
                color: chartColors.muted,
                font: {
                    size: 11,
                    weight: 'bold',
                },
            },
        },
        tooltip: {
            callbacks: {
                label: (context) => {
                    const value = horizontal ? context.parsed.x : context.parsed.y;

                    return `${context.dataset.label}: ${Math.round(value)} kg`;
                },
            },
        },
    },
    scales: {
        x: {
            stacked,
            beginAtZero: horizontal,
            grid: horizontal ? {
                color: chartColors.grid,
            } : {
                display: false,
            },
            ticks: {
                color: chartColors.muted,
                font: {
                    size: 11,
                    weight: 'bold',
                },
                callback: horizontal ? (value) => `${Math.round(value)} kg` : undefined,
                maxRotation: 0,
                minRotation: 0,
            },
        },
        y: {
            stacked,
            beginAtZero: !horizontal,
            grid: horizontal ? {
                display: false,
            } : {
                color: chartColors.grid,
            },
            ticks: {
                color: chartColors.muted,
                callback: horizontal
                    ? function (value) {
                        const label = this.getLabelForValue(value);

                        return label.length > 18 ? `${label.slice(0, 18)}...` : label;
                    }
                    : (value) => `${Math.round(value)} kg`,
            },
        },
    },
});

const readChartPayload = (container) => {
    const payload = container.querySelector('script[type="application/json"]')?.textContent?.trim();

    if (!payload) {
        return null;
    }

    try {
        return JSON.parse(payload);
    } catch {
        return null;
    }
};

const renderWorkoutCharts = () => {
    document.querySelectorAll('[data-workout-chart]').forEach((container) => {
        const canvas = container.querySelector('canvas');
        const payload = readChartPayload(container);

        if (!(canvas instanceof HTMLCanvasElement) || !payload?.labels?.length) {
            return;
        }

        if (container.dataset.workoutChart === 'exercise-volume') {
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: payload.labels,
                    datasets: [
                        {
                            label: 'Working',
                            data: payload.working,
                            backgroundColor: chartColors.working,
                            borderRadius: 4,
                        },
                        {
                            label: 'Drops',
                            data: payload.drops,
                            backgroundColor: chartColors.drops,
                            borderRadius: 4,
                        },
                    ],
                },
                options: chartOptions({ stacked: true, horizontal: true }),
            });
        }

        if (container.dataset.workoutChart === 'type-volume-history') {
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: payload.labels,
                    datasets: [
                        {
                            label: 'Total',
                            data: payload.total,
                            borderColor: chartColors.total,
                            backgroundColor: 'rgba(244, 244, 245, 0.08)',
                            borderWidth: 2,
                            pointBackgroundColor: chartColors.total,
                            pointRadius: 3,
                            tension: 0.35,
                        },
                        {
                            label: 'Working',
                            data: payload.working,
                            borderColor: chartColors.working,
                            borderWidth: 2,
                            pointBackgroundColor: chartColors.working,
                            pointRadius: 3,
                            tension: 0.35,
                        },
                    ],
                },
                options: chartOptions(),
            });
        }
    });
};

renderWorkoutCharts();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const serviceWorkerUrl = document.querySelector('meta[name="service-worker-url"]')?.getAttribute('content');

        if (serviceWorkerUrl) {
            navigator.serviceWorker.register(serviceWorkerUrl).catch(() => {});
        }
    });
}
