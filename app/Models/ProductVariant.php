<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'name_bn',
        'sku',
        'additional_price',
        'stock_quantity',
        'image',
        'is_active',
    ];

    protected $casts = [
        'additional_price' => 'float',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function inventoryTransactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function comboOfferProducts()
    {
        return $this->hasMany(ComboOfferProduct::class);
    }

    public function getCurrentStock()
    {
        return $this->stocks()->sum('quantity');
    }

    public function isInStock()
    {
        return $this->getCurrentStock() > 0;
    }
}
