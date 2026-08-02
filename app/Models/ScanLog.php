<?php

namespace App\Models;

use Database\Factories\ScanLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanLog extends Model
{
    /** @use HasFactory<ScanLogFactory> */
    use HasFactory;

    protected $fillable = [
        'scan_host_id',
        'tenant_id',
        'component_count',
        'vulnerable_count',
        'unmatched_count',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'component_count' => 'integer',
        'vulnerable_count' => 'integer',
        'unmatched_count' => 'integer',
    ];

    /**
     * @return BelongsTo<ScanHost, $this>
     */
    public function scanHost(): BelongsTo
    {
        return $this->belongsTo(ScanHost::class);
    }
}
