<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'type',
        'is_filterable',
        'is_required',
        'order',
    ];

    protected $casts = [
        'is_filterable' => 'boolean',
        'is_required' => 'boolean',
        'order' => 'integer',
    ];

    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
