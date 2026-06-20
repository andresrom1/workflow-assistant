<?php

namespace App\Models;

use App\Services\CustomerConsolidationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por cada cambio efectivo de un campo del registro canónico `Customer`,
 * con la fuente que lo produjo (`admin`/`checkout`/`chat`). Lo escribe
 * {@see CustomerConsolidationService}. Ver docs/v2/11.
 */
class CustomerAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'user_id',
        'source',
        'field',
        'old_value',
        'new_value',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
