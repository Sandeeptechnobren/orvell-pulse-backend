<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';

    public $timestamps = false; 
    protected $fillable = [
        'email',
        'otp',
        'created_at',
    ];
}
