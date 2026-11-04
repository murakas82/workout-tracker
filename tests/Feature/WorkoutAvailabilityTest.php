<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutType;
use App\Services\WorkoutRotationService;
use App\Services\WorkoutSessionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkoutAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_new_user_skips_an_empty_push_program(): void
    {
        $user = User::factory()->create();
        Exercise::query()->whereHas('workoutType', fn ($query) => $query->where('code', 'push'))->update(['active' => false]);

        $this->assertSame('legs', app(WorkoutRotationService::class)->nextFor($user)->code);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Start Legs workout')->assertDontSee('Start Push workout');
        $this->get(route('workouts.index'))->assertOk()->assertDontSee(route('workouts.start', 'push'), false);
    }

    public function test_rotation_skips_empty_templates_and_wraps_to_a_usable_type(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        Workout::query()->create([
            'user_id' => $user->id, 'workout_type_id' => $push->id,
            'status' => Workout::STATUS_COMPLETED, 'completed_at' => now(),
        ]);
        WorkoutTemplate::query()->whereHas('workoutType', fn ($query) => $query->where('code', 'legs'))->firstOrFail()->templateExercises()->delete();

        $rotation = app(WorkoutRotationService::class);
        $this->assertSame('pull', $rotation->nextFor($user)->code);
        WorkoutTemplate::query()->whereHas('workoutType', fn ($query) => $query->where('code', 'pull'))->update(['active' => false]);
        $this->assertSame('push', $rotation->nextFor($user)->code);
    }

    public function test_all_empty_programs_show_settings_without_a_broken_start_button(): void
    {
        $user = User::factory()->create();
        Exercise::query()->update(['active' => false]);

        $this->assertNull(app(WorkoutRotationService::class)->nextFor($user));
        foreach ([route('dashboard'), route('workouts.index')] as $url) {
            $this->actingAs($user)->get($url)->assertOk()->assertSee('No active workouts')
                ->assertSee(route('settings.index'), false)->assertDontSee('/workouts/start/', false);
        }
    }

    public function test_available_templates_and_start_use_the_same_eligibility_rule(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $original = $type->templates()->firstOrFail();
        $links = $original->templateExercises()->get();
        $usable = $type->templates()->create(['name' => 'Usable alternate', 'active' => true]);
        foreach ($links as $link) {
            $usable->templateExercises()->create(['exercise_id' => $link->exercise_id, 'position' => $link->position]);
        }
        $original->templateExercises()->delete();

        $this->assertSame('push', app(WorkoutRotationService::class)->nextFor($user)->code);
        $workout = app(WorkoutSessionService::class)->start($user, $type);
        $this->assertSame($links->pluck('exercise_id')->all(), $workout->exercises->pluck('exercise_id')->all());
    }

    public function test_workout_list_reuses_the_available_types_query(): void
    {
        $user = User::factory()->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from "workout_types"') && str_contains($query->sql, 'exists')) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($user)->get(route('workouts.index'))->assertOk();
        $this->assertCount(1, $queries);
    }
}
