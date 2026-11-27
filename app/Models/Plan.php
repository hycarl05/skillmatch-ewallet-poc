<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'price_sen' => 'integer',
            'min_staff' => 'integer',
            'max_staff' => 'integer',
            'features' => 'array',
            'active' => 'boolean',
        ];
    }
}
