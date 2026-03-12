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
        'ticket_amount',
        'currency',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'price' => 'integer',
        'ticket_amount' => 'integer',
        'is_active' => 'boolean',
    ];

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