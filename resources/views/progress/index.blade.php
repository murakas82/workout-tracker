@extends('layouts.app')

@section('content')
    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">Progress</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">Exercise history</h1>
        </div>

        <div class="space-y-3">
            @foreach ($exercises as $exercise)
                <a class="panel block" href="{{ route('progress.show', $exercise) }}">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="font-black">{{ $exercise->name }}</h2>
                            <p class="text-sm text-zinc-400">{{ $exercise->workoutType->name }} | {{ $exercise->working_sets }} x {{ $exercise->min_reps }}-{{ $exercise->max_reps }}</p>
                        </div>
                        <span class="text-zinc-500">Open</span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endsection
