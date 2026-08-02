<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'url',
        'driver',
        'page_size',
        'request_delay_ms',
        'unauthenticated_request_delay_ms',
    ];

    protected $casts = [
        'page_size' => 'integer',
        'request_delay_ms' => 'integer',
        'unauthenticated_request_delay_ms' => 'integer',
    ];

    /**
     * @return HasMany<Vulnerability, $this>
     */
    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(Vulnerability::class);
    }

    /**
     * @return HasOne<SyncState, $this>
     */
    public function syncState(): HasOne
    {
        return $this->hasOne(SyncState::class);
    }
}
