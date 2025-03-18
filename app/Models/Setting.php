<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    public static function getValue($key, $default = null, $group = 'general')
    {
        $setting = static::where('key', $key)
            ->where('group', $group)
            ->first();

        return $setting ? $setting->value : $default;
    }

    public static function setValue($key, $value, $group = 'general')
    {
        return static::updateOrCreate(
            ['key' => $key, 'group' => $group],
            ['value' => $value]
        );
    }
}
