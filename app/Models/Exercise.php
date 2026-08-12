<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    protected $fillable = [
        'workout_type_id',
        'name',
        'muscle_group',
        'sort_order',
        'working_sets',
        'min_reps',
        'max_reps',
        'rest_min_seconds',
        'rest_max_seconds',
        'unilateral',
        'active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'unilateral' => 'boolean',
            'active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function workoutType(): BelongsTo
    {
        return $this->belongsTo(WorkoutType::class);
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class);
    }

    public function restLabel(): string
    {
        if ($this->rest_min_seconds === null && $this->rest_max_seconds === null) {
            return 'As needed';
        }

        $format = fn (?int $seconds) => $seconds === null
            ? null
            : ($seconds >= 60 ? rtrim(rtrim(number_format($seconds / 60, 1), '0'), '.').' min' : $seconds.' sec');

        $min = $format($this->rest_min_seconds);
        $max = $format($this->rest_max_seconds);

        return $min === $max || $max === null ? (string) $min : $min.'-'.$max;
    }
}
