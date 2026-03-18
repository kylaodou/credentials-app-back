<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'email', 'password', 'google_id', 'avatar', 'app_password',
    ];

    protected $hidden = ['password', 'app_password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime'];

    public function credentials()
    {
        return $this->hasMany(Credential::class);
    }
}
