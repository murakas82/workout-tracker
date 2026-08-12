# Workout tracker

Mobile-first Laravel workout tracker.

## Requirements

- PHP 8.3+
- Composer
- Node.js and npm
- SQLite PHP extension

## Quick Start

Clone the project, install everything, and prepare the local database:

PowerShell:

```powershell
git clone https://github.com/murakas82/workout.git
cd workout
composer setup
```

Git Bash:

```bash
git clone https://github.com/murakas82/workout.git
cd workout
composer setup
```

The setup command installs PHP and Node dependencies, creates `.env`, creates
`database/database.sqlite`, generates the app key, runs migrations with seed
data, and builds the frontend assets.

Then start the app in either shell:

```powershell
php artisan serve
```

Open the URL printed by Laravel.

If `php` is not on PATH, run Artisan through Composer:

```powershell
composer exec -- php artisan serve
```

## Manual Setup

If you prefer to run each step yourself:

PowerShell:

```powershell
composer install
npm install
Copy-Item .env.example .env
New-Item -ItemType File -Force database\database.sqlite
composer exec -- php artisan key:generate
composer exec -- php artisan migrate --seed
npm run build
```

Git Bash:

```bash
composer install
npm install
cp .env.example .env
touch database/database.sqlite
composer exec -- php artisan key:generate
composer exec -- php artisan migrate --seed
npm run build
```

## Development

For active development, start Vite:

```powershell
npm run dev
```

In another terminal, start Laravel:

```powershell
php artisan serve
```

Open the URL printed by Laravel.

## Test

```powershell
php artisan test
```

## Deploy

Create `scripts\deploy-zone.local.json` from `scripts\deploy-zone.local.example.json`, then run:

```powershell
.\scripts\deploy-zone.ps1
```
