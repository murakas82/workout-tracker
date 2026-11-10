const STORAGE_KEY = 'workout-tracker:rest-timer';
const STALE_AFTER_MS = 30 * 60 * 1000;

const toSeconds = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) && number >= 0 ? number : null;
};

/**
 * rest: before the minimum rest, ready: inside the rest range,
 * over: past the maximum rest, open: the exercise has no rest range.
 */
export const restPhase = (elapsed, min, max) => {
    if (min === null && max === null) {
        return 'open';
    }

    if (min !== null && elapsed < min) {
        return 'rest';
    }

    if (max !== null && elapsed >= max) {
        return 'over';
    }

    return 'ready';
};

export const formatDuration = (seconds) => {
    const safe = Math.max(0, Math.floor(seconds));

    return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
};

const readSaved = (storage) => {
    try {
        const saved = JSON.parse(storage?.getItem(STORAGE_KEY) ?? 'null');

        return saved && typeof saved.startedAt === 'number' ? saved : null;
    } catch {
        return null;
    }
};

const writeSaved = (storage, value) => {
    try {
        if (value === null) {
            storage?.removeItem(STORAGE_KEY);
        } else {
            storage?.setItem(STORAGE_KEY, JSON.stringify(value));
        }
    } catch {
        // Storage can be unavailable (private mode); the timer still works on this page.
    }
};

const defaultStorage = () => {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
};

export const restTimer = ({ workoutId, min, max, storage = defaultStorage(), clock = () => Date.now() }) => ({
    workoutId,
    min: toSeconds(min),
    max: toSeconds(max),
    startedAt: null,
    now: clock(),
    alerted: { min: false, max: false },
    intervalId: null,

    init() {
        const saved = readSaved(storage);

        if (saved && saved.workoutId === this.workoutId && clock() - saved.startedAt < STALE_AFTER_MS) {
            this.startedAt = saved.startedAt;
            this.min = toSeconds(saved.min);
            this.max = toSeconds(saved.max);
        }

        this.now = clock();
        this.markPassedAlerts();
        this.intervalId = setInterval(() => this.tick(), 1000);
    },

    destroy() {
        clearInterval(this.intervalId);
    },

    get running() {
        return this.startedAt !== null;
    },

    get elapsed() {
        return this.running ? Math.max(0, Math.floor((this.now - this.startedAt) / 1000)) : 0;
    },

    get phase() {
        return restPhase(this.elapsed, this.min, this.max);
    },

    get display() {
        return formatDuration(this.elapsed);
    },

    get targetLabel() {
        if (this.min === null && this.max === null) {
            return 'Rest as needed';
        }

        if (this.min === null || this.max === null || this.min === this.max) {
            return `Rest ${formatDuration(this.min ?? this.max)}`;
        }

        return `Rest ${formatDuration(this.min)}-${formatDuration(this.max)}`;
    },

    get phaseLabel() {
        return {
            rest: 'Resting',
            ready: 'Ready',
            over: 'Go',
            open: 'Resting',
        }[this.phase];
    },

    start() {
        this.startedAt = clock();
        this.now = this.startedAt;
        this.alerted = { min: false, max: false };
        writeSaved(storage, { workoutId: this.workoutId, startedAt: this.startedAt, min: this.min, max: this.max });
    },

    stop() {
        this.startedAt = null;
        writeSaved(storage, null);
    },

    onInputChange(event) {
        const input = event.target;

        if (input?.matches?.('[data-rest-trigger]') && String(input.value).trim() !== '') {
            this.start();
        }
    },

    tick() {
        this.now = clock();

        if (!this.running) {
            return;
        }

        if (this.min !== null && !this.alerted.min && this.elapsed >= this.min) {
            this.alerted.min = true;
            this.vibrate(200);
        }

        if (this.max !== null && !this.alerted.max && this.elapsed >= this.max) {
            this.alerted.max = true;
            this.vibrate([300, 150, 300]);
        }
    },

    markPassedAlerts() {
        this.alerted = {
            min: this.running && this.min !== null && this.elapsed >= this.min,
            max: this.running && this.max !== null && this.elapsed >= this.max,
        };
    },

    vibrate(pattern) {
        try {
            globalThis.navigator?.vibrate?.(pattern);
        } catch {
            // Vibration is optional.
        }
    },
});
