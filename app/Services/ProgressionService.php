<?php

namespace App\Services;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

class ProgressionService
{
    public const RESULT_INCREASE = 'increase';
    public const RESULT_KEEP = 'keep';
    public const RESULT_INCOMPLETE = 'incomplete';

    public function evaluate(WorkoutExercise $exercise): string
    {
        $sets = $exercise->relationLoaded('sets')
            ? $exercise->sets
            : $exercise->sets()->get();

        $workingSets = $sets->where('set_type', WorkoutSet::TYPE_WORKING);

        if ($exercise->unilateral) {
            foreach ([WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT] as $side) {
                $sideSets = $workingSets->where('side', $side);

                if (! $this->sideReachedTarget($sideSets, $exercise->working_sets, $exercise->max_reps)) {
                    return $this->isComplete($workingSets, $exercise) ? self::RESULT_KEEP : self::RESULT_INCOMPLETE;
                }
            }

            return self::RESULT_INCREASE;
        }

        if (! $this->sideReachedTarget($workingSets, $exercise->working_sets, $exercise->max_reps)) {
            return $this->isComplete($workingSets, $exercise) ? self::RESULT_KEEP : self::RESULT_INCOMPLETE;
        }

        return self::RESULT_INCREASE;
    }

    public function label(?string $result): string
    {
        return match ($result) {
            self::RESULT_INCREASE => 'TARGET REACHED - INCREASE WEIGHT',
            self::RESULT_KEEP => 'KEEP WEIGHT',
            default => 'INCOMPLETE',
        };
    }

    public function shortLabel(?string $result): string
    {
        return match ($result) {
            self::RESULT_INCREASE => 'Increase weight next time',
            self::RESULT_KEEP => 'Keep weight',
            default => 'Incomplete',
        };
    }

    public function displayWeight(Collection $sets): string
    {
        $working = $sets->where('set_type', WorkoutSet::TYPE_WORKING);

        if ($working->isEmpty()) {
            return '-';
        }

        $weights = $working->pluck('weight')->map(fn ($weight) => (float) $weight)->unique();

        if ($weights->count() === 1) {
            return $this->formatWeight($weights->first()).' kg';
        }

        return $working
            ->map(fn (WorkoutSet $set) => $this->formatWeight((float) $set->weight))
            ->implode(' / ').' kg';
    }

    public function repsLine(Collection $sets, ?string $side = null): string
    {
        $working = $sets->where('set_type', WorkoutSet::TYPE_WORKING);

        if ($side !== null) {
            $working = $working->where('side', $side);
        }

        return $working->sortBy('set_number')->pluck('reps')->implode(' / ') ?: '-';
    }

    public function normalVolume(Collection $sets): float
    {
        return $sets
            ->where('set_type', WorkoutSet::TYPE_WORKING)
            ->sum(fn (WorkoutSet $set) => ((float) $set->weight) * $set->reps);
    }

    public function primaryWeight(Collection $sets): ?float
    {
        $set = $sets->where('set_type', WorkoutSet::TYPE_WORKING)->sortBy('set_number')->first();

        return $set ? (float) $set->weight : null;
    }

    public function formatWeight(float|int|null $weight): string
    {
        if ($weight === null) {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $weight, 2), '0'), '.');
    }

    private function sideReachedTarget(Collection $sets, int $expectedSets, int $maxReps): bool
    {
        return $sets->count() >= $expectedSets
            && $sets->take($expectedSets)->every(fn (WorkoutSet $set) => $set->reps >= $maxReps);
    }

    private function isComplete(Collection $workingSets, WorkoutExercise $exercise): bool
    {
        $expected = $exercise->unilateral ? $exercise->working_sets * 2 : $exercise->working_sets;

        return $workingSets->count() >= $expected;
    }
}
