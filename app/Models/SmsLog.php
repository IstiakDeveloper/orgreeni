<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'message',
        'type',
        'status',
        'gateway_response',
        'message_id',
    ];
}
