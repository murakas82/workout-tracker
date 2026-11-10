@extends('layouts.app')

@section('content')
    @php
        $progression = app(\App\Services\ProgressionService::class);
        $latest = $stats['latest'];
        $kg = fn ($weight) => $weight === null ? '-' : $progression->formatWeight($weight).' kg';
    @endphp

    <section class="space-y-5">
        <div>
            <a class="text-sm font-bold text-zinc-400" href="{{ route('progress.index') }}">← Progress</a>
            <p class="mt-3 text-sm font-bold uppercase text-lime-300">{{ $exercise->workoutType->name }}@unless ($exercise->active) · Archived @endunless</p>
            <h1 class="mt-2 text-3xl font-black leading-tight text-zinc-50">{{ $exercise->name }}</h1>
        </div>

        @if (! $latest)
            <div class="panel">
                <p class="text-zinc-400">No completed sessions yet. Progress shows up here after your first workout with this exercise.</p>
            </div>
        @else
            <div class="stat-grid">
                <div class="stat"><p class="text-xs uppercase text-zinc-400">Current weight</p><p class="text-2xl font-black">{{ $kg($stats['latest_weight']) }}</p></div>
                <div class="stat"><p class="text-xs uppercase text-zinc-400">Previous weight</p><p class="text-2xl font-black">{{ $kg($stats['previous_weight']) }}</p></div>
                <div class="stat"><p class="text-xs uppercase text-zinc-400">Est. 1-rep max</p><p class="text-2xl font-black">{{ $kg($stats['latest_estimated_max']) }}</p></div>
                <div class="stat"><p class="text-xs uppercase text-zinc-400">Best est. max</p><p class="text-2xl font-black">{{ $kg($stats['best_estimated_max']) }}</p></div>
                <div class="stat"><p class="text-xs uppercase text-zinc-400">Highest weight</p><p class="text-2xl font-black">{{ $kg($stats['highest_weight']) }}</p></div>
                <div class="stat"><p class="text-xs uppercase text-zinc-400">Sessions</p><p class="text-2xl font-black">{{ $stats['sessions'] }}</p></div>
            </div>

            <div class="panel space-y-3">
                <div>
                    <p class="text-sm text-zinc-400">Latest reps</p>
                    @if ($latest->unilateral)
                        <p class="text-xl font-black">L {{ $progression->repsLine($latest->sets, 'left') }}</p>
                        <p class="text-xl font-black">R {{ $progression->repsLine($latest->sets, 'right') }}</p>
                    @else
                        <p class="text-2xl font-black">{{ $progression->repsLine($latest->sets) }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-sm text-zinc-400">Best set at current weight</p>
                    <p class="text-2xl font-black">{{ $stats['best_set_at_current_weight'] !== null ? $stats['best_set_at_current_weight'].' reps' : '-' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex rounded bg-zinc-800 px-2 py-1 text-xs font-black uppercase text-lime-300">{{ $progression->shortLabel($latest->progression_result) }}</span>
                    @if ($stats['status'] === \App\Services\ExerciseProgressService::STATUS_STALLED)
                        <span class="progress-badge progress-badge-stalled">Stalled · {{ $stats['sessions_at_weight'] }} sessions at this weight</span>
                    @endif
                </div>
            </div>

            <div class="panel space-y-3">
                <div>
                    <h2 class="font-black">Weight and estimated max</h2>
                    <p class="text-sm text-zinc-400">The estimated max rises when you add reps at the same weight.</p>
                </div>
                <div class="chart-frame" data-workout-chart="exercise-progress">
                    <canvas role="img" aria-label="Working weight and estimated one-rep max over time">Working weight and estimated one-rep max over time</canvas>
                    <script type="application/json">@json($chartData)</script>
                </div>
            </div>

            <div class="panel space-y-3">
                <h2 class="font-black">Working volume per session</h2>
                <div class="chart-frame" data-workout-chart="exercise-volume-history">
                    <canvas role="img" aria-label="Working volume per session">Working volume per session</canvas>
                    <script type="application/json">@json($chartData)</script>
                </div>
            </div>

            <div class="space-y-3">
                <h2 class="text-xl font-black uppercase">{{ $hiddenSessions > 0 ? 'Recent sessions' : 'Sessions' }}</h2>
                @foreach ($records as $record)
                    <a class="panel block" href="{{ route('history.show', $record->workout) }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-zinc-400">{{ $record->workout->completed_at?->format('d M Y') }}</p>
                                <p class="mt-1 text-2xl font-black">{{ $progression->displayWeight($record->sets) }}</p>
                                @if ($record->unilateral)
                                    <p class="text-sm text-zinc-400">Left {{ $progression->repsLine($record->sets, 'left') }}</p>
                                    <p class="text-sm text-zinc-400">Right {{ $progression->repsLine($record->sets, 'right') }}</p>
                                @else
                                    <p class="text-sm text-zinc-400">{{ $progression->repsLine($record->sets) }}</p>
                                @endif
                            </div>
                            <p class="shrink-0 text-xs font-bold text-zinc-500">{{ $kg(app(\App\Services\ExerciseProgressService::class)->estimatedOneRepMax($record->sets)) }} est.</p>
                        </div>
                        <p class="mt-2 text-xs font-black uppercase text-lime-300">{{ $progression->shortLabel($record->progression_result) }}</p>
                    </a>
                @endforeach

                @if ($hiddenSessions > 0)
                    <a class="button-secondary w-full" href="{{ route('progress.show', [$exercise, 'all' => 1]) }}">Show all {{ $stats['sessions'] }} sessions</a>
                @endif
            </div>
        @endif
    </section>
@endsection
