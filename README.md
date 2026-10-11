# Workout tracker

This is my personal workout tracking project.

I built it for learning purposes: to practice in VScode with codex using Laravel, SQLite, mobile-first UI work, PWA basics, and deploying a PHP app to Zone.ee webhosting. The app is designed around my own workout routine and training history.

## Stack

- Laravel
- SQLite
- Blade
- Tailwind CSS
- Vite
- Alpine.js

## Run Locally

For a fresh local installation, use PHP/Composer and Node.js/npm versions compatible with the lockfiles:

```bash
git clone https://github.com/murakas82/workout-tracker.git
cd workout-tracker
composer setup
php artisan serve
```

Open the URL printed by Laravel.

If `php` is not available directly on PATH:

```bash
composer exec -- php artisan serve
```

## Initial Account and Recovery

`composer setup` creates `.env`, generates the initial `APP_KEY`, migrates SQLite, seeds the default exercises/templates, and builds assets. For a manual fresh installation, run `php artisan migrate` followed by `php artisan db:seed`. Seeding creates no user account; public registration is disabled.

Create the first account with `php artisan tinker` in an interactive terminal, then paste:

```php
(function () {
    if (App\Models\User::query()->exists()) {
        throw new RuntimeException('An account already exists.');
    }

    App\Models\User::query()->create([
        'name' => Laravel\Prompts\text('Name', required: true),
        'email' => Laravel\Prompts\text('Email', required: true),
        'password' => Illuminate\Support\Facades\Hash::make(
            Laravel\Prompts\password('Choose a strong password', required: true)
        ),
    ]);
})();
```

The [password prompt](https://laravel.com/docs/13.x/prompts#password) hides input; the closure returns no password to Tinker. Keep credentials and deployment settings out of Git and this README.

Keep a protected backup of `.env`, including the original `APP_KEY`, separately from SQLite backups. Never run `composer setup` or `php artisan key:generate` on an existing or restored deployment: replacing the key invalidates encrypted cookies and any encrypted data.

To restore SQLite, work from the application's private root, put the matching original `APP_KEY` in `.env`, and stop any workers/scheduled jobs that write to the database. Replace the placeholder backup path below. The SQLite CLI [backup/restore commands](https://www.sqlite.org/cli.html) handle database copying without copying a live SQLite file by hand. Run these commands one at a time and stop if any fails:

```bash
php artisan down
mkdir -p storage/app/backups
sqlite3 database/database.sqlite ".backup 'storage/app/backups/pre-restore-$(date +%Y%m%d-%H%M%S).sqlite'"
sqlite3 database/database.sqlite ".restore '/path/to/backup.sqlite'"
sqlite3 database/database.sqlite "PRAGMA integrity_check;"
```

Continue only if the integrity check returns `ok`; otherwise leave maintenance mode enabled. Restore ownership/permissions so PHP can write the database and its directory, then:

```bash
php artisan migrate --force
php artisan config:cache
php artisan up
```

Restart any stopped workers/jobs. Do not reseed or use `migrate:fresh`, `migrate:refresh`, or `migrate:reset` during recovery. Normal deployment backs up SQLite before migrating without seeding and preserves the existing `APP_KEY`.

## Development

Run Vite in one terminal:

```bash
npm run dev
```

Run Laravel in another terminal:

```bash
php artisan serve
```

## Tests

```bash
composer exec -- php artisan test
```

## Zone.ee Deployment

Deployment uses `scripts/deploy-zone.ps1`. Private deployment values are kept in the ignored file `scripts/deploy-zone.local.json`.

From Git Bash:

```bash
powershell.exe -ExecutionPolicy Bypass -File ./scripts/deploy-zone.ps1
```

The deploy script backs up the SQLite database before migrations.
