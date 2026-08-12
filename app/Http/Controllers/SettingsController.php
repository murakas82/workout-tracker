<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateExercise;
use App\Models\WorkoutType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $types = WorkoutType::query()
            ->with(['exercises' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return view('settings.index', compact('types'));
    }

    public function create(): View
    {
        return view('settings.form', [
            'exercise' => new Exercise([
                'working_sets' => 3,
                'min_reps' => 8,
                'max_reps' => 12,
                'rest_min_seconds' => 60,
                'rest_max_seconds' => 90,
                'active' => true,
            ]),
            'types' => WorkoutType::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $exercise = Exercise::query()->create($this->validatedExercise($request));
        $this->syncTemplate($exercise);

        return redirect()->route('settings.index')->with('status', 'Exercise created.');
    }

    public function edit(Exercise $exercise): View
    {
        return view('settings.form', [
            'exercise' => $exercise,
            'types' => WorkoutType::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Exercise $exercise): RedirectResponse
    {
        $exercise->update($this->validatedExercise($request));
        $this->syncTemplate($exercise);

        return redirect()->route('settings.index')->with('status', 'Exercise updated. Existing workout history was not changed.');
    }

    public function archive(Exercise $exercise): RedirectResponse
    {
        $exercise->forceFill([
            'active' => false,
            'archived_at' => now(),
        ])->save();

        WorkoutTemplateExercise::query()->where('exercise_id', $exercise->id)->delete();

        return redirect()->route('settings.index')->with('status', 'Exercise archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExercise(Request $request): array
    {
        return $request->validate([
            'workout_type_id' => ['required', Rule::exists('workout_types', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'muscle_group' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:1', 'max:99'],
            'working_sets' => ['required', 'integer', 'min:1', 'max:10'],
            'min_reps' => ['required', 'integer', 'min:1', 'max:100'],
            'max_reps' => ['required', 'integer', 'gte:min_reps', 'max:100'],
            'rest_min_seconds' => ['nullable', 'integer', 'min:0', 'max:1800'],
            'rest_max_seconds' => ['nullable', 'integer', 'gte:rest_min_seconds', 'max:1800'],
            'unilateral' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]) + [
            'unilateral' => $request->boolean('unilateral'),
            'active' => $request->boolean('active', true),
        ];
    }

    private function syncTemplate(Exercise $exercise): void
    {
        WorkoutTemplateExercise::query()->where('exercise_id', $exercise->id)->delete();

        if (! $exercise->active) {
            return;
        }

        $template = WorkoutTemplate::query()
            ->where('workout_type_id', $exercise->workout_type_id)
            ->where('active', true)
            ->first();

        if (! $template) {
            return;
        }

        WorkoutTemplateExercise::query()->create([
            'workout_template_id' => $template->id,
            'exercise_id' => $exercise->id,
            'position' => $exercise->sort_order,
        ]);
    }
}
