@extends('layouts.app')

@section('content')
    @php($progression = app(\App\Services\ProgressionService::class))

    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">{{ $workout->completed_at?->format('d F Y') }}</p>
            <h1 class="mt-2 text-3xl font-black uppercase text-zinc-50">{{ $workout->workoutType->name }}</h1>
        </div>

        @foreach ($workout->exercises as $exercise)
            <div class="panel space-y-3">
                <div>
                    <h2 class="text-xl font-black">{{ $exercise->name }}</h2>
                    <p class="text-sm text-zinc-400">{{ $exercise->working_sets }} x {{ $exercise->min_reps }}-{{ $exercise->max_reps }} | Rest {{ $exercise->restLabel() }}</p>
                </div>

                <div>
                    <p class="text-2xl font-black">{{ $progression->displayWeight($exercise->sets) }}</p>
                    @if ($exercise->unilateral)
                        <p class="text-sm text-zinc-400">Left {{ $progression->repsLine($exercise->sets, 'left') }}</p>
                        <p class="text-sm text-zinc-400">Right {{ $progression->repsLine($exercise->sets, 'right') }}</p>
                    @else
                        <p class="text-sm text-zinc-400">{{ $progression->repsLine($exercise->sets) }}</p>
                    @endif
                </div>

                @php($drops = $exercise->sets->where('set_type', 'drop'))
                @if ($drops->isNotEmpty())
                    <div class="rounded-lg bg-zinc-950 p-3">
                        <p class="text-xs font-black uppercase text-zinc-400">Drop sets</p>
                        @foreach ($drops as $drop)
                            <p class="text-sm text-zinc-200">
                                {{ $drop->side ? ucfirst($drop->side).' - ' : '' }}{{ $progression->formatWeight((float) $drop->weight) }} kg x {{ $drop->reps }}
                            </p>
                        @endforeach
                    </div>
                @endif

                <p class="inline-flex rounded bg-zinc-800 px-2 py-1 text-xs font-black uppercase text-lime-300">{{ $progression->label($exercise->progression_result) }}</p>
            </div>
        @endforeach
    </section>
@endsection
