<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'city',
        'city_bn',
        'delivery_charge',
        'min_order_amount',
        'free_delivery_min_amount',
        'estimated_delivery_time',
        'is_active',
    ];

    protected $casts = [
        'delivery_charge' => 'float',
        'min_order_amount' => 'float',
        'free_delivery_min_amount' => 'float',
        'estimated_delivery_time' => 'integer',
        'is_active' => 'boolean',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function getDeliveryCharge($orderAmount)
    {
        if ($this->free_delivery_min_amount && $orderAmount >= $this->free_delivery_min_amount) {
            return 0;
        }

        return $this->delivery_charge;
    }
}
