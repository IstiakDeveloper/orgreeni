<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComboOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'slug',
        'description',
        'description_bn',
        'image',
        'regular_price',
        'sale_price',
        'starts_at',
        'expires_at',
        'is_active',
        'order',
    ];

    protected $casts = [
        'regular_price' => 'float',
        'sale_price' => 'float',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    public function products()
    {
        return $this->hasMany(ComboOfferProduct::class);
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

    public function getSavingAmount()
    {
        return $this->regular_price - $this->sale_price;
    }

    public function getSavingPercentage()
    {
        if ($this->regular_price > 0) {
            return round(($this->getSavingAmount() / $this->regular_price) * 100, 2);
        }

        return 0;
    }
}
