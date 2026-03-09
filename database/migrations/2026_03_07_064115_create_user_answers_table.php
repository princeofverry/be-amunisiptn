<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tryout_session_id')->constrained('tryout_sessions')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->foreignUlid('tryout_question_id')->constrained('tryout_questions')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->string('answer', 5)->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['tryout_session_id', 'tryout_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_answers');
    }
};