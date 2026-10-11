<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use App\Services\ProgressionService;
use App\Services\WorkoutSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkoutReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->seed();
    }

    public function test_saving_the_last_exercise_completes_the_workout_and_redirects_to_summary(): void
    {
        $user = User::factory()->create();
        $workout = $this->workoutAtLastExercise($user);
        $last = $workout->exercises()->where('position', 7)->firstOrFail();

        $this->actingAs($user)->post(route('workouts.exercises.save', [$workout, $last]), $this->setsPayload($last))
            ->assertRedirect(route('workouts.summary', $workout));

        $this->assertSame(Workout::STATUS_COMPLETED, $workout->fresh()->status);
        $this->assertTrue($workout->fresh()->completed_at->equalTo(now()));
        $this->assertSame(7, $workout->fresh()->current_exercise_index);
        $this->assertSame(ProgressionService::RESULT_INCREASE, $last->fresh()->progression_result);
        $this->assertTrue($last->fresh()->completed_at->equalTo(now()));
        $this->assertSame(4, $last->sets()->count());
        $this->assertDatabaseHas('workout_sets', [
            'workout_exercise_id' => $last->id, 'set_number' => 1, 'weight' => 41.25, 'reps' => $last->max_reps,
            'set_type' => WorkoutSet::TYPE_WORKING,
        ]);
        $this->assertDatabaseHas('workout_sets', [
            'workout_exercise_id' => $last->id, 'weight' => 30.50, 'reps' => 10, 'set_type' => WorkoutSet::TYPE_DROP,
        ]);
    }

    public function test_completion_failure_rolls_back_sets_progression_and_both_completion_states(): void
    {
        $user = User::factory()->create();
        $workout = $this->workoutAtLastExercise($user);
        $last = $workout->exercises()->where('position', 7)->firstOrFail();
        WorkoutSet::query()->create([
            'workout_exercise_id' => $last->id, 'set_number' => 1, 'weight' => 10, 'reps' => 1,
            'set_type' => WorkoutSet::TYPE_WORKING,
        ]);
        $beforeWorkout = $workout->fresh()->getAttributes();
        $beforeExercise = $last->fresh()->getAttributes();
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get();
        DB::unprepared("CREATE TRIGGER reject_workout_completion AFTER UPDATE OF status ON workouts WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'Completion failed'); END");
        $this->withoutExceptionHandling();

        $this->assertThrows(
            fn () => $this->actingAs($user)->post(route('workouts.exercises.save', [$workout, $last]), $this->setsPayload($last)),
            QueryException::class,
            'Completion failed',
        );

        $this->assertEquals($beforeWorkout, $workout->fresh()->getAttributes());
        $this->assertEquals($beforeExercise, $last->fresh()->getAttributes());
        $this->assertEquals($beforeSets, DB::table('workout_sets')->orderBy('id')->get());

        DB::unprepared('DROP TRIGGER reject_workout_completion');
        $this->post(route('workouts.exercises.save', [$workout, $last]), $this->setsPayload($last))
            ->assertRedirect(route('workouts.summary', $workout));
        $this->assertSame(Workout::STATUS_COMPLETED, $workout->fresh()->status);
    }

    public function test_intermediate_saving_still_redirects_to_the_next_exercise(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $workout = app(WorkoutSessionService::class)->start($user, $type);
        $first = $workout->exercises()->firstOrFail();

        $this->actingAs($user)->post(route('workouts.exercises.save', [$workout, $first]), $this->setsPayload($first))
            ->assertRedirect(route('workouts.exercise', [$workout, 2]));

        $this->assertSame(Workout::STATUS_IN_PROGRESS, $workout->fresh()->status);
        $this->assertNull($workout->fresh()->completed_at);
        $this->assertSame(2, $workout->fresh()->current_exercise_index);
        $this->assertNotNull($first->fresh()->completed_at);
    }

    public function test_cancelled_workout_pages_return_404_for_the_owner(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $sessions = app(WorkoutSessionService::class);
        $workout = $sessions->start($user, $type);
        $sessions->cancel($workout);

        $this->actingAs($user)->get(route('workouts.show', $workout))->assertNotFound();
        $this->get(route('workouts.exercise', [$workout, 1]))->assertNotFound();
    }

    public function test_completed_workout_pages_still_redirect_to_summary(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $sessions = app(WorkoutSessionService::class);
        $workout = $sessions->start($user, $type);
        $sessions->finish($workout);

        $this->actingAs($user)->get(route('workouts.show', $workout))->assertRedirect(route('workouts.summary', $workout));
        $this->get(route('workouts.exercise', [$workout, 1]))->assertRedirect(route('workouts.summary', $workout));
    }

    private function workoutAtLastExercise(User $user): Workout
    {
        $type = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $workout = app(WorkoutSessionService::class)->start($user, $type);
        $workout->exercises()->where('position', '<', 7)->update([
            'completed_at' => now()->subMinute(), 'progression_result' => ProgressionService::RESULT_KEEP,
        ]);
        $workout->forceFill(['current_exercise_index' => 7])->save();

        return $workout;
    }

    private function setsPayload(WorkoutExercise $exercise): array
    {
        return [
            'working' => [
                1 => ['weight' => '41.25', 'reps' => $exercise->max_reps],
                2 => ['weight' => '41.25', 'reps' => $exercise->max_reps],
                3 => ['weight' => '41.25', 'reps' => $exercise->max_reps],
            ],
            'drops' => [['weight' => '30.50', 'reps' => 10]],
        ];
    }
}
