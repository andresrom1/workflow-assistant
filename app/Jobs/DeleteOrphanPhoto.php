<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteOrphanPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60; // segundos de espera entre reintentos

    /**
     * Create a new job instance.
     */
    public function __construct(public string $publicId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
        // Instanciar directamente con la URL del config
        // — más robusto en contexto de queue worker
            $cloudinary = new \Cloudinary\Cloudinary(
                config('cloudinary.cloud_url'));

            $result = $cloudinary->uploadApi()->destroy($this->publicId);

            Log::info('DeleteOrphanPhoto: asset eliminado', [
                'public_id' => $this->publicId,
                'result'    => $result['result'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            Log::error('DeleteOrphanPhoto Job failed', [
                'public_id' => $this->publicId,
                'error'     => $e->getMessage()
            ]);
            throw $e; // Relanzar para habilitar reintentos en la queue
        }
    }
}
