<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'title_bn',
        'slug',
        'content',
        'content_bn',
        'meta_title',
        'meta_description',
        'is_active',
        'show_in_footer',
        'show_in_header',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_footer' => 'boolean',
        'show_in_header' => 'boolean',
        'order' => 'integer',
    ];
}
