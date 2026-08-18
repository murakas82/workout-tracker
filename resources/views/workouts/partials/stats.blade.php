@php
    $formatNumber = fn (float|int $value) => number_format((float) $value, 0);
    $formatWeight = fn (float|int|null $value) => $value === null
        ? '-'
        : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $bestSet = $stats['heaviest_set'];
@endphp

<div class="stat-grid">
    <div class="stat">
        <p class="text-xs uppercase text-zinc-400">Volume</p>
        <p class="text-2xl font-black">{{ $formatNumber($stats['total_volume']) }} kg</p>
    </div>
    <div class="stat">
        <p class="text-xs uppercase text-zinc-400">Reps</p>
        <p class="text-2xl font-black">{{ $stats['total_reps'] }}</p>
    </div>
    <div class="stat">
        <p class="text-xs uppercase text-zinc-400">Working Sets</p>
        <p class="text-2xl font-black">{{ $stats['working_sets_completed'] }}</p>
    </div>
    <div class="stat">
        <p class="text-xs uppercase text-zinc-400">Drop Sets</p>
        <p class="text-2xl font-black">{{ $stats['drop_sets_completed'] }}</p>
    </div>
    <div class="stat">
        <p class="text-xs uppercase text-zinc-400">Targets</p>
        <p class="text-2xl font-black">{{ $stats['targets_reached'] }}</p>
    </div>
    <div class="stat">
        <p class="text-xs uppercase text-zinc-400">Heaviest</p>
        <p class="text-2xl font-black">{{ $bestSet ? $formatWeight($bestSet['weight']).' kg' : '-' }}</p>
        @if ($bestSet)
            <p class="truncate text-xs text-zinc-400">{{ $bestSet['reps'] }} reps</p>
        @endif
    </div>
</div>

<div class="panel space-y-3">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-black">Volume by Exercise</h2>
            <p class="text-sm text-zinc-400">{{ $formatNumber($stats['working_volume']) }} kg working | {{ $formatNumber($stats['drop_volume']) }} kg drops</p>
        </div>
    </div>
    <div class="chart-frame" data-workout-chart="exercise-volume">
        <canvas role="img" aria-label="Volume by exercise chart">Volume by exercise chart</canvas>
        <script type="application/json">@json($chartData['exercise_volume'])</script>
    </div>
</div>

@if (count($chartData['type_volume_history']['labels']) > 1)
    <div class="panel space-y-3">
        <div>
            <h2 class="text-lg font-black">{{ $workout->workoutType->name }} Volume Trend</h2>
            <p class="text-sm text-zinc-400">Last {{ count($chartData['type_volume_history']['labels']) }} completed workouts</p>
        </div>
        <div class="chart-frame" data-workout-chart="type-volume-history">
            <canvas role="img" aria-label="Workout volume trend chart">Workout volume trend chart</canvas>
            <script type="application/json">@json($chartData['type_volume_history'])</script>
        </div>
    </div>
@endif
