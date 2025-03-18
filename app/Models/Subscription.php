<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'phone',
        'is_active',
        'last_email_sent_at',
        'source',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_email_sent_at' => 'datetime',
    ];
}
