const assert = require('node:assert/strict');
const { resolve } = require('node:path');
const { pathToFileURL } = require('node:url');
const test = require('node:test');

const load = () => import(pathToFileURL(resolve(__dirname, '../../resources/js/rest-timer.js')).href);

const memoryStorage = () => {
    const items = new Map();

    return {
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(key, String(value)),
        removeItem: (key) => items.delete(key),
    };
};

const withTimer = (timer, run) => {
    timer.init();

    try {
        run();
    } finally {
        timer.destroy();
    }
};

test('phases follow the rest range', async () => {
    const { restPhase } = await load();

    assert.equal(restPhase(30, 60, 90), 'rest');
    assert.equal(restPhase(60, 60, 90), 'ready');
    assert.equal(restPhase(90, 60, 90), 'over');
    assert.equal(restPhase(10, null, 90), 'ready');
    assert.equal(restPhase(500, 60, null), 'ready');
    assert.equal(restPhase(500, null, null), 'open');
});

test('durations format as minutes and seconds', async () => {
    const { formatDuration } = await load();

    assert.equal(formatDuration(0), '0:00');
    assert.equal(formatDuration(65.9), '1:05');
    assert.equal(formatDuration(-3), '0:00');
});

test('entering reps starts the timer and it survives the next exercise page', async () => {
    const { restTimer } = await load();
    const storage = memoryStorage();
    let now = 1_000_000;
    const clock = () => now;

    const first = restTimer({ workoutId: 7, min: 60, max: 90, storage, clock });
    withTimer(first, () => {
        first.onInputChange({ target: { matches: () => true, value: '' } });
        assert.equal(first.running, false);

        first.onInputChange({ target: { matches: () => true, value: '8' } });
        assert.equal(first.running, true);
        assert.equal(first.targetLabel, 'Rest 1:00-1:30');
    });

    now += 75_000;
    const next = restTimer({ workoutId: 7, min: 30, max: 30, storage, clock });
    withTimer(next, () => {
        assert.equal(next.running, true);
        assert.equal(next.display, '1:15');
        assert.equal(next.phase, 'ready');
        assert.deepEqual(next.alerted, { min: true, max: false });
    });

    const otherWorkout = restTimer({ workoutId: 8, min: 60, max: 90, storage, clock });
    withTimer(otherWorkout, () => assert.equal(otherWorkout.running, false));
});

test('stopping clears the saved timer and stale timers are ignored', async () => {
    const { restTimer } = await load();
    const storage = memoryStorage();
    let now = 5_000_000;
    const clock = () => now;

    const timer = restTimer({ workoutId: 1, min: 60, max: 90, storage, clock });
    withTimer(timer, () => {
        timer.start();
        timer.stop();
    });
    const afterStop = restTimer({ workoutId: 1, min: 60, max: 90, storage, clock });
    withTimer(afterStop, () => assert.equal(afterStop.running, false));

    afterStop.start();
    now += 31 * 60 * 1000;
    const stale = restTimer({ workoutId: 1, min: 60, max: 90, storage, clock });
    withTimer(stale, () => assert.equal(stale.running, false));
});

test('crossing the minimum and maximum vibrates once each', async () => {
    const { restTimer } = await load();
    let now = 0;
    const vibrations = [];
    const timer = restTimer({ workoutId: 1, min: 60, max: 90, storage: memoryStorage(), clock: () => now });
    timer.vibrate = (pattern) => vibrations.push(pattern);

    withTimer(timer, () => {
        timer.start();
        now = 59_000;
        timer.tick();
        now = 60_000;
        timer.tick();
        timer.tick();
        now = 91_000;
        timer.tick();
    });

    assert.deepEqual(vibrations, [200, [300, 150, 300]]);
});

test('broken storage does not stop the timer', async () => {
    const { restTimer } = await load();
    const storage = {
        getItem: () => { throw new Error('blocked'); },
        setItem: () => { throw new Error('blocked'); },
        removeItem: () => { throw new Error('blocked'); },
    };
    const timer = restTimer({ workoutId: 1, min: null, max: null, storage, clock: () => 0 });

    withTimer(timer, () => {
        timer.start();
        assert.equal(timer.running, true);
        assert.equal(timer.phase, 'open');
        assert.equal(timer.targetLabel, 'Rest as needed');
        timer.stop();
    });
});
