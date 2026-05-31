<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cuenta de la app móvil. Identidad Firebase (Google / Apple).
 *
 * Vive en su propia tabla, desacoplada de `users` (admin Breeze) y de
 * `customers` (tomador del chat/checkout). El vínculo a Customer es 1:1
 * nullable; se resuelve cuando el usuario confirma su DNI en la app.
 *
 * @property int $id
 * @property string $firebase_uid
 * @property string $email
 * @property string|null $name
 * @property string|null $avatar_url
 * @property Carbon|null $email_verified_at
 * @property int|null $customer_id
 */
class MobileAccount extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'firebase_uid',
        'email',
        'name',
        'avatar_url',
        'email_verified_at',
        'customer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * El tomador (Customer) reclamado por esta cuenta. Null hasta el DNI.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * True si la cuenta ya está vinculada a un Customer (puede ver sus pólizas).
     */
    public function isLinked(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Resuelve el Customer titular asociado a esta cuenta.
     *
     * Seam único de des-mockeo (ver ROADMAP Fase 10): los controllers SIEMPRE
     * llaman a este método, nunca a la lógica de match directamente.
     *
     * - **Real (linking estricto):** solo el `customer_id` linkeado por el claim
     *   de DNI vale.
     * - **Mock laxo** (`config('mango.mock_customer_matching')`): si no hay
     *   linking, cae a match por email contra `customers.email`, para poder
     *   testear desde la app con cualquier seed sin cerrar el flujo de DNI.
     *
     * Para pasar a real: `MANGO_MOCK_CUSTOMER_MATCHING=false`. Cero cambios de
     * código en los call sites.
     */
    public function resolveCustomer(): ?Customer
    {
        if ($this->customer) {
            return $this->customer;
        }

        if (config('mango.mock_customer_matching')) {
            return Customer::where('email', $this->email)->first();
        }

        return null;
    }
}
