<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAnswer extends Model
{
    protected $fillable = [
        'tryout_session_id',
        'tryout_question_id',
        'answer',
        'is_correct',
        'answered_at',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'answered_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(TryoutSession::class, 'tryout_session_id');
    }

    public function tryoutQuestion()
    {
        return $this->belongsTo(TryoutQuestion::class);
    }
}