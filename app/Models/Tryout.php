<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Tryout extends Model
{
    use HasUlids;

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

    public function sessions()
    {
        return $this->hasMany(TryoutSession::class);
    }
}