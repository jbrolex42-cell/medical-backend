<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'is_active',
        'is_subscribed', 
    ];

  
    protected $hidden = [
        'password',
        'remember_token',
    ];

    
    protected $casts = [
        'is_active' => 'boolean',
        'is_subscribed' => 'boolean', 
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function emergencies(): HasMany
    {
        return $this->hasMany(Emergency::class, 'reported_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isDispatcher(): bool
    {
        return $this->role === 'dispatcher';
    }

    public function isParamedic(): bool
    {
        return $this->role === 'paramedic';
    }
}