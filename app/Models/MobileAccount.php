<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cuenta de la app móvil. Identidad Firebase (Google / Apple).
 *
 * Vive en su propia tabla, desacoplada de `users` (admin Breeze) y de
 * `customers` (tomador del chat/checkout). La identidad es el email verificado
 * por OAuth: el tomador se resuelve matcheando ese email contra `customers.email`
 * (ver resolveCustomer). No hay paso de DNI ni vínculo explícito.
 *
 * @property int $id
 * @property string $firebase_uid
 * @property string $email
 * @property string|null $name
 * @property string|null $avatar_url
 * @property Carbon|null $email_verified_at
 * @property int|null $customer_id Vestigial: el modelo de link por DNI se descartó. Sin uso.
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
     * Resuelve el Customer titular asociado a esta cuenta.
     *
     * La identidad de la app es el email verificado por OAuth (único dato
     * certificado de la cadena). El match es por email contra `customers.email`,
     * que es `unique`: un email mapea a lo sumo a un tomador. No hay paso de DNI.
     *
     * Seam único: todos los controllers pasan por acá para chequear propiedad,
     * nunca matchean directo.
     */
    public function resolveCustomer(): ?Customer
    {
        return Customer::where('email', $this->email)->first();
    }
}
