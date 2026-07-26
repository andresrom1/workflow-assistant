<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Quote extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'session_uuid',
        'risk_snapshot_id',
        'conversation_id',
        'status',                // 'pending', 'processed', 'failed', 'expired', 'checkout_pending', 'checkout_submitted'
        'external_ref_id',       // ID de correlación con el proveedor (Task ID)
        'resolution_method',     // 'api'
        'metadata',
        'expires_at',
        'checkout_token',
        'checkout_alternative_id',
        'public_token',
        'recommended_alternative_id',
        'presented_alternative_ids',
        'presentation_reasons',
        'presented_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'presented_alternative_ids' => 'array',
        'presentation_reasons' => 'array',
        'presented_at' => 'datetime',
    ];

    /**
     * Los dos tokens son credenciales de acceso a páginas públicas: nunca deben salir en una
     * serialización accidental del modelo. Mismo criterio que EmergencyTrackingToken::$hidden.
     */
    protected $hidden = [
        'checkout_token',
        'public_token',
    ];

    /**
     * Zona horaria del negocio. Los precios de las compañías valen por día calendario argentino,
     * mientras que la app corre en UTC (config('app.timezone')).
     */
    public const TIMEZONE = 'America/Argentina/Buenos_Aires';

    /** Fin del día calendario argentino, expresado en UTC para guardar en la base. */
    public static function endOfBusinessDay(?CarbonInterface $at = null): Carbon
    {
        return Carbon::instance($at ?? Carbon::now())
            ->setTimezone(self::TIMEZONE)
            ->endOfDay()
            ->utc();
    }

    /** ¿El precio todavía vale? Una cotización sin vencimiento se trata como vencida. */
    public function isVigente(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** @param  Builder<Quote>  $query */
    public function scopeVigente(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '>', now());
    }

    /**
     * Token de la vista pública, creándolo si hace falta.
     *
     * Idempotente a propósito: si el agente vuelve a presentar opciones, un link que ya se le
     * mandó al cliente tiene que seguir funcionando.
     */
    public function ensurePublicToken(): string
    {
        if ($this->public_token !== null && $this->public_token !== '') {
            return $this->public_token;
        }

        do {
            $token = Str::random(16);
        } while (static::withTrashed()->where('public_token', $token)->exists());

        $this->update(['public_token' => $token]);

        return $token;
    }

    /**
     * Las dos alternativas que el agente presentó, con la razón que le dio al cliente.
     * Null si esta cotización nunca se presentó.
     *
     * @return array{principal: array{id: int, razon: ?string}, segunda: array{id: int, razon: ?string}}|null
     */
    public function presentedPair(): ?array
    {
        $ids = $this->presented_alternative_ids ?? [];
        if (count($ids) < 2) {
            return null;
        }

        $razones = $this->presentation_reasons ?? [];
        $par = fn (int $id): array => ['id' => $id, 'razon' => $razones[(string) $id] ?? null];

        return ['principal' => $par((int) $ids[0]), 'segunda' => $par((int) $ids[1])];
    }

    /** @return BelongsTo<RiskSnapshot, $this> */
    public function riskSnapshot(): BelongsTo
    {
        return $this->belongsTo(RiskSnapshot::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<QuoteAlternative, $this> */
    public function alternatives(): HasMany
    {
        return $this->hasMany(QuoteAlternative::class);
    }

    public function checkoutSession(): HasOne
    {
        return $this->hasOne(CheckoutSession::class);
    }

    public function providerRef(): HasOne
    {
        return $this->hasOne(QuoteProviderRef::class);
    }
}
