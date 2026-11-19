<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillpointTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }
}
