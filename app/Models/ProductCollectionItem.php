<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCollectionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_collection_id',
        'product_id',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function collection()
    {
        return $this->belongsTo(ProductCollection::class, 'product_collection_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
