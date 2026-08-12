@extends('layouts.app')

@section('content')
    @php($progression = app(\App\Services\ProgressionService::class))

    <section class="space-y-5">
        @if ($inProgress)
            <div class="panel border-amber-400/40 bg-amber-400/10">
                <p class="text-sm font-black uppercase text-amber-200">Workout in progress</p>
                <h2 class="mt-2 text-3xl font-black uppercase text-zinc-50">{{ $inProgress->workoutType->name }}</h2>
                <div class="mt-4 grid grid-cols-1 gap-3">
                    <a href="{{ route('workouts.show', $inProgress) }}" class="button-primary">Continue workout</a>
                    <form method="POST" action="{{ route('workouts.cancel', $inProgress) }}" onsubmit="return confirm('Cancel this workout? Entered sets will be kept only in the cancelled record.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button-danger w-full">Cancel workout</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="panel">
            <p class="text-sm font-black uppercase text-lime-300">Next workout</p>
            <h1 class="mt-2 text-6xl font-black uppercase leading-none text-zinc-50">{{ $nextType->name }}</h1>
            <form method="POST" action="{{ route('workouts.start', $nextType) }}" class="mt-5">
                @csrf
                <button class="button-primary" type="submit">Start {{ $nextType->name }} workout</button>
            </form>
            <a href="{{ route('workouts.index') }}" class="mt-3 button-secondary w-full">Choose different workout</a>
        </div>

        <div class="panel">
            <h2 class="text-lg font-black text-zinc-50">Last Workout</h2>
            @if ($lastWorkout)
                <p class="mt-3 text-2xl font-black uppercase text-zinc-50">{{ $lastWorkout->workoutType->name }}</p>
                <p class="text-zinc-400">{{ $lastWorkout->completed_at?->format('d F Y') }}</p>
            @else
                <p class="mt-3 text-zinc-400">No completed workouts yet.</p>
            @endif
        </div>

        <div class="space-y-3">
            <h2 class="text-lg font-black text-zinc-50">Recent Progress</h2>

            @forelse ($recentProgress as $record)
                <a href="{{ $record->exercise_id ? route('progress.show', $record->exercise_id) : '#' }}" class="panel block">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-black text-zinc-50">{{ $record->name }}</h3>
                            <p class="mt-1 text-2xl font-black text-zinc-50">{{ $progression->displayWeight($record->sets) }}</p>
                            @if ($record->unilateral)
                                <p class="text-sm text-zinc-400">L {{ $progression->repsLine($record->sets, 'left') }} | R {{ $progression->repsLine($record->sets, 'right') }}</p>
                            @else
                                <p class="text-sm text-zinc-400">{{ $progression->repsLine($record->sets) }}</p>
                            @endif
                        </div>
                        <span class="rounded bg-zinc-800 px-2 py-1 text-xs font-black uppercase text-lime-300">{{ $progression->shortLabel($record->progression_result) }}</span>
                    </div>
                </a>
            @empty
                <div class="panel text-zinc-400">Progress appears here after completed workouts.</div>
            @endforelse
        </div>
    </section>
@endsection
