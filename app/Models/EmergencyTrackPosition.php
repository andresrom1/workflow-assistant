<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una posición del buffer de tracking (spec v2 §4.3, mejora de cadencia).
 *
 * @property int $id
 * @property int $emergency_tracking_token_id
 * @property string $lat
 * @property string $lon
 * @property Carbon $effective_at
 */
class EmergencyTrackPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'emergency_tracking_token_id',
        'lat',
        'lon',
        'effective_at',
    ];

    protected $casts = [
        'lat' => 'decimal:6',
        'lon' => 'decimal:6',
        'effective_at' => 'datetime',
    ];

    /** @return BelongsTo<EmergencyTrackingToken, $this> */
    public function trackingToken(): BelongsTo
    {
        return $this->belongsTo(EmergencyTrackingToken::class, 'emergency_tracking_token_id');
    }
}
