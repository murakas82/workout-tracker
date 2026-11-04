<?php

namespace App\Http\Requests;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class WorkoutExerciseSetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<array{set_number:int,side:?string,weight:float,reps:int,set_type:string}>
     */
    public function workoutSets(WorkoutExercise $exercise): array
    {
        $errors = [];
        $sets = [];
        $working = $this->arrayInput('working');
        $sides = $exercise->unilateral ? [WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT] : [null];

        foreach ($sides as $side) {
            for ($setNumber = 1; $setNumber <= $exercise->working_sets; $setNumber++) {
                $key = $side === null ? (string) $setNumber : $side.'.'.$setNumber;
                $row = data_get($working, $key);
                $row = is_array($row) ? $row : [];

                $weightValue = $this->normalizeWeight($row['weight'] ?? null);
                $reps = $row['reps'] ?? null;
                $label = $side ? ucfirst($side).' set '.$setNumber : 'Set '.$setNumber;
                $rowValid = true;

                if ($weightValue === null) {
                    $errors['working.'.$key.'.weight'] = $label.' needs a valid weight.';
                    $rowValid = false;
                }

                if (! $this->isValidReps($reps)) {
                    $errors['working.'.$key.'.reps'] = $label.' needs valid reps.';
                    $rowValid = false;
                }

                if ($rowValid) {
                    $sets[] = [
                        'set_number' => $setNumber,
                        'side' => $side,
                        'weight' => $weightValue,
                        'reps' => (int) $reps,
                        'set_type' => WorkoutSet::TYPE_WORKING,
                    ];
                }
            }
        }

        $dropRows = $this->input('drops', []);
        $dropNumber = 1;

        if (! is_array($dropRows)) {
            $errors['drops'] = 'Drop sets need valid weight and reps.';
            $dropRows = [];
        }

        foreach ($dropRows as $index => $row) {
            if (! is_array($row)) {
                $errors['drops.'.$index] = 'Drop sets need valid weight and reps.';

                continue;
            }

            $weight = $row['weight'] ?? null;
            $reps = $row['reps'] ?? null;
            $side = $exercise->unilateral ? ($row['side'] ?? null) : null;

            if ($this->isBlankInput($weight) && $this->isBlankInput($reps) && $this->isBlankInput($side)) {
                continue;
            }

            $weightValue = $this->normalizeWeight($weight);

            if ($weightValue === null || ! $this->isValidReps($reps)) {
                $errors['drops.'.$index] = 'Drop sets need valid weight and reps.';

                continue;
            }

            if ($exercise->unilateral && ! in_array($side, [WorkoutSet::SIDE_LEFT, WorkoutSet::SIDE_RIGHT], true)) {
                $errors['drops.'.$index.'.side'] = 'Choose left or right for each drop set.';

                continue;
            }

            $sets[] = [
                'set_number' => $dropNumber++,
                'side' => $side,
                'weight' => $weightValue,
                'reps' => (int) $reps,
                'set_type' => WorkoutSet::TYPE_DROP,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $sets;
    }

    private function normalizeWeight(mixed $weight): ?float
    {
        if ($this->isBlankInput($weight)) {
            return null;
        }

        if (is_string($weight)) {
            $weight = str_replace(',', '.', trim($weight));
        }

        if (! is_numeric($weight)) {
            return null;
        }

        $weight = (float) $weight;

        return is_finite($weight) && $weight >= 0 && $weight <= 99999.99 ? $weight : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayInput(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    private function isValidReps(mixed $reps): bool
    {
        return (is_int($reps) || is_string($reps)) && ctype_digit((string) $reps) && (int) $reps >= 1;
    }

    private function isBlankInput(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
