@extends('layouts.app')

@section('content')
    @php($progression = app(\App\Services\ProgressionService::class))

    <section class="space-y-5">
        <div>
            <p class="text-sm font-bold uppercase text-lime-300">Progress</p>
            <h1 class="mt-2 text-3xl font-black text-zinc-50">Exercise history</h1>
        </div>

        @forelse ($groups as $group)
            <div class="space-y-2">
                <h2 class="text-xl font-black uppercase">{{ $group['name'] }}</h2>
                @foreach ($group['rows'] as $row)
                    @include('progress.partials.row', ['exercise' => $row['exercise'], 'summary' => $row['summary']])
                @endforeach
            </div>
        @empty
            <p class="text-zinc-400">No exercises yet.</p>
        @endforelse

        @if ($archived->isNotEmpty())
            <details class="space-y-2">
                <summary class="cursor-pointer text-xl font-black uppercase text-zinc-400">Archived ({{ $archived->count() }})</summary>
                <div class="mt-2 space-y-2">
                    @foreach ($archived as $row)
                        @include('progress.partials.row', ['exercise' => $row['exercise'], 'summary' => $row['summary']])
                    @endforeach
                </div>
            </details>
        @endif
    </section>
@endsection
