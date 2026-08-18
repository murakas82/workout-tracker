<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/home');
    Route::get('/home', DashboardController::class)->name('dashboard');

    Route::get('workouts', [WorkoutController::class, 'index'])->name('workouts.index');
    Route::post('workouts/start/{workoutType}', [WorkoutController::class, 'start'])->name('workouts.start');
    Route::get('workouts/{workout}', [WorkoutController::class, 'show'])->name('workouts.show');
    Route::get('workouts/{workout}/exercise/{position}', [WorkoutController::class, 'show'])->name('workouts.exercise');
    Route::get('workouts/{workout}/reorder', [WorkoutController::class, 'reorder'])->name('workouts.reorder');
    Route::post('workouts/{workout}/exercises/{workoutExercise}/move', [WorkoutController::class, 'moveExercise'])->name('workouts.exercises.move');
    Route::post('workouts/{workout}/exercises/{workoutExercise}/later', [WorkoutController::class, 'moveExerciseLater'])->name('workouts.exercises.later');
    Route::post('workouts/{workout}/exercises/{workoutExercise}', [WorkoutController::class, 'saveExercise'])->name('workouts.exercises.save');
    Route::get('workouts/{workout}/summary', [WorkoutController::class, 'summary'])->name('workouts.summary');
    Route::delete('workouts/{workout}', [WorkoutController::class, 'cancel'])->name('workouts.cancel');

    Route::get('history', [HistoryController::class, 'index'])->name('history.index');
    Route::get('history/{workout}/exercises/{workoutExercise}/edit', [WorkoutController::class, 'editCompletedExercise'])->name('history.exercises.edit');
    Route::put('history/{workout}/exercises/{workoutExercise}', [WorkoutController::class, 'updateCompletedExercise'])->name('history.exercises.update');
    Route::get('history/{workout}', [HistoryController::class, 'show'])->name('history.show');

    Route::get('progress', [ProgressController::class, 'index'])->name('progress.index');
    Route::get('progress/{exercise}', [ProgressController::class, 'show'])->name('progress.show');

    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/exercises/create', [SettingsController::class, 'create'])->name('settings.exercises.create');
    Route::post('settings/exercises', [SettingsController::class, 'store'])->name('settings.exercises.store');
    Route::get('settings/exercises/{exercise}/edit', [SettingsController::class, 'edit'])->name('settings.exercises.edit');
    Route::put('settings/exercises/{exercise}', [SettingsController::class, 'update'])->name('settings.exercises.update');
    Route::patch('settings/exercises/{exercise}/archive', [SettingsController::class, 'archive'])->name('settings.exercises.archive');
});
