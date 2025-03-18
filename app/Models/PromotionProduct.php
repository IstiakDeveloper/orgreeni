<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromotionProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'product_id',
        'discount_percentage',
        'discount_amount',
        'special_price',
    ];

    protected $casts = [
        'discount_percentage' => 'float',
        'discount_amount' => 'float',
        'special_price' => 'float',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

