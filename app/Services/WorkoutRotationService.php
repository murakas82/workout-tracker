<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutType;

class WorkoutRotationService
{
    /** @var list<string> */
    private array $rotation = ['push', 'legs', 'pull'];

    public function nextFor(User $user): WorkoutType
    {
        $lastWorkout = Workout::query()
            ->where('user_id', $user->id)
            ->where('status', Workout::STATUS_COMPLETED)
            ->with('workoutType')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if (! $lastWorkout) {
            return $this->typeByCode('push');
        }

        return $this->after($lastWorkout->workoutType);
    }

    public function after(WorkoutType|string $type): WorkoutType
    {
        $code = $type instanceof WorkoutType ? $type->code : $type;
        $index = array_search($code, $this->rotation, true);

        if ($index === false) {
            return $this->typeByCode('push');
        }

        return $this->typeByCode($this->rotation[($index + 1) % count($this->rotation)]);
    }

    public function typeByCode(string $code): WorkoutType
    {
        return WorkoutType::query()->where('code', $code)->firstOrFail();
    }
}
