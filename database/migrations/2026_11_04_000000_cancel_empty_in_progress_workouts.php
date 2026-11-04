<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workouts')
            ->where('status', 'in_progress')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('workout_exercises')
                ->whereColumn('workout_exercises.workout_id', 'workouts.id'))
            ->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        // Do not reactivate invalid sessions during rollback.
    }
};
