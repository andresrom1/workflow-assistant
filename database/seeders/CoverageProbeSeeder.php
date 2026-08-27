<?php

namespace Database\Seeders;

use App\Models\Quote;
use App\Models\QuoteAlternative;
use Illuminate\Database\Seeder;

/**
 * Las alternativas reales de produccion que usa `ai:probe-coverage-qa`.
 *
 * El banco de preguntas resuelve cada caso por `aseguradora` + `titulo`, asi que sin estas
 * filas mide cero y reporta todo como omitido. Se copiaron tal cual de la cotizacion #21 de
 * produccion (mas `Auto Max 15` y `C2 FUll`, que vienen de otras): los textos de
 * `full_details` importan palabra por palabra, porque la verificacion de la Fase 3 chequea
 * que la cita del experto aparezca literalmente en ellos.
 *
 * Los seis planes no son arbitrarios, cada uno cubre un caso del banco:
 *
 * | Plan                                   | Para que esta |
 * |----------------------------------------|---------------|
 * | San Cristobal `Auto Plus +`            | tiene Granizo (15 coberturas) |
 * | San Cristobal `C - Auto Plus`          | NO tiene Granizo (12), y es el mismo grado "C" |
 * | San Cristobal `A - Responsabilidad Civil` | el piso: 2 coberturas |
 * | San Cristobal `Todo Riesgo Franquicia 7,5%` | franquicia derivable del titulo |
 * | Sancor `Auto Max 15`                   | features vacio: no se puede negar por ausencia |
 * | Triunfo `C2 FUll`                      | plan que no figura en el cuadro del manual |
 * | Rio Uruguay `Sigma`                    | SI figura en el manual: mide el costo del chequeo de presencia |
 *
 * No corre en `DatabaseSeeder`: se invoca a mano con
 * `php artisan db:seed --class=CoverageProbeSeeder`.
 */
class CoverageProbeSeeder extends Seeder
{
    public function run(): void
    {
        $quote = Quote::factory()->create(['status' => 'processed']);

        foreach (self::ALTERNATIVAS as $fila) {
            QuoteAlternative::updateOrCreate(
                ['aseguradora' => $fila['aseguradora'], 'titulo' => $fila['titulo']],
                $fila + ['quote_id' => $quote->id, 'moneda' => 'ARS'],
            );
        }

        $this->command?->info(count(self::ALTERNATIVAS).' alternativas listas para ai:probe-coverage-qa');
    }

    /** @var list<array<string, mixed>> */
    private const ALTERNATIVAS = [
        [
            'aseguradora' => 'San Cristobal',
            'titulo' => 'Auto Plus +',
            'descripcion' => 'Robo e Incendio Total y Parcial. Destrucción Total. Adicionales: cristales, granizo y cerraduras',
            'normalized_grade' => 'third_party_complete_plus',
            'precio' => '97853.00',
            'sum_asegurada' => '28140000.00',
            'sum_insured_text' => '',
            'marketing_title' => 'San Cristobal - Auto Plus +',
            'features_tags' => [
                'Auxilio mecánico y/o Grúa',
                'Cerraduras',
                'Cristales Laterales',
                'Destrucción Total por accidente',
                'Extensión Mercosur',
                'Granizo',
                'Incendio Parcial',
                'Incendio Total',
                'Luneta',
                'Parabrisas',
                'Responsabilidad Civil',
                'Robo Parcial',
                'Robo Total',
                'Ruedas',
                'Sistema Cleas',
            ],
            'full_details' => [
                'Auxilio mecánico y/o Grúa' => 'Auxilio mecánico y servicio de grúa por avería o accidente.',
                'Cerraduras' => 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
                'Cristales Laterales' => 'Daños y/o rotura de cristales laterales.',
                'Destrucción Total por accidente' => 'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
                'Extensión Mercosur' => 'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial y la permanencia del vehículo asegurado en países limítrofes.',
                'Granizo' => 'Daños parciales consecuencia del granizo.',
                'Incendio Parcial' => 'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo al momento del siniestro.',
                'Incendio Total' => 'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
                'Luneta' => 'Daño y/o Rotura accidental de la luneta',
                'Parabrisas' => 'Daño y/o Rotura accidental del Parabrisas.',
                'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
                'Robo Parcial' => 'Robo de los elementos fijos que hacen al funcionamiento de la unidad a excepción de: - Equipo de audio - Tasa de rueda - Escobilla de parabrisas y Luneta - Espejos retrovisores - Insignias',
                'Robo Total' => 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
                'Ruedas' => 'Cubre por robo las ruedas del vehículo.',
                'Sistema Cleas' => 'La compañía perteneces al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención. http://www.cleas.com.ar/site/index.html',
            ],
        ],
        [
            'aseguradora' => 'San Cristobal',
            'titulo' => 'C - Auto Plus',
            'descripcion' => 'Responsabilidad Civil. Robo e Incendio Total y Parcial. Destrucción Total.',
            'normalized_grade' => 'third_party_complete',
            'precio' => '92940.00',
            'sum_asegurada' => '28140000.00',
            'sum_insured_text' => '',
            'marketing_title' => 'San Cristobal - C - Auto Plus',
            'features_tags' => [
                'Auxilio mecánico y/o Grúa',
                'Cerraduras',
                'Cristales Laterales',
                'Destrucción Total por accidente',
                'Extensión Mercosur',
                'Incendio Parcial',
                'Incendio Total',
                'Responsabilidad Civil',
                'Robo Parcial',
                'Robo Total',
                'Ruedas',
                'Sistema Cleas',
            ],
            'full_details' => [
                'Auxilio mecánico y/o Grúa' => 'Auxilio mecánico y servicio de grúa por avería o accidente.',
                'Cerraduras' => 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
                'Cristales Laterales' => 'Daños y/o rotura de cristales laterales.',
                'Destrucción Total por accidente' => 'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
                'Extensión Mercosur' => 'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial y la permanencia del vehículo asegurado en países limítrofes.',
                'Incendio Parcial' => 'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo al momento del siniestro.',
                'Incendio Total' => 'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
                'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
                'Robo Parcial' => 'Robo de los elementos fijos que hacen al funcionamiento de la unidad a excepción de: - Equipo de audio - Tasa de rueda - Escobilla de parabrisas y Luneta - Espejos retrovisores - Insignias',
                'Robo Total' => 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
                'Ruedas' => 'Cubre por robo las ruedas del vehículo.',
                'Sistema Cleas' => 'La compañía perteneces al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención. http://www.cleas.com.ar/site/index.html',
            ],
        ],
        [
            'aseguradora' => 'San Cristobal',
            'titulo' => 'A - Responsabilidad Civil',
            'descripcion' => 'Responsabilidad Civil',
            'normalized_grade' => 'liability',
            'precio' => '56141.00',
            'sum_asegurada' => '28140000.00',
            'sum_insured_text' => '',
            'marketing_title' => 'San Cristobal - A - Responsabilidad Civil',
            'features_tags' => [
                'Responsabilidad Civil',
                'Sistema Cleas',
            ],
            'full_details' => [
                'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
                'Sistema Cleas' => 'La compañía perteneces al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención. http://www.cleas.com.ar/site/index.html',
            ],
        ],
        [
            'aseguradora' => 'San Cristobal',
            'titulo' => 'Todo Riesgo Franquicia 7,5% suma asegurada',
            'descripcion' => 'Todo Riesgo con franquicia del 7,5% de la suma asegurada',
            'normalized_grade' => 'all_risk',
            'precio' => '136227.00',
            'sum_asegurada' => '28140000.00',
            'sum_insured_text' => '',
            'marketing_title' => 'San Cristobal - Todo Riesgo Franquicia 7,5% suma asegurada',
            'features_tags' => [
                'Auxilio mecánico y/o Grúa',
                'Cerraduras',
                'Cristal de Techo',
                'Cristales Laterales',
                'Daños Parciales',
                'Daños Parciales al Amparo del Robo Total',
                'Destrucción Total por accidente',
                'Extensión Mercosur',
                'Granizo',
                'Incendio Parcial',
                'Incendio Total',
                'Inundación',
                'Luneta',
                'Parabrisas',
                'Responsabilidad Civil',
                'Robo Parcial',
                'Robo Total',
                'Ruedas',
                'Sistema Cleas',
            ],
            'full_details' => [
                'Auxilio mecánico y/o Grúa' => 'Auxilio mecánico y servicio de grúa por avería o accidente.',
                'Cerraduras' => 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
                'Cristal de Techo' => 'Cubre el daño y/o rotura accidental del cristal de techo.',
                'Cristales Laterales' => 'Daños y/o rotura de cristales laterales.',
                'Daños Parciales' => 'Daños parciales por accidentes, sujeta a la franquicia contratada.',
                'Daños Parciales al Amparo del Robo Total' => 'Daños parciales como consecuencia de un Robo Total con posterior aparición del vehículo.',
                'Destrucción Total por accidente' => 'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
                'Extensión Mercosur' => 'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial y la permanencia del vehículo asegurado en países limítrofes.',
                'Granizo' => 'Daños parciales consecuencia del granizo.',
                'Incendio Parcial' => 'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo al momento del siniestro.',
                'Incendio Total' => 'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
                'Inundación' => 'Cubre daños al vehículo a causa de inundación.',
                'Luneta' => 'Daño y/o Rotura accidental de la luneta',
                'Parabrisas' => 'Daño y/o Rotura accidental del Parabrisas.',
                'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
                'Robo Parcial' => 'Robo de los elementos fijos que hacen al funcionamiento de la unidad a excepción de: - Equipo de audio - Tasa de rueda - Escobilla de parabrisas y Luneta - Espejos retrovisores - Insignias',
                'Robo Total' => 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
                'Ruedas' => 'Cubre por robo las ruedas del vehículo.',
                'Sistema Cleas' => 'La compañía perteneces al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención. http://www.cleas.com.ar/site/index.html',
            ],
        ],
        [
            'aseguradora' => 'Sancor',
            'titulo' => 'Auto Max 15',
            'descripcion' => 'Auto Max 15 (RC/IT/RT c/Asistencia)',
            'normalized_grade' => 'basic',
            'precio' => '67737.01',
            'sum_asegurada' => '28016000.00',
            'sum_insured_text' => '$ 28.016.000',
            'marketing_title' => 'Sancor - Auto Max 15',
            'features_tags' => [],
            'full_details' => [],
        ],
        [
            'aseguradora' => 'Triunfo',
            'titulo' => 'C2 FUll',
            'descripcion' => 'Robo e Incendio Total y Parcial. Destrucción Total. Adicionales',
            'normalized_grade' => 'third_party_complete_plus',
            'precio' => '88846.40',
            'sum_asegurada' => '26800000.00',
            'sum_insured_text' => '$ 26.800.000',
            'marketing_title' => 'Triunfo - C2 FUll',
            'features_tags' => [
                'Auxilio mecánico y/o Grúa',
                'Cerraduras',
                'Cristal de Techo',
                'Cristales Laterales',
                'Daños Parciales al Amparo del Robo Total',
                'Destrucción Total por accidente',
                'Extensión Mercosur',
                'Granizo',
                'Incendio Parcial',
                'Incendio Total',
                'Inundación',
                'Luneta',
                'Parabrisas',
                'Responsabilidad Civil',
                'Robo Parcial',
                'Robo Total',
                'Ruedas',
            ],
            'full_details' => [
                'Auxilio mecánico y/o Grúa' => 'Auxilio mecánico y servicio de grúa por avería o accidente.',
                'Cerraduras' => 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
                'Cristal de Techo' => 'Cubre el daño y/o rotura accidental del cristal de techo.',
                'Cristales Laterales' => 'Daños y/o rotura de cristales laterales.',
                'Daños Parciales al Amparo del Robo Total' => 'Daños parciales como consecuencia de un Robo Total con posterior aparición del vehículo.',
                'Destrucción Total por accidente' => 'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
                'Extensión Mercosur' => 'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial y la permanencia del vehículo asegurado en países limítrofes.',
                'Granizo' => 'Daños parciales consecuencia del granizo.',
                'Incendio Parcial' => 'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo al momento del siniestro.',
                'Incendio Total' => 'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
                'Inundación' => 'Cubre daños al vehículo a causa de inundación.',
                'Luneta' => 'Daño y/o Rotura accidental de la luneta',
                'Parabrisas' => 'Daño y/o Rotura accidental del Parabrisas.',
                'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
                'Robo Parcial' => 'Robo de los elementos fijos que hacen al funcionamiento de la unidad a excepción de: - Equipo de audio - Tasa de rueda - Escobilla de parabrisas y Luneta - Espejos retrovisores - Insignias',
                'Robo Total' => 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
                'Ruedas' => 'Cubre por robo las ruedas del vehículo.',
            ],
        ],
        [
            'aseguradora' => 'Rio Uruguay',
            'titulo' => 'Sigma',
            'descripcion' => 'Robo e Incendio Total y Parcial. Destrucción Total. Adicionales: cristales, granizo y cerraduras',
            'normalized_grade' => 'third_party_complete_plus',
            'precio' => '124160.33',
            'sum_asegurada' => '27604000.00',
            'sum_insured_text' => '$ 27.604.000',
            'marketing_title' => 'Rio Uruguay - Sigma',
            'features_tags' => [
                'Auxilio mecánico y/o Grúa',
                'Cerraduras',
                'Cristal de Techo',
                'Cristales Laterales',
                'Daños Parciales al Amparo del Robo Total',
                'Destrucción Total por accidente',
                'Extensión Mercosur',
                'Granizo',
                'Incendio Parcial',
                'Incendio Total',
                'Luneta',
                'Parabrisas',
                'Responsabilidad Civil',
                'Robo Parcial',
                'Robo Total',
                'Ruedas',
                'Sistema Cleas',
            ],
            'full_details' => [
                'Auxilio mecánico y/o Grúa' => 'Auxilio mecánico y servicio de grúa por avería o accidente.',
                'Cerraduras' => 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
                'Cristal de Techo' => 'Cubre el daño y/o rotura accidental del cristal de techo.',
                'Cristales Laterales' => 'Daños y/o rotura de cristales laterales.',
                'Daños Parciales al Amparo del Robo Total' => 'Daños parciales como consecuencia de un Robo Total con posterior aparición del vehículo.',
                'Destrucción Total por accidente' => 'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
                'Extensión Mercosur' => 'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial y la permanencia del vehículo asegurado en países limítrofes.',
                'Granizo' => 'Daños parciales consecuencia del granizo.',
                'Incendio Parcial' => 'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo al momento del siniestro.',
                'Incendio Total' => 'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
                'Luneta' => 'Daño y/o Rotura accidental de la luneta',
                'Parabrisas' => 'Daño y/o Rotura accidental del Parabrisas.',
                'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
                'Robo Parcial' => 'Robo de los elementos fijos que hacen al funcionamiento de la unidad a excepción de: - Equipo de audio - Tasa de rueda - Escobilla de parabrisas y Luneta - Espejos retrovisores - Insignias',
                'Robo Total' => 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
                'Ruedas' => 'Cubre por robo las ruedas del vehículo.',
                'Sistema Cleas' => 'La compañía perteneces al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención. http://www.cleas.com.ar/site/index.html',
            ],
        ],
    ];
}
