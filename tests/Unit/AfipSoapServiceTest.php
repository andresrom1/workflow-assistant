<?php

use App\Models\Invoice;
use App\Services\Afip\AfipSoapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Bootea la app; `qrUrl` resuelve el CUIT vía Emisor (settings con fallback a config).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Verifica el armado del comprobante y del QR SIN tocar la red (métodos puros).
 */
function makeInvoice(array $attrs = []): Invoice
{
    return Invoice::factory()->make(array_merge([
        'batch_id' => 1,
        'billing_company_id' => 1,
        'pto_vta' => 2,
        'tipo_comprobante' => 11,
        'importe' => 1500.50,
        'receptor_cuit' => '30123456789',
        'receptor_condicion_iva' => 'RI',
        'fecha_comprobante' => '2026-06-30',
        'fecha_servicio_desde' => '2026-06-01',
        'fecha_servicio_hasta' => '2026-06-30',
        'fecha_vto_pago' => '2026-07-10',
    ], $attrs));
}

it('arma el payload de Factura C servicios', function (): void {
    $payload = app(AfipSoapService::class)->buildFacturaPayload(makeInvoice(), 45);
    $det = $payload['FECAEDetRequest'];

    expect($payload['PtoVta'])->toBe(2)
        ->and($payload['CbteTipo'])->toBe(11)
        ->and($det['Concepto'])->toBe(2) // Servicios
        ->and($det['DocTipo'])->toBe(80) // CUIT
        ->and($det['DocNro'])->toBe('30123456789')
        ->and($det['CbteDesde'])->toBe(45)
        ->and($det['CbteHasta'])->toBe(45)
        ->and($det['ImpTotal'])->toBe('1500.50')
        ->and($det['ImpNeto'])->toBe($det['ImpTotal']) // Factura C: neto = total
        ->and($det['ImpIVA'])->toBe('0.00')
        ->and($det['CondicionIVAReceptorId'])->toBe(1) // RI
        ->and($det['FchServDesde'])->toBe('20260601')
        ->and($det['FchServHasta'])->toBe('20260630')
        ->and($det['FchVtoPago'])->toBe('20260710')
        ->and($det['MonId'])->toBe('PES');
});

it('arma la URL del QR reglamentario de AFIP', function (): void {
    config(['afip.cuit' => '20111111112']);

    $invoice = makeInvoice(['numero_comprobante' => 45, 'cae' => '71234567890123']);
    $url = app(AfipSoapService::class)->qrUrl($invoice);

    expect($url)->toStartWith('https://www.afip.gob.ar/fe/qr/?p=');

    $json = json_decode(base64_decode(str_replace('https://www.afip.gob.ar/fe/qr/?p=', '', $url)), true);

    expect($json['ver'])->toBe(1)
        ->and($json['ptoVta'])->toBe(2)
        ->and($json['tipoCmp'])->toBe(11)
        ->and($json['nroCmp'])->toBe(45)
        ->and($json['moneda'])->toBe('PES')
        ->and($json['tipoDocRec'])->toBe(80)
        ->and($json['tipoCodAut'])->toBe('E')
        ->and($json['codAut'])->toBe(71234567890123);
});
