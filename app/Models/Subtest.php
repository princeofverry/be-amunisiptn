<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subtest extends Model
{
    protected $fillable = [
        'name',
        'category',
    ];

    public function tryoutSubtests()
    {
        return $this->hasMany(TryoutSubtest::class);
    }
}