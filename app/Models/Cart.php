<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'coupon_id',
        'subtotal',
        'discount',
        'shipping_charge',
        'vat',
        'total',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'discount' => 'float',
        'shipping_charge' => 'float',
        'vat' => 'float',
        'total' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    public function calculateTotals()
    {
        $subtotal = $this->items->sum('subtotal');
        $this->subtotal = $subtotal;

        // Apply coupon discount if available
        $this->discount = 0;
        if ($this->coupon_id) {
            $coupon = $this->coupon;
            if ($coupon && $coupon->isValid()) {
                $this->discount = $coupon->calculateDiscount($subtotal);
            }
        }

        // Calculate VAT
        $vatableAmount = $subtotal - $this->discount;
        $this->vat = $this->calculateVat($vatableAmount);

        // Calculate total
        $this->total = $subtotal - $this->discount + $this->shipping_charge + $this->vat;

        return $this;
    }

    private function calculateVat($amount)
    {
        // Implement VAT calculation logic
        // This is a simple example assuming a fixed VAT rate of 5%
        return $amount * 0.05;
    }
}
