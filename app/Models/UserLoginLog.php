<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLoginLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'device_info',
        'is_successful',
        'failure_reason',
    ];

    protected $casts = [
        'is_successful' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
