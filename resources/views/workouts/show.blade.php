@extends('layouts.app', ['activeWorkout' => true])

@section('content')
    @php
        $progression = app(\App\Services\ProgressionService::class);
        $existingSets = $exercise->sets;
        $previousWeight = $previous ? $progression->primaryWeight($previous->sets) : null;
        $workingInput = old('working', []);
        $dropInput = old('drops', $existingSets->where('set_type', 'drop')->values()->map(fn ($set) => [
            'side' => $set->side,
            'weight' => $progression->formatWeight((float) $set->weight),
            'reps' => $set->reps,
        ])->all());
        $dropSlots = max(3, count($dropInput) + 3);
        $workingValue = function (?string $side, int $setNumber, string $field) use ($workingInput, $existingSets, $previousWeight, $exercise, $progression) {
            $path = $side === null ? $setNumber.'.'.$field : $side.'.'.$setNumber.'.'.$field;
            $oldValue = data_get($workingInput, $path);
            if ($oldValue !== null) {
                return $oldValue;
            }

            $set = $existingSets
                ->where('set_type', 'working')
                ->where('side', $side)
                ->where('set_number', $setNumber)
                ->first();

            if ($set) {
                return $field === 'weight' ? $progression->formatWeight((float) $set->weight) : $set->reps;
            }

            if ($field === 'weight' && $previousWeight !== null) {
                return $progression->formatWeight($previousWeight);
            }

            return '';
        };
    @endphp

    <section class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <a class="button-secondary w-auto" href="{{ route('dashboard') }}">Close</a>
            <p class="text-sm font-black uppercase text-lime-300">Exercise {{ $exercise->position }} of {{ $totalExercises }}</p>
        </div>

        <div>
            <p class="text-sm font-bold uppercase text-zinc-400">{{ $workout->workoutType->name }}</p>
            <h1 class="mt-2 text-3xl font-black leading-tight text-zinc-50">{{ $exercise->name }}</h1>
        </div>

        <div class="stat-grid">
            <div class="stat">
                <p class="text-xs font-bold uppercase text-zinc-400">Target</p>
                <p class="mt-1 text-xl font-black">{{ $exercise->working_sets }} x {{ $exercise->min_reps }}-{{ $exercise->max_reps }}</p>
            </div>
            <div class="stat">
                <p class="text-xs font-bold uppercase text-zinc-400">Rest</p>
                <p class="mt-1 text-xl font-black">{{ $exercise->restLabel() }}</p>
            </div>
        </div>

        <div class="panel">
            <h2 class="font-black">Previous Workout</h2>
            @if ($previous)
                <p class="mt-3 text-3xl font-black">{{ $progression->displayWeight($previous->sets) }}</p>
                @if ($previous->unilateral)
                    <p class="mt-1 text-zinc-400">Left {{ $progression->repsLine($previous->sets, 'left') }}</p>
                    <p class="text-zinc-400">Right {{ $progression->repsLine($previous->sets, 'right') }}</p>
                @else
                    <p class="mt-1 text-zinc-400">{{ $progression->repsLine($previous->sets) }}</p>
                @endif
                <p class="mt-3 inline-flex rounded bg-zinc-800 px-2 py-1 text-xs font-black uppercase text-lime-300">{{ $progression->label($previous->progression_result) }}</p>
            @else
                <p class="mt-2 text-zinc-400">No previous session for this exercise.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('workouts.exercises.save', [$workout, $exercise]) }}" class="space-y-4">
            @csrf

            @if ($exercise->unilateral)
                @foreach (['left' => 'Left', 'right' => 'Right'] as $side => $label)
                    <div class="panel space-y-3">
                        <h2 class="text-xl font-black">{{ $label }}</h2>
                        @for ($set = 1; $set <= $exercise->working_sets; $set++)
                            <div class="grid grid-cols-[1fr_1fr] gap-3">
                                <div class="space-y-2">
                                    <label for="working_{{ $side }}_{{ $set }}_weight">{{ $label }} set {{ $set }} weight</label>
                                    <input id="working_{{ $side }}_{{ $set }}_weight" name="working[{{ $side }}][{{ $set }}][weight]" value="{{ $workingValue($side, $set, 'weight') }}" inputmode="decimal" type="number" min="0" step="0.5" {{ $set === 1 ? 'data-copy-source=weight data-copy-group='.$side : 'data-copy-target='.$side }}>
                                </div>
                                <div class="space-y-2">
                                    <label for="working_{{ $side }}_{{ $set }}_reps">Reps</label>
                                    <input id="working_{{ $side }}_{{ $set }}_reps" name="working[{{ $side }}][{{ $set }}][reps]" value="{{ $workingValue($side, $set, 'reps') }}" inputmode="numeric" type="number" min="1" step="1">
                                </div>
                            </div>
                        @endfor
                    </div>
                @endforeach
            @else
                <div class="panel space-y-3">
                    @for ($set = 1; $set <= $exercise->working_sets; $set++)
                        <div class="grid grid-cols-[1fr_1fr] gap-3">
                            <div class="space-y-2">
                                <label for="working_{{ $set }}_weight">Set {{ $set }} weight</label>
                                <input id="working_{{ $set }}_weight" name="working[{{ $set }}][weight]" value="{{ $workingValue(null, $set, 'weight') }}" inputmode="decimal" type="number" min="0" step="0.5" {{ $set === 1 ? 'data-copy-source=weight data-copy-group=main' : 'data-copy-target=main' }}>
                            </div>
                            <div class="space-y-2">
                                <label for="working_{{ $set }}_reps">Reps</label>
                                <input id="working_{{ $set }}_reps" name="working[{{ $set }}][reps]" value="{{ $workingValue(null, $set, 'reps') }}" inputmode="numeric" type="number" min="1" step="1">
                            </div>
                        </div>
                    @endfor
                </div>
            @endif

            <div class="panel space-y-3" x-data="{ drops: {{ count($dropInput) }} }">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-black">Drop Sets</h2>
                    <button class="button-secondary w-auto" type="button" x-on:click="drops++">+ Add Drop Set</button>
                </div>

                @for ($i = 0; $i < $dropSlots; $i++)
                    @php($drop = $dropInput[$i] ?? [])
                    <div class="grid grid-cols-{{ $exercise->unilateral ? '3' : '2' }} gap-3" x-show="drops > {{ $i }}" x-cloak>
                        @if ($exercise->unilateral)
                            <div class="space-y-2">
                                <label for="drop_{{ $i }}_side">Side</label>
                                <select id="drop_{{ $i }}_side" name="drops[{{ $i }}][side]">
                                    <option value="">Side</option>
                                    <option value="left" @selected(($drop['side'] ?? '') === 'left')>Left</option>
                                    <option value="right" @selected(($drop['side'] ?? '') === 'right')>Right</option>
                                </select>
                            </div>
                        @endif
                        <div class="space-y-2">
                            <label for="drop_{{ $i }}_weight">Weight</label>
                            <input id="drop_{{ $i }}_weight" name="drops[{{ $i }}][weight]" value="{{ $drop['weight'] ?? '' }}" inputmode="decimal" type="number" min="0" step="0.5">
                        </div>
                        <div class="space-y-2">
                            <label for="drop_{{ $i }}_reps">Reps</label>
                            <input id="drop_{{ $i }}_reps" name="drops[{{ $i }}][reps]" value="{{ $drop['reps'] ?? '' }}" inputmode="numeric" type="number" min="1" step="1">
                        </div>
                    </div>
                @endfor
            </div>

            <div class="grid grid-cols-2 gap-3">
                @if ($exercise->position > 1)
                    <a class="button-secondary" href="{{ route('workouts.exercise', [$workout, $exercise->position - 1]) }}">Previous Exercise</a>
                @else
                    <span></span>
                @endif

                <button class="button-primary" type="submit">
                    {{ $exercise->position >= $totalExercises ? 'Finish Workout' : 'Done - Next Exercise' }}
                </button>
            </div>
        </form>
    </section>
@endsection
