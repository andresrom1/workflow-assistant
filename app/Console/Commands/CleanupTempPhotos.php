<?php

namespace App\Console\Commands;

use App\Enums\InspectionPhotoStatus;
use App\Jobs\DeleteOrphanPhoto;
use App\Models\InspectionPhoto;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class CleanupTempPhotos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checkout:cleanup-temp-photos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up temporary inspection photos older than 24 hours';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Buscando fotos de inspección temporales de más de 24 horas...');

        $ttlHours = (int) app(SettingsService::class)->get('checkout.temp_photo_ttl_hours', 24);
        $orphans = InspectionPhoto::where('status', InspectionPhotoStatus::Temp)
            ->where('created_at', '<', now()->subHours($ttlHours))
            ->get();

        $count = $orphans->count();

        if ($count === 0) {
            $this->info('No se encontraron fotos huérfanas.');

            return;
        }

        $this->warn("Se encontraron {$count} fotos huérfanas. Despachando Jobs e invalidando registros...");

        foreach ($orphans as $photo) {
            DeleteOrphanPhoto::dispatch($photo->storage_path);
            $photo->delete();
        }

        $this->info("¡Limpieza finalizada! Se encolaron {$count} jobs de eliminación.");
    }
}
