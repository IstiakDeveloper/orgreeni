<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'description',
        'description_bn',
        'cost',
        'estimated_delivery_time',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'float',
        'estimated_delivery_time' => 'integer',
        'is_active' => 'boolean',
    ];
}
