<?php

namespace App\Models;

use App\Support\DocumentoIdentidad;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $pas_id
 * @property string|null $name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $dni
 * @property string|null $documento_key
 * @property string|null $document_type_id
 * @property string|null $person_type_id
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $birthdate
 * @property string|null $sex_id
 * @property string|null $tax_condition_id
 * @property string|null $domicilio_calle
 * @property string|null $domicilio_numero
 * @property string|null $domicilio_cp
 * @property string|null $domicilio_provincia
 * @property string|null $domicilio_localidad
 * @property bool $is_anonymous
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pas_id',
        'dni',
        'documento_key',
        'document_type_id',
        'person_type_id',
        'email',
        'phone',
        'name',
        'first_name',
        'last_name',
        'birthdate',
        'sex_id',
        'tax_condition_id',
        'domicilio_calle',
        'domicilio_numero',
        'domicilio_cp',
        'domicilio_provincia',
        'domicilio_localidad',
        'is_anonymous',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_anonymous' => 'boolean',
            'completed_at' => 'datetime',
            'birthdate' => 'date',
        ];
    }

    /**
     * Mantiene la identidad canónica coherente en TODO alta/edición (checkout, chat, ingesta):
     * normaliza `dni` a solo-dígitos y recalcula `documento_key` desde el documento + su tipo.
     * Única fuente de verdad de la clave de dedup ({@see DocumentoIdentidad}).
     */
    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->dni === null || $customer->dni === '') {
                return;
            }

            $customer->dni = DocumentoIdentidad::normalizar($customer->dni) ?? $customer->dni;
            $customer->documento_key = DocumentoIdentidad::clave(
                $customer->dni,
                $customer->document_type_id,
                $customer->person_type_id,
            );
        });
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @return HasMany<Risk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /**
     * PAS (Productor Asesor de Seguros) asignado al cliente.
     *
     * Es un User con role=pas; modelado como BelongsTo a users por simplicidad.
     * El consumer debe usar el helper isPas() o el scope pas() para validar.
     *
     * @return BelongsTo<User, $this>
     */
    public function pas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pas_id');
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<Quote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** @return HasMany<CustomerAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(CustomerAudit::class)->latest('created_at');
    }

    /**
     * Mantiene `name` (columna legacy que leen Avatar/búsqueda/mail) sincronizada con
     * los splits canónicos. La llama el servicio de consolidación al cambiar first/last.
     */
    public function syncName(): void
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        if ($name !== '') {
            $this->name = $name;
        }
    }

    // Scopes
    public function scopeAnonymous($query)
    {
        return $query->where('is_anonymous', true);
    }

    public function scopeComplete($query)
    {
        return $query->where('is_anonymous', false);
    }

    // Helpers
    /*
    * Determine if the customer has any contact information.
    * @return bool
    */
    public function hasContactInfo(): bool
    {
        return ! is_null($this->email)
            || ! is_null($this->phone);
    }

    /**
     * Determine if customer has legal identification.
     */
    public function hasLegalIdentification(): bool
    {
        return ! is_null($this->dni);
    }

    /**
     * Determine if customer can proceed to full policy flow.
     * TODO: Implement full validation logic
     * - hasLegalIdentification()
     * - hasContactInfo()
     * - profile_complete
     * - has vehicle inspection
     * - has payment method
     */
    public function canEmitPolicy(): bool
    {
        return true;
    }

    /*
    * Determine if the customer is anonymous.
    * @return bool
    */
    public function isAnonymous(): bool
    {
        return $this->is_anonymous;
    }

    public function markAsComplete(): void
    {
        $this->update([
            'is_anonymous' => false,
            'completed_at' => now(),
        ]);
    }
}
