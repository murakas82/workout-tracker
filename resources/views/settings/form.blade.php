@extends('layouts.app')

@section('content')
    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">Settings</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">{{ $exercise->exists ? 'Edit exercise' : 'New exercise' }}</h1>
        </div>

        <form method="POST" action="{{ $exercise->exists ? route('settings.exercises.update', $exercise) : route('settings.exercises.store') }}" class="space-y-4">
            @csrf
            @if ($exercise->exists)
                @method('PUT')
            @endif

            <div class="space-y-2">
                <label for="name">Exercise name</label>
                <input id="name" name="name" value="{{ old('name', $exercise->name) }}" required>
            </div>

            <div class="space-y-2">
                <label for="muscle_group">Muscle group</label>
                <input id="muscle_group" name="muscle_group" value="{{ old('muscle_group', $exercise->muscle_group) }}">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2">
                    <label for="workout_type_id">Workout</label>
                    <select id="workout_type_id" name="workout_type_id">
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}" @selected((int) old('workout_type_id', $exercise->workout_type_id) === $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-2">
                    <label for="sort_order">Order</label>
                    <input id="sort_order" name="sort_order" value="{{ old('sort_order', $exercise->sort_order ?: 1) }}" inputmode="numeric" type="number" min="1" step="1">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="space-y-2">
                    <label for="working_sets">Sets</label>
                    <input id="working_sets" name="working_sets" value="{{ old('working_sets', $exercise->working_sets) }}" inputmode="numeric" type="number" min="1" step="1">
                </div>
                <div class="space-y-2">
                    <label for="min_reps">Min reps</label>
                    <input id="min_reps" name="min_reps" value="{{ old('min_reps', $exercise->min_reps) }}" inputmode="numeric" type="number" min="1" step="1">
                </div>
                <div class="space-y-2">
                    <label for="max_reps">Max reps</label>
                    <input id="max_reps" name="max_reps" value="{{ old('max_reps', $exercise->max_reps) }}" inputmode="numeric" type="number" min="1" step="1">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2">
                    <label for="rest_min_seconds">Min rest sec</label>
                    <input id="rest_min_seconds" name="rest_min_seconds" value="{{ old('rest_min_seconds', $exercise->rest_min_seconds) }}" inputmode="numeric" type="number" min="0" step="15">
                </div>
                <div class="space-y-2">
                    <label for="rest_max_seconds">Max rest sec</label>
                    <input id="rest_max_seconds" name="rest_max_seconds" value="{{ old('rest_max_seconds', $exercise->rest_max_seconds) }}" inputmode="numeric" type="number" min="0" step="15">
                </div>
            </div>

            <input type="hidden" name="active" value="0">
            <label class="flex items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-900 p-3">
                <input class="h-5 w-5" type="checkbox" name="active" value="1" @checked(old('active', $exercise->active ?? true))>
                <span>Active</span>
            </label>

            <input type="hidden" name="unilateral" value="0">
            <label class="flex items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-900 p-3">
                <input class="h-5 w-5" type="checkbox" name="unilateral" value="1" @checked(old('unilateral', $exercise->unilateral))>
                <span>Unilateral</span>
            </label>

            <button class="button-primary" type="submit">Save exercise</button>
        </form>

        @if ($exercise->exists)
            <form method="POST" action="{{ route('settings.exercises.archive', $exercise) }}" onsubmit="return confirm('Archive this exercise? Workout history will stay available.');">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-danger w-full">Archive exercise</button>
            </form>
        @endif
    </section>
@endsection
