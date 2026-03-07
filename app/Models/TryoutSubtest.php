<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TryoutSubtest extends Model
{
    protected $fillable = [
        'tryout_id',
        'subtest_id',
        'duration_minutes',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tryout()
    {
        return $this->belongsTo(Tryout::class);
    }

    public function subtest()
    {
        return $this->belongsTo(Subtest::class);
    }

    public function tryoutQuestions()
    {
        return $this->hasMany(TryoutQuestion::class)->orderBy('order_no');
    }
}