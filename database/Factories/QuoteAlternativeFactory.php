<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteAlternative;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteAlternativeFactory extends Factory
{
    protected $model = QuoteAlternative::class;

    /**
     * Coberturas que traen todas las compañías en Todo Riesgo. Los textos son los del glosario
     * canónico del proveedor: cada tag tiene una única descripción, igual entre compañías.
     */
    public const COBERTURAS_BASE = [
        'Responsabilidad Civil' => 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
        'Robo Total' => 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
        'Robo Parcial' => 'Robo de los elementos fijos que hacen al funcionamiento de la unidad.',
        'Incendio Total' => 'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
        'Incendio Parcial' => 'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo.',
        'Destrucción Total por accidente' => 'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
        'Daños Parciales' => 'Daños parciales por accidentes, sujeta a la franquicia contratada.',
        'Granizo' => 'Daños parciales consecuencia del granizo.',
        'Ruedas' => 'Cubre por robo las ruedas del vehículo.',
        'Cerraduras' => 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
        'Cristales Laterales' => 'Daños y/o rotura de cristales laterales.',
        'Luneta' => 'Daño y/o Rotura accidental de la luneta',
        'Parabrisas' => 'Daño y/o Rotura accidental del Parabrisas.',
        'Auxilio mecánico y/o Grúa' => 'Auxilio mecánico y servicio de grúa por avería o accidente.',
        'Extensión Mercosur' => 'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial.',
    ];

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'aseguradora' => 'Sancor',
            'titulo' => 'Todo Riesgo Franquicia 4%',
            'descripcion' => 'Todo Riesgo',
            'marketing_title' => 'Sancor - Todo Riesgo',
            'normalized_grade' => 'all_risk',
            'precio' => 90317.04,
            'moneda' => 'ARS',
            'sum_asegurada' => 16512000,
            'sum_insured_text' => '$ 16.512.000',
            'features_tags' => array_keys(self::COBERTURAS_BASE),
            'full_details' => self::COBERTURAS_BASE,
        ];
    }

    /**
     * Alternativa con las coberturas base más las extra que se le pasen.
     *
     * @param  list<string>  $extra  tags que deben existir en COBERTURAS_EXTRA
     */
    public function conCoberturas(array $extra): static
    {
        $detalles = array_merge(
            self::COBERTURAS_BASE,
            array_intersect_key(self::COBERTURAS_EXTRA, array_flip($extra)),
        );

        return $this->state(fn (): array => [
            'features_tags' => array_keys($detalles),
            'full_details' => $detalles,
        ]);
    }

    /**
     * El caso que obliga a filtrar antes de calcular el "desde $X": Sancor devuelve productos
     * sin coberturas y a un precio que no corresponde a ninguna póliza comparable.
     */
    public function sinCoberturas(): static
    {
        return $this->state(fn (): array => [
            'titulo' => 'Garage',
            'precio' => 3321.98,
            'features_tags' => [],
            'full_details' => [],
        ]);
    }

    /** Coberturas que solo traen algunas compañías. */
    public const COBERTURAS_EXTRA = [
        'Cristal de Techo' => 'Cubre el daño y/o rotura accidental del cristal de techo.',
        'Inundación' => 'Cubre daños al vehículo a causa de inundación.',
        'Caída de árboles' => 'Cubre daños causados al vehículo por caída de árboles',
        'Daños Parciales al Amparo del Robo Total' => 'Daños parciales como consecuencia de un Robo Total con posterior aparición del vehículo.',
        // Estos dos no son coberturas: uno es un atributo de la compañía y el otro un beneficio
        // comercial. QuoteComparisonService::NON_COVERAGE_TAGS los separa en la vista.
        'Sistema Cleas' => 'La compañía pertenece al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención.',
        'Reposición 0KM' => 'En caso de Robo, Incendio o Destrucción Total la Compañía repone un auto 0KM.',
    ];
}
