<?php

namespace App\Enums;

/**
 * Origen de un documento oficial de póliza.
 *
 * `VisredEmission` se captura automáticamente dentro del `emit()` (snapshot, mientras
 * vive el `presale_id`). `AdminUpload` es la carga manual post-emisión que hace el
 * admin desde el panel. `LocalIngesta` es lo que materializa el ingestor local (script
 * Python sin LLM) tras la confirmación humana en Pendientes. Los documentos NO se
 * reemplazan: conviven y se acumulan al contrato.
 */
enum PolicyDocumentSource: string
{
    case VisredEmission = 'visred_emission';
    case AdminUpload = 'admin_upload';
    case LocalIngesta = 'local_ingesta';

    public function label(): string
    {
        return match ($this) {
            self::VisredEmission => 'Emisión',
            self::AdminUpload => 'Carga manual',
            self::LocalIngesta => 'Ingesta local',
        };
    }
}
