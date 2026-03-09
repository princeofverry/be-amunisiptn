<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class QuestionOption extends Model
{
    use HasUlids;

    protected $fillable = [
        'question_id',
        'option_key',
        'option_text',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}