<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use App\Services\ProgressionService;
use App\Services\WorkoutSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutAssistTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_PUSH = 'Incline Smith Machine Bench Press';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_existing_and_seeded_exercises_default_to_a_two_and_a_half_kg_step(): void
    {
        $this->assertSame(2.5, Exercise::query()->where('name', self::FIRST_PUSH)->firstOrFail()->weight_increment);
    }

    public function test_reaching_the_target_prefills_the_increased_weight(): void
    {
        $user = User::factory()->create();
        $this->completePush($user, reps: 8, weight: 60);
        $workout = $this->startPush($user);

        $this->actingAs($user)
            ->get(route('workouts.show', $workout))
            ->assertOk()
            ->assertSee('Try 62.5 kg today')
            ->assertSee('(+2.5 kg)')
            ->assertSee('value="62.5"', false)
            ->assertSee('input-compact input-suggested', false);
    }

    public function test_missing_the_target_keeps_the_previous_weight_without_a_suggestion(): void
    {
        $user = User::factory()->create();
        $this->completePush($user, reps: 7, weight: 60);
        $workout = $this->startPush($user);

        $this->actingAs($user)
            ->get(route('workouts.show', $workout))
            ->assertOk()
            ->assertDontSee('kg today')
            ->assertSee('value="60"', false)
            ->assertDontSee('input-suggested', false);
    }

    public function test_an_empty_or_zero_step_keeps_the_previous_weight(): void
    {
        foreach ([null, 0.0] as $increment) {
            $this->assertNull(app(ProgressionService::class)->suggestedWeight($this->increasedSession(60), $increment));
        }

        $this->assertSame(61.25, app(ProgressionService::class)->suggestedWeight($this->increasedSession(60), 1.25));
    }

    public function test_saved_sets_are_shown_instead_of_the_suggestion(): void
    {
        $user = User::factory()->create();
        $this->completePush($user, reps: 8, weight: 60);
        $workout = $this->startPush($user);
        $exercise = $workout->exercises->firstWhere('position', 1);

        app(WorkoutSessionService::class)->saveExercise($exercise, $this->sets(3, 8, 61));

        $this->actingAs($user)
            ->get(route('workouts.exercise', [$workout, 1]))
            ->assertOk()
            ->assertSee('value="61"', false)
            ->assertDontSee('value="62.5"', false)
            ->assertDontSee('input-suggested', false);
    }

    public function test_active_workout_renders_the_rest_timer_with_the_exercise_rest_range(): void
    {
        $user = User::factory()->create();
        $workout = $this->startPush($user);

        $this->actingAs($user)
            ->get(route('workouts.show', $workout))
            ->assertOk()
            ->assertSee('restTimer({ workoutId: '.$workout->id.', min: 120, max: 180 })', false)
            ->assertSee('data-rest-trigger', false);
    }

    public function test_settings_save_the_weight_step_and_accept_a_comma(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::query()->where('name', self::FIRST_PUSH)->firstOrFail();

        $this->actingAs($user)
            ->put(route('settings.exercises.update', $exercise), $this->exerciseForm($exercise, '1,25'))
            ->assertRedirect(route('settings.index'));

        $this->assertSame(1.25, $exercise->refresh()->weight_increment);

        $this->actingAs($user)
            ->put(route('settings.exercises.update', $exercise), $this->exerciseForm($exercise, ''))
            ->assertRedirect(route('settings.index'));

        $this->assertNull($exercise->refresh()->weight_increment);
    }

    public function test_settings_reject_an_invalid_weight_step(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::query()->where('name', self::FIRST_PUSH)->firstOrFail();

        foreach (['-1', '101', '1.255', 'abc'] as $invalid) {
            $this->actingAs($user)
                ->from(route('settings.exercises.edit', $exercise))
                ->put(route('settings.exercises.update', $exercise), $this->exerciseForm($exercise, $invalid))
                ->assertSessionHasErrors('weight_increment');
        }

        $this->assertSame(2.5, $exercise->refresh()->weight_increment);
    }

    private function startPush(User $user): Workout
    {
        return app(WorkoutSessionService::class)->start($user, WorkoutType::query()->where('code', 'push')->firstOrFail());
    }

    private function completePush(User $user, int $reps, float $weight): void
    {
        $sessions = app(WorkoutSessionService::class);
        $workout = $this->startPush($user);

        foreach ($workout->exercises as $exercise) {
            $sessions->saveExercise($exercise, $this->sets($exercise->working_sets, $reps, $weight, $exercise->unilateral));
        }

        $sessions->finish($workout->refresh());
    }

    /**
     * @return list<array{set_number:int,side:?string,weight:float,reps:int,set_type:string}>
     */
    private function sets(int $count, int $reps, float $weight, bool $unilateral = false): array
    {
        $sets = [];

        foreach ($unilateral ? [WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT] : [null] as $side) {
            for ($set = 1; $set <= $count; $set++) {
                $sets[] = ['set_number' => $set, 'side' => $side, 'weight' => $weight, 'reps' => $reps, 'set_type' => WorkoutSet::TYPE_WORKING];
            }
        }

        return $sets;
    }

    private function increasedSession(float $weight): WorkoutExercise
    {
        $exercise = new WorkoutExercise(['progression_result' => ProgressionService::RESULT_INCREASE]);
        $exercise->setRelation('sets', collect([
            new WorkoutSet(['set_number' => 1, 'weight' => $weight, 'reps' => 8, 'set_type' => WorkoutSet::TYPE_WORKING]),
        ]));

        return $exercise;
    }

    /**
     * @return array<string, mixed>
     */
    private function exerciseForm(Exercise $exercise, string $increment): array
    {
        return [
            'workout_type_id' => $exercise->workout_type_id,
            'name' => $exercise->name,
            'muscle_group' => $exercise->muscle_group,
            'sort_order' => $exercise->sort_order,
            'working_sets' => $exercise->working_sets,
            'min_reps' => $exercise->min_reps,
            'max_reps' => $exercise->max_reps,
            'rest_min_seconds' => $exercise->rest_min_seconds,
            'rest_max_seconds' => $exercise->rest_max_seconds,
            'weight_increment' => $increment,
            'unilateral' => $exercise->unilateral ? '1' : '0',
            'active' => '1',
        ];
    }
}
