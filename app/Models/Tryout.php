<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tryout extends Model
{
    protected $fillable = [
        'title',
        'description',
        'duration_minutes',
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

    public function accessCode()
    {
        return $this->hasMany(AccessCode::class);
    }
}