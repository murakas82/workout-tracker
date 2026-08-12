@extends('layouts.app')

@section('content')
    @php($progression = app(\App\Services\ProgressionService::class))

    <section class="space-y-5">
        <div>
            <p class="text-sm font-black uppercase text-lime-300">Completed</p>
            <h1 class="mt-2 text-4xl font-black uppercase text-zinc-50">{{ $workout->workoutType->name }} Completed</h1>
        </div>

        <div class="stat-grid">
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Exercises</p><p class="text-2xl font-black">{{ $stats['exercises_completed'] }}</p></div>
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Working Sets</p><p class="text-2xl font-black">{{ $stats['working_sets_completed'] }}</p></div>
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Weight Up</p><p class="text-2xl font-black">{{ $stats['weight_increased'] }}</p></div>
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Targets</p><p class="text-2xl font-black">{{ $stats['targets_reached'] }}</p></div>
        </div>

        <div class="space-y-3">
            @foreach ($workout->exercises as $exercise)
                <div class="panel">
                    <h2 class="font-black">{{ $exercise->name }}</h2>
                    <p class="mt-2 text-2xl font-black">{{ $progression->displayWeight($exercise->sets) }}</p>
                    @if ($exercise->unilateral)
                        <p class="text-sm text-zinc-400">Left {{ $progression->repsLine($exercise->sets, 'left') }}</p>
                        <p class="text-sm text-zinc-400">Right {{ $progression->repsLine($exercise->sets, 'right') }}</p>
                    @else
                        <p class="text-sm text-zinc-400">{{ $progression->repsLine($exercise->sets) }}</p>
                    @endif
                    <p class="mt-3 inline-flex rounded bg-zinc-800 px-2 py-1 text-xs font-black uppercase text-lime-300">{{ $progression->label($exercise->progression_result) }}</p>
                </div>
            @endforeach
        </div>

        <a href="{{ route('dashboard') }}" class="button-primary">Back Home</a>
    </section>
@endsection
