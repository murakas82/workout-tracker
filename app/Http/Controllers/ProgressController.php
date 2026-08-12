<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\ProgressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ProgressController extends Controller
{
    public function index(Request $request): View
    {
        $exercises = Exercise::query()
            ->with('workoutType')
            ->where('active', true)
            ->orderBy('workout_type_id')
            ->orderBy('sort_order')
            ->get();

        return view('progress.index', compact('exercises'));
    }

    public function show(Request $request, Exercise $exercise, ProgressionService $progression): View
    {
        $records = WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workouts', 'workouts.id', '=', 'workout_exercises.workout_id')
            ->where('workout_exercises.exercise_id', $exercise->id)
            ->where('workouts.user_id', $request->user()->id)
            ->where('workouts.status', Workout::STATUS_COMPLETED)
            ->with(['sets', 'workout'])
            ->orderByDesc('workouts.completed_at')
            ->get();

        $latest = $records->first();
        $previous = $records->skip(1)->first();
        $currentWeight = $latest ? $progression->primaryWeight($latest->sets) : null;

        $stats = [
            'current_weight' => $currentWeight,
            'previous_weight' => $previous ? $progression->primaryWeight($previous->sets) : null,
            'previous_reps' => $latest ? $progression->repsLine($latest->sets) : '-',
            'highest_weight' => $records->map(fn (WorkoutExercise $record) => $progression->primaryWeight($record->sets))->filter()->max(),
            'best_reps_current_weight' => $this->bestRepsAtWeight($records, $currentWeight),
            'sessions' => $records->count(),
            'latest_recommendation' => $latest ? $progression->shortLabel($latest->progression_result) : 'No history yet',
        ];

        $chartRows = $records
            ->sortBy(fn (WorkoutExercise $record) => $record->workout->completed_at?->timestamp ?? 0)
            ->values()
            ->map(fn (WorkoutExercise $record) => [
                'date' => $record->workout->completed_at?->format('d M') ?? '',
                'weight' => $progression->primaryWeight($record->sets) ?? 0,
                'volume' => $progression->normalVolume($record->sets),
            ]);

        return view('progress.show', compact('exercise', 'records', 'stats', 'chartRows'));
    }

    private function bestRepsAtWeight(Collection $records, ?float $weight): int
    {
        if ($weight === null) {
            return 0;
        }

        return (int) $records->map(function (WorkoutExercise $record) use ($weight) {
            $setsAtWeight = $record->sets
                ->where('set_type', WorkoutSet::TYPE_WORKING)
                ->filter(fn (WorkoutSet $set) => (float) $set->weight === $weight);

            return $setsAtWeight->sum('reps');
        })->max();
    }
}
