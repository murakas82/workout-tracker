import Alpine from 'alpinejs';

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

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const serviceWorkerUrl = document.querySelector('meta[name="service-worker-url"]')?.getAttribute('content');

        if (serviceWorkerUrl) {
            navigator.serviceWorker.register(serviceWorkerUrl).catch(() => {});
        }
    });
}
