<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class QuestionBank extends Model
{
    use HasUlids;

    protected $table = 'question_bank';

    protected $fillable = [
        'subtest_id',
        'question_text',
        'question_image',
        'discussion',
        'discussion_image',
        'correct_answer',
        'difficulty',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'question_image_url',
        'discussion_image_url',
    ];

    public function getQuestionImageUrlAttribute(): ?string
    {
        return $this->question_image
            ? asset('storage/' . $this->question_image)
            : null;
    }

    public function getDiscussionImageUrlAttribute(): ?string
    {
        return $this->discussion_image
            ? asset('storage/' . $this->discussion_image)
            : null;
    }

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