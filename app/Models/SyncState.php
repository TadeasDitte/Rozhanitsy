<?php

// app/Models/SyncState.php

namespace App\Models;

use Database\Factories\SyncStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncState extends Model
{
    /** @use HasFactory<SyncStateFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
