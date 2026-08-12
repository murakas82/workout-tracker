@extends('layouts.app')

@section('content')
    <section class="space-y-6">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">Welcome back</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">Log in</h1>
        </div>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div class="space-y-2">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus>
            </div>

            <div class="space-y-2">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <label class="flex items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-900 p-3">
                <input class="h-5 w-5" type="checkbox" name="remember" value="1">
                <span>Remember me</span>
            </label>

            <button class="button-primary" type="submit">Log in</button>
        </form>

        <a href="{{ route('register') }}" class="button-secondary w-full">Create account</a>
    </section>
@endsection
