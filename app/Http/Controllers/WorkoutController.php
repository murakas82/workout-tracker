<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use App\Services\ProgressionService;
use App\Services\WorkoutRotationService;
use App\Services\WorkoutSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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
        $inProgress = Workout::query()
            ->where('user_id', $request->user()->id)
            ->where('status', Workout::STATUS_IN_PROGRESS)
            ->latest('updated_at')
            ->first();

        if ($inProgress) {
            return redirect()->route('workouts.show', $inProgress)->with('status', 'Continue or cancel the workout already in progress.');
        }

        $workout = $sessions->start($request->user(), $workoutType);

        return redirect()->route('workouts.show', $workout);
    }

    public function show(Request $request, Workout $workout, ?int $position = null): View|RedirectResponse
    {
        $this->authorizeWorkout($request, $workout);

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

    public function saveExercise(
        Request $request,
        Workout $workout,
        WorkoutExercise $workoutExercise,
        WorkoutSessionService $sessions,
    ): RedirectResponse {
        $this->authorizeWorkout($request, $workout);
        abort_unless($workoutExercise->workout_id === $workout->id, 404);
        abort_unless($workout->status === Workout::STATUS_IN_PROGRESS, 404);

        $sets = $this->extractSets($request, $workoutExercise);
        $sessions->saveExercise($workoutExercise, $sets);

        $total = $workout->exercises()->count();

        if ($workoutExercise->position >= $total) {
            $sessions->finish($workout);

            return redirect()->route('workouts.summary', $workout);
        }

        return redirect()->route('workouts.exercise', [$workout, $workoutExercise->position + 1]);
    }

    public function summary(Request $request, Workout $workout, ProgressionService $progression): View
    {
        $this->authorizeWorkout($request, $workout);

        $workout->load('workoutType', 'exercises.sets');

        $stats = [
            'exercises_completed' => $workout->exercises->whereNotNull('completed_at')->count(),
            'working_sets_completed' => $workout->exercises->sum(
                fn (WorkoutExercise $exercise) => $exercise->sets->where('set_type', WorkoutSet::TYPE_WORKING)->count(),
            ),
            'weight_increased' => 0,
            'reps_improved' => 0,
            'targets_reached' => $workout->exercises->where('progression_result', ProgressionService::RESULT_INCREASE)->count(),
        ];

        foreach ($workout->exercises as $exercise) {
            $previous = $this->previousExercise($request, $exercise, $workout);

            if (! $previous) {
                continue;
            }

            $currentWeight = $progression->primaryWeight($exercise->sets);
            $previousWeight = $progression->primaryWeight($previous->sets);
            $currentReps = $exercise->sets->where('set_type', WorkoutSet::TYPE_WORKING)->sum('reps');
            $previousReps = $previous->sets->where('set_type', WorkoutSet::TYPE_WORKING)->sum('reps');

            if ($currentWeight !== null && $previousWeight !== null && $currentWeight > $previousWeight) {
                $stats['weight_increased']++;
            }

            if ($currentReps > $previousReps) {
                $stats['reps_improved']++;
            }
        }

        return view('workouts.summary', compact('workout', 'stats'));
    }

    public function cancel(Request $request, Workout $workout, WorkoutSessionService $sessions): RedirectResponse
    {
        $this->authorizeWorkout($request, $workout);
        abort_unless($workout->status === Workout::STATUS_IN_PROGRESS, 404);

        $sessions->cancel($workout);

        return redirect()->route('dashboard')->with('status', 'Workout cancelled.');
    }

    private function authorizeWorkout(Request $request, Workout $workout): void
    {
        abort_unless($workout->user_id === $request->user()->id, 404);
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
     * @return list<array{set_number:int,side:?string,weight:float,reps:int,set_type:string}>
     */
    private function extractSets(Request $request, WorkoutExercise $exercise): array
    {
        $errors = [];
        $sets = [];
        $working = $request->input('working', []);
        $sides = $exercise->unilateral ? [WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT] : [null];

        foreach ($sides as $side) {
            for ($setNumber = 1; $setNumber <= $exercise->working_sets; $setNumber++) {
                $row = $side === null
                    ? data_get($working, (string) $setNumber, [])
                    : data_get($working, $side.'.'.$setNumber, []);

                $weightValue = $this->normalizeWeight($row['weight'] ?? null);
                $reps = $row['reps'] ?? null;
                $label = $side ? ucfirst($side).' set '.$setNumber : 'Set '.$setNumber;

                if ($weightValue === null) {
                    $errors['working'] = $label.' needs a valid weight.';
                }

                if (! ctype_digit((string) $reps) || (int) $reps < 1) {
                    $errors['working'] = $label.' needs valid reps.';
                }

                if (! $errors) {
                    $sets[] = [
                        'set_number' => $setNumber,
                        'side' => $side,
                        'weight' => $weightValue,
                        'reps' => (int) $reps,
                        'set_type' => WorkoutSet::TYPE_WORKING,
                    ];
                }
            }
        }

        $dropRows = $request->input('drops', []);
        $dropNumber = 1;

        foreach ($dropRows as $row) {
            $weight = $row['weight'] ?? null;
            $reps = $row['reps'] ?? null;
            $side = $exercise->unilateral ? ($row['side'] ?? null) : null;

            if ($this->isBlankInput($weight) && $this->isBlankInput($reps) && $this->isBlankInput($side)) {
                continue;
            }

            $weightValue = $this->normalizeWeight($weight);

            if ($weightValue === null || ! ctype_digit((string) $reps) || (int) $reps < 1) {
                $errors['drops'] = 'Drop sets need valid weight and reps.';
                continue;
            }

            if ($exercise->unilateral && ! in_array($side, [WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT], true)) {
                $errors['drops'] = 'Choose left or right for each drop set.';
                continue;
            }

            $sets[] = [
                'set_number' => $dropNumber++,
                'side' => $side,
                'weight' => $weightValue,
                'reps' => (int) $reps,
                'set_type' => WorkoutSet::TYPE_DROP,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $sets;
    }

    private function normalizeWeight(mixed $weight): ?float
    {
        if ($this->isBlankInput($weight)) {
            return null;
        }

        if (is_string($weight)) {
            $weight = str_replace(',', '.', trim($weight));
        }

        if (! is_numeric($weight)) {
            return null;
        }

        $weight = (float) $weight;

        return $weight >= 0 ? $weight : null;
    }

    private function isBlankInput(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
