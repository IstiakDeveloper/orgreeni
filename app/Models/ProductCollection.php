<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'slug',
        'description',
        'description_bn',
        'image',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(ProductCollectionItem::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_collection_items')
            ->withPivot('order')
            ->orderBy('product_collection_items.order');
    }
}
