<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('muscle_group')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('working_sets')->default(3);
            $table->unsignedInteger('min_reps');
            $table->unsignedInteger('max_reps');
            $table->unsignedInteger('rest_min_seconds')->nullable();
            $table->unsignedInteger('rest_max_seconds')->nullable();
            $table->boolean('unilateral')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['workout_type_id', 'active', 'sort_order']);
        });

        Schema::create('workout_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['workout_type_id', 'active']);
        });

        Schema::create('workout_template_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['workout_template_id', 'exercise_id']);
            $table->index(['workout_template_id', 'position']);
        });

        Schema::create('workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_type_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('in_progress');
            $table->unsignedInteger('current_exercise_index')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'completed_at']);
            $table->index(['workout_type_id', 'status']);
        });

        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->string('muscle_group')->nullable();
            $table->unsignedInteger('working_sets');
            $table->unsignedInteger('min_reps');
            $table->unsignedInteger('max_reps');
            $table->unsignedInteger('rest_min_seconds')->nullable();
            $table->unsignedInteger('rest_max_seconds')->nullable();
            $table->boolean('unilateral')->default(false);
            $table->string('progression_result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['workout_id', 'position']);
            $table->index(['exercise_id', 'position']);
        });

        Schema::create('workout_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('set_number');
            $table->string('side')->nullable();
            $table->decimal('weight', 7, 2);
            $table->unsignedInteger('reps');
            $table->string('set_type')->default('working');
            $table->timestamps();

            $table->index(['workout_exercise_id', 'set_type']);
            $table->index(['workout_exercise_id', 'side', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_sets');
        Schema::dropIfExists('workout_exercises');
        Schema::dropIfExists('workouts');
        Schema::dropIfExists('workout_template_exercises');
        Schema::dropIfExists('workout_templates');
        Schema::dropIfExists('exercises');
        Schema::dropIfExists('workout_types');
    }
};
