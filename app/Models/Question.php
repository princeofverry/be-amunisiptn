<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'tryout_subtest_id',
        'question_text',
        'discussion',
        'correct_answer',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tryoutSubtest()
    {
        return $this->belongsTo(TryoutSubtest::class);
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class)->orderBy('option_key');
    }

    public function userAnswers()
    {
        return $this->hasMany(UserAnswer::class);
    }
}   