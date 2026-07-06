<?php

use App\Support\DocumentoIdentidad;

it('normaliza a solo dígitos', function (): void {
    expect(DocumentoIdentidad::normalizar('30-71784318-1'))->toBe('30717843181')
        ->and(DocumentoIdentidad::normalizar('  20.717.843.18 '))->toBe('2071784318')
        ->and(DocumentoIdentidad::normalizar(''))->toBeNull()
        ->and(DocumentoIdentidad::normalizar(null))->toBeNull();
});

it('clave de un DNI pelado es el DNI', function (): void {
    expect(DocumentoIdentidad::clave('71784318', 'dni', 'fisica'))->toBe('71784318');
});

it('clave de un DNI con puntos/guiones se normaliza', function (): void {
    expect(DocumentoIdentidad::clave('7.178.431', 'dni', 'fisica'))->toBe('7178431');
});

it('clave de un CUIL de persona física extrae el DNI', function (): void {
    // 20-71784318-3
    expect(DocumentoIdentidad::clave('20717843183', 'cuil', 'fisica'))->toBe('71784318');
});

it('clave de un CUIT de física responsable inscripto extrae el DNI', function (): void {
    // 27-71784318-4 (misma persona, otra forma) → mismo DNI
    expect(DocumentoIdentidad::clave('27717843184', 'cuit', 'fisica'))->toBe('71784318');
});

it('DNI y CUIL de la misma persona producen la misma clave', function (): void {
    expect(DocumentoIdentidad::clave('71784318', 'dni', 'fisica'))
        ->toBe(DocumentoIdentidad::clave('20-71784318-3', 'cuil', 'fisica'));
});

it('clave de un CUIT de persona jurídica es el CUIT completo', function (): void {
    // 30-71784318-1 (LJM Ingeniería SAS): el medio NO es un DNI
    expect(DocumentoIdentidad::clave('30-71784318-1', 'cuit', 'juridica'))->toBe('30717843181');
});

it('saca el cero de relleno del DNI dentro del CUIL', function (): void {
    // DNI 7123456 (7 dígitos) → CUIL 20-07123456-9
    expect(DocumentoIdentidad::clave('20071234569', 'cuil', 'fisica'))->toBe('7123456');
});

it('infiere jurídica/física por prefijo cuando no se declara el tipo', function (): void {
    expect(DocumentoIdentidad::clave('30717843181', null, null))->toBe('30717843181') // jurídica
        ->and(DocumentoIdentidad::clave('20717843183', null, null))->toBe('71784318');  // física
});

it('inferirTipoPersona por prefijo de 11 dígitos', function (): void {
    expect(DocumentoIdentidad::inferirTipoPersona('30717843181'))->toBe('juridica')
        ->and(DocumentoIdentidad::inferirTipoPersona('27717843184'))->toBe('fisica')
        ->and(DocumentoIdentidad::inferirTipoPersona('71784318'))->toBeNull(); // DNI: no infiere
});

it('clave null cuando el número está vacío', function (): void {
    expect(DocumentoIdentidad::clave('', 'dni', 'fisica'))->toBeNull()
        ->and(DocumentoIdentidad::clave(null, null, null))->toBeNull();
});
