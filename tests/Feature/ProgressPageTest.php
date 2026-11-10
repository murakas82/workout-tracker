<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutSet;
use App\Models\WorkoutType;
use App\Services\ExerciseProgressService;
use App\Services\WorkoutSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressPageTest extends TestCase
{
    use RefreshDatabase;

    private const BENCH = 'Incline Smith Machine Bench Press';
    private const LATERAL = 'Single-Arm Cable Lateral Raise';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_estimated_max_uses_the_best_working_set(): void
    {
        $sets = collect([
            new WorkoutSet(['weight' => 100, 'reps' => 10, 'set_type' => WorkoutSet::TYPE_WORKING]),
            new WorkoutSet(['weight' => 110, 'reps' => 3, 'set_type' => WorkoutSet::TYPE_WORKING]),
            new WorkoutSet(['weight' => 200, 'reps' => 10, 'set_type' => WorkoutSet::TYPE_DROP]),
        ]);

        $this->assertSame(133.3, app(ExerciseProgressService::class)->estimatedOneRepMax($sets));
        $this->assertNull(app(ExerciseProgressService::class)->estimatedOneRepMax(collect()));
    }

    public function test_overview_groups_exercises_by_workout_and_shows_the_latest_session(): void
    {
        $user = User::factory()->create();
        $this->pushSession($user, weight: 60, reps: [7, 7, 6]);
        $this->pushSession($user, weight: 60, reps: [8, 7, 6]);

        $this->actingAs($user)->get(route('progress.index'))
            ->assertOk()
            ->assertSeeInOrder(['Push', self::BENCH, '60 kg', '↑', '8 / 7 / 6', 'Legs', 'Leg Press', 'No sessions yet', 'Pull'])
            ->assertSee('Ready to increase')
            ->assertSee('L 8 / 7 / 6 | R 8 / 7 / 6');
    }

    public function test_four_sessions_at_one_weight_without_the_target_is_stalled(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $session) {
            $this->pushSession($user, weight: 60, reps: [7, 7, 6]);
        }

        $this->actingAs($user)->get(route('progress.index'))->assertDontSee('Stalled');

        $this->pushSession($user, weight: 60, reps: [6, 6, 6]);

        $this->actingAs($user)->get(route('progress.index'))
            ->assertSee('Stalled · 4x')
            ->assertSee('↓');

        $this->actingAs($user)->get(route('progress.show', $this->exercise(self::BENCH)))
            ->assertSee('Stalled · 4 sessions at this weight');
    }

    public function test_archived_exercises_with_history_stay_reachable(): void
    {
        $user = User::factory()->create();
        $this->pushSession($user, weight: 60, reps: [8, 8, 8]);
        $bench = $this->exercise(self::BENCH);
        $bench->forceFill(['active' => false, 'archived_at' => now()])->save();
        $this->exercise('Leg Press')->forceFill(['active' => false, 'archived_at' => now()])->save();

        $this->actingAs($user)->get(route('progress.index'))
            ->assertOk()
            ->assertSee('Archived (1)')
            ->assertSee(route('progress.show', $bench), false);

        $this->actingAs($user)->get(route('progress.show', $bench))
            ->assertOk()
            ->assertSee('Archived')
            ->assertSee('8 / 8 / 8');
    }

    public function test_detail_shows_corrected_stats_and_chart_data(): void
    {
        $user = User::factory()->create();
        $this->pushSession($user, weight: 55, reps: [10, 9, 8]);
        $this->pushSession($user, weight: 60, reps: [8, 7, 6]);

        $response = $this->actingAs($user)->get(route('progress.show', $this->exercise(self::BENCH)))
            ->assertOk()
            ->assertSee('Latest reps')
            ->assertDontSee('Previous reps')
            ->assertSee('8 reps')
            ->assertSee('76 kg')
            ->assertSee('data-workout-chart="exercise-progress"', false)
            ->assertSee('data-workout-chart="exercise-volume-history"', false);

        $this->assertStringContainsString('"weight":[55,60]', $response->getContent());
        $this->assertStringContainsString('"estimated_max":[73.3,76]', $response->getContent());
    }

    public function test_unilateral_detail_splits_left_and_right(): void
    {
        $user = User::factory()->create();
        $this->pushSession($user, weight: 10, reps: [12, 11, 10], rightReps: [11, 10, 9]);

        $this->actingAs($user)->get(route('progress.show', $this->exercise(self::LATERAL)))
            ->assertOk()
            ->assertSee('L 12 / 11 / 10')
            ->assertSee('R 11 / 10 / 9');
    }

    public function test_detail_lists_ten_sessions_until_all_are_requested(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 12) as $session) {
            $this->pushSession($user, weight: 40 + $session, reps: [8, 8, 8]);
        }

        $bench = $this->exercise(self::BENCH);

        $this->actingAs($user)->get(route('progress.show', $bench))
            ->assertOk()
            ->assertSee('Recent sessions')
            ->assertSee('Show all 12 sessions')
            ->assertSee('52 kg')
            ->assertDontSee('>41 kg<', false);

        $this->actingAs($user)->get(route('progress.show', [$bench, 'all' => 1]))
            ->assertOk()
            ->assertDontSee('Show all')
            ->assertSee('>41 kg<', false);
    }

    public function test_other_users_sessions_are_not_counted(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->pushSession($other, weight: 90, reps: [8, 8, 8]);

        $this->actingAs($owner)->get(route('progress.index'))->assertDontSee('90 kg')->assertDontSee('Ready to increase');
        $this->actingAs($owner)->get(route('progress.show', $this->exercise(self::BENCH)))
            ->assertOk()
            ->assertSee('No completed sessions yet');
    }

    private function exercise(string $name): Exercise
    {
        return Exercise::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @param  list<int>  $reps
     * @param  list<int>|null  $rightReps
     */
    private function pushSession(User $user, float $weight, array $reps, ?array $rightReps = null): void
    {
        $this->travel(1)->days();
        $sessions = app(WorkoutSessionService::class);
        $workout = $sessions->start($user, WorkoutType::query()->where('code', 'push')->firstOrFail());

        foreach ($workout->exercises as $exercise) {
            $sides = $exercise->unilateral
                ? [WorkoutSet::SIDE_LEFT => $reps, WorkoutSet::SIDE_RIGHT => $rightReps ?? $reps]
                : ['' => $reps];
            $sets = [];

            foreach ($sides as $side => $sideReps) {
                foreach (array_slice($sideReps, 0, $exercise->working_sets) as $index => $repCount) {
                    $sets[] = [
                        'set_number' => $index + 1,
                        'side' => $side === '' ? null : $side,
                        'weight' => $weight,
                        'reps' => $repCount,
                        'set_type' => WorkoutSet::TYPE_WORKING,
                    ];
                }
            }

            $sessions->saveExercise($exercise, $sets);
        }

        $sessions->finish($workout->refresh());
    }
}
