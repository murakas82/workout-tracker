<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutType;
use App\Services\WorkoutSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmptyWorkoutCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_cleanup_changes_only_the_status_of_empty_active_workouts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $empty = Workout::query()->create([
            'user_id' => $user->id, 'workout_type_id' => $type->id,
            'status' => Workout::STATUS_IN_PROGRESS, 'started_at' => now()->subWeek(),
        ]);
        Workout::query()->create([
            'user_id' => $user->id, 'workout_type_id' => $type->id,
            'status' => Workout::STATUS_COMPLETED, 'completed_at' => now(),
        ]);
        Workout::query()->create([
            'user_id' => $user->id, 'workout_type_id' => $type->id,
            'status' => Workout::STATUS_CANCELLED,
        ]);
        $sessions = app(WorkoutSessionService::class);
        $valid = $sessions->start($other, $type);
        $exercise = $valid->exercises->first();
        $sessions->saveExercise($exercise, [
            ['set_number' => 1, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => 'working'],
        ]);
        $expected = DB::table('workouts')->orderBy('id')->get();
        foreach ($expected as $row) {
            if ($row->id === $empty->id) {
                $row->status = Workout::STATUS_CANCELLED;
            }
        }
        $beforeExercises = DB::table('workout_exercises')->orderBy('id')->get();
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get();
        $migration = require database_path('migrations/2026_11_04_000000_cancel_empty_in_progress_workouts.php');
        $migration->up();

        $this->assertEquals($expected, DB::table('workouts')->orderBy('id')->get());
        $this->assertEquals($beforeExercises, DB::table('workout_exercises')->orderBy('id')->get());
        $this->assertEquals($beforeSets, DB::table('workout_sets')->orderBy('id')->get());
        $migration->down();
        $this->assertEquals($expected, DB::table('workouts')->orderBy('id')->get());
        $this->actingAs($user)->post(route('workouts.start', $type))->assertRedirect();
        $this->assertSame(1, $user->workouts()->where('status', Workout::STATUS_IN_PROGRESS)->count());
    }

    public function test_page_loads_never_cancel_workouts(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $empty = Workout::query()->create([
            'user_id' => $user->id, 'workout_type_id' => $type->id,
            'status' => Workout::STATUS_IN_PROGRESS,
        ]);
        $before = $empty->fresh()->getAttributes();
        $this->actingAs($user)->get(route('workouts.show', $empty))->assertNotFound();
        $this->assertEquals($before, $empty->fresh()->getAttributes());
    }
}
