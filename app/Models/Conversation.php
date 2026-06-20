<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string $external_conversation_id
 * @property Carbon|null $last_message_at
 * @property Carbon $created_at
 */
class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'external_conversation_id',
        'customer_id',
        'channel',
        'status',
        'metadata',
        'ended_at',
        'ext_user_id',
        'ext_username',
        'flags',
        'turns_in_current_step',
        'last_health_analysis_at',
        'semantic_analysis',
        'last_semantic_analysis_at',
        'semantic_analysis_turn_count',
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'flags' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_message_at' => 'datetime',
        'last_health_analysis_at' => 'datetime',
        'turns_in_current_step' => 'integer',
        'semantic_analysis' => 'array',
        'last_semantic_analysis_at' => 'datetime',
        'semantic_analysis_turn_count' => 'integer',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** @return HasMany<Quote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function coveragePreferences(): HasMany
    {
        return $this->hasMany(CoveragePreference::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function agentExecutionLogs(): HasMany
    {
        return $this->hasMany(AgentExecutionLog::class)->orderBy('created_at');
    }

    // =========================================================================
    // AI Orchestrator State
    // =========================================================================

    private const AI_STATE_DEFAULTS = [
        'customer_identified' => false,
        'vehicle_identified' => false,
        'coverage_set' => false,
        'quote_ready' => false,
        'checkout_done' => false,
    ];

    /**
     * Retorna el estado actual del orquestador de IA.
     * Los campos no presentes se completan con los valores por defecto.
     *
     * @return array<string, bool>
     */
    public function aiState(): array
    {
        $meta = $this->metadata ?? [];

        return array_merge(self::AI_STATE_DEFAULTS, $meta['ai_state'] ?? []);
    }

    /**
     * Actualiza parcialmente el estado del orquestador de IA.
     * Solo los campos incluidos en $patch son modificados.
     *
     * @param  array<string, bool>  $patch
     */
    public function updateAiState(array $patch): void
    {
        $previous = $this->aiState();
        $next = array_merge($previous, $patch);

        $meta = $this->metadata ?? [];
        $meta['ai_state'] = $next;

        $updates = ['metadata' => $meta];

        // Si alguna flag pasó de false → true (avance de step), resetear el contador.
        foreach ($patch as $key => $value) {
            if ($value === true && ($previous[$key] ?? false) === false) {
                $updates['turns_in_current_step'] = 0;
                break;
            }
        }

        $this->update($updates);
    }

    /**
     * Guarda el identificador externo de usuario (ej: BSUID) y el username externo.
     * Solo actualiza un campo si aún está vacío — no sobreescribe un BSUID ya guardado.
     */
    public function updateExternalIdentifiers(?string $extUserId, ?string $extUsername): void
    {
        $updates = [];

        if ($extUserId && ! $this->ext_user_id) {
            $updates['ext_user_id'] = $extUserId;
        }

        if ($extUsername && ! $this->ext_username) {
            $updates['ext_username'] = $extUsername;
        }

        if ($updates !== []) {
            $this->update($updates);
        }
    }
}
