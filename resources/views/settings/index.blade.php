@extends('layouts.app')

@section('content')
    <section class="space-y-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-bold uppercase text-lime-300">Settings</p>
                <h1 class="mt-2 text-3xl font-black text-zinc-50">Exercises</h1>
            </div>
            <a href="{{ route('settings.exercises.create') }}" class="button-secondary w-auto">Add</a>
        </div>

        @foreach ($types as $type)
            <div class="space-y-3">
                <h2 class="text-xl font-black uppercase">{{ $type->name }}</h2>
                @foreach ($type->exercises as $exercise)
                    <div class="panel">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-black {{ $exercise->active ? 'text-zinc-50' : 'text-zinc-500' }}">{{ $exercise->name }}</h3>
                                <p class="text-sm text-zinc-400">{{ $exercise->working_sets }} x {{ $exercise->min_reps }}-{{ $exercise->max_reps }} | {{ $exercise->muscle_group }}</p>
                                @if ($exercise->unilateral)
                                    <p class="text-xs font-black uppercase text-amber-300">Unilateral</p>
                                @endif
                            </div>
                            <a href="{{ route('settings.exercises.edit', $exercise) }}" class="button-secondary w-auto">Edit</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </section>
@endsection
