# PPL Tracker

Mobile-first Push / Legs / Pull workout tracker built with Laravel, Blade, Tailwind CSS, Alpine.js, SQLite, and standard Laravel session authentication.

The app is designed for gym use on an Android phone: dark mode, large numeric inputs, one exercise at a time, saved sets after each exercise, no timers, and no nutrition/body-stat tracking.

## Requirements

- PHP 8.3 or newer
- Composer
- Node.js and npm
- SQLite PHP extension

## Installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

On this Windows machine, PHP is provided by Herd Lite. If `php` is not on your PATH, use Composer's PHP bridge:

```bash
composer exec -- php artisan key:generate
```

## SQLite Setup

The default `.env` uses SQLite:

```env
DB_CONNECTION=sqlite
```

Create the database file:

```bash
type nul > database/database.sqlite
```

PowerShell alternative:

```powershell
New-Item -ItemType File -Force database\database.sqlite
```

## Migrations And Seeding

```bash
php artisan migrate --seed
```

The seeder creates the exact Push, Legs, and Pull workouts from the prompt. It does not create a Rest workout.

To reset local data:

```bash
php artisan migrate:fresh --seed
```

## Frontend Build

Development assets:

```bash
npm run dev
```

Production assets:

```bash
npm run build
```

## Development Server

```bash
php artisan serve
```

Then open the URL printed by Laravel, usually `http://127.0.0.1:8000`.

## Testing

```bash
php artisan test
```

Current coverage includes user data isolation, workout rotation, rest-day behavior, progression, drop sets, resume behavior, historical exercise snapshots, unilateral sets, and manual workout selection.

## PWA Installation

The app includes:

- `public/manifest.webmanifest`
- `public/service-worker.js`
- `public/icons/icon.svg`
- Android standalone display mode
- theme/background colors

After deploying over HTTPS, open the app on Android Chrome and use "Install app" from the browser menu.

## Production Deployment To `murakas.eu/wt`

Recommended production values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://murakas.eu/wt
ASSET_URL=https://murakas.eu/wt
DB_CONNECTION=sqlite
```

Run before upload:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

For Zone hosting, keep Laravel application files outside the public web root when possible, and expose only the contents of Laravel's `public` directory at:

```text
https://murakas.eu/wt
```

If Zone cannot point `/wt` directly at this project's `public` folder, copy the contents of `public/` into the web root's `wt` folder and adjust that copied `wt/index.php` so its `vendor/autoload.php` and `bootstrap/app.php` paths point back to the private Laravel project directory.

Make sure these locations are writable by PHP in production:

- `database/database.sqlite`
- `storage/`
- `bootstrap/cache/`

## Main Tables

- `users`
- `workout_types`
- `exercises`
- `workout_templates`
- `workout_template_exercises`
- `workouts`
- `workout_exercises`
- `workout_sets`

No body weight, body measurements, calorie, meal, or nutrition tables are present.
