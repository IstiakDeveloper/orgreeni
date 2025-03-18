<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'otp',
        'type',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    public function isValid()
    {
        return !$this->is_used && now()->lt($this->expires_at);
    }

    public function markAsUsed()
    {
        $this->is_used = true;
        return $this->save();
    }

    public static function generateOtp()
    {
        return str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
}
