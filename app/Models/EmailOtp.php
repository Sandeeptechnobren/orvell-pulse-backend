<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    protected $table = 'email_otps';

    protected $fillable = [
        'email',
        'otp_hash',
        'type',
        'expires_at',
        'verified_at',
        'attempts',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'attempts'    => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isVerified() && $this->attempts < 5;
    }
}
