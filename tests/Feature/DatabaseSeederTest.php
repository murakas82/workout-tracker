<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutSet;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateExercise;
use App\Models\WorkoutType;
use App\Services\WorkoutSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installations_receive_the_default_workouts_and_templates(): void
    {
        $this->seed();

        $this->assertDatabaseCount('workout_types', 3);
        $this->assertDatabaseCount('exercises', 21);
        $this->assertDatabaseCount('workout_templates', 3);
        $this->assertDatabaseCount('workout_template_exercises', 21);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('exercises', [
            'name' => 'Incline Smith Machine Bench Press',
            'working_sets' => 3,
            'min_reps' => 6,
            'max_reps' => 8,
            'rest_min_seconds' => 120,
            'rest_max_seconds' => 180,
            'active' => true,
        ]);

        foreach (WorkoutTemplate::query()->with('templateExercises.exercise')->get() as $template) {
            $this->assertTrue($template->active);
            $this->assertCount(7, $template->templateExercises);
            $this->assertSame(range(1, 7), $template->templateExercises->pluck('position')->all());
            $this->assertTrue($template->templateExercises->every(fn ($entry) => $entry->exercise->active));
        }
    }

    public function test_rerunning_preserves_custom_settings_archives_templates_and_history(): void
    {
        $this->freezeTime();
        $this->seed();
        $user = User::factory()->create();
        $type = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $sessions = app(WorkoutSessionService::class);
        $workout = $sessions->start($user, $type);
        $snapshot = $workout->exercises()->firstOrFail();
        WorkoutSet::query()->create([
            'workout_exercise_id' => $snapshot->id,
            'set_number' => 1,
            'weight' => 41.25,
            'reps' => 8,
            'set_type' => WorkoutSet::TYPE_WORKING,
        ]);
        $sessions->finish($workout);

        $type->update(['name' => 'My Push', 'sort_order' => 99]);
        $exercise = Exercise::query()->where('workout_type_id', $type->id)->firstOrFail();
        $exercise->update([
            'name' => 'My customized exercise',
            'muscle_group' => 'Custom group',
            'sort_order' => 40,
            'working_sets' => 5,
            'min_reps' => 2,
            'max_reps' => 4,
            'rest_min_seconds' => 240,
            'rest_max_seconds' => 300,
            'unilateral' => true,
            'active' => false,
            'archived_at' => now()->subDay(),
        ]);
        Exercise::query()->where('name', 'Rope Triceps Pushdown')->update([
            'active' => false, 'archived_at' => now()->subDays(2),
        ]);
        $template = WorkoutTemplate::query()->where('workout_type_id', $type->id)->firstOrFail();
        $template->update(['name' => 'My custom template', 'active' => false]);
        $template->templateExercises()->where('position', 2)->delete();
        $template->templateExercises()->where('position', 1)->update(['position' => 20]);
        $custom = $exercise->replicate();
        $custom->name = 'My additional exercise';
        $custom->save();
        WorkoutTemplateExercise::query()->create([
            'workout_template_id' => $template->id, 'exercise_id' => $custom->id, 'position' => 1,
        ]);
        WorkoutTemplate::query()->create(['workout_type_id' => $type->id, 'name' => 'Another custom template', 'active' => true]);
        $legs = WorkoutType::query()->where('code', 'legs')->firstOrFail();
        WorkoutTemplateExercise::query()->whereIn('workout_template_id', $legs->templates()->pluck('id'))->delete();

        $tables = ['users', 'workout_types', 'exercises', 'workout_templates', 'workout_template_exercises', 'workouts', 'workout_exercises', 'workout_sets'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get();
        }

        $this->travel(5)->minutes();
        $this->seed();
        $this->seed();

        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get(), $table.' changed after reseeding.');
        }
    }

    public function test_initializing_a_missing_template_keeps_existing_exercise_settings(): void
    {
        $type = WorkoutType::query()->create(['code' => 'push', 'name' => 'My Push', 'sort_order' => 42]);
        $exercise = Exercise::query()->create([
            'workout_type_id' => $type->id,
            'name' => 'Incline Smith Machine Bench Press',
            'muscle_group' => 'Custom',
            'working_sets' => 5,
            'min_reps' => 2,
            'max_reps' => 4,
            'active' => false,
            'archived_at' => now()->subDay(),
        ]);
        $beforeType = $type->getAttributes();
        $beforeExercise = $exercise->getAttributes();

        $this->seed();

        $this->assertDatabaseHas('workout_types', $beforeType);
        $this->assertDatabaseHas('exercises', $beforeExercise);
        $this->assertDatabaseCount('exercises', 21);
        $this->assertDatabaseCount('workout_template_exercises', 21);
    }

    public function test_failed_initial_seeding_rolls_back_and_can_be_retried(): void
    {
        DB::unprepared("CREATE TRIGGER reject_seed_exercise BEFORE INSERT ON exercises WHEN NEW.name = 'Plate-Loaded Chest Press' BEGIN SELECT RAISE(ABORT, 'Seed failed'); END");

        $this->assertThrows(fn () => $this->seed(), QueryException::class, 'Seed failed');

        foreach (['workout_types', 'exercises', 'workout_templates', 'workout_template_exercises'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        DB::unprepared('DROP TRIGGER reject_seed_exercise');
        $this->seed();
        $this->assertDatabaseCount('exercises', 21);
        $this->assertDatabaseCount('workout_template_exercises', 21);
    }
}
