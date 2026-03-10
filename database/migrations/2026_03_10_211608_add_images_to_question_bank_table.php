<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank', function (Blueprint $table) {
            $table->string('question_image')->nullable()->after('question_text');
            $table->string('discussion_image')->nullable()->after('discussion');
        });
    }

    public function down(): void
    {
        Schema::table('question_bank', function (Blueprint $table) {
            $table->dropColumn([
                'question_image',
                'discussion_image',
            ]);
        });
    }
};