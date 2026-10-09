<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $duplicateUsers = DB::table('workouts')
                ->where('status', 'in_progress')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('user_id');

            foreach ($duplicateUsers as $userId) {
                $keepId = DB::table('workouts')
                    ->where('user_id', $userId)
                    ->where('status', 'in_progress')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->value('id');

                // Keep the workout previously offered for resuming. Retain every
                // row, exercise, set, and timestamp from the other sessions.
                DB::table('workouts')
                    ->where('user_id', $userId)
                    ->where('status', 'in_progress')
                    ->where('id', '!=', $keepId)
                    ->update(['status' => 'cancelled']);
            }

            DB::statement("CREATE UNIQUE INDEX workouts_one_in_progress_per_user ON workouts (user_id) WHERE status = 'in_progress'");
        });
    }

    public function down(): void
    {
        // Rolling back the constraint must not reactivate cancelled duplicates.
        DB::statement('DROP INDEX workouts_one_in_progress_per_user');
    }
};
