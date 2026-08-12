import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

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

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const serviceWorkerUrl = document.querySelector('meta[name="service-worker-url"]')?.getAttribute('content');

        if (serviceWorkerUrl) {
            navigator.serviceWorker.register(serviceWorkerUrl).catch(() => {});
        }
    });
}
