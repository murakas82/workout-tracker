@php
    $latest = $summary['latest'];
    $trendIcon = ['up' => '↑', 'flat' => '→', 'down' => '↓'][$summary['trend']] ?? null;
    $trendLabel = ['up' => 'Estimated max went up', 'flat' => 'Estimated max unchanged', 'down' => 'Estimated max went down'][$summary['trend']] ?? null;
    $trendClass = ['up' => 'text-lime-300', 'flat' => 'text-zinc-400', 'down' => 'text-amber-300'][$summary['trend']] ?? '';
@endphp

<a class="panel panel-compact block" href="{{ route('progress.show', $exercise) }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h3 class="truncate font-black {{ $exercise->active ? 'text-zinc-50' : 'text-zinc-400' }}">{{ $exercise->name }}</h3>

            @if ($latest)
                <p class="mt-1 flex items-baseline gap-2">
                    <span class="text-lg font-black text-zinc-100">{{ $progression->formatWeight($summary['latest_weight']) }} kg</span>
                    @if ($trendIcon)
                        <span class="text-base font-black {{ $trendClass }}" title="{{ $trendLabel }}" aria-label="{{ $trendLabel }}">{{ $trendIcon }}</span>
                    @endif
                </p>
                <p class="truncate text-xs text-zinc-400">
                    @if ($latest->unilateral)
                        L {{ $progression->repsLine($latest->sets, 'left') }} | R {{ $progression->repsLine($latest->sets, 'right') }}
                    @else
                        {{ $progression->repsLine($latest->sets) }}
                    @endif
                    @if ($summary['latest_at'])
                        · {{ $summary['latest_at']->diffForHumans() }}
                    @endif
                </p>
            @else
                <p class="mt-1 text-sm text-zinc-500">No sessions yet</p>
            @endif
        </div>

        @if ($summary['status'] === \App\Services\ExerciseProgressService::STATUS_READY)
            <span class="progress-badge progress-badge-ready">Ready to increase</span>
        @elseif ($summary['status'] === \App\Services\ExerciseProgressService::STATUS_STALLED)
            <span class="progress-badge progress-badge-stalled">Stalled · {{ $summary['sessions_at_weight'] }}x</span>
        @endif
    </div>
</a>
