<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionBankOption extends Model
{
    protected $fillable = [
        'question_bank_id',
        'option_key',
        'option_text',
    ];

    public function questionBank()
    {
        return $this->belongsTo(QuestionBank::class);
    }
}