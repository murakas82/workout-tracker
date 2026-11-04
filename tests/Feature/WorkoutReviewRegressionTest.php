<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutType;
use App\Services\WorkoutSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkoutReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_later_and_save_buttons_use_separate_forms(): void
    {
        [$workout, $exercise] = $this->startWorkout();
        $html = $this->get(route('workouts.show', $workout))->assertOk()->getContent();
        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $saveForm = $document->getElementById('workout-exercise-form');
        $this->assertNotNull($saveForm);
        $this->assertSame(route('workouts.exercises.save', [$workout, $exercise]), $saveForm->getAttribute('action'));
        $later = $xpath->query('//button[normalize-space(.)="Later"]')->item(0);
        $this->assertNotNull($later);
        $laterForm = $xpath->query('ancestor::form[1]', $later)->item(0);
        $this->assertNotNull($laterForm);
        $this->assertSame(route('workouts.exercises.later', [$workout, $exercise]), $laterForm->getAttribute('action'));
        $this->assertSame(0, $xpath->query('.//input[starts-with(@name, "working[")]', $laterForm)->length);
        $this->assertSame(1, $xpath->query('//button[@type="submit" and @form="workout-exercise-form"]')->length);

        $this->post($laterForm->getAttribute('action'))->assertRedirect(route('workouts.exercise', [$workout, 1]));
        $this->assertSame(7, $exercise->fresh()->position);
        $this->assertNull($exercise->fresh()->completed_at);
        $this->assertDatabaseCount('workout_sets', 0);
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_working_and_drop_weights_are_rejected(string $weight): void
    {
        [$workout, $exercise] = $this->startWorkout();
        $working = $this->workingSets();
        $working[1]['weight'] = $weight;
        $this->postJson(route('workouts.exercises.save', [$workout, $exercise]), ['working' => $working])
            ->assertUnprocessable()->assertJsonValidationErrors('working.1.weight');
        $this->postJson(route('workouts.exercises.save', [$workout, $exercise]), [
            'working' => $this->workingSets(),
            'drops' => [['weight' => $weight, 'reps' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('drops.0');

        $this->assertDatabaseCount('workout_sets', 0);
        $this->assertNull($exercise->fresh()->completed_at);
        $this->get(route('workouts.show', $workout))->assertOk();
    }

    public static function invalidWeights(): array
    {
        return [
            'positive infinity' => ['1e309'],
            'negative infinity' => ['-1e309'],
            'outside database precision' => ['100000'],
            'negative' => ['-0.01'],
            'not numeric' => ['NaN'],
        ];
    }

    #[DataProvider('validWeights')]
    public function test_valid_weight_boundaries_and_comma_decimals_remain_accepted(string $weight, string $stored): void
    {
        [$workout, $exercise] = $this->startWorkout();
        $working = $this->workingSets();
        $working[1]['weight'] = $weight;
        $this->post(route('workouts.exercises.save', [$workout, $exercise]), ['working' => $working])
            ->assertRedirect(route('workouts.exercise', [$workout, 2]));

        $this->assertSame($stored, $exercise->sets()->where('set_number', 1)->firstOrFail()->weight);
    }

    public static function validWeights(): array
    {
        return [
            'zero' => ['0', '0.00'],
            'database upper bound' => ['99999.99', '99999.99'],
            'comma decimal' => ['1,25', '1.25'],
        ];
    }

    #[DataProvider('malformedInputs')]
    public function test_both_forms_render_after_malformed_input_is_rejected(array $invalid): void
    {
        [$workout, $exercise] = $this->startWorkout();
        $payload = array_replace(['working' => $this->workingSets()], $invalid);
        $url = route('workouts.show', $workout);
        $this->from($url)->post(route('workouts.exercises.save', [$workout, $exercise]), $payload)
            ->assertRedirect($url)->assertSessionHasErrors();
        $this->get($url)->assertOk();
        $this->assertDatabaseCount('workout_sets', 0);

        $sessions = app(WorkoutSessionService::class);
        $sessions->saveExercise($exercise, [
            ['set_number' => 1, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => 'working'],
            ['set_number' => 2, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => 'working'],
            ['set_number' => 3, 'side' => null, 'weight' => 70, 'reps' => 8, 'set_type' => 'working'],
        ]);
        $sessions->finish($workout);
        $url = route('history.exercises.edit', [$workout, $exercise]);
        $this->from($url)->put(route('history.exercises.update', [$workout, $exercise]), $payload)
            ->assertRedirect($url)->assertSessionHasErrors();
        $this->get($url)->assertOk();
        $this->assertDatabaseCount('workout_sets', 3);
        $this->assertSame('70.00', $exercise->sets()->firstOrFail()->weight);
    }

    public static function malformedInputs(): array
    {
        return [
            'drop list is a string' => [['drops' => 'invalid']],
            'drop row is scalar' => [['drops' => [5]]],
            'drop weight is an array' => [['drops' => [['weight' => ['bad'], 'reps' => 8]]]],
            'drop reps is an array' => [['drops' => [['weight' => 70, 'reps' => ['bad']]]]],
            'working weight is an array' => [['working' => [1 => ['weight' => ['bad'], 'reps' => 8]]]],
            'working list is a string' => [['working' => 'invalid']],
        ];
    }

    public function test_archiving_all_exercises_prevents_start_without_blocking_another_workout(): void
    {
        $user = User::factory()->create();
        $push = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $this->actingAs($user);
        foreach ($push->exercises()->get() as $exercise) {
            $this->patch(route('settings.exercises.archive', $exercise))->assertRedirect();
        }
        $this->from(route('workouts.index'))->post(route('workouts.start', $push))
            ->assertRedirect(route('workouts.index'))->assertSessionHasErrors('workout_type');
        $this->assertDatabaseCount('workouts', 0);
        $this->assertDatabaseCount('workout_exercises', 0);

        $legs = WorkoutType::query()->where('code', 'legs')->firstOrFail();
        $this->post(route('workouts.start', $legs))->assertRedirect();
        $this->assertDatabaseCount('workouts', 1);
        $workout = Workout::query()->firstOrFail();
        $this->assertSame($legs->id, $workout->workout_type_id);
        $this->get(route('workouts.show', $workout))->assertOk();
    }

    private function startWorkout(): array
    {
        $user = User::factory()->create();
        $type = WorkoutType::query()->where('code', 'push')->firstOrFail();
        $workout = app(WorkoutSessionService::class)->start($user, $type);
        $this->actingAs($user);

        return [$workout, $workout->exercises->first()];
    }

    private function workingSets(): array
    {
        return [
            1 => ['weight' => '70', 'reps' => '8'],
            2 => ['weight' => '70', 'reps' => '8'],
            3 => ['weight' => '70', 'reps' => '8'],
        ];
    }
}
