<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tryout_subtest_id')->constrained('tryout_subtests')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->text('question_text');
            $table->text('discussion')->nullable();
            $table->string('correct_answer', 5)->nullable();
            $table->unsignedInteger('order_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};