<?php

namespace App\Services;

use App\Enums\PolicyDocumentKind;
use App\Enums\PolizaEstado;
use App\Jobs\PublishDocumentAvailable;
use App\Models\MobileAccount;
use App\Models\Poliza;

/**
 * Decide y encola el aviso al asegurado cuando se carga documentación nueva.
 *
 * Agnóstico de canal: opera sobre el modelo de dominio (`Poliza` → titular) y la
 * identidad de la app (email del `MobileAccount`, mismo seam que
 * {@see MobileAccount::resolveCustomer()}). Solo avisa de documentos de la póliza
 * **vigente** (regla "todo lo de la vigente") y solo si el titular tiene cuenta en la
 * app. Encola un job por cuenta — el envío real (FCM) vive en
 * {@see DocumentAvailablePublisher}, desacoplado del request de carga.
 */
class PolicyDocumentNotifier
{
    public function notifyNewDocument(Poliza $poliza, PolicyDocumentKind $kind): void
    {
        if ($poliza->estado !== PolizaEstado::Vigente) {
            return;
        }

        $poliza->loadMissing('risk.customer');
        $email = $poliza->risk?->customer?->email;

        if ($email === null || $email === '') {
            return;
        }

        $accounts = MobileAccount::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])
            ->get();

        foreach ($accounts as $account) {
            PublishDocumentAvailable::dispatch($account->id, $poliza->id, $kind->value);
        }
    }
}
