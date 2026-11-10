<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateExercise;
use App\Models\WorkoutType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'weight_increment' => 2.5,
                'active' => true,
            ]),
            'types' => WorkoutType::query()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedExercise($request);

        DB::transaction(function () use ($data): void {
            $exercise = Exercise::query()->create($data);
            $this->resequence($exercise->workout_type_id, $exercise, movingDown: false);
        });

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
        $data = $this->validatedExercise($request);

        DB::transaction(function () use ($exercise, $data): void {
            $oldTypeId = $exercise->workout_type_id;
            $oldSortOrder = $exercise->sort_order;

            $exercise->update($data);

            $movingDown = $exercise->workout_type_id === $oldTypeId && $exercise->sort_order > $oldSortOrder;
            $this->resequence($exercise->workout_type_id, $exercise, $movingDown);

            if ($exercise->workout_type_id !== $oldTypeId) {
                $this->resequence($oldTypeId);
            }
        });

        return redirect()->route('settings.index')->with('status', 'Exercise updated. Existing workout history was not changed.');
    }

    public function archive(Exercise $exercise): RedirectResponse
    {
        DB::transaction(function () use ($exercise): void {
            $exercise->forceFill([
                'active' => false,
                'archived_at' => now(),
            ])->save();

            $this->resequence($exercise->workout_type_id);
        });

        return redirect()->route('settings.index')->with('status', 'Exercise archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExercise(Request $request): array
    {
        if (is_string($request->input('weight_increment'))) {
            $increment = str_replace(',', '.', trim($request->input('weight_increment')));
            $request->merge(['weight_increment' => $increment === '' ? null : $increment]);
        }

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
            'weight_increment' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'unilateral' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]) + [
            'unilateral' => $request->boolean('unilateral'),
            'active' => $request->boolean('active', true),
        ];
    }

    /**
     * Renumber a workout type's active exercises 1..n and rebuild its template to match.
     *
     * When $changed shares an order number with another exercise, it takes that exact slot:
     * it goes before the other exercise, or after it when it was moved down the list.
     */
    private function resequence(int $workoutTypeId, ?Exercise $changed = null, bool $movingDown = false): void
    {
        $exercises = Exercise::query()
            ->where('workout_type_id', $workoutTypeId)
            ->where('active', true)
            ->get()
            ->sort(function (Exercise $a, Exercise $b) use ($changed, $movingDown): int {
                if ($a->sort_order !== $b->sort_order) {
                    return $a->sort_order <=> $b->sort_order;
                }

                if ($changed && ($a->is($changed) || $b->is($changed))) {
                    $changedFirst = $a->is($changed) ? -1 : 1;

                    return $movingDown ? -$changedFirst : $changedFirst;
                }

                return $a->id <=> $b->id;
            })
            ->values();

        foreach ($exercises as $index => $exercise) {
            if ($exercise->sort_order !== $index + 1) {
                $exercise->forceFill(['sort_order' => $index + 1])->save();
            }
        }

        $templateIds = WorkoutTemplate::query()
            ->where('workout_type_id', $workoutTypeId)
            ->pluck('id');

        // Drop every template link for this type's exercises (including inactive ones), then
        // link the active ones to the active template in order.
        WorkoutTemplateExercise::query()->whereIn('workout_template_id', $templateIds)->delete();

        $template = WorkoutTemplate::query()
            ->where('workout_type_id', $workoutTypeId)
            ->where('active', true)
            ->first();

        if (! $template) {
            return;
        }

        foreach ($exercises as $index => $exercise) {
            WorkoutTemplateExercise::query()->create([
                'workout_template_id' => $template->id,
                'exercise_id' => $exercise->id,
                'position' => $index + 1,
            ]);
        }
    }
}
