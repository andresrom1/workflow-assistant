<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class CheckoutSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'quote_id',
        'quote_alternative_id',
        'status',
        'nombre',
        'first_name',
        'last_name',
        'birthdate',
        'sex_id',
        'tax_condition_id',
        'dni',
        'domicilio',
        'domicilio_calle',
        'domicilio_numero',
        'domicilio_cp',
        'domicilio_provincia',
        'domicilio_localidad',
        'vehiculo_uso',
        'vehiculo_nro_chasis',
        'vehiculo_nro_motor',
        'has_gnc',
        'email',
        'telefono',
        'phone_prefix',
        'phone_number',
        'cc_brand',
        'cc_pan_encrypted',
        'cc_expiry_encrypted',
        'cc_holder_name_encrypted',
        'cc_holder_dni_encrypted',
        'cc_processed_at',
        'cc_processed_by',
        'cc_cleared_at',
        'photo_paths',
        'submitted_at',
        'expires_at',
    ];

    /**
     * Los campos cifrados nunca se incluyen en serialización JSON.
     * Se accede a ellos solo desde el código PHP (vista admin).
     */
    protected $hidden = [
        'cc_pan_encrypted',
        'cc_expiry_encrypted',
        'cc_holder_name_encrypted',
        'cc_holder_dni_encrypted',
    ];

    protected $casts = [
        'photo_paths' => 'array',
        'birthdate' => 'date',
        'has_gnc' => 'boolean',
        'submitted_at' => 'datetime',
        'expires_at' => 'datetime',
        'cc_processed_at' => 'datetime',
        'cc_cleared_at' => 'datetime',
    ];

    // ─── Accessors de descifrado (solo para uso interno — vista admin) ────────

    public function getCcPanAttribute(): ?string
    {
        return $this->cc_pan_encrypted ? Crypt::decryptString($this->cc_pan_encrypted) : null;
    }

    public function getCcExpiryAttribute(): ?string
    {
        return $this->cc_expiry_encrypted ? Crypt::decryptString($this->cc_expiry_encrypted) : null;
    }

    public function getCcHolderNameAttribute(): ?string
    {
        return $this->cc_holder_name_encrypted ? Crypt::decryptString($this->cc_holder_name_encrypted) : null;
    }

    public function getCcHolderDniAttribute(): ?string
    {
        return $this->cc_holder_dni_encrypted ? Crypt::decryptString($this->cc_holder_dni_encrypted) : null;
    }

    // ─── Relaciones ────────────────────────────────────────────────────────────

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function quoteAlternative(): BelongsTo
    {
        return $this->belongsTo(QuoteAlternative::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cc_processed_by');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isCcCleared(): bool
    {
        return $this->cc_cleared_at !== null;
    }
}
