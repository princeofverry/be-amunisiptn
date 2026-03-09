<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class QuestionBankOption extends Model
{
    use HasUlids;

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