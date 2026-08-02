<?php

namespace App\Models;

use Database\Factories\CpeMapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CpeMap extends Model
{
    /** @use HasFactory<CpeMapFactory> */
    use HasFactory;

    protected $table = 'cpe_map';

    protected $fillable = [
        'cpe_vendor',
        'cpe_product',
        'product_id',
        'match_type',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
