<!doctype html>
<html lang="en" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#18181b">
        <meta name="service-worker-url" content="{{ asset('service-worker.js') }}">
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <title>{{ config('app.name', 'PPL Tracker') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        @php($activeWorkout = $activeWorkout ?? false)

        <div class="app-shell">
            <header class="sticky top-0 z-20 border-b border-zinc-800 bg-zinc-950/95 px-4 py-3 backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('dashboard') }}" class="text-lg font-black uppercase text-zinc-50">PPL Tracker</a>

                    @auth
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="text-sm font-bold text-zinc-400" type="submit">Log out</button>
                        </form>
                    @endauth
                </div>
            </header>

            <main class="{{ $activeWorkout ? 'px-4 pb-6 pt-4' : 'px-4 pb-28 pt-4' }}">
                @if (session('status'))
                    <div class="mb-4 rounded-lg border border-lime-400/30 bg-lime-400/10 p-3 text-sm font-semibold text-lime-100">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-4 rounded-lg border border-red-400/30 bg-red-500/10 p-3 text-sm font-semibold text-red-100">
                        {{ $errors->first() }}
                    </div>
                @endif

                @yield('content')
            </main>

            @auth
                @unless ($activeWorkout)
                    <nav class="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md border-t border-zinc-800 bg-zinc-950/95 px-3 py-2 backdrop-blur">
                        <div class="grid grid-cols-5 gap-1">
                            <a class="bottom-nav-link {{ request()->routeIs('dashboard') ? 'bottom-nav-link-active' : '' }}" href="{{ route('dashboard') }}">Home</a>
                            <a class="bottom-nav-link {{ request()->routeIs('workouts.*') ? 'bottom-nav-link-active' : '' }}" href="{{ route('workouts.index') }}">Workout</a>
                            <a class="bottom-nav-link {{ request()->routeIs('progress.*') ? 'bottom-nav-link-active' : '' }}" href="{{ route('progress.index') }}">Progress</a>
                            <a class="bottom-nav-link {{ request()->routeIs('history.*') ? 'bottom-nav-link-active' : '' }}" href="{{ route('history.index') }}">History</a>
                            <a class="bottom-nav-link {{ request()->routeIs('settings.*') ? 'bottom-nav-link-active' : '' }}" href="{{ route('settings.index') }}">Settings</a>
                        </div>
                    </nav>
                @endunless
            @endauth
        </div>
    </body>
</html>
