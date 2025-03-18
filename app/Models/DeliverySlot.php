<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliverySlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'start_time',
        'end_time',
        'max_orders',
        'is_active',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'max_orders' => 'integer',
        'is_active' => 'boolean',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function isAvailable($date)
    {
        $orderCount = $this->orders()
            ->whereDate('delivery_date', $date)
            ->count();

        return $this->is_active && $orderCount < $this->max_orders;
    }

    public function getRemainingSlots($date)
    {
        $orderCount = $this->orders()
            ->whereDate('delivery_date', $date)
            ->count();

        return max(0, $this->max_orders - $orderCount);
    }
}
