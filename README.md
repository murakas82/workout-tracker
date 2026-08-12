# Workout tracker

Mobile-first Laravel workout tracker.

## Requirements

- PHP 8.3+
- Composer
- Node.js and npm
- SQLite PHP extension

## Setup

```powershell
composer install
npm install
Copy-Item .env.example .env
New-Item -ItemType File -Force database\database.sqlite
php artisan key:generate
php artisan migrate --seed
```

If `php` is not on PATH, run Artisan through Composer:

```powershell
composer exec -- php artisan key:generate
composer exec -- php artisan migrate --seed
```

## Run Locally

Start Vite:

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
