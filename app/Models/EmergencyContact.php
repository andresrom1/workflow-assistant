<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contacto de emergencia del usuario. Máx 3 por MobileAccount.
 *
 * @property int $id
 * @property int $mobile_account_id
 * @property string $name
 * @property string $phone
 */
class EmergencyContact extends Model
{
    use HasFactory;

    public const MAX_PER_ACCOUNT = 3;

    protected $fillable = [
        'mobile_account_id',
        'name',
        'phone',
    ];

    /** @return BelongsTo<MobileAccount, $this> */
    public function mobileAccount(): BelongsTo
    {
        return $this->belongsTo(MobileAccount::class);
    }
}
