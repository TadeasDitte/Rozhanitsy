<?php

namespace App\Models;

use Database\Factories\CnaProductNameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A CNA vendor / product name that an analysed record ties to the CPE vendor /
 * product NVD uses for it, so CNA ranges of records NVD has not analysed yet,
 * which are filed under the CNA's own names, can be found by the CPE name.
 *
 * @property int $id
 * @property int $parsed_record_id
 * @property string|null $cna_vendor
 * @property string $cna_product
 * @property string|null $vendor
 * @property string $product
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CnaProductName extends Model
{
    /** @use HasFactory<CnaProductNameFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<ParsedRecord, $this>
     */
    public function parsedRecord(): BelongsTo
    {
        return $this->belongsTo(ParsedRecord::class);
    }
}
