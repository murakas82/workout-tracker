<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateExercise;
use App\Models\WorkoutType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $workouts = [
            'push' => [
                'name' => 'Push',
                'order' => 1,
                'exercises' => [
                    ['Incline Smith Machine Bench Press', 'Chest', 3, 6, 8, 120, 180, false],
                    ['Plate-Loaded Chest Press', 'Chest', 3, 8, 12, 120, 120, false],
                    ['High-to-Low Cable Fly', 'Chest', 3, 10, 15, 60, 90, false],
                    ['Shoulder Press', 'Shoulders', 3, 6, 10, 120, 120, false],
                    ['Single-Arm Cable Lateral Raise', 'Shoulders', 3, 10, 15, 60, 90, true],
                    ['Rope Triceps Pushdown', 'Triceps', 3, 8, 12, 60, 90, false],
                    ['Overhead Cable Triceps Extension', 'Triceps', 3, 10, 15, 60, 90, false],
                ],
            ],
            'legs' => [
                'name' => 'Legs',
                'order' => 2,
                'exercises' => [
                    ['Leg Press', 'Quads', 3, 6, 10, 120, 180, false],
                    ['Leg Extension Machine', 'Quads', 3, 10, 15, 90, 90, false],
                    ['Seated Leg Curl', 'Hamstrings', 3, 8, 12, 90, 120, false],
                    ['Hip Adductor Machine', 'Adductors', 3, 10, 15, 60, 90, false],
                    ['Hip Abductor Machine', 'Abductors', 3, 12, 20, 60, 90, false],
                    ['Standing Calf Raise', 'Calves', 3, 8, 12, 90, 120, false],
                    ['Seated Calf Raise', 'Calves', 3, 10, 15, 90, 90, false],
                ],
            ],
            'pull' => [
                'name' => 'Pull',
                'order' => 3,
                'exercises' => [
                    ['Lat Pulldown', 'Back', 3, 6, 10, 120, 120, false],
                    ['Plate-Loaded Row - Elbows 30-45 Degrees', 'Back', 3, 8, 12, 120, 120, false],
                    ['Plate-Loaded Row - Elbows 70-90 Degrees', 'Back', 3, 10, 15, 90, 120, false],
                    ['Face Pull', 'Rear Delts', 3, 12, 15, 60, 90, false],
                    ['Straight-Bar Cable Curl', 'Biceps', 3, 8, 12, 60, 90, false],
                    ['Single-Arm Cable Curl', 'Biceps', 3, 10, 12, 60, 90, true],
                    ['Rope Hammer Curl', 'Biceps', 3, 10, 15, 60, 90, false],
                ],
            ],
        ];

        foreach ($workouts as $code => $data) {
            $type = WorkoutType::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $data['name'], 'sort_order' => $data['order']],
            );

            $template = WorkoutTemplate::query()->updateOrCreate(
                ['workout_type_id' => $type->id, 'name' => $data['name'].' Template'],
                ['active' => true],
            );

            WorkoutTemplateExercise::query()->where('workout_template_id', $template->id)->delete();

            foreach ($data['exercises'] as $index => $exerciseData) {
                [$name, $group, $sets, $minReps, $maxReps, $restMin, $restMax, $unilateral] = $exerciseData;

                $exercise = Exercise::query()->updateOrCreate(
                    ['workout_type_id' => $type->id, 'name' => $name],
                    [
                        'muscle_group' => $group,
                        'sort_order' => $index + 1,
                        'working_sets' => $sets,
                        'min_reps' => $minReps,
                        'max_reps' => $maxReps,
                        'rest_min_seconds' => $restMin,
                        'rest_max_seconds' => $restMax,
                        'unilateral' => $unilateral,
                        'active' => true,
                        'archived_at' => null,
                    ],
                );

                WorkoutTemplateExercise::query()->create([
                    'workout_template_id' => $template->id,
                    'exercise_id' => $exercise->id,
                    'position' => $index + 1,
                ]);
            }
        }
    }
}
