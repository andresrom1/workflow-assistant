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
}
