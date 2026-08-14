<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use App\Services\ProgressionService;
use App\Services\WorkoutRotationService;
use App\Services\WorkoutSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_user_a_cannot_access_user_b_workout_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workout = $this->completeWorkout($owner, 'push');

        $this->actingAs($other)
            ->get(route('workouts.show', $workout))
            ->assertNotFound();
    }

    public function test_push_completion_makes_legs_next(): void
    {
        $user = User::factory()->create();

        $this->completeWorkout($user, 'push');

        $this->assertSame('legs', app(WorkoutRotationService::class)->nextFor($user)->code);
    }

    public function test_legs_completion_makes_pull_next(): void
    {
        $user = User::factory()->create();

        $this->completeWorkout($user, 'legs');

        $this->assertSame('pull', app(WorkoutRotationService::class)->nextFor($user)->code);
    }

    public function test_pull_completion_makes_push_next(): void
    {
        $user = User::factory()->create();

        $this->completeWorkout($user, 'pull');

        $this->assertSame('push', app(WorkoutRotationService::class)->nextFor($user)->code);
    }

    public function test_days_without_training_do_not_affect_rotation(): void
    {
        $user = User::factory()->create();
        $workout = $this->completeWorkout($user, 'push');
        $workout->forceFill(['completed_at' => now()->subDays(5)])->save();

        $this->assertSame('legs', app(WorkoutRotationService::class)->nextFor($user)->code);
    }

    public function test_eight_eight_eight_on_six_to_eight_triggers_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 8, 8]);

        $this->assertSame(ProgressionService::RESULT_INCREASE, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_eight_eight_seven_on_six_to_eight_does_not_trigger_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 8, 7]);

        $this->assertSame(ProgressionService::RESULT_KEEP, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_twelve_twelve_twelve_on_eight_to_twelve_triggers_progression(): void
    {
        $exercise = $this->progressionExercise(min: 8, max: 12, reps: [12, 12, 12]);

        $this->assertSame(ProgressionService::RESULT_INCREASE, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_drop_sets_do_not_affect_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 8, 7], drops: [[50, 20]]);

        $this->assertSame(ProgressionService::RESULT_KEEP, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_exercise_data_is_saved_when_pressing_done_next_exercise(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $exercise = $workout->exercises()->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), [
                'working' => [
                    1 => ['weight' => 70, 'reps' => 8],
                    2 => ['weight' => 70, 'reps' => 8],
                    3 => ['weight' => 70, 'reps' => 8],
                ],
            ])
            ->assertRedirect(route('workouts.exercise', [$workout, 2]));

        $this->assertDatabaseHas('workout_sets', [
            'workout_exercise_id' => $exercise->id,
            'set_number' => 1,
            'weight' => 70,
            'reps' => 8,
            'set_type' => WorkoutSet::TYPE_WORKING,
        ]);

        $this->assertNotNull($exercise->refresh()->completed_at);
    }

    public function test_decimal_weight_inputs_accept_hundredths_and_comma_separator(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $exercise = $workout->exercises()->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), [
                'working' => [
                    1 => ['weight' => '1,25', 'reps' => 8],
                    2 => ['weight' => '1.50', 'reps' => 8],
                    3 => ['weight' => '1,75', 'reps' => 8],
                ],
                'drops' => [
                    ['weight' => '0,75', 'reps' => 10],
                    ['weight' => '', 'reps' => ''],
                ],
            ])
            ->assertRedirect(route('workouts.exercise', [$workout, 2]));

        $this->assertSame('1.25', $exercise->sets()
            ->where('set_type', WorkoutSet::TYPE_WORKING)
            ->where('set_number', 1)
            ->firstOrFail()
            ->weight);

        $this->assertSame('0.75', $exercise->sets()
            ->where('set_type', WorkoutSet::TYPE_DROP)
            ->where('set_number', 1)
            ->firstOrFail()
            ->weight);
    }

    public function test_unfinished_workout_can_be_resumed(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $exercise = $workout->exercises()->first();

        app(WorkoutSessionService::class)->saveExercise($exercise, $this->setsFor($exercise, [8, 8, 8]));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Workout in progress')
            ->assertSee('Continue workout');

        $this->actingAs($user)
            ->get(route('workouts.show', $workout))
            ->assertOk()
            ->assertSee('Exercise 2 of 7');
    }

    public function test_editing_exercise_does_not_change_historical_workout_configuration(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $snapshot = $workout->exercises()->where('position', 1)->first();
        $source = $snapshot->exercise;

        $source->update(['max_reps' => 10]);

        $this->assertSame(8, $snapshot->refresh()->max_reps);
        $this->assertSame(10, $source->refresh()->max_reps);
    }

    public function test_unilateral_exercise_sets_store_left_and_right(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $exercise = $workout->exercises()->where('name', 'Single-Arm Cable Lateral Raise')->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), [
                'working' => [
                    'left' => [
                        1 => ['weight' => 10, 'reps' => 15],
                        2 => ['weight' => 10, 'reps' => 15],
                        3 => ['weight' => 10, 'reps' => 15],
                    ],
                    'right' => [
                        1 => ['weight' => 10, 'reps' => 15],
                        2 => ['weight' => 10, 'reps' => 15],
                        3 => ['weight' => 10, 'reps' => 15],
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(3, $exercise->sets()->where('side', WorkoutSet::SIDE_LEFT)->count());
        $this->assertSame(3, $exercise->sets()->where('side', WorkoutSet::SIDE_RIGHT)->count());
    }

    public function test_user_cannot_access_another_users_workout_history(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workout = $this->completeWorkout($owner, 'pull');

        $this->actingAs($other)
            ->get(route('history.show', $workout))
            ->assertNotFound();
    }

    public function test_manual_workout_selection_determines_subsequent_workout(): void
    {
        $user = User::factory()->create();

        $this->completeWorkout($user, 'push');
        $this->assertSame('legs', app(WorkoutRotationService::class)->nextFor($user)->code);

        $this->completeWorkout($user, 'pull');

        $this->assertSame('push', app(WorkoutRotationService::class)->nextFor($user)->code);
    }

    private function completeWorkout(User $user, string $code): Workout
    {
        $session = app(WorkoutSessionService::class);
        $workout = $session->start($user, WorkoutType::query()->where('code', $code)->firstOrFail());

        foreach ($workout->exercises as $exercise) {
            $session->saveExercise($exercise, $this->setsFor($exercise, array_fill(0, $exercise->working_sets, $exercise->max_reps)));
        }

        return $session->finish($workout->refresh());
    }

    /**
     * @param  list<int>  $reps
     * @return list<array{set_number:int,side:?string,weight:float,reps:int,set_type:string}>
     */
    private function setsFor(WorkoutExercise $exercise, array $reps): array
    {
        $sets = [];
        $sides = $exercise->unilateral ? [WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT] : [null];

        foreach ($sides as $side) {
            foreach ($reps as $index => $repCount) {
                $sets[] = [
                    'set_number' => $index + 1,
                    'side' => $side,
                    'weight' => 70.0,
                    'reps' => $repCount,
                    'set_type' => WorkoutSet::TYPE_WORKING,
                ];
            }
        }

        return $sets;
    }

    /**
     * @param  list<int>  $reps
     * @param  list<array{0:int,1:int}>  $drops
     */
    private function progressionExercise(int $min, int $max, array $reps, array $drops = []): WorkoutExercise
    {
        $user = User::factory()->create();
        $workout = Workout::query()->create([
            'user_id' => $user->id,
            'workout_type_id' => WorkoutType::query()->where('code', 'push')->first()->id,
            'status' => Workout::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $exercise = WorkoutExercise::query()->create([
            'workout_id' => $workout->id,
            'exercise_id' => Exercise::query()->first()->id,
            'position' => 1,
            'name' => 'Progression Test',
            'working_sets' => 3,
            'min_reps' => $min,
            'max_reps' => $max,
            'unilateral' => false,
        ]);

        foreach ($reps as $index => $repCount) {
            WorkoutSet::query()->create([
                'workout_exercise_id' => $exercise->id,
                'set_number' => $index + 1,
                'weight' => 70,
                'reps' => $repCount,
                'set_type' => WorkoutSet::TYPE_WORKING,
            ]);
        }

        foreach ($drops as $index => [$weight, $repCount]) {
            WorkoutSet::query()->create([
                'workout_exercise_id' => $exercise->id,
                'set_number' => $index + 1,
                'weight' => $weight,
                'reps' => $repCount,
                'set_type' => WorkoutSet::TYPE_DROP,
            ]);
        }

        return $exercise->load('sets');
    }
}
