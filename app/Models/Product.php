<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    public const TYPES = ['core', 'plugin', 'theme', 'extension', 'package', 'library'];

    protected $fillable = [
        'vendor_id',
        'name',
        'slug',
        'type',
    ];

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<CpeMap, $this>
     */
    public function cpeMaps(): HasMany
    {
        return $this->hasMany(CpeMap::class);
    }

    /**
     * @return HasMany<VulnerabilityRange, $this>
     */
    public function vulnerabilityRanges(): HasMany
    {
        return $this->hasMany(VulnerabilityRange::class);
    }
}
