<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            // Kilograms added to the suggested weight after a session that reached the progression target.
            // Null or 0 keeps the previous weight.
            $table->decimal('weight_increment', 5, 2)->nullable()->default(2.5)->after('rest_max_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('weight_increment');
        });
    }
};
