<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['email', 'code', 'type', 'attempts', 'expires_at'];

    protected $hidden = ['code'];

    protected $casts = ['expires_at' => 'datetime', 'attempts' => 'integer'];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
