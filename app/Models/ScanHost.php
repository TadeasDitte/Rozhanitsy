<?php

// app/Models/ScanHost.php

namespace App\Models;

use Database\Factories\ScanHostFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A machine that runs the Svetovid scanner and posts component inventories.
 *
 * Implements Authenticatable so the `sanctum` guard — which is bound to the
 * `scan_hosts` provider — can resolve it as the authenticated principal.
 */
class ScanHost extends Model implements AuthenticatableContract
{
    /** @use HasFactory<ScanHostFactory> */
    use AuthenticatableTrait, HasApiTokens, HasFactory;

    protected $fillable = [
        'hostname',
        'last_seen_at',
        'is_active',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return HasMany<ScanLog, $this>
     */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class);
    }
}
