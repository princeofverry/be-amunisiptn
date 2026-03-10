<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tryout_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tryout_subtest_id')->constrained('tryout_subtests')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->foreignUlid('question_bank_id')->constrained('question_bank')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->unsignedInteger('order_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tryout_subtest_id', 'question_bank_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tryout_questions');
    }
};