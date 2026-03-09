<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Package extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tryouts()
    {
        return $this->belongsToMany(
            Tryout::class,
            'package_tryout',
            'package_id',
            'tryout_id'
        )->withTimestamps();
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function enrollments()
    {
        return $this->hasMany(UserPackageEnrollment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}