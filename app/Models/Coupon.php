<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_amount',
        'minimum_purchase_amount',
        'maximum_discount_amount',
        'usage_limit_per_coupon',
        'usage_limit_per_user',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_amount' => 'float',
        'minimum_purchase_amount' => 'float',
        'maximum_discount_amount' => 'float',
        'usage_limit_per_coupon' => 'integer',
        'usage_limit_per_user' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function isValid()
    {
        $now = now();
        return $this->is_active && $now->between($this->starts_at, $this->expires_at);
    }

    public function isExpired()
    {
        return now()->greaterThan($this->expires_at);
    }

    public function hasReachedUsageLimit()
    {
        if (!$this->usage_limit_per_coupon) {
            return false;
        }

        return $this->orders()->count() >= $this->usage_limit_per_coupon;
    }

    public function hasUserReachedLimit($userId)
    {
        if (!$this->usage_limit_per_user) {
            return false;
        }

        return $this->orders()->where('user_id', $userId)->count() >= $this->usage_limit_per_user;
    }

    public function calculateDiscount($subtotal)
    {
        if ($subtotal < $this->minimum_purchase_amount) {
            return 0;
        }

        $discount = 0;
        if ($this->discount_type === 'fixed') {
            $discount = $this->discount_amount;
        } else {
            $discount = ($subtotal * $this->discount_amount) / 100;
            if ($this->maximum_discount_amount && $discount > $this->maximum_discount_amount) {
                $discount = $this->maximum_discount_amount;
            }
        }

        return $discount;
    }
}
