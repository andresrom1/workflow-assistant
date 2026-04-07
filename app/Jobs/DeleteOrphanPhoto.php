<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteOrphanPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60; // segundos de espera entre reintentos

    /**
     * Create a new job instance.
     */
    public function __construct(public string $publicId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Storage::disk('r2')->delete($this->publicId);

            Log::info('DeleteOrphanPhoto: asset eliminado', [
                'storage_path' => $this->publicId,
            ]);
        } catch (\Exception $e) {
            Log::error('DeleteOrphanPhoto Job failed', [
                'storage_path' => $this->publicId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Relanzar para habilitar reintentos en la queue
        }
    }
}
