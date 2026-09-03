<?php

namespace App\Models;

use App\Enums\InspectionPhotoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionPhoto extends Model
{
    use HasFactory;

    /**
     * Las claves de inspección que el checkout sabe pedir. Es un vocabulario cerrado:
     * `photo_key` llega del cliente y se interpola en el path de almacenamiento, así que
     * se valida contra esta lista y no como texto libre.
     *
     * Vive acá y no en `config/visred.php` porque son las fotos que pedimos nosotros; el
     * config del adapter las traduce al catálogo del proveedor. `InspectionPhotoKeysTest`
     * verifica que ese mapa no se salga de esta lista.
     *
     * Las dos últimas solo aplican a vehículos con GNC (el formulario las condiciona).
     *
     * @var list<string>
     */
    public const CLAVES = [
        'tarjeta_verde',
        'frente',
        'atras',
        'lateral_i',
        'lateral_d',
        'auxilio',
        'parabrisas',
        'velocimetro',
        'tubo_gnc',
        'oblea_gnc',
    ];

    /**
     * OJO con `storage_url`: quedó de cuando el bucket era público y **ya no resuelve**.
     * La columna es NOT NULL, así que se sigue escribiendo, pero para mostrar una foto va
     * `Storage::disk('r2')->temporaryUrl($foto->storage_path, ...)`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'photo_key',
        'storage_path',
        'storage_url',
        'status',
        'uploaded_by_ip',
        'confirmed_at',
        'image_width',
        'image_height',
        'file_size',
    ];

    protected $casts = [
        'status' => InspectionPhotoStatus::class,
        'confirmed_at' => 'datetime',
    ];

    /**
     * Define the relationship to Quote.
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
