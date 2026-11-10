<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\WorkoutType;
use App\Services\ExerciseProgressService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgressController extends Controller
{
    private const RECENT_SESSIONS = 10;

    public function index(Request $request, ExerciseProgressService $progress): View
    {
        $recordsByExercise = $progress->recordsByExercise($request->user());
        $summaryFor = fn (Exercise $exercise) => $progress->summary($recordsByExercise->get($exercise->id, collect()));

        $groups = WorkoutType::query()
            ->with(['exercises' => fn ($query) => $query->where('active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (WorkoutType $type) => $type->exercises->isNotEmpty())
            ->map(fn (WorkoutType $type) => [
                'name' => $type->name,
                'rows' => $type->exercises->map(fn (Exercise $exercise) => ['exercise' => $exercise, 'summary' => $summaryFor($exercise)]),
            ])
            ->values();

        $archived = Exercise::query()
            ->with('workoutType')
            ->where('active', false)
            ->whereIn('id', $recordsByExercise->keys())
            ->orderBy('name')
            ->get()
            ->map(fn (Exercise $exercise) => ['exercise' => $exercise, 'summary' => $summaryFor($exercise)]);

        return view('progress.index', compact('groups', 'archived'));
    }

    public function show(Request $request, Exercise $exercise, ExerciseProgressService $progress): View
    {
        $exercise->loadMissing('workoutType');
        $records = $progress->records($request->user(), $exercise);
        $showAll = $request->boolean('all');

        return view('progress.show', [
            'exercise' => $exercise,
            'stats' => $progress->detail($records),
            'chartData' => $progress->chartData($records),
            'records' => $showAll ? $records : $records->take(self::RECENT_SESSIONS),
            'hiddenSessions' => $showAll ? 0 : max(0, $records->count() - self::RECENT_SESSIONS),
        ]);
    }
}
