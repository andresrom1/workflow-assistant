<?php

namespace App\Services\Facturacion;

use App\Services\Afip\AfipSoapService;
use App\Services\SettingsService;

/**
 * Datos del emisor de las Facturas C (el productor), editables desde la pantalla de
 * Configuración de facturación. Fuente de verdad: el grupo de settings `facturacion`
 * ({@see SettingsService}), con fallback a `config('afip.*')` / `.env` para CUIT y punto de venta.
 *
 * Es el único punto que resuelve estos datos: lo consumen {@see AfipSoapService}
 * (CUIT), el controller de facturación (punto de venta) y el PDF (todos los campos).
 */
class Emisor
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function cuit(): string
    {
        return (string) ($this->settings->get('facturacion.cuit') ?: config('afip.cuit'));
    }

    public function puntoVenta(): int
    {
        return (int) ($this->settings->get('facturacion.punto_venta') ?: config('afip.punto_venta'));
    }

    public function razonSocial(): string
    {
        return (string) ($this->settings->get('facturacion.razon_social') ?: 'Razón social sin configurar');
    }

    public function condicionIva(): string
    {
        return (string) ($this->settings->get('facturacion.condicion_iva') ?: 'Responsable Monotributo');
    }

    public function subtitulo(): string
    {
        return (string) ($this->settings->get('facturacion.subtitulo') ?: '');
    }

    public function domicilio(): string
    {
        return (string) ($this->settings->get('facturacion.domicilio') ?: '');
    }

    public function ingresosBrutos(): string
    {
        return (string) ($this->settings->get('facturacion.ingresos_brutos') ?: '');
    }

    public function inicioActividades(): string
    {
        return (string) ($this->settings->get('facturacion.inicio_actividades') ?: '');
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'razon_social' => $this->razonSocial(),
            'cuit' => $this->cuit(),
            'punto_venta' => $this->puntoVenta(),
            'condicion_iva' => $this->condicionIva(),
            'subtitulo' => $this->subtitulo(),
            'domicilio' => $this->domicilio(),
            'ingresos_brutos' => $this->ingresosBrutos(),
            'inicio_actividades' => $this->inicioActividades(),
        ];
    }
}
