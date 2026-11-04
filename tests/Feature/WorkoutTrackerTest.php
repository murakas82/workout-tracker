<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutType;
use App\Services\ProgressionService;
use App\Services\WorkoutStatsService;
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

    public function test_new_legs_workouts_put_standing_calf_raise_before_seated_calf_raise(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'legs')->first());

        $this->assertSame('Standing Calf Raise', $workout->exercises->firstWhere('position', 6)->name);
        $this->assertSame('Seated Calf Raise', $workout->exercises->firstWhere('position', 7)->name);
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

    public function test_first_set_at_max_and_last_set_at_min_triggers_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 7, 6]);

        $this->assertSame(ProgressionService::RESULT_INCREASE, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_first_set_below_max_does_not_trigger_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [7, 8, 8]);

        $this->assertSame(ProgressionService::RESULT_KEEP, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_last_set_below_min_does_not_trigger_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 8, 5]);

        $this->assertSame(ProgressionService::RESULT_KEEP, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_twelve_eight_eight_on_eight_to_twelve_triggers_progression(): void
    {
        $exercise = $this->progressionExercise(min: 8, max: 12, reps: [12, 10, 8]);

        $this->assertSame(ProgressionService::RESULT_INCREASE, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_drop_sets_do_not_affect_progression(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 8, 5], drops: [[50, 20]]);

        $this->assertSame(ProgressionService::RESULT_KEEP, app(ProgressionService::class)->evaluate($exercise));
    }

    public function test_unilateral_progression_requires_both_sides_to_reach_the_rule(): void
    {
        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 7, 6], rightReps: [8, 7, 5]);

        $this->assertSame(ProgressionService::RESULT_KEEP, app(ProgressionService::class)->evaluate($exercise));

        $exercise = $this->progressionExercise(min: 6, max: 8, reps: [8, 7, 6], rightReps: [8, 7, 6]);

        $this->assertSame(ProgressionService::RESULT_INCREASE, app(ProgressionService::class)->evaluate($exercise));
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

    public function test_active_workout_cannot_be_viewed_as_completed_history(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());

        $this->actingAs($user)
            ->get(route('history.show', $workout))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('workouts.summary', $workout))
            ->assertNotFound();
    }

    public function test_cancelled_workout_does_not_show_live_exercise_screen(): void
    {
        $user = User::factory()->create();
        $session = app(WorkoutSessionService::class);
        $workout = $session->start($user, WorkoutType::query()->where('code', 'push')->first());
        $session->cancel($workout);

        $this->actingAs($user)
            ->get(route('workouts.show', $workout))
            ->assertNotFound();
    }

    public function test_malformed_set_input_returns_validation_errors_not_server_error(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $exercise = $workout->exercises()->first();
        $validWorking = [
            1 => ['weight' => 70, 'reps' => 8],
            2 => ['weight' => 70, 'reps' => 8],
            3 => ['weight' => 70, 'reps' => 8],
        ];

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), ['working' => 'abc'])
            ->assertSessionHasErrors('working.1.weight');

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), ['working' => $validWorking, 'drops' => 'abc'])
            ->assertSessionHasErrors('drops');

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), ['working' => $validWorking, 'drops' => [5]])
            ->assertSessionHasErrors('drops.0');

        $this->assertNull($exercise->refresh()->completed_at);
    }

    public function test_every_invalid_working_set_is_reported(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $exercise = $workout->exercises()->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $exercise]), [
                'working' => [
                    1 => ['weight' => '', 'reps' => 0],
                    2 => ['weight' => 70, 'reps' => 8],
                    3 => ['weight' => 70, 'reps' => 0],
                ],
            ])
            ->assertSessionHasErrors(['working.1.weight', 'working.1.reps', 'working.3.reps'])
            ->assertSessionDoesntHaveErrors(['working.2.weight', 'working.2.reps']);
    }

    public function test_workout_starts_after_archiving_first_exercise(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $first = Exercise::query()->where('workout_type_id', $push->id)->where('sort_order', 1)->firstOrFail();

        $this->actingAs($user)->patch(route('settings.exercises.archive', $first));

        $this->assertSame(range(1, 6), $this->pushOrder()->pluck('sort_order')->all());

        $this->actingAs($user)->post(route('workouts.start', $push));
        $workout = Workout::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(range(1, 6), $workout->exercises()->orderBy('position')->pluck('position')->all());
        $this->actingAs($user)
            ->get(route('workouts.show', $workout))
            ->assertOk()
            ->assertSee('Exercise 1 of 6');
    }

    public function test_new_exercise_with_taken_order_number_is_inserted_at_that_slot(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $before = $this->pushOrder()->pluck('name')->all();

        $this->actingAs($user)->post(route('settings.exercises.store'), [
            'workout_type_id' => $push->id,
            'name' => 'Dips',
            'sort_order' => 2,
            'working_sets' => 3,
            'min_reps' => 8,
            'max_reps' => 12,
        ])->assertRedirect(route('settings.index'));

        array_splice($before, 1, 0, 'Dips');
        $this->assertSame($before, $this->pushOrder()->pluck('name')->all());
        $this->assertSame(range(1, 8), $this->pushOrder()->pluck('sort_order')->all());

        $this->actingAs($user)->post(route('workouts.start', $push))->assertRedirect();
        $workout = Workout::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame($before, $workout->exercises()->orderBy('position')->pluck('name')->all());
    }

    public function test_moving_exercise_down_puts_it_at_the_requested_slot(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $order = $this->pushOrder();
        $moved = $order[1];

        $this->actingAs($user)->put(route('settings.exercises.update', $moved), [
            'workout_type_id' => $push->id,
            'name' => $moved->name,
            'muscle_group' => $moved->muscle_group,
            'sort_order' => 5,
            'working_sets' => $moved->working_sets,
            'min_reps' => $moved->min_reps,
            'max_reps' => $moved->max_reps,
            'unilateral' => $moved->unilateral ? 1 : 0,
            'active' => 1,
        ])->assertRedirect(route('settings.index'));

        $expected = $order->pluck('name')->all();
        array_splice($expected, 1, 1);
        array_splice($expected, 4, 0, $moved->name);

        $this->assertSame($expected, $this->pushOrder()->pluck('name')->all());
        $this->assertSame(5, $moved->refresh()->sort_order);
    }

    public function test_workout_start_numbers_exercises_consecutively_even_with_messy_template(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $template = WorkoutTemplate::query()->where('workout_type_id', $push->id)->firstOrFail();

        // Simulate legacy data: a gap and a duplicate position.
        $links = $template->templateExercises()->get();
        $links[0]->forceFill(['position' => 3])->save();
        $links[1]->forceFill(['position' => 3])->save();
        $links[2]->forceFill(['position' => 9])->save();

        $workout = app(WorkoutSessionService::class)->start($user, $push);

        $this->assertSame(range(1, 7), $workout->exercises->pluck('position')->all());
        $this->actingAs($user)->get(route('workouts.show', $workout))->assertOk();
    }

    public function test_completed_workout_cannot_be_changed_as_active_workout(): void
    {
        $user = User::factory()->create();
        $workout = $this->completeWorkout($user, 'push');

        $this->actingAs($user)
            ->delete(route('workouts.cancel', $workout))
            ->assertNotFound();
    }

    public function test_unfinished_exercises_can_be_reordered_during_active_workout(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $first = $workout->exercises()->where('position', 1)->first();
        $second = $workout->exercises()->where('position', 2)->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.move', [$workout, $second]), ['direction' => 'up'])
            ->assertRedirect(route('workouts.reorder', $workout));

        $this->assertSame(2, $first->refresh()->position);
        $this->assertSame(1, $second->refresh()->position);
        $this->assertSame(1, $workout->refresh()->current_exercise_index);
    }

    public function test_current_exercise_can_be_moved_to_later_during_active_workout(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $first = $workout->exercises()->where('position', 1)->first();
        $second = $workout->exercises()->where('position', 2)->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.later', [$workout, $first]))
            ->assertRedirect(route('workouts.exercise', [$workout, 1]));

        $this->assertSame(7, $first->refresh()->position);
        $this->assertSame(1, $second->refresh()->position);
        $this->assertSame(1, $workout->refresh()->current_exercise_index);
    }

    public function test_done_next_follows_reordered_unfinished_exercises(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $first = $workout->exercises()->where('position', 1)->first();
        $second = $workout->exercises()->where('position', 2)->first();
        $third = $workout->exercises()->where('position', 3)->first();

        $this->actingAs($user)
            ->post(route('workouts.exercises.later', [$workout, $first]));

        $this->actingAs($user)
            ->post(route('workouts.exercises.save', [$workout, $second->refresh()]), [
                'working' => [
                    1 => ['weight' => 70, 'reps' => 8],
                    2 => ['weight' => 70, 'reps' => 8],
                    3 => ['weight' => 70, 'reps' => 8],
                ],
            ])
            ->assertRedirect(route('workouts.exercise', [$workout, 2]));

        $this->assertSame(2, $third->refresh()->position);
        $this->assertSame(2, $workout->refresh()->current_exercise_index);
    }

    public function test_completed_exercises_cannot_be_reordered_during_active_workout(): void
    {
        $user = User::factory()->create();
        $workout = app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->first());
        $first = $workout->exercises()->where('position', 1)->first();
        $second = $workout->exercises()->where('position', 2)->first();

        app(WorkoutSessionService::class)->saveExercise($first, $this->setsFor($first, [8, 8, 8]));

        $this->actingAs($user)
            ->post(route('workouts.exercises.move', [$workout, $second]), ['direction' => 'up'])
            ->assertRedirect(route('workouts.reorder', $workout));

        $this->assertSame(1, $first->refresh()->position);
        $this->assertSame(2, $second->refresh()->position);
        $this->assertSame(2, $workout->refresh()->current_exercise_index);
    }

    public function test_completed_workout_exercise_sets_can_be_edited_from_history(): void
    {
        $user = User::factory()->create();
        $workout = $this->completeWorkout($user, 'push');
        $exercise = $workout->exercises()->where('position', 1)->first();

        $this->actingAs($user)
            ->put(route('history.exercises.update', [$workout, $exercise]), [
                'working' => [
                    1 => ['weight' => '41,25', 'reps' => 8],
                    2 => ['weight' => '41.25', 'reps' => 8],
                    3 => ['weight' => '41,25', 'reps' => 8],
                ],
                'drops' => [
                    ['weight' => '30,50', 'reps' => 10],
                ],
            ])
            ->assertRedirect(route('history.show', $workout).'#exercise-'.$exercise->id);

        $this->assertSame('41.25', $exercise->sets()
            ->where('set_type', WorkoutSet::TYPE_WORKING)
            ->where('set_number', 1)
            ->firstOrFail()
            ->weight);
        $this->assertSame('30.50', $exercise->sets()
            ->where('set_type', WorkoutSet::TYPE_DROP)
            ->where('set_number', 1)
            ->firstOrFail()
            ->weight);
        $this->assertSame(ProgressionService::RESULT_INCREASE, $exercise->refresh()->progression_result);
    }

    public function test_workout_stats_include_volume_and_chart_payloads(): void
    {
        $user = User::factory()->create();
        $workout = $this->completeWorkout($user, 'push')->load('exercises.sets');
        $stats = app(WorkoutStatsService::class);

        $summary = $stats->forWorkout($workout);
        $chartData = $stats->chartData($workout);

        $this->assertSame(7, $summary['exercises_completed']);
        // 7 exercises x 3 sets, plus 3 extra for the right side of the unilateral Single-Arm Cable Lateral Raise.
        $this->assertSame(24, $summary['working_sets_completed']);
        $this->assertGreaterThan(0, $summary['total_volume']);
        $this->assertNotNull($summary['heaviest_set']);
        $this->assertCount(7, $summary['per_exercise']);
        $this->assertSame('Incline Smith Machine Bench Press', $chartData['exercise_volume']['labels'][0]);
        $this->assertCount(1, $chartData['type_volume_history']['labels']);
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

    /**
     * @return \Illuminate\Support\Collection<int, Exercise>
     */
    private function pushOrder(): \Illuminate\Support\Collection
    {
        return Exercise::query()
            ->whereHas('workoutType', fn ($query) => $query->where('code', 'push'))
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();
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
     * @param  list<int>|null  $rightReps
     */
    private function progressionExercise(int $min, int $max, array $reps, array $drops = [], ?array $rightReps = null): WorkoutExercise
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
            'unilateral' => $rightReps !== null,
        ]);

        $sides = $rightReps === null
            ? [[null, $reps]]
            : [[WorkoutSet::SIDE_LEFT, $reps], [WorkoutSet::SIDE_RIGHT, $rightReps]];

        foreach ($sides as [$side, $sideReps]) {
            foreach ($sideReps as $index => $repCount) {
                WorkoutSet::query()->create([
                    'workout_exercise_id' => $exercise->id,
                    'set_number' => $index + 1,
                    'side' => $side,
                    'weight' => 70,
                    'reps' => $repCount,
                    'set_type' => WorkoutSet::TYPE_WORKING,
                ]);
            }
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
