<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

class ExerciseProgressService
{
    public const TREND_UP = 'up';
    public const TREND_FLAT = 'flat';
    public const TREND_DOWN = 'down';

    public const STATUS_READY = 'ready';
    public const STATUS_STALLED = 'stalled';

    /** Sessions at the same weight without reaching the target before an exercise counts as stalled. */
    public const STALL_SESSIONS = 4;

    public function __construct(private readonly ProgressionService $progression)
    {
    }

    /**
     * Completed sessions of one exercise for a user, newest first.
     *
     * @return Collection<int, WorkoutExercise>
     */
    public function records(User $user, Exercise $exercise): Collection
    {
        return $this->recordsQuery($user)
            ->where('workout_exercises.exercise_id', $exercise->id)
            ->get();
    }

    /**
     * Completed sessions for every exercise a user has done, grouped by exercise id, newest first.
     *
     * @return Collection<int, Collection<int, WorkoutExercise>>
     */
    public function recordsByExercise(User $user): Collection
    {
        return $this->recordsQuery($user)
            ->whereNotNull('workout_exercises.exercise_id')
            ->get()
            ->groupBy('exercise_id');
    }

    /**
     * Epley estimate of the one-rep max from the best working set.
     */
    public function estimatedOneRepMax(Collection $sets): ?float
    {
        $best = $sets
            ->where('set_type', WorkoutSet::TYPE_WORKING)
            ->filter(fn (WorkoutSet $set) => $set->reps > 0)
            ->map(fn (WorkoutSet $set) => (float) $set->weight * (1 + $set->reps / 30))
            ->max();

        return $best === null ? null : round($best, 1);
    }

    /**
     * @param  Collection<int, WorkoutExercise>  $records  newest first
     * @return array{
     *     latest:?WorkoutExercise,
     *     latest_weight:?float,
     *     latest_at:?\Illuminate\Support\Carbon,
     *     trend:?string,
     *     status:?string,
     *     sessions_at_weight:int
     * }
     */
    public function summary(Collection $records): array
    {
        $latest = $records->first();
        $previous = $records->skip(1)->first();

        return [
            'latest' => $latest,
            'latest_weight' => $latest ? $this->progression->primaryWeight($latest->sets) : null,
            'latest_at' => $latest?->workout?->completed_at,
            'trend' => $this->trend($latest, $previous),
            'status' => $this->status($records),
            'sessions_at_weight' => $this->sessionsAtLatestWeight($records),
        ];
    }

    /**
     * @param  Collection<int, WorkoutExercise>  $records  newest first
     * @return array<string, mixed>
     */
    public function detail(Collection $records): array
    {
        $latest = $records->first();
        $previous = $records->skip(1)->first();
        $currentWeight = $latest ? $this->progression->primaryWeight($latest->sets) : null;

        return $this->summary($records) + [
            'previous_weight' => $previous ? $this->progression->primaryWeight($previous->sets) : null,
            'highest_weight' => $records->map(fn (WorkoutExercise $record) => $this->progression->primaryWeight($record->sets))->filter(fn ($weight) => $weight !== null)->max(),
            'best_set_at_current_weight' => $this->bestRepsAtWeight($records, $currentWeight),
            'latest_estimated_max' => $latest ? $this->estimatedOneRepMax($latest->sets) : null,
            'best_estimated_max' => $records->map(fn (WorkoutExercise $record) => $this->estimatedOneRepMax($record->sets))->filter(fn ($value) => $value !== null)->max(),
            'sessions' => $records->count(),
        ];
    }

    /**
     * @param  Collection<int, WorkoutExercise>  $records  newest first
     * @return array{labels:list<string>,weight:list<?float>,estimated_max:list<?float>,volume:list<float>}
     */
    public function chartData(Collection $records): array
    {
        $ordered = $records->reverse()->values();
        $spansYears = $ordered->map(fn (WorkoutExercise $record) => $record->workout?->completed_at?->year)->filter()->unique()->count() > 1;

        return [
            'labels' => $ordered->map(fn (WorkoutExercise $record) => $record->workout?->completed_at?->format($spansYears ? 'd M y' : 'd M') ?? '')->all(),
            'weight' => $ordered->map(fn (WorkoutExercise $record) => $this->progression->primaryWeight($record->sets))->all(),
            'estimated_max' => $ordered->map(fn (WorkoutExercise $record) => $this->estimatedOneRepMax($record->sets))->all(),
            'volume' => $ordered->map(fn (WorkoutExercise $record) => round($this->progression->normalVolume($record->sets), 2))->all(),
        ];
    }

    private function recordsQuery(User $user)
    {
        return WorkoutExercise::query()
            ->select('workout_exercises.*')
            ->join('workouts', 'workouts.id', '=', 'workout_exercises.workout_id')
            ->where('workouts.user_id', $user->id)
            ->where('workouts.status', Workout::STATUS_COMPLETED)
            ->with(['sets', 'workout'])
            ->orderByDesc('workouts.completed_at')
            ->orderByDesc('workout_exercises.id');
    }

    private function trend(?WorkoutExercise $latest, ?WorkoutExercise $previous): ?string
    {
        if (! $latest || ! $previous) {
            return null;
        }

        $now = $this->estimatedOneRepMax($latest->sets);
        $before = $this->estimatedOneRepMax($previous->sets);

        if ($now === null || $before === null) {
            return null;
        }

        return match (true) {
            $now > $before => self::TREND_UP,
            $now < $before => self::TREND_DOWN,
            default => self::TREND_FLAT,
        };
    }

    /**
     * @param  Collection<int, WorkoutExercise>  $records  newest first
     */
    private function status(Collection $records): ?string
    {
        $latest = $records->first();

        if (! $latest) {
            return null;
        }

        if ($latest->progression_result === ProgressionService::RESULT_INCREASE) {
            return self::STATUS_READY;
        }

        return $this->sessionsAtLatestWeight($records) >= self::STALL_SESSIONS ? self::STATUS_STALLED : null;
    }

    /**
     * Consecutive most recent sessions done at the latest weight.
     *
     * @param  Collection<int, WorkoutExercise>  $records  newest first
     */
    private function sessionsAtLatestWeight(Collection $records): int
    {
        $latestWeight = $records->isEmpty() ? null : $this->progression->primaryWeight($records->first()->sets);

        if ($latestWeight === null) {
            return 0;
        }

        $count = 0;

        foreach ($records as $record) {
            if ($this->progression->primaryWeight($record->sets) !== $latestWeight) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Most reps in a single working set at the given weight.
     *
     * @param  Collection<int, WorkoutExercise>  $records
     */
    private function bestRepsAtWeight(Collection $records, ?float $weight): ?int
    {
        if ($weight === null) {
            return null;
        }

        $best = $records
            ->flatMap(fn (WorkoutExercise $record) => $record->sets)
            ->where('set_type', WorkoutSet::TYPE_WORKING)
            ->filter(fn (WorkoutSet $set) => (float) $set->weight === $weight)
            ->max('reps');

        return $best === null ? null : (int) $best;
    }
}
