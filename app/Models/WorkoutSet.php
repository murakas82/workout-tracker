<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutSet extends Model
{
    public const TYPE_WORKING = 'working';
    public const TYPE_DROP = 'drop';
    public const SIDE_LEFT = 'left';
    public const SIDE_RIGHT = 'right';

    protected $fillable = [
        'workout_exercise_id',
        'set_number',
        'side',
        'weight',
        'reps',
        'set_type',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
        ];
    }

    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExercise::class);
    }
}
