<?php

namespace App\Console\Commands;

use App\Services\Afip\AfipSoapService;
use App\Services\Facturacion\Emisor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Smoke test de conectividad + firma contra AFIP: consulta el último comprobante autorizado
 * (`FECompUltimoAutorizado`) sin emitir nada. Sirve para validar el certificado, el CUIT y la
 * autorización del servicio wsfe antes de facturar de verdad.
 */
class AfipPing extends Command
{
    protected $signature = 'afip:ping {--pv= : Punto de venta (default: el configurado en el emisor)}';

    protected $description = 'Verifica la conexión y la firma contra AFIP (no emite nada).';

    public function handle(AfipSoapService $afip, Emisor $emisor): int
    {
        $pv = (int) ($this->option('pv') ?: $emisor->puntoVenta());
        $tipo = (int) config('afip.tipo_comprobante');
        $entorno = config('afip.homologacion') ? 'HOMOLOGACIÓN (wswhomo)' : 'PRODUCCIÓN (servicios1)';

        $this->line("Entorno:  <info>{$entorno}</info>");
        $this->line("CUIT:     <info>{$emisor->cuit()}</info>");
        $this->line("Pto vta:  <info>{$pv}</info> · Tipo: {$tipo} (Factura C)");
        $this->newLine();

        try {
            $ultimo = $afip->ultimoAutorizado($pv, $tipo);
            $this->info("✓ Conexión y firma OK. Último comprobante autorizado: {$ultimo} (el próximo sería {$ultimo}+1).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('✗ Falló: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
