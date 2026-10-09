<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkoutInProgressMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->seed();
        $this->migration()->down();
    }

    public function test_migration_safely_normalizes_duplicates_and_preserves_all_saved_data(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $single = User::factory()->create();
        $first = $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 0);
        $keep = $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 0);
        $older = $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 2);
        $otherOlder = $this->legacyWorkout($other, Workout::STATUS_IN_PROGRESS, 3);
        $otherKeep = $this->legacyWorkout($other, Workout::STATUS_IN_PROGRESS, 1);
        $singleKeep = $this->legacyWorkout($single, Workout::STATUS_IN_PROGRESS, 2);
        $this->legacyWorkout($user, Workout::STATUS_COMPLETED, 4);
        $this->legacyWorkout($user, Workout::STATUS_CANCELLED, 5);
        $keepIds = [$keep->id, $otherKeep->id, $singleKeep->id];
        $expectedWorkouts = DB::table('workouts')->orderBy('id')->get();
        $beforeExercises = DB::table('workout_exercises')->orderBy('id')->get();
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get();

        foreach ($expectedWorkouts as $workout) {
            if ($workout->status === Workout::STATUS_IN_PROGRESS && ! in_array($workout->id, $keepIds, true)) {
                $workout->status = Workout::STATUS_CANCELLED;
            }
        }

        $this->migration()->up();

        $this->assertEquals($expectedWorkouts, DB::table('workouts')->orderBy('id')->get());
        $this->assertEquals($beforeExercises, DB::table('workout_exercises')->orderBy('id')->get());
        $this->assertEquals($beforeSets, DB::table('workout_sets')->orderBy('id')->get());
        $this->assertSame(Workout::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(Workout::STATUS_CANCELLED, $older->fresh()->status);
        $this->assertSame(Workout::STATUS_CANCELLED, $otherOlder->fresh()->status);
        $this->assertSame($keepIds, Workout::query()->where('status', Workout::STATUS_IN_PROGRESS)->orderBy('id')->pluck('id')->all());

        $this->assertThrows(
            fn () => $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 0),
            UniqueConstraintViolationException::class,
        );
        $this->assertDatabaseCount('workouts', 8);
    }

    public function test_cleanup_rolls_back_if_the_index_cannot_be_created(): void
    {
        $user = User::factory()->create();
        $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 1);
        $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 0);
        $beforeWorkouts = DB::table('workouts')->orderBy('id')->get();
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get();
        DB::statement('CREATE INDEX workouts_one_in_progress_per_user ON workouts (user_id)');

        $this->assertThrows(fn () => $this->migration()->up(), QueryException::class);

        $this->assertEquals($beforeWorkouts, DB::table('workouts')->orderBy('id')->get());
        $this->assertEquals($beforeSets, DB::table('workout_sets')->orderBy('id')->get());
    }

    public function test_rollback_only_removes_the_index_without_reactivating_duplicates(): void
    {
        $user = User::factory()->create();
        $older = $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 1);
        $keep = $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 0);
        $this->migration()->up();
        $beforeWorkouts = DB::table('workouts')->orderBy('id')->get();
        $beforeExercises = DB::table('workout_exercises')->orderBy('id')->get();
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get();

        $this->migration()->down();

        $this->assertEquals($beforeWorkouts, DB::table('workouts')->orderBy('id')->get());
        $this->assertEquals($beforeExercises, DB::table('workout_exercises')->orderBy('id')->get());
        $this->assertEquals($beforeSets, DB::table('workout_sets')->orderBy('id')->get());
        $this->assertSame(Workout::STATUS_CANCELLED, $older->fresh()->status);
        $this->assertSame(Workout::STATUS_IN_PROGRESS, $keep->fresh()->status);
        $this->legacyWorkout($user, Workout::STATUS_IN_PROGRESS, 0);
        $this->assertDatabaseCount('workouts', 3);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_09_000000_enforce_one_in_progress_workout_per_user.php');
    }

    private function legacyWorkout(User $user, string $status, int $daysAgo): Workout
    {
        $workout = (new Workout)->forceFill([
            'user_id' => $user->id,
            'workout_type_id' => WorkoutType::query()->firstOrFail()->id,
            'status' => $status,
            'current_exercise_index' => 2,
            'started_at' => now()->subDays(10),
            'completed_at' => $status === Workout::STATUS_COMPLETED ? now()->subDays($daysAgo) : null,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays($daysAgo),
        ]);
        $workout->save();
        $exercise = WorkoutExercise::query()->create([
            'workout_id' => $workout->id,
            'position' => 1,
            'name' => 'Preserved exercise snapshot',
            'working_sets' => 3,
            'min_reps' => 6,
            'max_reps' => 8,
            'completed_at' => now()->subDays($daysAgo),
        ]);
        WorkoutSet::query()->create([
            'workout_exercise_id' => $exercise->id,
            'set_number' => 1,
            'weight' => 41.25,
            'reps' => 8,
            'set_type' => WorkoutSet::TYPE_WORKING,
        ]);

        return $workout;
    }
}
