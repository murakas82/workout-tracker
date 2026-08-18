@extends('layouts.app', ['activeWorkout' => true])

@section('content')
    @php($exercises = $workout->exercises->values())

    <section class="active-workout-screen">
        <div class="flex items-center justify-between gap-2">
            <a class="button-secondary button-compact w-auto" href="{{ route('workouts.show', $workout) }}">Back</a>
            <p class="text-xs font-black uppercase text-lime-300">{{ $workout->workoutType->name }}</p>
        </div>

        <div class="space-y-1">
            <p class="text-sm font-bold uppercase text-lime-300">Workout order</p>
            <h1 class="text-2xl font-black leading-tight text-zinc-50">Exercises</h1>
        </div>

        <div class="space-y-2">
            @foreach ($exercises as $index => $exercise)
                @php($previous = $index > 0 ? $exercises[$index - 1] : null)
                @php($next = $index < $exercises->count() - 1 ? $exercises[$index + 1] : null)
                @php($locked = $exercise->completed_at !== null)
                @php($canMoveUp = ! $locked && $previous && $previous->completed_at === null)
                @php($canMoveDown = ! $locked && $next && $next->completed_at === null)

                <div class="panel panel-compact flex items-center gap-3 {{ $locked ? 'opacity-60' : '' }}">
                    <div class="flex h-10 w-8 shrink-0 items-center justify-center rounded-md bg-zinc-800 text-sm font-black text-zinc-300">
                        {{ $exercise->position }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-black text-zinc-50">{{ $exercise->name }}</h2>
                        <p class="truncate text-xs text-zinc-400">
                            {{ $locked ? 'Done' : $exercise->working_sets.' x '.$exercise->min_reps.'-'.$exercise->max_reps.' reps' }}
                        </p>
                    </div>

                    @if ($locked)
                        <span class="rounded bg-zinc-800 px-2 py-1 text-[11px] font-black uppercase text-zinc-400">Done</span>
                    @else
                        <div class="grid grid-cols-2 gap-1">
                            <form method="POST" action="{{ route('workouts.exercises.move', [$workout, $exercise]) }}">
                                @csrf
                                <input type="hidden" name="direction" value="up">
                                <button class="button-secondary button-compact w-12 px-0" type="submit" @disabled(! $canMoveUp)>Up</button>
                            </form>

                            <form method="POST" action="{{ route('workouts.exercises.move', [$workout, $exercise]) }}">
                                @csrf
                                <input type="hidden" name="direction" value="down">
                                <button class="button-secondary button-compact w-12 px-0" type="submit" @disabled(! $canMoveDown)>Down</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="active-action-bar grid-cols-1">
            <a class="button-primary button-compact" href="{{ route('workouts.show', $workout) }}">Back to exercise</a>
        </div>
    </section>
@endsection
