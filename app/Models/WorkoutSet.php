<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutSet extends Model
{
    // Matches the decimal(7,2) workout_sets.weight column.
    public const MAX_WEIGHT = 99999.99;

    public const MAX_REPS = 1000;

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
