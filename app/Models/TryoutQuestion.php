<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TryoutQuestion extends Model
{
    protected $fillable = [
        'tryout_subtest_id',
        'question_bank_id',
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

    public function questionBank()
    {
        return $this->belongsTo(QuestionBank::class);
    }
}