<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vocabulario de coberturas del proveedor
    |--------------------------------------------------------------------------
    |
    | El diff entre alternativas de la vista pública es una diferencia de conjuntos sobre
    | `features_tags`, sin diccionario de sinónimos. Eso se sostiene porque el proveedor usa un
    | vocabulario cerrado: cada cobertura llega con el mismo texto exacto en todas las compañías.
    |
    | Pero el vocabulario se mueve — `Caída de árboles` apareció con la cotización 6 —, y cuando
    | aparece un tag nuevo el diff empieza a reportar diferencias falsas en silencio. Esta lista
    | es el canario, no un mapeo: nadie traduce nada contra ella, solo se avisa cuando entra algo
    | que no está.
    |
    | Snapshot del vocabulario observado. Cuando el aviso salte y el tag nuevo sea legítimo, se
    | agrega acá y listo. Ver QuoteService::auditarVocabulario().
    |
    | opcion-de-configuracion
    |
    */

    'tags_conocidos' => [
        'Auxilio mecánico y/o Grúa',
        'Cerraduras',
        'Cristal de Techo',
        'Cristales',
        'Cristales Laterales',
        'Daños Parciales',
        'Daños Parciales al Amparo del Robo Total',
        'Daños Parciales por Robo',
        // La variante con A mayúscula ('por Accidente') existe en la base y es la minoritaria:
        // se deja afuera a propósito para que el chequeo de variantes la marque.
        'Destrucción Total por accidente',
        'Extensión Mercosur',
        'Franquicia 5%',
        'Franquicia Fija',
        'Franquicia Variable',
        'Granizo',
        'Incendio Parcial',
        'Incendio Total',
        'Incendio Total y Parcial',
        'Inundación',
        'Inundación o Desbordamiento',
        'Luneta',
        'Parabrisas',
        'Reposición 0KM',
        'Responsabilidad Civil',
        'Robo',
        'Robo Parcial',
        'Robo Total',
        'Robo Total y Parcial',
        'Robo o Hurto Total',
        'Robo o Hurto Total y Parcial',
        'Ruedas',
        'Sistema Cleas',
        'Todo Riesgo',
        'Todo Riesgo Sin Franquicia',
    ],

];
