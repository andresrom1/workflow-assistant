<?php

use App\Models\InspectionPhoto;

/**
 * Las claves de foto son vocabulario del dominio —las fotos que el checkout sabe pedir— y
 * `config/visred.php` las traduce al catálogo del proveedor. Si el mapa del adapter nombrara
 * una clave que el checkout no pide, la validación de `photo_key` la rechazaría y esa foto
 * nunca se subiría: el desajuste recién se vería en una emisión, contra la compañía.
 *
 * Mismo criterio que `WorkerConfigTest` con los presupuestos de cola: el invariante se
 * verifica acá y no en producción.
 */
it('el mapa de inspección de Visred no nombra claves que el checkout no pide', function () {
    $delProveedor = array_keys(config('visred.inspection_photo_map'));

    expect(array_values(array_diff($delProveedor, InspectionPhoto::CLAVES)))->toBe([]);
});
