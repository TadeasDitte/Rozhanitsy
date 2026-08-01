<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CpeMap extends Model
{
    use HasFactory;

    protected $table = 'cpe_maps';

    protected $fillable = [
        'cpe_vendor',
        'cpe_product',
        'product_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
