<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Creativeorange\Gravatar\Facades\Gravatar;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $authentik_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property bool $is_admin
 * @property Carbon|null $updated_at
 * @property-read string $avatar
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'authentik_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'is_admin' => false,
    ];

    protected $appends = [
        'avatar',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ScanHost, $this>
     */
    public function scanHosts(): HasMany
    {
        return $this->hasMany(ScanHost::class);
    }

    /**
     * The Gravatar image URL for the user's email. The URL 404s when no
     * Gravatar is registered, so the frontend falls back to initials.
     *
     * @return Attribute<string, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Gravatar::get($this->email, ['secure' => true, 'fallback' => 404]),
        );
    }
}
