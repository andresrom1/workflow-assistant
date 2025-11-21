<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'dni',
        'email',
        'phone',
        'name',
        'is_anonymous',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_anonymous' => 'boolean',
        'completed_at' => 'datetime'
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
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
        return !is_null($this->email) 
            || !is_null($this->phone);
    }

    /**
     * Determine if customer has legal identification.
     * @return bool
     */
    public function hasLegalIdentification(): bool
    {
        return !is_null($this->dni);
    }

    /**
    * Determine if customer can proceed to full policy flow.
    * TODO: Implement full validation logic
    * - hasLegalIdentification()
    * - hasContactInfo()
    * - profile_complete
    * - has vehicle inspection
    * - has payment method
    * @return bool
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