<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tryout_subtests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tryout_id')->constrained('tryouts')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->foreignUlid('subtest_id')->constrained('subtests')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedInteger('order_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tryout_id', 'subtest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tryout_subtests');
    }
};