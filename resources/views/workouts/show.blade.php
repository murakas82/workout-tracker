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

    <section class="active-workout-screen">
        <div class="flex items-center justify-between gap-2">
            <a class="button-secondary button-compact w-auto" href="{{ route('dashboard') }}">Close</a>
            <p class="text-xs font-black uppercase text-lime-300">Exercise {{ $exercise->position }} of {{ $totalExercises }}</p>
        </div>

        <div class="space-y-1">
            <div class="flex items-center justify-between gap-2 text-xs font-bold uppercase text-zinc-400">
                <span>{{ $workout->workoutType->name }}</span>
                <span>{{ $exercise->working_sets }} x {{ $exercise->min_reps }}-{{ $exercise->max_reps }} reps | {{ $exercise->restLabel() }}</span>
            </div>
            <h1 class="text-2xl font-black leading-tight text-zinc-50">{{ $exercise->name }}</h1>
        </div>

        <div class="active-workout-previous">
            <p class="text-xs font-black uppercase text-zinc-500">Previous</p>
            @if ($previous)
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-black text-zinc-100">{{ $progression->displayWeight($previous->sets) }}</p>
                    @if ($previous->unilateral)
                        <p class="truncate text-xs text-zinc-400">L {{ $progression->repsLine($previous->sets, 'left') }} | R {{ $progression->repsLine($previous->sets, 'right') }}</p>
                    @else
                        <p class="truncate text-xs text-zinc-400">{{ $progression->repsLine($previous->sets) }}</p>
                    @endif
                </div>
                <p class="rounded bg-zinc-800 px-2 py-1 text-[11px] font-black uppercase text-lime-300">{{ $progression->label($previous->progression_result) }}</p>
            @else
                <p class="flex-1 text-sm text-zinc-400">No previous session.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('workouts.exercises.save', [$workout, $exercise]) }}" class="active-workout-form">
            @csrf

            @if ($exercise->unilateral)
                @foreach (['left' => 'Left', 'right' => 'Right'] as $side => $label)
                    <div class="panel panel-compact space-y-2">
                        <h2 class="text-base font-black">{{ $label }}</h2>
                        @for ($set = 1; $set <= $exercise->working_sets; $set++)
                            <div class="active-set-row">
                                <p class="set-number">{{ $set }}</p>
                                <div>
                                    <label class="sr-only" for="working_{{ $side }}_{{ $set }}_weight">{{ $label }} set {{ $set }} weight</label>
                                    <input class="input-compact" id="working_{{ $side }}_{{ $set }}_weight" name="working[{{ $side }}][{{ $set }}][weight]" value="{{ $workingValue($side, $set, 'weight') }}" placeholder="Weight" inputmode="decimal" type="number" min="0" step="0.5" {{ $set === 1 ? 'data-copy-source=weight data-copy-group='.$side : 'data-copy-target='.$side }}>
                                </div>
                                <div>
                                    <label class="sr-only" for="working_{{ $side }}_{{ $set }}_reps">{{ $label }} set {{ $set }} reps</label>
                                    <input class="input-compact" id="working_{{ $side }}_{{ $set }}_reps" name="working[{{ $side }}][{{ $set }}][reps]" value="{{ $workingValue($side, $set, 'reps') }}" placeholder="Reps" inputmode="numeric" type="number" min="1" step="1">
                                </div>
                            </div>
                        @endfor
                    </div>
                @endforeach
            @else
                <div class="panel panel-compact space-y-2">
                    @for ($set = 1; $set <= $exercise->working_sets; $set++)
                        <div class="active-set-row">
                            <p class="set-number">{{ $set }}</p>
                            <div>
                                <label class="sr-only" for="working_{{ $set }}_weight">Set {{ $set }} weight</label>
                                <input class="input-compact" id="working_{{ $set }}_weight" name="working[{{ $set }}][weight]" value="{{ $workingValue(null, $set, 'weight') }}" placeholder="Weight" inputmode="decimal" type="number" min="0" step="0.5" {{ $set === 1 ? 'data-copy-source=weight data-copy-group=main' : 'data-copy-target=main' }}>
                            </div>
                            <div>
                                <label class="sr-only" for="working_{{ $set }}_reps">Set {{ $set }} reps</label>
                                <input class="input-compact" id="working_{{ $set }}_reps" name="working[{{ $set }}][reps]" value="{{ $workingValue(null, $set, 'reps') }}" placeholder="Reps" inputmode="numeric" type="number" min="1" step="1">
                            </div>
                        </div>
                    @endfor
                </div>
            @endif

            <div class="panel panel-compact space-y-2" x-data="{ open: {{ count($dropInput) > 0 ? 'true' : 'false' }}, drops: {{ count($dropInput) }} }">
                <div class="flex items-center justify-between gap-2">
                    <button class="text-sm font-black text-zinc-300" type="button" x-on:click="open = !open">
                        Drop sets <span class="text-zinc-500" x-text="open ? '-' : '+'"></span>
                    </button>
                    <button class="button-secondary button-compact w-auto" type="button" x-on:click="open = true; drops++">Add</button>
                </div>

                <div class="space-y-2" x-show="open" x-cloak>
                    @for ($i = 0; $i < $dropSlots; $i++)
                        @php($drop = $dropInput[$i] ?? [])
                        <div class="grid grid-cols-{{ $exercise->unilateral ? '3' : '2' }} gap-2" x-show="drops > {{ $i }}" x-cloak>
                            @if ($exercise->unilateral)
                                <div>
                                    <label class="sr-only" for="drop_{{ $i }}_side">Side</label>
                                    <select class="input-compact" id="drop_{{ $i }}_side" name="drops[{{ $i }}][side]">
                                        <option value="">Side</option>
                                        <option value="left" @selected(($drop['side'] ?? '') === 'left')>Left</option>
                                        <option value="right" @selected(($drop['side'] ?? '') === 'right')>Right</option>
                                    </select>
                                </div>
                            @endif
                            <div>
                                <label class="sr-only" for="drop_{{ $i }}_weight">Drop set {{ $i + 1 }} weight</label>
                                <input class="input-compact" id="drop_{{ $i }}_weight" name="drops[{{ $i }}][weight]" value="{{ $drop['weight'] ?? '' }}" placeholder="Weight" inputmode="decimal" type="number" min="0" step="0.5">
                            </div>
                            <div>
                                <label class="sr-only" for="drop_{{ $i }}_reps">Drop set {{ $i + 1 }} reps</label>
                                <input class="input-compact" id="drop_{{ $i }}_reps" name="drops[{{ $i }}][reps]" value="{{ $drop['reps'] ?? '' }}" placeholder="Reps" inputmode="numeric" type="number" min="1" step="1">
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            <div class="active-action-bar">
                @if ($exercise->position > 1)
                    <a class="button-secondary button-compact" href="{{ route('workouts.exercise', [$workout, $exercise->position - 1]) }}">Previous</a>
                @else
                    <span></span>
                @endif

                <button class="button-primary button-compact" type="submit">
                    {{ $exercise->position >= $totalExercises ? 'Finish' : 'Done - Next' }}
                </button>
            </div>
        </form>
    </section>
@endsection
