<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'keyword',
        'user_id',
        'ip_address',
        'results_count',
    ];

    protected $casts = [
        'results_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
