<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class QuestionBank extends Model
{
    use HasUlids;

    protected $table = 'question_bank';

    protected $fillable = [
        'subtest_id',
        'question_text',
        'discussion',
        'correct_answer',
        'difficulty',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function subtest()
    {
        return $this->belongsTo(Subtest::class);
    }

    public function options()
    {
        return $this->hasMany(QuestionBankOption::class)->orderBy('option_key');
    }

    public function tryoutQuestions()
    {
        return $this->hasMany(TryoutQuestion::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}