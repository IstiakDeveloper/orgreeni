<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'title_bn',
        'description',
        'description_bn',
        'type',
        'discount_percentage',
        'discount_amount',
        'is_active',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'discount_percentage' => 'float',
        'discount_amount' => 'float',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function products()
    {
        return $this->hasMany(PromotionProduct::class);
    }

    public function isActive()
    {
        $now = now();
        return $this->is_active && $now->between($this->starts_at, $this->expires_at);
    }

    public function isExpired()
    {
        return now()->greaterThan($this->expires_at);
    }
}

