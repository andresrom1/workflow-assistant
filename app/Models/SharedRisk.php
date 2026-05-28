<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitación a Cuenta Compartida — un titular comparte un Risk con otro
 * usuario por email. El invitado se asocia al MobileAccount al aceptar.
 *
 * @property int $id
 * @property int $risk_id
 * @property string $shared_with_email
 * @property int $invited_by_mobile_account_id
 * @property int|null $accepted_by_mobile_account_id
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class SharedRisk extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_id',
        'shared_with_email',
        'invited_by_mobile_account_id',
        'accepted_by_mobile_account_id',
        'token',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return BelongsTo<MobileAccount, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(MobileAccount::class, 'invited_by_mobile_account_id');
    }

    /** @return BelongsTo<MobileAccount, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(MobileAccount::class, 'accepted_by_mobile_account_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null && $this->revoked_at === null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('accepted_at')->whereNull('revoked_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeForEmail(Builder $query, string $email): void
    {
        $query->where('shared_with_email', $email);
    }
}
