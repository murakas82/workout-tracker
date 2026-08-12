<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutExercise extends Model
{
    protected $fillable = [
        'workout_id',
        'exercise_id',
        'position',
        'name',
        'muscle_group',
        'working_sets',
        'min_reps',
        'max_reps',
        'rest_min_seconds',
        'rest_max_seconds',
        'unilateral',
        'progression_result',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'unilateral' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class)->orderBy('set_type')->orderBy('side')->orderBy('set_number');
    }

    public function workingSets(): HasMany
    {
        return $this->sets()->where('set_type', WorkoutSet::TYPE_WORKING);
    }

    public function dropSets(): HasMany
    {
        return $this->sets()->where('set_type', WorkoutSet::TYPE_DROP);
    }

    public function restLabel(): string
    {
        $format = fn (?int $seconds) => $seconds === null
            ? null
            : ($seconds >= 60 ? rtrim(rtrim(number_format($seconds / 60, 1), '0'), '.').' min' : $seconds.' sec');

        $min = $format($this->rest_min_seconds);
        $max = $format($this->rest_max_seconds);

        if ($min === null && $max === null) {
            return 'As needed';
        }

        return $min === $max || $max === null ? (string) $min : $min.'-'.$max;
    }
}
