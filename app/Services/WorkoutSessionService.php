<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WorkoutSessionService
{
    public function __construct(private ProgressionService $progression) {}

    public function start(User $user, WorkoutType $type): Workout
    {
        if ($inProgress = $this->inProgressFor($user)) {
            return $inProgress;
        }

        try {
            return DB::transaction(function () use ($user, $type): Workout {
                // Acquire SQLite's write lock before reading the template to avoid
                // competing deferred transactions upgrading their read locks.
                $workout = Workout::query()->create([
                    'user_id' => $user->id,
                    'workout_type_id' => $type->id,
                    'status' => Workout::STATUS_IN_PROGRESS,
                    'started_at' => now(),
                    'current_exercise_index' => 1,
                ]);

                $template = WorkoutTemplate::query()
                    ->where('workout_type_id', $type->id)
                    ->where('active', true)
                    ->with('templateExercises.exercise')
                    ->firstOrFail();

                foreach ($template->templateExercises as $templateExercise) {
                    $exercise = $templateExercise->exercise;

                    if (! $exercise->active) {
                        continue;
                    }

                    WorkoutExercise::query()->create([
                        'workout_id' => $workout->id,
                        'exercise_id' => $exercise->id,
                        'position' => $templateExercise->position,
                        'name' => $exercise->name,
                        'muscle_group' => $exercise->muscle_group,
                        'working_sets' => $exercise->working_sets,
                        'min_reps' => $exercise->min_reps,
                        'max_reps' => $exercise->max_reps,
                        'rest_min_seconds' => $exercise->rest_min_seconds,
                        'rest_max_seconds' => $exercise->rest_max_seconds,
                        'unilateral' => $exercise->unilateral,
                    ]);
                }

                return $workout->load('workoutType', 'exercises.sets');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($exception->columns !== ['user_id']) {
                throw $exception;
            }

            // The losing transaction has rolled back; resume the committed winner.
            return $this->inProgressFor($user) ?? throw $exception;
        }
    }

    /**
     * @param  list<array{set_number:int,side:?string,weight:float,reps:int,set_type:string}>  $sets
     */
    public function saveExercise(WorkoutExercise $exercise, array $sets): WorkoutExercise
    {
        return DB::transaction(function () use ($exercise, $sets): WorkoutExercise {
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

            $exercise->load('sets');
            $exercise->forceFill([
                'progression_result' => $this->progression->evaluate($exercise),
                'completed_at' => now(),
            ])->save();

            $workout = $exercise->workout;
            $nextIndex = $workout->exercises()
                ->whereNull('completed_at')
                ->min('position') ?? $workout->exercises()->count();

            $workout->forceFill(['current_exercise_index' => $nextIndex])->save();

            return $exercise->refresh()->load('sets');
        });
    }

    public function finish(Workout $workout): Workout
    {
        $workout->forceFill([
            'status' => Workout::STATUS_COMPLETED,
            'completed_at' => now(),
            'current_exercise_index' => $workout->exercises()->count(),
        ])->save();

        return $workout->refresh()->load('workoutType', 'exercises.sets');
    }

    public function cancel(Workout $workout): void
    {
        $workout->forceFill(['status' => Workout::STATUS_CANCELLED])->save();
    }

    private function inProgressFor(User $user): ?Workout
    {
        return Workout::query()
            ->where('user_id', $user->id)
            ->where('status', Workout::STATUS_IN_PROGRESS)
            ->with('workoutType', 'exercises.sets')
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }
}
