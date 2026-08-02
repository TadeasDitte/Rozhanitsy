<?php

namespace App\Models;

use Database\Factories\ScanHostFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class ScanHost extends Model implements AuthenticatableContract
{
    /** @use HasFactory<ScanHostFactory> */
    use AuthenticatableTrait, HasApiTokens, HasFactory;

    protected $fillable = [
        'user_id',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ScanLog, $this>
     */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class);
    }
}
