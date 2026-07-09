<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del admin panel (Breeze, sesión cookie). Cuando role=pas —o role=admin,
 * que por jerarquía es un superset del PAS— también representa al Productor Asesor
 * de Seguros: su perfil (matrícula, teléfono, avatar) vive en `metadata` JSONB.
 *
 * No tiene nada que ver con la identidad de la app móvil: esa identidad vive
 * en App\Models\MobileAccount.
 *
 * @property UserRole $role
 * @property array<string, mixed> $metadata
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'metadata',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'metadata' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Un admin actúa como PAS: su jerarquía es superior, así que puede ser el
     * productor asignado a un cliente y el destino por default del siniestro.
     */
    public function isPas(): bool
    {
        return $this->role === UserRole::Pas || $this->role === UserRole::Admin;
    }

    /** @param  Builder<self>  $query */
    public function scopePas(Builder $query): void
    {
        $query->whereIn('role', [UserRole::Pas, UserRole::Admin]);
    }

    public function pasMatricula(): ?string
    {
        return $this->metadata['matricula'] ?? null;
    }

    public function pasPhone(): ?string
    {
        return $this->metadata['phone'] ?? null;
    }

    public function pasAvatarUrl(): ?string
    {
        return $this->metadata['avatar_url'] ?? null;
    }
}
