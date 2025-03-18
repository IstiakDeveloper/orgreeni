<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_bn',
        'short_name',
        'short_name_bn',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
