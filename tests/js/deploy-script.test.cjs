const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(path.resolve(__dirname, '../../scripts/deploy-zone.ps1'), 'utf8').replace(/\r/g, '');
const remoteScript = source.match(/set -euo pipefail[\s\S]*?\n'@/)[0].slice(0, -3);
const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash';
const shellPath = (value) => value.replace(/\\/g, '/').replace(/^([A-Za-z]):/, (_, drive) => `/${drive.toLowerCase()}`);
const quote = (value) => `'${value.replace(/'/g, "'\\''")}'`;

// Execute the real remote script against an isolated filesystem. External deployment
// commands are shell stubs, so these tests cannot push, connect, migrate, or build.
function deployment(mode, check, scriptSource = remoteScript) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'workout-deploy-runtime-'));
    const app = path.join(root, 'app');
    const web = path.join(root, 'web');
    const backups = path.join(app, 'storage/app/backups');
    try {
        assert.ok(path.resolve(app).startsWith(path.resolve(root) + path.sep));
        fs.mkdirSync(path.join(app, 'database'), { recursive: true });
        fs.mkdirSync(path.join(app, 'bootstrap/cache'), { recursive: true });
        fs.mkdirSync(path.join(app, 'vendor'), { recursive: true });
        fs.mkdirSync(path.join(app, 'public/build'), { recursive: true });
        fs.mkdirSync(path.join(backups, 'pending'), { recursive: true });
        fs.mkdirSync(web);
        fs.writeFileSync(path.join(app, 'database/database.sqlite'), 'fixture database');
        fs.writeFileSync(path.join(app, '.env'), 'original environment');
        const protectedFiles = [
            [path.join(app, 'vendor/autoload.php'), 'original vendor'],
            [path.join(app, 'public/build/app.js'), 'original assets'],
            [path.join(web, 'index.php'), 'original entrypoint'],
        ];
        for (const [file, content] of protectedFiles) fs.writeFileSync(file, content);
        protectedFiles.push([path.join(app, '.env'), 'original environment']);
        protectedFiles.push([path.join(app, 'database/database.sqlite'), 'fixture database']);
        const assertProductionUnchanged = () => {
            for (const [file, content] of protectedFiles) assert.equal(fs.readFileSync(file, 'utf8'), content, file);
        };
        for (let index = 0; index < 21; index++) {
            fs.writeFileSync(path.join(backups, `database-20000101-${String(index).padStart(6, '0')}.sqlite`), 'successful snapshot');
        }
        fs.writeFileSync(path.join(backups, 'pending/database-failed.sqlite'), 'failed-attempt snapshot');
        const settings = {
            REMOTE_APP: shellPath(app), REMOTE_WEB: shellPath(web),
            REMOTE_GIT_URL: 'https://example.invalid/repo.git', PUBLIC_URL: 'https://example.invalid/wt/',
            PUBLIC_BASE_PATH: '/wt', APP_NAME: 'Workout tracker', MAIL_FROM_ADDRESS: 'test@example.invalid',
        };
        let script = scriptSource.replace(/__([A-Z_]+)__/g, (_, name) => quote(settings[name]));
        const stubs = `
export PATH="/usr/bin:/bin:$PATH"
log() { printf '%s\\n' "$*" >> "$REVIEW_LOG"; }
command() {
    if [ "$1" = '-v' ] && [ "$2" = 'sqlite3' ] && [ "$REVIEW_MODE" = 'missing-sqlite' ]; then return 1; fi
    builtin command "$@"
}
git() { log "git $*"; }
composer() { log 'composer'; }
npm() { log "npm $*"; if [ "$REVIEW_MODE" = 'build-failure' ] && [ "$1" = 'run' ]; then return 1; fi; }
php() { log "php $*"; if [ "$REVIEW_MODE" = 'migration-failure' ] && [ "$2" = 'migrate' ]; then return 1; fi; }
ln() { log 'ln'; }
curl() { log "curl $*"; if [ "$REVIEW_MODE" = 'health-failure' ]; then return 22; fi; }
sqlite3() {
    log 'backup'
    if [ "$REVIEW_MODE" = 'backup-failure' ]; then return 1; fi
    printf '%s' 'fixture backup' > "$BACKUP_FILE"
}
`;
        script = stubs + script;
        const result = spawnSync(bash, ['--noprofile', '--norc', '-s'], {
            input: script, encoding: 'utf8', cwd: root,
            env: { ...process.env, REVIEW_LOG: shellPath(path.join(root, 'calls')), REVIEW_MODE: mode },
            timeout: 15000,
        });
        if (result.error) throw result.error;
        const calls = fs.existsSync(path.join(root, 'calls')) ? fs.readFileSync(path.join(root, 'calls'), 'utf8').trim().split('\n') : [];
        check({ result, calls, app, backups, assertProductionUnchanged });
    } finally {
        assert.ok(path.resolve(root).startsWith(path.resolve(os.tmpdir()) + path.sep));
        assert.ok(path.basename(root).startsWith('workout-deploy-runtime-'));
        fs.rmSync(root, { recursive: true, force: true });
    }
}

test('missing SQLite or a failed early backup leaves production unchanged', () => {
    for (const mode of ['missing-sqlite', 'backup-failure']) {
        deployment(mode, ({ result, calls, backups, assertProductionUnchanged }) => {
            assert.notEqual(result.status, 0);
            assert.ok(!calls.some((call) => call.startsWith('git ')));
            if (mode === 'backup-failure') assert.deepEqual(calls, ['backup']);
            assertProductionUnchanged();
            assert.equal(fs.readdirSync(backups).filter((name) => name.endsWith('.sqlite')).length, 21);
        });
    }
});

test('failed builds, migrations, and health checks never prune successful snapshots', () => {
    for (const mode of ['build-failure', 'migration-failure', 'health-failure']) {
        deployment(mode, ({ result, calls, backups }) => {
            assert.notEqual(result.status, 0);
            const reached = mode === 'build-failure' ? 'npm run build'
                : mode === 'migration-failure' ? 'php artisan migrate --force --ansi' : 'php artisan up --ansi';
            assert.ok(calls.includes(reached), result.stderr);
            assert.equal(fs.readdirSync(backups).filter((name) => name.endsWith('.sqlite')).length, 21);
            assert.ok(fs.existsSync(path.join(backups, 'pending/database-failed.sqlite')));
        });
    }
});

test('successful deployment refreshes the snapshot in maintenance mode and retains completed backups separately', () => {
    deployment('success', ({ result, calls, backups }) => {
        assert.equal(result.status, 0, result.stderr);
        const firstBackup = calls.indexOf('backup');
        const lastBackup = calls.lastIndexOf('backup');
        const build = calls.indexOf('npm run build');
        const down = calls.indexOf('php artisan down --retry=60 --ansi');
        const migrate = calls.indexOf('php artisan migrate --force --ansi');
        assert.ok(firstBackup < calls.findIndex((call) => call.startsWith('git ')));
        assert.ok(build < down && down < lastBackup && lastBackup < migrate);
        assert.ok(calls.indexOf('php artisan up --ansi') > migrate);
        assert.equal(fs.readdirSync(backups).filter((name) => name.endsWith('.sqlite')).length, 20);
        assert.deepEqual(fs.readdirSync(path.join(backups, 'pending')), ['database-failed.sqlite']);
    });
});

test('preflight behavior does not depend on its error message wording', () => {
    deployment('missing-sqlite', ({ result, calls, assertProductionUnchanged }) => {
        assert.notEqual(result.status, 0);
        assert.equal(calls.length, 0);
        assertProductionUnchanged();
    }, remoteScript.replace('sqlite3 is required for a consistent SQLite backup.', 'Install the SQLite CLI.'));
});
