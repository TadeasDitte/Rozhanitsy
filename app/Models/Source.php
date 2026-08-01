<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'base_url',
    ];

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(Vulnerability::class);
    }
}
