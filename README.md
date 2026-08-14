# Workout tracker

This is my personal workout tracking project.

I built it for learning purposes: to practice Laravel, SQLite, mobile-first UI work, PWA basics, and deploying a PHP app to Zone.ee webhosting. The app is designed around my own workout routine and training history.

## Stack

- Laravel
- SQLite
- Blade
- Tailwind CSS
- Vite
- Alpine.js

## Run Locally

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
