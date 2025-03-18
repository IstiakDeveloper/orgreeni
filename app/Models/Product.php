<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'name_bn',
        'slug',
        'description',
        'description_bn',
        'category_id',
        'brand_id',
        'unit_id',
        'sku',
        'barcode',
        'base_price',
        'sale_price',
        'discount_percentage',
        'weight',
        'is_vat_applicable',
        'vat_percentage',
        'is_featured',
        'is_popular',
        'stock_alert_quantity',
        'status',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    protected $casts = [
        'base_price' => 'float',
        'sale_price' => 'float',
        'discount_percentage' => 'float',
        'weight' => 'float',
        'is_vat_applicable' => 'boolean',
        'vat_percentage' => 'float',
        'is_featured' => 'boolean',
        'is_popular' => 'boolean',
        'stock_alert_quantity' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
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

    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function inventoryTransactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function promotionProducts()
    {
        return $this->hasMany(PromotionProduct::class);
    }

    public function promotions()
    {
        return $this->hasManyThrough(Promotion::class, PromotionProduct::class, 'product_id', 'id', 'id', 'promotion_id');
    }

    public function collections()
    {
        return $this->belongsToMany(ProductCollection::class, 'product_collection_items');
    }

    public function comboOffers()
    {
        return $this->belongsToMany(ComboOffer::class, 'combo_offer_products');
    }

    public function coupons()
    {
        return $this->belongsToMany(Coupon::class);
    }

    public function getThumbnail()
    {
        return $this->images()->where('is_primary', true)->first() ?? $this->images()->first();
    }

    public function getCurrentStock()
    {
        return $this->stocks()->sum('quantity');
    }

    public function isInStock()
    {
        return $this->getCurrentStock() > 0;
    }

    public function isLowStock()
    {
        return $this->getCurrentStock() <= $this->stock_alert_quantity;
    }

    public function getAverageRating()
    {
        return $this->reviews()->where('is_approved', true)->avg('rating') ?? 0;
    }

    public function getReviewCount()
    {
        return $this->reviews()->where('is_approved', true)->count();
    }

    public function getDiscountedPrice()
    {
        // Check for active promotions
        $promotionProduct = $this->promotionProducts()
            ->whereHas('promotion', function ($query) {
                $query->where('is_active', true)
                    ->where('starts_at', '<=', now())
                    ->where('expires_at', '>=', now());
            })
            ->first();

        if ($promotionProduct) {
            if ($promotionProduct->special_price) {
                return $promotionProduct->special_price;
            } elseif ($promotionProduct->discount_amount) {
                return $this->sale_price - $promotionProduct->discount_amount;
            } elseif ($promotionProduct->discount_percentage) {
                return $this->sale_price * (1 - $promotionProduct->discount_percentage / 100);
            }
        }

        // If no promotion, use regular discount
        return $this->sale_price;
    }
}
