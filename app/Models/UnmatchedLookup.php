<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnmatchedLookup extends Model
{
    protected $table = 'unmatched_lookups';
    protected $fillable = ['cpe_vendor', 'cpe_product', 'hit_count', 'first_seen_at', 'last_seen_at'];
    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
