@extends('layouts.app')

@section('content')
    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">Workout</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">Start training</h1>
        </div>

        @if ($inProgress)
            <div class="panel border-amber-400/40 bg-amber-400/10">
                <p class="font-black uppercase text-amber-200">Workout in progress</p>
                <p class="mt-1 text-zinc-200">{{ $inProgress->workoutType->name }}</p>
                <a class="mt-4 button-primary" href="{{ route('workouts.show', $inProgress) }}">Continue workout</a>
            </div>
        @endif

        <div class="panel">
            <p class="text-sm font-black uppercase text-zinc-400">Recommended</p>
            <h2 class="mt-2 text-4xl font-black uppercase text-zinc-50">{{ $nextType->name }}</h2>
            <form method="POST" action="{{ route('workouts.start', $nextType) }}" class="mt-4">
                @csrf
                <button class="button-primary">Start {{ $nextType->name }}</button>
            </form>
        </div>

        <div class="space-y-3">
            <h2 class="text-lg font-black">Choose Different Workout</h2>
            @foreach ($types as $type)
                <form method="POST" action="{{ route('workouts.start', $type) }}">
                    @csrf
                    <button class="button-secondary w-full justify-between" type="submit">
                        <span>{{ $type->name }}</span>
                        <span>Start</span>
                    </button>
                </form>
            @endforeach
        </div>
    </section>
@endsection
