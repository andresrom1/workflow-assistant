<?php

namespace App\Models;

use App\Enums\InspectionPhotoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionPhoto extends Model
{
    use HasFactory;

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
