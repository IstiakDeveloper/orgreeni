<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'shipping_address',
        'delivery_area_id',
        'delivery_slot_id',
        'delivery_date',
        'coupon_id',
        'subtotal',
        'discount',
        'shipping_charge',
        'vat',
        'total',
        'status',
        'payment_status',
        'payment_method',
        'transaction_id',
        'notes',
        'assigned_delivery_person_id',
        'delivered_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'subtotal' => 'float',
        'discount' => 'float',
        'shipping_charge' => 'float',
        'vat' => 'float',
        'total' => 'float',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveryArea()
    {
        return $this->belongsTo(DeliveryArea::class);
    }

    public function deliverySlot()
    {
        return $this->belongsTo(DeliverySlot::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function deliveryPerson()
    {
        return $this->belongsTo(User::class, 'assigned_delivery_person_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function addStatusHistory($status, $remarks = null, $userId = null)
    {
        return $this->statusHistories()->create([
            'status' => $status,
            'remarks' => $remarks,
            'created_by' => $userId,
        ]);
    }

    public function isPaid()
    {
        return $this->payment_status === 'paid';
    }

    public function isDelivered()
    {
        return $this->status === 'delivered';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function canBeCancelled()
    {
        return in_array($this->status, ['pending', 'confirmed', 'processing']);
    }

    public function generateOrderNumber()
    {
        // Generate a unique order number (e.g., CHL-2023-00001)
        $prefix = 'CHL';
        $year = date('Y');
        $lastOrder = static::whereYear('created_at', $year)->latest()->first();
        $number = $lastOrder ? intval(substr($lastOrder->order_number, -5)) + 1 : 1;

        return $prefix . '-' . $year . '-' . str_pad($number, 5, '0', STR_PAD_LEFT);
    }
}
