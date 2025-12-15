<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class RiskSnapshot extends Model
{
    protected $fillable = [
        'vehicle_id',
        'customer_id',
        'marca',
        'modelo',
        'version',
        'year',
        'combustible',
        'uso',
        'codigo_postal',
        'dni',
        'edad_conductor',
    ];

    protected $casts = [
        'year' => 'integer',
        'edad_conductor' => 'date',
    ];

    // Relaciones "blandas" (pueden ser null si se borra el original)
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withDefault();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withDefault();
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
