<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class WorkoutRotationService
{
    /** @var list<string> */
    private array $rotation = ['push', 'legs', 'pull'];

    public function nextFor(User $user, ?Collection $available = null): ?WorkoutType
    {
        $lastWorkout = Workout::query()
            ->where('user_id', $user->id)
            ->where('status', Workout::STATUS_COMPLETED)
            ->with('workoutType')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if (! $lastWorkout) {
            return $this->availableFrom(0, $available);
        }

        return $this->after($lastWorkout->workoutType, $available);
    }

    public function after(WorkoutType|string $type, ?Collection $available = null): ?WorkoutType
    {
        $code = $type instanceof WorkoutType ? $type->code : $type;
        $index = array_search($code, $this->rotation, true);

        return $this->availableFrom($index === false ? 0 : $index + 1, $available);
    }

    /** @return Collection<int, WorkoutType> */
    public function availableTypes(): Collection
    {
        return WorkoutType::query()
            ->whereHas('templates', fn (Builder $query) => $query->startable())
            ->orderBy('sort_order')
            ->get();
    }

    private function availableFrom(int $start, ?Collection $available = null): ?WorkoutType
    {
        $available = ($available ?? $this->availableTypes())->keyBy('code');
        $count = count($this->rotation);

        for ($offset = 0; $offset < $count; $offset++) {
            $code = $this->rotation[($start + $offset) % $count];
            if ($available->has($code)) {
                return $available->get($code);
            }
        }

        return null;
    }
}
