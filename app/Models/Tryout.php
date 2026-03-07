<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tryout extends Model
{
    protected $fillable = [
        'title',
        'description',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accessCodes()
    {
        return $this->hasMany(AccessCode::class);
    }

    public function tryoutSubtests()
    {
        return $this->hasMany(TryoutSubtest::class)->orderBy('order_no');
    }
}