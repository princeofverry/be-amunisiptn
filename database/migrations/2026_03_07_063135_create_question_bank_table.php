<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_bank', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subtest_id')->constrained('subtests')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->text('question_text');
            $table->text('discussion')->nullable();
            $table->string('correct_answer', 5);
            $table->string('difficulty')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete(); // Ubah ke foreignUlid
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank');
    }
};