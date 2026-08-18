<?php

namespace App\Services;

use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

class WorkoutStatsService
{
    /**
     * @return array{
     *     exercises_completed:int,
     *     working_sets_completed:int,
     *     drop_sets_completed:int,
     *     total_reps:int,
     *     working_volume:float,
     *     drop_volume:float,
     *     total_volume:float,
     *     targets_reached:int,
     *     heaviest_set:?array{exercise:string,weight:float,reps:int,set_type:string},
     *     per_exercise:list<array{name:string,working_volume:float,drop_volume:float,total_volume:float,total_reps:int,working_sets:int,drop_sets:int}>
     * }
     */
    public function forWorkout(Workout $workout): array
    {
        $workout->loadMissing('exercises.sets');

        $perExercise = $this->perExercise($workout);
        $allSets = $workout->exercises->flatMap(fn (WorkoutExercise $exercise) => $exercise->sets);
        $workingSets = $allSets->where('set_type', WorkoutSet::TYPE_WORKING);
        $dropSets = $allSets->where('set_type', WorkoutSet::TYPE_DROP);
        $heaviestSet = $this->heaviestSet($workout);

        return [
            'exercises_completed' => $workout->exercises->whereNotNull('completed_at')->count(),
            'working_sets_completed' => $workingSets->count(),
            'drop_sets_completed' => $dropSets->count(),
            'total_reps' => (int) $allSets->sum('reps'),
            'working_volume' => $this->volume($workingSets),
            'drop_volume' => $this->volume($dropSets),
            'total_volume' => $this->volume($allSets),
            'targets_reached' => $workout->exercises->where('progression_result', ProgressionService::RESULT_INCREASE)->count(),
            'heaviest_set' => $heaviestSet,
            'per_exercise' => $perExercise,
        ];
    }

    /**
     * @return array{
     *     exercise_volume:array{labels:list<string>,working:list<float>,drops:list<float>},
     *     type_volume_history:array{labels:list<string>,total:list<float>,working:list<float>,drops:list<float>}
     * }
     */
    public function chartData(Workout $workout): array
    {
        $workout->loadMissing('exercises.sets');
        $perExercise = $this->perExercise($workout);
        $history = $this->sameTypeHistory($workout);

        return [
            'exercise_volume' => [
                'labels' => array_column($perExercise, 'name'),
                'working' => array_column($perExercise, 'working_volume'),
                'drops' => array_column($perExercise, 'drop_volume'),
            ],
            'type_volume_history' => [
                'labels' => $history->pluck('label')->all(),
                'total' => $history->pluck('total_volume')->all(),
                'working' => $history->pluck('working_volume')->all(),
                'drops' => $history->pluck('drop_volume')->all(),
            ],
        ];
    }

    /**
     * @return list<array{name:string,working_volume:float,drop_volume:float,total_volume:float,total_reps:int,working_sets:int,drop_sets:int}>
     */
    private function perExercise(Workout $workout): array
    {
        return $workout->exercises
            ->map(function (WorkoutExercise $exercise): array {
                $workingSets = $exercise->sets->where('set_type', WorkoutSet::TYPE_WORKING);
                $dropSets = $exercise->sets->where('set_type', WorkoutSet::TYPE_DROP);
                $workingVolume = $this->volume($workingSets);
                $dropVolume = $this->volume($dropSets);

                return [
                    'name' => $exercise->name,
                    'working_volume' => $workingVolume,
                    'drop_volume' => $dropVolume,
                    'total_volume' => round($workingVolume + $dropVolume, 2),
                    'total_reps' => (int) $exercise->sets->sum('reps'),
                    'working_sets' => $workingSets->count(),
                    'drop_sets' => $dropSets->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WorkoutSet>  $sets
     */
    private function volume(Collection $sets): float
    {
        return round((float) $sets->sum(fn (WorkoutSet $set) => ((float) $set->weight) * $set->reps), 2);
    }

    /**
     * @return array{exercise:string,weight:float,reps:int,set_type:string}|null
     */
    private function heaviestSet(Workout $workout): ?array
    {
        $best = $workout->exercises
            ->flatMap(fn (WorkoutExercise $exercise) => $exercise->sets->map(fn (WorkoutSet $set) => [
                'exercise' => $exercise->name,
                'weight' => (float) $set->weight,
                'reps' => $set->reps,
                'set_type' => $set->set_type,
            ]))
            ->sortByDesc('reps')
            ->sortByDesc('weight')
            ->first();

        return $best ?: null;
    }

    /**
     * @return Collection<int, array{label:string,total_volume:float,working_volume:float,drop_volume:float}>
     */
    private function sameTypeHistory(Workout $workout): Collection
    {
        return Workout::query()
            ->where('user_id', $workout->user_id)
            ->where('workout_type_id', $workout->workout_type_id)
            ->where('status', Workout::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->with('exercises.sets')
            ->orderByDesc('completed_at')
            ->limit(8)
            ->get()
            ->reverse()
            ->values()
            ->map(function (Workout $historyWorkout): array {
                $sets = $historyWorkout->exercises->flatMap(fn (WorkoutExercise $exercise) => $exercise->sets);
                $workingSets = $sets->where('set_type', WorkoutSet::TYPE_WORKING);
                $dropSets = $sets->where('set_type', WorkoutSet::TYPE_DROP);

                return [
                    'label' => $historyWorkout->completed_at?->format('d M') ?? '#'.$historyWorkout->id,
                    'total_volume' => $this->volume($sets),
                    'working_volume' => $this->volume($workingSets),
                    'drop_volume' => $this->volume($dropSets),
                ];
            });
    }
}
