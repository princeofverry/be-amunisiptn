<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAnswer extends Model
{
    protected $fillable = [
        'tryout_session_id',
        'question_id',
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

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}