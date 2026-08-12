@extends('layouts.app')

@section('content')
    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">History</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">Completed workouts</h1>
        </div>

        <div class="space-y-3">
            @forelse ($workouts as $workout)
                <a class="panel flex items-center justify-between gap-3" href="{{ route('history.show', $workout) }}">
                    <div>
                        <p class="text-xl font-black uppercase">{{ $workout->completed_at?->format('d M') }} - {{ $workout->workoutType->name }}</p>
                        <p class="text-sm text-zinc-400">{{ $workout->completed_at?->format('Y') }}</p>
                    </div>
                    <span class="text-zinc-500">Open</span>
                </a>
            @empty
                <div class="panel text-zinc-400">No completed workouts yet.</div>
            @endforelse
        </div>

        {{ $workouts->links() }}
    </section>
@endsection
