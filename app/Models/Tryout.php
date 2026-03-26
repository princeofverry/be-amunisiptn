<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Facades\Storage;

class Tryout extends Model
{
    use HasUlids;

    protected $fillable = [
        'title',
        'description',
        'image',
        'start_date',
        'end_date',
        'category',
        'is_free',
        'is_published',
        'created_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_free' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function getImageUrlAttribute()
    {
        if ($this->image) {
            return url('storage/' . $this->image);
        }
        return null;
    }

    protected $appends = ['image_url'];

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
        return $this->hasMany(TryoutSubtest::class);
    }

    public function sessions()
    {
        return $this->hasMany(TryoutSession::class);
    }
}