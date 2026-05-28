<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Token de tracking en tiempo real para Estado 2 de "Necesito Ayuda".
 *
 * @property int $id
 * @property int $mobile_account_id
 * @property string $token
 * @property string|null $last_lat
 * @property string|null $last_lon
 * @property Carbon|null $last_updated_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
class EmergencyTrackingToken extends Model
{
    use HasFactory;

    public const DEFAULT_TTL_HOURS = 4;

    protected $fillable = [
        'mobile_account_id',
        'token',
        'last_lat',
        'last_lon',
        'last_updated_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_lat' => 'decimal:6',
        'last_lon' => 'decimal:6',
        'last_updated_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<MobileAccount, $this> */
    public function mobileAccount(): BelongsTo
    {
        return $this->belongsTo(MobileAccount::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
