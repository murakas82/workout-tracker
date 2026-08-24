<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Auth\Access\Response;

class WorkoutPolicy
{
    public function view(User $user, Workout $workout): Response
    {
        return $this->ownerResponse($user, $workout);
    }

    public function viewActive(User $user, Workout $workout): Response
    {
        if (! $this->isOwner($user, $workout)) {
            return Response::denyAsNotFound();
        }

        return $workout->status === Workout::STATUS_IN_PROGRESS
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function viewCompleted(User $user, Workout $workout): Response
    {
        if (! $this->isOwner($user, $workout)) {
            return Response::denyAsNotFound();
        }

        return $workout->status === Workout::STATUS_COMPLETED
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function ownerResponse(User $user, Workout $workout): Response
    {
        return $this->isOwner($user, $workout)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function isOwner(User $user, Workout $workout): bool
    {
        return $workout->user_id === $user->id;
    }
}
