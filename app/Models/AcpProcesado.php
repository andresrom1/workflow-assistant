<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Aviso a Corto Plazo (ACP) del SMN ya procesado y publicado al topic FCM.
 *
 * Dedup server-side: si el mismo `cap:identifier` aparece de nuevo en el feed
 * (caso típico mientras el aviso sigue vigente), no se reenvía.
 *
 * Spec v2 §4.4. Fase 4.
 *
 * @property string $id cap:identifier del XML CAP (PK natural).
 * @property Carbon $expires_at Vencimiento del aviso (cap:expires).
 * @property Carbon $processed_at Cuándo lo publicamos al topic.
 */
class AcpProcesado extends Model
{
    protected $table = 'acp_procesados';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'expires_at',
        'processed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
