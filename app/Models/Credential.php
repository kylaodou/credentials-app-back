<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Credential extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'name', 'type', 'username', 'password', 'token', 'url', 'description', 'validity_date',
    ];

    protected $hidden = ['username', 'password', 'token'];

    public function setUsernameAttribute($value)
    {
        $this->attributes['username'] = $value ? encrypt($value) : null;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = $value ? encrypt($value) : null;
    }

    public function setTokenAttribute($value)
    {
        $this->attributes['token'] = $value ? encrypt($value) : null;
    }

    public function getUsernameAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    public function getPasswordAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    public function getTokenAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }
}
