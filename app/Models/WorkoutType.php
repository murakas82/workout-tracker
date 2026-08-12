<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutType extends Model
{
    protected $fillable = ['code', 'name', 'sort_order'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class)->orderBy('sort_order');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WorkoutTemplate::class);
    }

    public function workouts(): HasMany
    {
        return $this->hasMany(Workout::class);
    }
}
