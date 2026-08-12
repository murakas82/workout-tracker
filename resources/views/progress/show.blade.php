@extends('layouts.app')

@section('content')
    @php
        $progression = app(\App\Services\ProgressionService::class);
        $maxWeight = max(1, $chartRows->max('weight') ?: 1);
        $maxVolume = max(1, $chartRows->max('volume') ?: 1);
    @endphp

    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">{{ $exercise->workoutType->name }}</p>
            <h1 class="mt-2 text-3xl font-black leading-tight text-zinc-50">{{ $exercise->name }}</h1>
        </div>

        <div class="stat-grid">
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Current Weight</p><p class="text-2xl font-black">{{ $progression->formatWeight($stats['current_weight']) }} kg</p></div>
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Previous Weight</p><p class="text-2xl font-black">{{ $progression->formatWeight($stats['previous_weight']) }} kg</p></div>
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Highest Weight</p><p class="text-2xl font-black">{{ $progression->formatWeight($stats['highest_weight']) }} kg</p></div>
            <div class="stat"><p class="text-xs uppercase text-zinc-400">Sessions</p><p class="text-2xl font-black">{{ $stats['sessions'] }}</p></div>
        </div>

        <div class="panel">
            <p class="text-sm text-zinc-400">Previous reps</p>
            <p class="text-2xl font-black">{{ $stats['previous_reps'] }}</p>
            <p class="mt-3 text-sm text-zinc-400">Best reps at current weight</p>
            <p class="text-2xl font-black">{{ $stats['best_reps_current_weight'] }}</p>
            <p class="mt-3 inline-flex rounded bg-zinc-800 px-2 py-1 text-xs font-black uppercase text-lime-300">{{ $stats['latest_recommendation'] }}</p>
        </div>

        <div class="panel space-y-3">
            <h2 class="font-black">Working Weight Over Time</h2>
            @forelse ($chartRows as $row)
                <div>
                    <div class="mb-1 flex justify-between text-xs text-zinc-400"><span>{{ $row['date'] }}</span><span>{{ $progression->formatWeight($row['weight']) }} kg</span></div>
                    <div class="h-3 rounded bg-zinc-800"><div class="h-3 rounded bg-lime-400" style="width: {{ max(4, ($row['weight'] / $maxWeight) * 100) }}%"></div></div>
                </div>
            @empty
                <p class="text-zinc-400">No chart data yet.</p>
            @endforelse
        </div>

        <div class="panel space-y-3">
            <h2 class="font-black">Exercise Volume Over Time</h2>
            @forelse ($chartRows as $row)
                <div>
                    <div class="mb-1 flex justify-between text-xs text-zinc-400"><span>{{ $row['date'] }}</span><span>{{ number_format($row['volume']) }}</span></div>
                    <div class="h-3 rounded bg-zinc-800"><div class="h-3 rounded bg-amber-400" style="width: {{ max(4, ($row['volume'] / $maxVolume) * 100) }}%"></div></div>
                </div>
            @empty
                <p class="text-zinc-400">No chart data yet.</p>
            @endforelse
        </div>

        <div class="space-y-3">
            @foreach ($records as $record)
                <div class="panel">
                    <p class="text-sm font-bold text-zinc-400">{{ $record->workout->completed_at?->format('d M') }}</p>
                    <p class="mt-1 text-2xl font-black">{{ $progression->displayWeight($record->sets) }}</p>
                    @if ($record->unilateral)
                        <p class="text-sm text-zinc-400">Left {{ $progression->repsLine($record->sets, 'left') }}</p>
                        <p class="text-sm text-zinc-400">Right {{ $progression->repsLine($record->sets, 'right') }}</p>
                    @else
                        <p class="text-sm text-zinc-400">{{ $progression->repsLine($record->sets) }}</p>
                    @endif
                    <p class="mt-2 text-xs font-black uppercase text-lime-300">{{ $progression->shortLabel($record->progression_result) }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endsection
