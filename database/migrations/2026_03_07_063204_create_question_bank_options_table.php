<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_bank_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('question_bank_id')->constrained('question_bank')->cascadeOnDelete(); // Ubah ke foreignUlid
            $table->string('option_key', 5);
            $table->text('option_text');
            $table->timestamps();

            $table->unique(['question_bank_id', 'option_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_options');
    }
};