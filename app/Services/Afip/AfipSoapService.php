<?php

namespace App\Services\Afip;

use App\Models\Invoice;
use App\Services\Facturacion\Emisor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Cliente AFIP (WSAA + WSFEv1) 100% local: firma el ticket de acceso con openssl y le habla a
 * los servicios por SOAP crudo sobre HTTP (sin ext-soap). Los certificados no salen del server;
 * las únicas llamadas externas son a AFIP.
 *
 * Toda la complejidad SOAP queda encapsulada acá: el resto del sistema solo ve
 * {@see self::ultimoAutorizado()}, {@see self::autorizar()} y {@see self::consultar()}, que
 * devuelven arrays tipados.
 */
class AfipSoapService
{
    private const WSFE_NS = 'http://ar.gov.afip.dif.FEV1/';

    public function __construct(
        private readonly Emisor $emisor,
    ) {}

    /**
     * Último número de comprobante autorizado para (punto de venta + tipo). El siguiente a
     * emitir es este + 1.
     */
    public function ultimoAutorizado(int $ptoVta, int $tipoCbte): int
    {
        [$token, $sign] = $this->ticketAcceso();
        $cuit = $this->emisor->cuit();

        $body = <<<XML
            <ar:FECompUltimoAutorizado>
              <ar:Auth>
                <ar:Token>{$token}</ar:Token>
                <ar:Sign>{$sign}</ar:Sign>
                <ar:Cuit>{$cuit}</ar:Cuit>
              </ar:Auth>
              <ar:PtoVta>{$ptoVta}</ar:PtoVta>
              <ar:CbteTipo>{$tipoCbte}</ar:CbteTipo>
            </ar:FECompUltimoAutorizado>
            XML;

        $xml = $this->callWsfe('FECompUltimoAutorizado', $body);
        $this->throwOnErrors($xml);

        return (int) $this->first($xml, 'CbteNro');
    }

    /**
     * Solicita el CAE de un comprobante ya numerado. Devuelve los datos que asigna AFIP.
     * Lanza {@see AfipEmisionException} si AFIP rechaza (Resultado != A o hay Errors/Obs).
     *
     * @return array{numero: int, cae: string, cae_vencimiento: string}
     */
    public function autorizar(Invoice $invoice, int $numero): array
    {
        [$token, $sign] = $this->ticketAcceso();
        $cuit = $this->emisor->cuit();
        $p = $this->buildFacturaPayload($invoice, $numero);

        $det = '';
        foreach ($p['FECAEDetRequest'] as $tag => $value) {
            $det .= "<ar:{$tag}>{$value}</ar:{$tag}>";
        }

        $body = <<<XML
            <ar:FECAESolicitar>
              <ar:Auth>
                <ar:Token>{$token}</ar:Token>
                <ar:Sign>{$sign}</ar:Sign>
                <ar:Cuit>{$cuit}</ar:Cuit>
              </ar:Auth>
              <ar:FeCAEReq>
                <ar:FeCabReq>
                  <ar:CantReg>1</ar:CantReg>
                  <ar:PtoVta>{$p['PtoVta']}</ar:PtoVta>
                  <ar:CbteTipo>{$p['CbteTipo']}</ar:CbteTipo>
                </ar:FeCabReq>
                <ar:FeDetReq>
                  <ar:FECAEDetRequest>{$det}</ar:FECAEDetRequest>
                </ar:FeDetReq>
              </ar:FeCAEReq>
            </ar:FECAESolicitar>
            XML;

        $xml = $this->callWsfe('FECAESolicitar', $body);
        $this->throwOnErrors($xml);

        $resultado = $this->first($xml, 'Resultado');
        if ($resultado !== 'A') {
            throw new AfipEmisionException($this->observaciones($xml) ?: 'AFIP rechazó el comprobante.');
        }

        return [
            'numero' => $numero,
            'cae' => (string) $this->first($xml, 'CAE'),
            'cae_vencimiento' => (string) $this->first($xml, 'CAEFchVto'), // Ymd
        ];
    }

    /**
     * Consulta un comprobante ya numerado. Solo lectura: no emite ni consume numeración.
     *
     * Sirve para resolver una emisión en duda — el proceso llamó a {@see self::autorizar()} y murió
     * antes de persistir el CAE, así que no sabemos si AFIP lo autorizó. Devuelve `null` cuando el
     * comprobante NO existe en AFIP (número todavía libre), y los datos si existe.
     *
     * Ojo con el manejo de errores: AFIP contesta "no existe" con el código de error 602, que para
     * nosotros es una respuesta legítima y no una falla. Por eso NO se usa
     * {@see self::throwOnErrors()}, que lanza ante cualquier `Errors/Msg`.
     *
     * @return array{numero: int, cae: string, cae_vencimiento: string, doc_nro: string, imp_total: string, resultado: string}|null
     */
    public function consultar(int $ptoVta, int $tipoCbte, int $numero): ?array
    {
        [$token, $sign] = $this->ticketAcceso();
        $cuit = $this->emisor->cuit();

        $body = <<<XML
            <ar:FECompConsultar>
              <ar:Auth>
                <ar:Token>{$token}</ar:Token>
                <ar:Sign>{$sign}</ar:Sign>
                <ar:Cuit>{$cuit}</ar:Cuit>
              </ar:Auth>
              <ar:FeCompConsReq>
                <ar:CbteTipo>{$tipoCbte}</ar:CbteTipo>
                <ar:CbteNro>{$numero}</ar:CbteNro>
                <ar:PtoVta>{$ptoVta}</ar:PtoVta>
              </ar:FeCompConsReq>
            </ar:FECompConsultar>
            XML;

        $xml = $this->callWsfe('FECompConsultar', $body);

        if ($this->tieneErrorNoExiste($xml)) {
            return null;
        }

        $this->throwOnErrors($xml);

        return [
            'numero' => (int) $this->first($xml, 'CbteDesde'),
            'cae' => (string) $this->first($xml, 'CodAutorizacion'),
            'cae_vencimiento' => (string) $this->first($xml, 'FchVto'), // Ymd
            'doc_nro' => (string) $this->first($xml, 'DocNro'),
            'imp_total' => (string) $this->first($xml, 'ImpTotal'),
            'resultado' => (string) $this->first($xml, 'Resultado'),
        ];
    }

    /**
     * Arma los campos del comprobante Factura C servicios. Extraído para testear sin red.
     *
     * Factura C: sin IVA discriminado → ImpNeto = ImpTotal, resto en 0, sin array Iva.
     *
     * @return array{PtoVta: int, CbteTipo: int, FECAEDetRequest: array<string, string|int>}
     */
    public function buildFacturaPayload(Invoice $invoice, int $numero): array
    {
        $importe = number_format((float) $invoice->importe, 2, '.', '');
        $condIva = (int) (config('afip.condicion_iva_receptor_map')[$invoice->receptor_condicion_iva] ?? 1);

        return [
            'PtoVta' => $invoice->pto_vta,
            'CbteTipo' => $invoice->tipo_comprobante,
            'FECAEDetRequest' => [
                'Concepto' => (int) config('afip.concepto'), // 2 = Servicios
                'DocTipo' => 80, // CUIT
                'DocNro' => $invoice->receptor_cuit,
                'CbteDesde' => $numero,
                'CbteHasta' => $numero,
                'CbteFch' => $invoice->fecha_comprobante->format('Ymd'),
                'ImpTotal' => $importe,
                'ImpTotConc' => '0.00',
                'ImpNeto' => $importe,
                'ImpOpEx' => '0.00',
                'ImpTrib' => '0.00',
                'ImpIVA' => '0.00',
                'FchServDesde' => $invoice->fecha_servicio_desde->format('Ymd'),
                'FchServHasta' => $invoice->fecha_servicio_hasta->format('Ymd'),
                'FchVtoPago' => $invoice->fecha_vto_pago->format('Ymd'),
                'MonId' => 'PES',
                'MonCotiz' => 1,
                'CondicionIVAReceptorId' => $condIva,
            ],
        ];
    }

    /**
     * URL del QR reglamentario (RG 4892) a imprimir en el PDF. Extraída para testear.
     */
    public function qrUrl(Invoice $invoice): string
    {
        $data = [
            'ver' => 1,
            'fecha' => $invoice->fecha_comprobante->format('Y-m-d'),
            'cuit' => (int) $this->emisor->cuit(),
            'ptoVta' => $invoice->pto_vta,
            'tipoCmp' => $invoice->tipo_comprobante,
            'nroCmp' => (int) $invoice->numero_comprobante,
            'importe' => (float) $invoice->importe,
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => 80,
            'nroDocRec' => (int) $invoice->receptor_cuit,
            'tipoCodAut' => 'E',
            'codAut' => (int) $invoice->cae,
        ];

        return 'https://www.afip.gob.ar/fe/qr/?p='.base64_encode((string) json_encode($data));
    }

    // ─── WSAA (autenticación) ────────────────────────────────────────────────────

    /**
     * Token + Sign vigentes, cacheados 12h. Firma el TRA con openssl y lo canjea en WSAA.
     *
     * @return array{0: string, 1: string} [token, sign]
     */
    private function ticketAcceso(): array
    {
        $entorno = config('afip.homologacion') ? 'homo' : 'prod';
        $cacheKey = "afip:ta:{$entorno}:".$this->emisor->cuit();

        /** @var array{0: string, 1: string} */
        return Cache::remember($cacheKey, (int) config('afip.ta_cache_ttl'), function (): array {
            $cms = $this->firmarTra();

            $endpoint = config('afip.homologacion') ? config('afip.urls.homo.wsaa') : config('afip.urls.prod.wsaa');

            $envelope = <<<XML
                <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsaa="http://wsaa.view.sua.dvadac.desein.afip.gov">
                  <soapenv:Header/>
                  <soapenv:Body>
                    <wsaa:loginCms><wsaa:in0>{$cms}</wsaa:in0></wsaa:loginCms>
                  </soapenv:Body>
                </soapenv:Envelope>
                XML;

            $response = Http::withHeaders(['SOAPAction' => ''])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post($endpoint);

            $xml = $this->parse($response->body());
            $this->throwOnFault($xml);

            $ticketXml = (string) $this->first($xml, 'loginCmsReturn');
            $ticket = $this->parse($ticketXml);

            return [
                (string) $this->first($ticket, 'token'),
                (string) $this->first($ticket, 'sign'),
            ];
        });
    }

    /**
     * Firma el Ticket de Requerimiento de Acceso (TRA) con el certificado + clave y devuelve
     * el CMS en base64 (lo que espera WSAA en `in0`).
     */
    private function firmarTra(): string
    {
        $now = Carbon::now();
        $tra = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <loginTicketRequest version="1.0">
              <header>
                <uniqueId>{$now->timestamp}</uniqueId>
                <generationTime>{$now->copy()->subMinutes(10)->format('c')}</generationTime>
                <expirationTime>{$now->copy()->addMinutes(10)->format('c')}</expirationTime>
              </header>
              <service>wsfe</service>
            </loginTicketRequest>
            XML;

        $traFile = tempnam(sys_get_temp_dir(), 'afip_tra_');
        $cmsFile = tempnam(sys_get_temp_dir(), 'afip_cms_');
        file_put_contents($traFile, $tra);

        try {
            // flags = 0: sin PKCS7_DETACHED → CMS opaco con el TRA embebido (lo que espera WSAA).
            $signed = openssl_pkcs7_sign(
                $traFile,
                $cmsFile,
                'file://'.config('afip.cert_path'),
                ['file://'.config('afip.key_path'), (string) config('afip.key_passphrase')],
                [],
                0,
            );

            if (! $signed) {
                throw new RuntimeException('No se pudo firmar el TRA de AFIP (revisá certificado/clave en storage/app/certs).');
            }

            // El output es S/MIME: headers + línea en blanco + base64 del CMS. Nos quedamos con el cuerpo.
            $mime = (string) file_get_contents($cmsFile);
            $parts = preg_split("/\n\s*\n/", $mime, 2);

            return preg_replace('/\s+/', '', $parts[1] ?? '') ?? '';
        } finally {
            @unlink($traFile);
            @unlink($cmsFile);
        }
    }

    // ─── WSFEv1 (facturación) ────────────────────────────────────────────────────

    private function callWsfe(string $action, string $body): SimpleXMLElement
    {
        $endpoint = config('afip.homologacion') ? config('afip.urls.homo.wsfe') : config('afip.urls.prod.wsfe');
        $ns = self::WSFE_NS;

        $envelope = <<<XML
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="{$ns}">
              <soapenv:Header/>
              <soapenv:Body>{$body}</soapenv:Body>
            </soapenv:Envelope>
            XML;

        $response = Http::withHeaders(['SOAPAction' => self::WSFE_NS.$action])
            ->withBody($envelope, 'text/xml; charset=utf-8')
            ->post($endpoint);

        $xml = $this->parse($response->body());
        $this->throwOnFault($xml);

        return $xml;
    }

    // ─── Parsing helpers (namespace-agnostic) ────────────────────────────────────

    private function parse(string $body): SimpleXMLElement
    {
        $xml = @simplexml_load_string($body);

        if (! $xml instanceof SimpleXMLElement) {
            throw new AfipRespuestaIndeterminadaException('Respuesta ilegible de AFIP: '.mb_substr($body, 0, 300));
        }

        return $xml;
    }

    /**
     * Primer valor de un elemento por nombre local (ignora namespaces).
     */
    private function first(SimpleXMLElement $xml, string $name): ?string
    {
        $nodes = $xml->xpath("//*[local-name()='{$name}']");

        return $nodes ? (string) $nodes[0] : null;
    }

    private function throwOnFault(SimpleXMLElement $xml): void
    {
        $fault = $xml->xpath("//*[local-name()='Fault']");
        if ($fault) {
            $msg = $this->first($xml, 'faultstring') ?? 'SOAP Fault de AFIP.';
            throw new AfipRespuestaIndeterminadaException($msg);
        }
    }

    private function throwOnErrors(SimpleXMLElement $xml): void
    {
        $errs = $xml->xpath("//*[local-name()='Errors']//*[local-name()='Msg']");
        if ($errs) {
            $msgs = array_map(fn ($n): string => (string) $n, $errs);
            throw new AfipEmisionException(implode(' | ', $msgs));
        }
    }

    /**
     * ¿La respuesta es el "no existen datos en nuestros registros" de AFIP (código 602)?
     *
     * Se compara por código y no por texto: el mensaje es prosa en castellano que AFIP puede
     * reescribir sin aviso, y confundir "no existe" con una falla real haría que una emisión en
     * duda se reintente a ciegas.
     */
    private function tieneErrorNoExiste(SimpleXMLElement $xml): bool
    {
        $codes = $xml->xpath("//*[local-name()='Errors']//*[local-name()='Code']");

        foreach ($codes ?: [] as $code) {
            if ((int) (string) $code === 602) {
                return true;
            }
        }

        return false;
    }

    private function observaciones(SimpleXMLElement $xml): string
    {
        $obs = $xml->xpath("//*[local-name()='Observaciones']//*[local-name()='Msg']");

        return $obs ? implode(' | ', array_map(fn ($n): string => (string) $n, $obs)) : '';
    }
}
