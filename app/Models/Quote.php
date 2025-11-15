<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'vehicle_id',
        'coverage_type',
        'status',
        'vehicle_data',
        'coverage_data',
        'customer_preferences',
        'premium_amount',
        'payment_frequency',
        'suma_asegurada',
        'special_conditions',
        'discount_applied',
        'final_price',
        'priced_by_user_id',
        'priced_at',
        'expires_at',
    ];

    protected $casts = [
        'vehicle_data' => 'array',
        'coverage_data' => 'array',
        'customer_preferences' => 'array',
        'premium_amount' => 'decimal:2',
        'suma_asegurada' => 'decimal:2',
        'discount_applied' => 'decimal:2',
        'final_price' => 'decimal:2',
        'priced_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function pricedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priced_by_user_id');
    }
}