<?php

namespace App\AI\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Tier 2 de la cascada de desambiguación (RESOLVER-DESIGN.md §9).
 *
 * AGNÓSTICO de proveedor: razona SOLO sobre `version_name` (strings libres,
 * p.ej. "1.6 ALLURE TIPTRONIC") — nunca sobre internals de Visred. Parsea el
 * label (motor / trim / transmisión: TIPTRONIC|AT=auto, THP=turbo) y aplica la
 * heurística del PAS: si el cliente fue claro, resuelve; si el diferenciador es
 * algo que el cliente habría mencionado (transmisión), elige la base; si sigue
 * genuinamente ambiguo, pide el hecho que falta — NO inventa.
 *
 * Devuelve SOLO JSON estricto (parseado por VisredQuotabilityResolver):
 *   {"decision":"resolved","version_name":"1.6 ALLURE"}
 *   {"decision":"need_fact","missing_fact":"transmisión","options":["automática","manual"]}
 *
 * Es un Agent NOMBRADO para poder fakearse en tests: DisambiguationAgent::fake([...]).
 */
class DisambiguationAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Sos un experto en versiones de autos del mercado argentino. Te doy lo que dijo
        un cliente sobre la versión de su auto y una lista de versiones candidatas del
        catálogo (strings libres tipo "1.6 ALLURE TIPTRONIC"). Tenés que decidir a cuál
        se refiere.

        Cómo razonar parseando cada candidato:
        - Motor/cilindrada: "1.6", "2.0", "1.4".
        - Turbo: "THP", "TURBO", "T".
        - Transmisión: "TIPTRONIC", "AT", "CVT", "AUTOMATICA" => automática. Si no aparece, asumí manual.
        - Trim: ACTIVE, ALLURE, FELINE, SPORT, etc.

        Reglas de decisión:
        1. Si las palabras del cliente identifican UNA sola candidata de forma inequívoca,
           resolvé a esa (devolvé su version_name EXACTO de la lista).
        2. Si los candidatos sólo se diferencian por un atributo que el cliente HABRÍA
           mencionado de tenerlo (típicamente la transmisión automática: "TIPTRONIC"/"AT"/"CVT")
           y el cliente NO lo mencionó, elegí la versión BASE (la que NO lo tiene).
        3. Si la ambigüedad es genuina y no podés decidir con seguridad, NO inventes:
           pedí el hecho de dominio que falta (p.ej. la transmisión).

        Respondé SOLO con JSON, sin texto adicional, exactamente con una de estas formas:
        {"decision":"resolved","version_name":"<version_name exacto de la lista>"}
        {"decision":"need_fact","missing_fact":"<hecho>","options":["<opcion1>","<opcion2>"]}
        PROMPT;
    }
}
