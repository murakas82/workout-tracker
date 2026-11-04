const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('activation removes only outdated workout caches', async () => {
    const handlers = {};
    const deleted = [];
    const context = {
        self: {
            addEventListener: (name, handler) => { handlers[name] = handler; },
            clients: { claim() {} },
        },
        caches: {
            keys: async () => ['workout-tracker-v1', 'workout-tracker-v2', 'workout-tracker-v3', 'other-app-cache'],
            delete: async (name) => { deleted.push(name); return true; },
        },
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../public/service-worker.js'), 'utf8'), context);
    let activation;
    handlers.activate({ waitUntil: (task) => { activation = task; } });
    await activation;

    assert.deepEqual(deleted, ['workout-tracker-v1', 'workout-tracker-v2']);
});
