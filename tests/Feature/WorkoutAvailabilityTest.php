<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutType;
use App\Services\WorkoutRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_start_cancels_only_the_users_legacy_empty_workout(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $type = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $empty = $this->emptyWorkout($user, $type);
        $othersEmpty = $this->emptyWorkout($other, $type);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Continue workout');
        $this->post(route('workouts.start', $type))->assertRedirect();
        $this->assertSame(Workout::STATUS_CANCELLED, $empty->fresh()->status);
        $this->assertSame(Workout::STATUS_IN_PROGRESS, $othersEmpty->fresh()->status);
        $active = $user->workouts()->where('status', Workout::STATUS_IN_PROGRESS)->firstOrFail();
        $this->assertNotSame($empty->id, $active->id);
        $this->assertGreaterThan(0, $active->exercises()->count());
        $this->get(route('workouts.show', $active))->assertOk();
    }

    public function test_opening_a_legacy_empty_session_recovers_to_dashboard(): void
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->firstOrFail();
        $empty = $this->emptyWorkout($user, $type);

        $this->actingAs($user)->get(route('workouts.show', $empty))
            ->assertRedirect(route('dashboard'))->assertSessionHas('status');
        $this->assertSame(Workout::STATUS_CANCELLED, $empty->fresh()->status);
        $this->assertDatabaseHas('workouts', ['id' => $empty->id]);
    }

    private function emptyWorkout(User $user, WorkoutType $type): Workout
    {
        return Workout::query()->create([
            'user_id' => $user->id, 'workout_type_id' => $type->id,
            'status' => Workout::STATUS_IN_PROGRESS,
        ]);
    }
}
