<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'supplier_id',
        'total_amount',
        'discount',
        'paid_amount',
        'payment_status',
        'purchase_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'discount' => 'float',
        'paid_amount' => 'float',
        'purchase_date' => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDueAmount()
    {
        return $this->total_amount - $this->discount - $this->paid_amount;
    }

    public function isPaid()
    {
        return $this->payment_status === 'paid';
    }

    public function isPartiallyPaid()
    {
        return $this->payment_status === 'partial';
    }

    public function isPending()
    {
        return $this->payment_status === 'pending';
    }
}

