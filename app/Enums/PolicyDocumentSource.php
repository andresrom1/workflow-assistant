<?php

namespace App\Enums;

/**
 * Origen de un documento oficial de póliza.
 *
 * `VisredEmission` se captura automáticamente dentro del `emit()` (snapshot, mientras
 * vive el `presale_id`). `AdminUpload` es la carga manual post-emisión que hace el
 * admin desde el panel. Los documentos NO se reemplazan: conviven y se acumulan al
 * contrato.
 */
enum PolicyDocumentSource: string
{
    case VisredEmission = 'visred_emission';
    case AdminUpload = 'admin_upload';

    public function label(): string
    {
        return match ($this) {
            self::VisredEmission => 'Emisión',
            self::AdminUpload => 'Carga manual',
        };
    }
}
