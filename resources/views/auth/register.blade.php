@extends('layouts.app')

@section('content')
    <section class="space-y-6">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">PPL Tracker</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">Create account</h1>
        </div>

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <div class="space-y-2">
                <label for="name">Name</label>
                <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
            </div>

            <div class="space-y-2">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            </div>

            <div class="space-y-2">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
            </div>

            <div class="space-y-2">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>

            <button class="button-primary" type="submit">Create account</button>
        </form>

        <a href="{{ route('login') }}" class="button-secondary w-full">Log in instead</a>
    </section>
@endsection
