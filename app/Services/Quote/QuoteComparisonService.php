<?php

namespace App\Services\Quote;

use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Support\Franquicia;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Arma la comparación de coberturas que ve el cliente en la vista pública.
 *
 * Agnóstico de canal: recibe modelos de dominio y devuelve arrays. No sabe nada de Inertia, de
 * WhatsApp ni de los agentes.
 *
 * El diff entre alternativas es una diferencia de conjuntos sobre `features_tags`, sin
 * diccionario de sinónimos ni modelo de por medio. Se puede porque el vocabulario del proveedor
 * es cerrado y cada tag tiene una única descripción, idéntica entre compañías — verificado sobre
 * la base entera de producción: 22 tags, ~3.700 apariciones, 7 compañías, cero excepciones.
 *
 * Lo que el dato NO dice son los límites de cada plan (cuántas ruedas, qué tope). Por eso las
 * coberturas compartidas se reportan como "incluidas en las dos" y nunca como "iguales".
 *
 * La vista muestra los grados de las DOS alternativas presentadas, que pueden ser distintos: el
 * closer tiene una rama que presenta "una de cada nivel". Cuando lo son y una contiene a la otra, la
 * comparación se arma como escalón (`escalon`) en vez de como diff simétrico — ahí no hay dos
 * columnas de exclusivas, hay una sola lista de lo que suma la más cara.
 *
 * El grado nunca sale en el payload público. Se usa para agrupar y ordenar, no para mostrarle una
 * etiqueta al cliente: aunque el adapter ahora lo deriva de las coberturas y no del nombre del
 * plan, sigue siendo una simplificación de cuatro casilleros sobre un catálogo mucho más variado.
 *
 * Ojo con las cotizaciones anteriores al 2026-08-08: se guardaron con el clasificador viejo, que
 * matcheaba por substring del nombre y dejó `third_party_complete` en cero. No se migraron, así
 * que en las históricas `basic` sigue siendo el cajón de lo que no matcheó.
 */
final class QuoteComparisonService
{
    /**
     * Tags que viajan mezclados con las coberturas pero no lo son: `Sistema Cleas` es un atributo
     * de la compañía y `Reposición 0KM` un beneficio comercial. Se muestran aparte.
     */
    public const NON_COVERAGE_TAGS = ['Sistema Cleas', 'Reposición 0KM'];

    /** Placeholder que manda el proveedor cuando no tiene descripción para un tag. */
    private const DESCRIPCION_VACIA = 'Incluido.';

    private const GRADE_LABELS = [
        'liability' => 'Responsabilidad Civil',
        'basic' => 'Terceros Básico',
        // C y C+A comparten label a propósito: la distinción sirve para elegir, no para
        // mostrarle al cliente una categoría que ninguna aseguradora nombra así.
        'third_party_complete' => 'Terceros Completo',
        'third_party_complete_plus' => 'Terceros Completo',
        'all_risk' => 'Todo Riesgo',
    ];

    /**
     * Payload completo de la vista pública. Único punto de entrada del controller.
     *
     * `$soloGradoRecomendado` sirve la vista anterior en `/cotizaciones/{token}/B`, que muestra un
     * único grado. Es temporal — ver el docblock de PublicQuoteController.
     *
     * @return array<string, mixed>
     */
    public function buildPublicView(Quote $quote, bool $soloGradoRecomendado = false): array
    {
        $quote->loadMissing('alternatives');

        $grades = $this->gradesAMostrar($quote);

        if ($soloGradoRecomendado) {
            $grades = array_slice($grades, 0, 1);
        }

        // `esOfrecible()` saca las variantes que el checkout no puede cobrar. Además de no
        // ofrecer lo impagable, deja una sola fila por producto: el dedupe de `visiblePlans()`
        // colapsaba esas repeticiones a la más barata, pero por coincidencia — la variante de
        // cupón es más cara, no un producto distinto.
        $alternativasVisibles = $quote->alternatives->filter(
            fn (QuoteAlternative $a): bool => $a->esOfrecible() && in_array($a->normalized_grade, $grades, true)
        );

        $glosario = $this->glossary($alternativasVisibles);
        $planes = $this->visiblePlans($alternativasVisibles);

        // El reenganche por `claveVariante` busca en TODAS las alternativas, no en las visibles: si
        // la segunda presentada es de otro grado, buscarla entre las visibles la perdía y se caía
        // la comparación entera.
        $recomendadas = $this->resolverRecomendadas($quote, $quote->alternatives, $planes);

        $comparacion = $recomendadas === null
            ? null
            : $this->comparar($quote, $planes, $recomendadas, $glosario);

        // `claveVariante` es de uso interno (dedupe y reenganche de la recomendación): no tiene
        // por qué viajar al frontend.
        $publicos = array_map(
            fn (array $plan): array => Arr::except($plan, 'claveVariante'),
            $planes,
        );

        // Con dos grados no hay un label único que sea cierto. Y en las cotizaciones anteriores al
        // 2026-08-08 el grado tampoco está en condiciones de imprimirse: las guardó el clasificador
        // por nombre, cuyo bucket `basic` junta B reales con productos C y hasta alguna A. La vista
        // se calla el grado.
        $gradeUnico = count($grades) === 1 ? $grades[0] : null;

        // Pero el label sí puede ser único con dos grados: C y C+A son el mismo producto comercial
        // partido para poder elegir mejor, y al cliente los dos se le nombran "Terceros Completo".
        // Sin esto, partir el bucket C dejaba sin título una página que antes lo tenía.
        $labels = array_unique(array_map($this->gradeLabel(...), $grades));

        return [
            'grade' => $gradeUnico,
            'gradeLabel' => count($labels) === 1 ? reset($labels) : null,
            'totalOpciones' => count($publicos),
            'glosario' => $glosario,
            'companias' => $this->groupByCompany($publicos),
            'recomendadas' => $recomendadas,
            'comparacion' => $comparacion,
        ];
    }

    /**
     * El diff de las dos recomendadas, más lo que necesita la vista para decidir cómo presentarlo.
     *
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, mixed>  $recomendadas
     * @param  array<string, array{nota: string, esCobertura: bool}>  $glossary
     * @return array<string, mixed>
     */
    private function comparar(Quote $quote, array $plans, array $recomendadas, array $glossary): array
    {
        $principal = $this->planPorId($plans, $recomendadas['principal']['planId']);
        $segunda = $this->planPorId($plans, $recomendadas['segunda']['planId']);

        $comparacion = $this->diff($principal, $segunda, $glossary);

        $gradeDe = fn (int $planId): ?string => $quote->alternatives
            ->firstWhere('id', $planId)?->normalized_grade;

        $comparacion['cruzada'] = $gradeDe($principal['id']) !== $gradeDe($segunda['id']);
        $comparacion['escalon'] = $comparacion['cruzada']
            ? $this->escalon($principal, $segunda, $glossary)
            : null;

        return $comparacion;
    }

    /**
     * El par presentado como escalón: una contiene a la otra y la diferencia es lo que suma la cara.
     *
     * Solo se arma cuando la más cara **contiene** a la más barata. Si cada una tiene algo que la
     * otra no, hay un tradeoff real en dos direcciones y la comparación simétrica es el rendering
     * correcto aunque los grados difieran.
     *
     * @param  array<string, mixed>  $planA
     * @param  array<string, mixed>  $planB
     * @param  array<string, array{nota: string, esCobertura: bool}>  $glossary
     * @return array<string, mixed>|null
     */
    private function escalon(array $planA, array $planB, array $glossary): ?array
    {
        [$arriba, $abajo] = (float) $planA['precio'] >= (float) $planB['precio']
            ? [$planA, $planB]
            : [$planB, $planA];

        // Lo que tiene la barata y la cara no: si hay algo, no es un escalón.
        if (array_diff($abajo['features'], $arriba['features']) !== []) {
            return null;
        }

        $suma = $this->ordenarItems(array_map(
            fn (string $tag): array => $this->item($tag, $glossary),
            array_values(array_diff($arriba['features'], $abajo['features'])),
        ));

        // Sin nada que sumar no hay escalón que mostrar: son el mismo producto a distinto precio.
        if ($suma === []) {
            return null;
        }

        return [
            'arribaPlanId' => $arriba['id'],
            'abajoPlanId' => $abajo['id'],
            'abajoTitulo' => $abajo['titulo'],
            'diferenciaPrecio' => round((float) $arriba['precio'] - (float) $abajo['precio'], 2),
            'sumaCoberturas' => array_values(array_filter($suma, fn (array $i): bool => $i['esCobertura'])),
            'sumaExtras' => array_values(array_filter($suma, fn (array $i): bool => ! $i['esCobertura'])),
        ];
    }

    /**
     * Glosario canónico tag → descripción, construido con las alternativas de la cotización.
     *
     * Va una sola vez en la respuesta y cada plan lleva únicamente su lista de tags: repetir la
     * descripción por plan multiplicaría el payload por quince sin agregar información.
     *
     * @param  Collection<int, QuoteAlternative>  $alternatives
     * @return array<string, array{nota: string, esCobertura: bool}>
     */
    public function glossary(Collection $alternatives): array
    {
        $glosario = [];

        foreach ($alternatives as $alternative) {
            foreach ($alternative->features_tags ?? [] as $tag) {
                $nueva = trim((string) (($alternative->full_details ?? [])[$tag] ?? ''));
                $actual = $glosario[$tag]['nota'] ?? null;

                // Una descripción real le gana al placeholder; entre dos reales, la primera.
                $mejor = match (true) {
                    $actual === null => $nueva,
                    $actual === '' || $actual === self::DESCRIPCION_VACIA => $nueva !== '' ? $nueva : $actual,
                    default => $actual,
                };

                $glosario[$tag] = [
                    'nota' => $mejor,
                    'esCobertura' => ! in_array($tag, self::NON_COVERAGE_TAGS, true),
                ];
            }
        }

        return $glosario;
    }

    /**
     * Los planes que se le muestran al cliente, ordenados de más barato a más caro.
     *
     * @param  Collection<int, QuoteAlternative>  $alternatives
     * @return list<array<string, mixed>>
     */
    public function visiblePlans(Collection $alternatives): array
    {
        $conCoberturas = $alternatives->filter(
            fn (QuoteAlternative $a): bool => ($a->features_tags ?? []) !== []
        );

        $porVariante = [];

        foreach ($conCoberturas as $alternative) {
            $clave = $this->claveDeVariante($alternative);
            $elegido = $porVariante[$clave] ?? null;

            if ($elegido === null || (float) $alternative->precio < (float) $elegido->precio) {
                $porVariante[$clave] = $alternative;
            }
        }

        $planes = array_map(fn (QuoteAlternative $a): array => $this->plan($a), array_values($porVariante));

        usort($planes, fn (array $a, array $b): int => $a['precio'] <=> $b['precio']);

        return $planes;
    }

    /**
     * Planes agrupados por compañía, de la más barata a la más cara.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    public function groupByCompany(array $plans): array
    {
        $grupos = [];

        foreach ($plans as $plan) {
            $grupos[$plan['companiaSlug']]['nombre'] ??= $plan['aseguradora'];
            $grupos[$plan['companiaSlug']]['planes'][] = $plan;
        }

        $companias = [];

        foreach ($grupos as $slug => $grupo) {
            $precios = array_column($grupo['planes'], 'precio');
            $sumas = array_unique(array_column($grupo['planes'], 'sumaAsegurada'));

            $companias[] = [
                'slug' => $slug,
                'nombre' => $grupo['nombre'],
                'desde' => min($precios),
                // Null cuando los planes de la compañía no comparten suma asegurada: la vista
                // muestra la de cada plan en vez de afirmar una común que no existe.
                'sumaAsegurada' => count($sumas) === 1 ? reset($sumas) : null,
                'planes' => $grupo['planes'],
            ];
        }

        usort($companias, fn (array $a, array $b): int => $a['desde'] <=> $b['desde']);

        return $companias;
    }

    /**
     * Qué comparten y qué no dos planes.
     *
     * @param  array<string, mixed>  $planA
     * @param  array<string, mixed>  $planB
     * @param  array<string, array{nota: string, esCobertura: bool}>  $glossary
     * @return array<string, mixed>
     */
    public function diff(array $planA, array $planB, array $glossary): array
    {
        $a = $planA['features'];
        $b = $planB['features'];

        $items = fn (array $tags): array => $this->ordenarItems(
            array_map(fn (string $tag): array => $this->item($tag, $glossary), array_values($tags))
        );

        // Redondeado para no mandar residuo de punto flotante al frontend.
        $diferencia = round(abs($planA['precio'] - $planB['precio']), 2);

        return [
            'comunes' => $items(array_intersect($a, $b)),
            'soloA' => $items(array_diff($a, $b)),
            'soloB' => $items(array_diff($b, $a)),
            'diferenciaPrecio' => $diferencia,
            // Proyección a 12 cuotas. La vista la presenta como aproximada: la cuota se reajusta
            // cuando la compañía actualiza la suma asegurada.
            'ahorroAnual' => round($diferencia * 12, 2),
        ];
    }

    /**
     * Grados de cobertura que se muestran: los de las dos alternativas presentadas.
     *
     * Son dos cuando el agente presentó "una de cada nivel" (la rama cross-grade del closer), y uno
     * en el caso normal. Nunca uno fijo — `normalized_grade` viene inconsistente entre versiones
     * del adapter.
     *
     * El primero es siempre el de la recomendada, porque `presentedPair()` la devuelve primero. De
     * eso depende `$soloGradoRecomendado`.
     *
     * @return list<string>
     */
    private function gradesAMostrar(Quote $quote): array
    {
        $par = $quote->presentedPair();

        if ($par !== null) {
            $grades = array_values(array_unique(array_filter([
                $quote->alternatives->firstWhere('id', $par['principal']['id'])?->normalized_grade,
                $quote->alternatives->firstWhere('id', $par['segunda']['id'])?->normalized_grade,
            ])));

            if ($grades !== []) {
                return $grades;
            }
        }

        $recomendada = $quote->alternatives
            ->firstWhere('id', $quote->recommended_alternative_id);

        if ($recomendada?->normalized_grade !== null) {
            return [$recomendada->normalized_grade];
        }

        $mayoritario = $quote->alternatives
            ->groupBy('normalized_grade')
            ->sortByDesc(fn (Collection $grupo): int => $grupo->count())
            ->keys()
            ->first();

        return $mayoritario === null ? [] : [(string) $mayoritario];
    }

    private function gradeLabel(string $grade): string
    {
        return self::GRADE_LABELS[$grade] ?? Str::headline($grade);
    }

    /**
     * Las dos que presentó el agente, mapeadas a los planes que sobrevivieron al dedupe.
     *
     * Si el agente recomendó una alternativa que resultó ser la cara de un par idéntico, la
     * recomendación apunta a la barata en vez de perderse.
     *
     * @param  Collection<int, QuoteAlternative>  $todasLasAlternativas  Sin filtrar por grado
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>|null
     */
    private function resolverRecomendadas(Quote $quote, Collection $todasLasAlternativas, array $plans): ?array
    {
        $par = $quote->presentedPair();
        if ($par === null || $plans === []) {
            return null;
        }

        $principal = $this->planDeAlternativa($par['principal']['id'], $todasLasAlternativas, $plans);
        $segunda = $this->planDeAlternativa($par['segunda']['id'], $todasLasAlternativas, $plans);

        // Si alguna no sobrevivió al dedupe, o las dos colapsaron en el mismo plan, no hay
        // comparación que hacer.
        if ($principal === null || $segunda === null || $principal === $segunda) {
            return null;
        }

        return [
            'principal' => ['planId' => $principal, 'razon' => $par['principal']['razon']],
            'segunda' => ['planId' => $segunda, 'razon' => $par['segunda']['razon']],
        ];
    }

    /**
     * Id del plan visible que representa a una alternativa presentada.
     *
     * @param  Collection<int, QuoteAlternative>  $todasLasAlternativas  Sin filtrar por grado
     * @param  list<array<string, mixed>>  $plans
     */
    private function planDeAlternativa(int $alternativeId, Collection $todasLasAlternativas, array $plans): ?int
    {
        foreach ($plans as $plan) {
            if ($plan['id'] === $alternativeId) {
                return $alternativeId;
            }
        }

        $original = $todasLasAlternativas->firstWhere('id', $alternativeId);
        if ($original === null) {
            return null;
        }

        $clave = $this->claveDeVariante($original);

        foreach ($plans as $plan) {
            if ($plan['claveVariante'] === $clave) {
                return $plan['id'];
            }
        }

        return null;
    }

    /**
     * Dos alternativas con la misma clave son el mismo producto al mismo precio de lista: misma
     * compañía, misma franquicia, mismas coberturas. Lo único que las separa es el
     * `external_quote_id` del proveedor, que el dominio no expone.
     */
    private function claveDeVariante(QuoteAlternative $alternative): string
    {
        $tags = $alternative->features_tags ?? [];
        sort($tags);

        return $alternative->aseguradora
            .'|'.Franquicia::clave($alternative->titulo)
            .'|'.implode(',', $tags);
    }

    /** @return array<string, mixed> */
    private function plan(QuoteAlternative $alternative): array
    {
        return [
            'id' => $alternative->id,
            'aseguradora' => $alternative->aseguradora,
            'companiaSlug' => Str::slug((string) $alternative->aseguradora),
            'titulo' => $alternative->titulo,
            'franquicia' => Franquicia::extraer($alternative->titulo)['texto'],
            'precio' => (float) $alternative->precio,
            'sumaAsegurada' => (float) $alternative->sum_asegurada,
            'sumaAseguradaTexto' => $alternative->sum_insured_text,
            'features' => array_values($alternative->features_tags ?? []),
            'claveVariante' => $this->claveDeVariante($alternative),
        ];
    }

    /**
     * @param  array<string, array{nota: string, esCobertura: bool}>  $glossary
     * @return array{label: string, nota: string, esCobertura: bool}
     */
    private function item(string $tag, array $glossary): array
    {
        return [
            'label' => $tag,
            'nota' => $glossary[$tag]['nota'] ?? '',
            'esCobertura' => $glossary[$tag]['esCobertura'] ?? ! in_array($tag, self::NON_COVERAGE_TAGS, true),
        ];
    }

    /**
     * Coberturas primero, después lo que no lo es; dentro de cada bloque, alfabético sin acentos
     * para que el orden no dependa del locale del servidor.
     *
     * @param  list<array{label: string, nota: string, esCobertura: bool}>  $items
     * @return list<array{label: string, nota: string, esCobertura: bool}>
     */
    private function ordenarItems(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            return [! $a['esCobertura'], Str::ascii($a['label'])]
                <=> [! $b['esCobertura'], Str::ascii($b['label'])];
        });

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    private function planPorId(array $plans, int $id): array
    {
        foreach ($plans as $plan) {
            if ($plan['id'] === $id) {
                return $plan;
            }
        }

        return [];
    }
}
