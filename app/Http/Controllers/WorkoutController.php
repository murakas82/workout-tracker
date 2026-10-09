<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkoutExerciseSetsRequest;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use App\Services\ProgressionService;
use App\Services\WorkoutRotationService;
use App\Services\WorkoutSessionService;
use App\Services\WorkoutStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkoutController extends Controller
{
    public function index(Request $request, WorkoutRotationService $rotation): View
    {
        return view('workouts.index', [
            'nextType' => $rotation->nextFor($request->user()),
            'types' => WorkoutType::query()->orderBy('sort_order')->get(),
            'inProgress' => Workout::query()
                ->where('user_id', $request->user()->id)
                ->where('status', Workout::STATUS_IN_PROGRESS)
                ->with('workoutType')
                ->latest('updated_at')
                ->first(),
        ]);
    }

    public function start(Request $request, WorkoutType $workoutType, WorkoutSessionService $sessions): RedirectResponse
    {
        $workout = $sessions->start($request->user(), $workoutType);
        $response = redirect()->route('workouts.show', $workout);

        if (! $workout->wasRecentlyCreated) {
            $response->with('status', 'Continue or cancel the workout already in progress.');
        }

        return $response;
    }

    public function show(Request $request, Workout $workout, ?int $position = null): View|RedirectResponse
    {
        Gate::authorize('view', $workout);

        if ($workout->isCompleted()) {
            return redirect()->route('workouts.summary', $workout);
        }

        $workout->load('workoutType', 'exercises.sets');

        $position ??= $workout->current_exercise_index;
        $position = max(1, min($position, $workout->exercises->count()));

        $exercise = $workout->exercises->firstWhere('position', $position);
        abort_unless($exercise, 404);

        return view('workouts.show', [
            'workout' => $workout,
            'exercise' => $exercise,
            'previous' => $this->previousExercise($request, $exercise, $workout),
            'totalExercises' => $workout->exercises->count(),
        ]);
    }

    public function reorder(Workout $workout): View
    {
        Gate::authorize('viewActive', $workout);

        $workout->load('workoutType', 'exercises');

        return view('workouts.reorder', compact('workout'));
    }

    public function moveExercise(Request $request, Workout $workout, WorkoutExercise $workoutExercise): RedirectResponse
    {
        Gate::authorize('viewActive', $workout);
        abort_unless($workoutExercise->workout_id === $workout->id, 404);

        $validated = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);

        if ($workoutExercise->completed_at !== null) {
            return redirect()->route('workouts.reorder', $workout)->with('status', 'Completed exercises stay locked.');
        }

        $targetPosition = $workoutExercise->position + ($validated['direction'] === 'up' ? -1 : 1);
        $target = WorkoutExercise::query()
            ->where('workout_id', $workout->id)
            ->where('position', $targetPosition)
            ->first();

        if (! $target || $target->completed_at !== null) {
            return redirect()->route('workouts.reorder', $workout)->with('status', 'Only upcoming exercises can be reordered.');
        }

        DB::transaction(function () use ($workout, $workoutExercise, $target): void {
            $current = $workoutExercise->fresh();
            $swap = $target->fresh();
            $currentPosition = $current->position;
            $swapPosition = $swap->position;

            $current->forceFill(['position' => 0])->save();
            $swap->forceFill(['position' => $currentPosition])->save();
            $current->forceFill(['position' => $swapPosition])->save();

            $nextPosition = WorkoutExercise::query()
                ->where('workout_id', $workout->id)
                ->whereNull('completed_at')
                ->min('position');

            $workout->forceFill([
                'current_exercise_index' => $nextPosition ?? $workout->exercises()->count(),
            ])->save();
        });

        return redirect()->route('workouts.reorder', $workout)->with('status', 'Workout order updated.');
    }

    public function moveExerciseLater(Request $request, Workout $workout, WorkoutExercise $workoutExercise): RedirectResponse
    {
        Gate::authorize('viewActive', $workout);
        abort_unless($workoutExercise->workout_id === $workout->id, 404);

        $nextPosition = null;

        DB::transaction(function () use ($workout, $workoutExercise, &$nextPosition): void {
            $unfinished = WorkoutExercise::query()
                ->where('workout_id', $workout->id)
                ->whereNull('completed_at')
                ->orderBy('position')
                ->get();

            if ($unfinished->count() <= 1 || ! $unfinished->contains('id', $workoutExercise->id)) {
                $nextPosition = $workoutExercise->position;

                return;
            }

            $positions = $unfinished->pluck('position')->values();
            $ordered = $unfinished
                ->reject(fn (WorkoutExercise $exercise) => $exercise->id === $workoutExercise->id)
                ->push($workoutExercise->fresh())
                ->values();
            $offset = (int) $workout->exercises()->max('position') + 1000;

            foreach ($unfinished as $exercise) {
                $exercise->forceFill(['position' => $exercise->position + $offset])->save();
            }

            foreach ($ordered as $index => $exercise) {
                $exercise->forceFill(['position' => $positions[$index]])->save();
            }

            $nextPosition = (int) $positions->first();
            $workout->forceFill(['current_exercise_index' => $nextPosition])->save();
        });

        return redirect()
            ->route('workouts.exercise', [$workout, $nextPosition ?? $workoutExercise->position])
            ->with('status', 'Moved to later.');
    }

    public function saveExercise(
        WorkoutExerciseSetsRequest $request,
        Workout $workout,
        WorkoutExercise $workoutExercise,
        WorkoutSessionService $sessions,
    ): RedirectResponse {
        Gate::authorize('viewActive', $workout);
        abort_unless($workoutExercise->workout_id === $workout->id, 404);

        $sets = $request->workoutSets($workoutExercise);
        $sessions->saveExercise($workoutExercise, $sets);

        $next = $workout->exercises()
            ->whereNull('completed_at')
            ->orderBy('position')
            ->first();

        if (! $next) {
            $sessions->finish($workout);

            return redirect()->route('workouts.summary', $workout);
        }

        return redirect()->route('workouts.exercise', [$workout, $next->position]);
    }

    public function editCompletedExercise(Workout $workout, WorkoutExercise $workoutExercise): View
    {
        Gate::authorize('viewCompleted', $workout);
        abort_unless($workoutExercise->workout_id === $workout->id, 404);

        $workout->load('workoutType');
        $workoutExercise->load('sets');

        return view('workouts.edit-exercise', [
            'workout' => $workout,
            'exercise' => $workoutExercise,
        ]);
    }

    public function updateCompletedExercise(
        WorkoutExerciseSetsRequest $request,
        Workout $workout,
        WorkoutExercise $workoutExercise,
        ProgressionService $progression,
    ): RedirectResponse {
        Gate::authorize('viewCompleted', $workout);
        abort_unless($workoutExercise->workout_id === $workout->id, 404);

        $sets = $request->workoutSets($workoutExercise);

        DB::transaction(function () use ($workoutExercise, $sets, $progression): void {
            $this->replaceExerciseSets($workoutExercise, $sets);

            $workoutExercise->load('sets');
            $workoutExercise->forceFill([
                'progression_result' => $progression->evaluate($workoutExercise),
            ])->save();
        });

        return redirect(route('history.show', $workout).'#exercise-'.$workoutExercise->id)
            ->with('status', 'Exercise updated.');
    }

    public function summary(Workout $workout, WorkoutStatsService $workoutStats): View
    {
        Gate::authorize('viewCompleted', $workout);

        $workout->load('workoutType', 'exercises.sets');

        return view('workouts.summary', [
            'workout' => $workout,
            'stats' => $workoutStats->forWorkout($workout),
            'chartData' => $workoutStats->chartData($workout),
        ]);
    }

    public function cancel(Workout $workout, WorkoutSessionService $sessions): RedirectResponse
    {
        Gate::authorize('viewActive', $workout);

        $sessions->cancel($workout);

        return redirect()->route('dashboard')->with('status', 'Workout cancelled.');
    }

    private function previousExercise(Request $request, WorkoutExercise $exercise, Workout $workout): ?WorkoutExercise
    {
        if (! $exercise->exercise_id) {
            return null;
        }

        return WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workouts', 'workouts.id', '=', 'workout_exercises.workout_id')
            ->where('workout_exercises.exercise_id', $exercise->exercise_id)
            ->where('workout_exercises.id', '!=', $exercise->id)
            ->where('workouts.id', '!=', $workout->id)
            ->where('workouts.user_id', $request->user()->id)
            ->where('workouts.status', Workout::STATUS_COMPLETED)
            ->with('sets')
            ->orderByDesc('workouts.completed_at')
            ->orderByDesc('workout_exercises.id')
            ->first();
    }

    /**
     * @param  list<array{set_number:int,side:?string,weight:float,reps:int,set_type:string}>  $sets
     */
    private function replaceExerciseSets(WorkoutExercise $exercise, array $sets): void
    {
        $exercise->sets()->delete();

        foreach ($sets as $set) {
            WorkoutSet::query()->create([
                'workout_exercise_id' => $exercise->id,
                'set_number' => $set['set_number'],
                'side' => $set['side'],
                'weight' => $set['weight'],
                'reps' => $set['reps'],
                'set_type' => $set['set_type'],
            ]);
        }
    }
}
