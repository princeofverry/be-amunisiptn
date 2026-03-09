<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'google_id',
        'phone_number',
        'birth_date',
        'gender',
        'school_origin',
        'grade_level',
        'target_university_1',
        'target_major_1',
        'target_university_2',
        'target_major_2',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function createdTryouts()
    {
        return $this->hasMany(Tryout::class, 'created_by');
    }

    public function tryoutAccesses(){
        return $this->hasMany(UserTryoutAccess::class);
    }

    public function tryoutSessions()
    {
        return $this->hasMany(TryoutSession::class);
    }
}