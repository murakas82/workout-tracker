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

    public function nextFor(User $user): ?WorkoutType
    {
        $lastWorkout = Workout::query()
            ->where('user_id', $user->id)
            ->where('status', Workout::STATUS_COMPLETED)
            ->with('workoutType')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if (! $lastWorkout) {
            return $this->availableFrom(0);
        }

        return $this->after($lastWorkout->workoutType);
    }

    public function after(WorkoutType|string $type): ?WorkoutType
    {
        $code = $type instanceof WorkoutType ? $type->code : $type;
        $index = array_search($code, $this->rotation, true);

        return $this->availableFrom($index === false ? 0 : $index + 1);
    }

    /** @return Collection<int, WorkoutType> */
    public function availableTypes(): Collection
    {
        return WorkoutType::query()
            ->whereHas('templates', fn (Builder $query) => $query
                ->where('active', true)
                ->whereHas('templateExercises.exercise', fn (Builder $query) => $query->where('active', true)))
            ->orderBy('sort_order')
            ->get();
    }

    private function availableFrom(int $start): ?WorkoutType
    {
        $available = $this->availableTypes()->keyBy('code');
        $count = count($this->rotation);

        for ($offset = 0; $offset < $count; $offset++) {
            $code = $this->rotation[($start + $offset) % $count];
            if ($available->has($code)) {
                return $available->get($code);
            }
        }

        return null;
    }

    public function typeByCode(string $code): WorkoutType
    {
        return WorkoutType::query()->where('code', $code)->firstOrFail();
    }
}
