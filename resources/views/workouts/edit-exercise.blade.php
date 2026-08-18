@extends('layouts.app')

@section('content')
    @php
        $progression = app(\App\Services\ProgressionService::class);
        $existingSets = $exercise->sets;
        $workingInput = old('working', []);
        $dropInput = old('drops', $existingSets->where('set_type', 'drop')->values()->map(fn ($set) => [
            'side' => $set->side,
            'weight' => $progression->formatWeight((float) $set->weight),
            'reps' => $set->reps,
        ])->all());
        $dropSlots = max(3, count($dropInput) + 3);
        $workingValue = function (?string $side, int $setNumber, string $field) use ($workingInput, $existingSets, $progression) {
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

            if (! $set) {
                return '';
            }

            return $field === 'weight' ? $progression->formatWeight((float) $set->weight) : $set->reps;
        };
    @endphp

    <section class="space-y-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-bold uppercase text-lime-300">{{ $workout->workoutType->name }}</p>
                <h1 class="mt-2 text-3xl font-black text-zinc-50">Edit exercise</h1>
            </div>
            <a class="button-secondary w-auto" href="{{ route('history.show', $workout) }}#exercise-{{ $exercise->id }}">Cancel</a>
        </div>

        <div class="panel space-y-1">
            <h2 class="text-xl font-black">{{ $exercise->name }}</h2>
            <p class="text-sm text-zinc-400">{{ $exercise->working_sets }} x {{ $exercise->min_reps }}-{{ $exercise->max_reps }} reps | {{ $exercise->restLabel() }}</p>
        </div>

        <form method="POST" action="{{ route('history.exercises.update', [$workout, $exercise]) }}" class="space-y-3">
            @csrf
            @method('PUT')

            @if ($exercise->unilateral)
                @foreach (['left' => 'Left', 'right' => 'Right'] as $side => $label)
                    <div class="panel panel-compact space-y-2">
                        <h3 class="text-base font-black">{{ $label }}</h3>
                        @for ($set = 1; $set <= $exercise->working_sets; $set++)
                            <div class="active-set-row">
                                <p class="set-number">{{ $set }}</p>
                                <div>
                                    <label class="sr-only" for="working_{{ $side }}_{{ $set }}_weight">{{ $label }} set {{ $set }} weight</label>
                                    <input class="input-compact" id="working_{{ $side }}_{{ $set }}_weight" name="working[{{ $side }}][{{ $set }}][weight]" value="{{ $workingValue($side, $set, 'weight') }}" placeholder="Weight" inputmode="decimal" type="number" min="0" step="0.01">
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
                                <input class="input-compact" id="working_{{ $set }}_weight" name="working[{{ $set }}][weight]" value="{{ $workingValue(null, $set, 'weight') }}" placeholder="Weight" inputmode="decimal" type="number" min="0" step="0.01">
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
                                <input class="input-compact" id="drop_{{ $i }}_weight" name="drops[{{ $i }}][weight]" value="{{ $drop['weight'] ?? '' }}" placeholder="Weight" inputmode="decimal" type="number" min="0" step="0.01">
                            </div>
                            <div>
                                <label class="sr-only" for="drop_{{ $i }}_reps">Drop set {{ $i + 1 }} reps</label>
                                <input class="input-compact" id="drop_{{ $i }}_reps" name="drops[{{ $i }}][reps]" value="{{ $drop['reps'] ?? '' }}" placeholder="Reps" inputmode="numeric" type="number" min="1" step="1">
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            <button class="button-primary" type="submit">Save changes</button>
        </form>
    </section>
@endsection
