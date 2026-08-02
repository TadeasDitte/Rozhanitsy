<?php

namespace App\Models;

use Database\Factories\UnmatchedLookupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnmatchedLookup extends Model
{
    /** @use HasFactory<UnmatchedLookupFactory> */
    use HasFactory;

    protected $table = 'unmatched_lookups';

    protected $fillable = [
        'cpe_vendor',
        'cpe_product',
        'hit_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'hit_count' => 'integer',
    ];

    protected $attributes = [
        'hit_count' => 1,
    ];
}
