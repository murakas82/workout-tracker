<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Services\WorkoutStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoryController extends Controller
{
    public function index(Request $request): View
    {
        $workouts = Workout::query()
            ->where('user_id', $request->user()->id)
            ->where('status', Workout::STATUS_COMPLETED)
            ->with('workoutType')
            ->orderByDesc('completed_at')
            ->paginate(20);

        return view('history.index', compact('workouts'));
    }

    public function show(Request $request, Workout $workout, WorkoutStatsService $workoutStats): View
    {
        abort_unless($workout->user_id === $request->user()->id, 404);

        $workout->load('workoutType', 'exercises.sets');

        return view('history.show', [
            'workout' => $workout,
            'stats' => $workoutStats->forWorkout($workout),
            'chartData' => $workoutStats->chartData($workout),
        ]);
    }
}
