<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Subtest extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'category',
    ];

    public function tryoutSubtests()
    {
        return $this->hasMany(TryoutSubtest::class);
    }

    public function questionBank()
    {
        return $this->hasMany(QuestionBank::class);
    }
}