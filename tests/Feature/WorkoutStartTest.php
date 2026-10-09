<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutSet;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutType;
use App\Services\WorkoutSessionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkoutStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_repeated_start_requests_resume_the_workout_without_changing_progress(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $legs = WorkoutType::query()->where('code', 'legs')->firstOrFail();

        $this->actingAs($user)->post(route('workouts.start', $push))
            ->assertRedirect(route('workouts.show', Workout::query()->firstOrFail()))
            ->assertSessionMissing('status');

        $workout = Workout::query()->firstOrFail();
        app(WorkoutSessionService::class)->saveExercise($workout->exercises()->firstOrFail(), [
            ['set_number' => 1, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => WorkoutSet::TYPE_WORKING],
            ['set_number' => 2, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => WorkoutSet::TYPE_WORKING],
            ['set_number' => 3, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => WorkoutSet::TYPE_WORKING],
        ]);
        $beforeWorkout = $workout->fresh()->getAttributes();
        $beforeExercises = DB::table('workout_exercises')->orderBy('id')->get();
        $beforeSets = DB::table('workout_sets')->orderBy('id')->get();

        foreach ([$push, $legs] as $type) {
            $this->post(route('workouts.start', $type))
                ->assertRedirect(route('workouts.show', $workout))
                ->assertSessionHas('status', 'Continue or cancel the workout already in progress.');
        }

        $this->assertDatabaseCount('workouts', 1);
        $this->assertEquals($beforeWorkout, $workout->fresh()->getAttributes());
        $this->assertEquals($beforeExercises, DB::table('workout_exercises')->orderBy('id')->get());
        $this->assertEquals($beforeSets, DB::table('workout_sets')->orderBy('id')->get());
    }

    public function test_direct_service_calls_return_the_existing_workout_even_for_another_type(): void
    {
        $user = User::factory()->create();
        $sessions = app(WorkoutSessionService::class);
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $legs = WorkoutType::query()->where('code', 'legs')->firstOrFail();
        $first = $sessions->start($user, $push);
        WorkoutTemplate::query()->where('workout_type_id', $legs->id)->update(['active' => false]);

        $resumed = $sessions->start($user, $legs);

        $this->assertSame($first->id, $resumed->id);
        $this->assertSame($push->id, $resumed->workout_type_id);
        $this->assertFalse($resumed->wasRecentlyCreated);
        $this->assertCount(7, $resumed->exercises);
        $this->assertDatabaseCount('workouts', 1);
        $this->assertDatabaseCount('workout_exercises', 7);
    }

    public function test_a_start_race_redirects_to_the_winner_without_duplicate_exercises(): void
    {
        $user = User::factory()->create();
        $sessions = app(WorkoutSessionService::class);
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $legs = WorkoutType::query()->where('code', 'legs')->firstOrFail();
        $winner = null;
        $raceTriggered = false;

        // Complete a competing start after the first request's empty lookup,
        // before its transaction begins. The losing insert hits the real index.
        DB::listen(function (QueryExecuted $query) use ($user, $legs, $sessions, &$winner, &$raceTriggered): void {
            if ($raceTriggered || ! str_starts_with($query->sql, 'select * from "workouts"')
                || ! in_array(Workout::STATUS_IN_PROGRESS, $query->bindings, true)) {
                return;
            }

            $raceTriggered = true;
            $winner = $sessions->start($user, $legs);
        });

        $response = $this->actingAs($user)->post(route('workouts.start', $push));

        $this->assertTrue($raceTriggered);
        $this->assertNotNull($winner);
        $response->assertRedirect(route('workouts.show', $winner))
            ->assertSessionHas('status', 'Continue or cancel the workout already in progress.');
        $this->assertDatabaseCount('workouts', 1);
        $this->assertDatabaseCount('workout_exercises', 7);
        $this->assertSame($legs->id, Workout::query()->firstOrFail()->workout_type_id);
    }

    public function test_database_rejects_a_second_active_workout_even_when_bypassing_the_service(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        app(WorkoutSessionService::class)->start($user, $type);
        $otherType = WorkoutType::query()->where('id', '!=', $type->id)->firstOrFail();

        $this->assertThrows(
            fn () => DB::table('workouts')->insert([
                'user_id' => $user->id,
                'workout_type_id' => $otherType->id,
                'status' => Workout::STATUS_IN_PROGRESS,
            ]),
            fn (UniqueConstraintViolationException $exception) => $exception->columns === ['user_id'],
        );

        $this->assertDatabaseCount('workouts', 1);
    }

    public function test_database_rejects_reactivating_history_while_another_workout_is_active(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        app(WorkoutSessionService::class)->start($user, $type);
        $history = Workout::query()->create([
            'user_id' => $user->id,
            'workout_type_id' => $type->id,
            'status' => Workout::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->assertThrows(
            fn () => DB::table('workouts')->where('id', $history->id)->update(['status' => Workout::STATUS_IN_PROGRESS]),
            UniqueConstraintViolationException::class,
        );

        $this->assertSame(Workout::STATUS_COMPLETED, $history->fresh()->status);
        $this->assertDatabaseCount('workouts', 2);
    }

    public function test_the_constraint_allows_other_users_and_multiple_history_rows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $sessions = app(WorkoutSessionService::class);
        $first = $sessions->start($user, $type);
        $second = $sessions->start($other, $type);

        foreach ([Workout::STATUS_COMPLETED, Workout::STATUS_COMPLETED, Workout::STATUS_CANCELLED, Workout::STATUS_CANCELLED] as $status) {
            Workout::query()->create(['user_id' => $user->id, 'workout_type_id' => $type->id, 'status' => $status]);
        }

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('workouts', 6);
        $this->assertSame(2, Workout::query()->where('status', Workout::STATUS_IN_PROGRESS)->count());
    }

    public function test_finishing_or_cancelling_allows_a_new_workout_without_removing_history(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $sessions = app(WorkoutSessionService::class);
        $completed = $sessions->start($user, $type);
        $sessions->finish($completed);
        $cancelled = $sessions->start($user, $type);
        $sessions->cancel($cancelled);
        $active = $sessions->start($user, $type);

        $this->assertCount(3, array_unique([$completed->id, $cancelled->id, $active->id]));
        $this->assertSame(Workout::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(Workout::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(Workout::STATUS_IN_PROGRESS, $active->status);
        $this->assertDatabaseCount('workouts', 3);
        $this->assertDatabaseCount('workout_exercises', 21);
    }

    public function test_missing_templates_do_not_leave_an_empty_active_workout(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        WorkoutTemplate::query()->where('workout_type_id', $type->id)->update(['active' => false]);

        $this->assertThrows(
            fn () => app(WorkoutSessionService::class)->start($user, $type),
            ModelNotFoundException::class,
        );

        $this->assertDatabaseCount('workouts', 0);
        $this->assertDatabaseCount('workout_exercises', 0);
    }

    public function test_unrelated_unique_constraint_errors_are_not_swallowed(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $template = WorkoutTemplate::query()->where('workout_type_id', $type->id)->firstOrFail();
        DB::table('workout_template_exercises')->where('workout_template_id', $template->id)->update(['position' => 1]);

        $this->assertThrows(
            fn () => app(WorkoutSessionService::class)->start($user, $type),
            fn (UniqueConstraintViolationException $exception) => $exception->columns === ['workout_id', 'position'],
        );

        $this->assertDatabaseCount('workouts', 0);
        $this->assertDatabaseCount('workout_exercises', 0);
    }
}
