<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\WorkoutRotationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, WorkoutRotationService $rotation): View
    {
        $user = $request->user();

        $inProgress = Workout::query()
            ->where('user_id', $user->id)
            ->where('status', Workout::STATUS_IN_PROGRESS)
            ->with('workoutType')
            ->latest('updated_at')
            ->first();

        $lastWorkout = Workout::query()
            ->where('user_id', $user->id)
            ->where('status', Workout::STATUS_COMPLETED)
            ->with('workoutType')
            ->orderByDesc('completed_at')
            ->first();

        $recentProgress = WorkoutExercise::query()
            ->with(['sets', 'workout.workoutType'])
            ->whereNotNull('completed_at')
            ->whereHas('workout', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', Workout::STATUS_COMPLETED))
            ->orderByDesc('completed_at')
            ->limit(4)
            ->get();

        return view('dashboard', [
            'nextType' => $rotation->nextFor($user),
            'inProgress' => $inProgress,
            'lastWorkout' => $lastWorkout,
            'recentProgress' => $recentProgress,
        ]);
    }
}
