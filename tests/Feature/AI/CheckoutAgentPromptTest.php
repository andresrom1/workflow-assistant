<?php

use App\AI\Tools\CheckCoverageRuleTool;

/**
 * Regression tests for CheckoutAgent.md prompt invariants.
 *
 * These tests do NOT exercise the LLM — they assert that the prompt file
 * retains the critical structural elements that prevent the #48–#64 bug
 * (agent filtering only by grade, ignoring explicit features like "grúa").
 *
 * If any of these assertions fail, the prompt has drifted in a way that
 * may re-introduce the bug. Re-read the plan at
 * C:\Users\Andrés\.claude\plans\distributed-sniffing-hollerith.md before
 * "fixing" the tests.
 */
beforeEach(function () {
    $this->prompt = (string) file_get_contents(
        resource_path('prompts/agents/CheckoutAgent.md')
    );
});

it('has the explicit-requirements detection section', function () {
    expect($this->prompt)
        ->toContain('detectar requisitos explícitos')
        ->toContain('Vocabulario abierto')
        ->toContain('Mención más reciente gana');
});

it('instructs the agent to filter by both grade and features', function () {
    expect($this->prompt)
        ->toContain('doble eje')
        ->toContain('normalized_grade')
        ->toContain('features_tags')
        ->toContain('full_details');
});

it('has the contrasted fallback rule', function () {
    expect($this->prompt)
        ->toContain('Fallback contrastado')
        ->toContain('warning')
        ->toContain('grade inmediato superior');
});

it('has an honest escalation path when no alternative fulfills features', function () {
    expect($this->prompt)
        ->toContain('Ninguna de las aseguradoras')
        ->toContain('productor humano');
});

it('covers the bug scenarios (feature requirements + pivot)', function () {
    // The bug signature from #48–#64 (cliente pide grúa, filtrado ignora feature).
    expect($this->prompt)
        ->toContain('grúa')
        // pivot scenario — mención más reciente gana
        ->toContain('Mención más reciente gana')
        // fallback contrastado when no grade includes the feature
        ->toContain('Fallback contrastado');
});

it('forbids presenting alternatives without a requested feature as fulfilling it', function () {
    expect($this->prompt)
        ->toContain('Presentar una alternativa sin feature X como si la incluyera');
});

it('delegates coverage rule queries to the tool description (no duplicated rules)', function () {
    // The 20-line block of check_coverage_rule instructions used to live here.
    // It has been moved to CheckCoverageRuleTool::description(). The prompt
    // should only reference the tool, not duplicate its usage rules.
    expect($this->prompt)
        ->toContain('check_coverage_rule')
        ->not->toContain('NO avises que vas a consultar')
        ->not->toContain('Parametros OBLIGATORIOS');
});

it('does not use the legacy 4-script-per-profile presentation structure', function () {
    // The old prompt had 4 separate presentation scripts (Sensible al precio,
    // Orientado al servicio, Urgente, Sin perfil marcado). They have been
    // unified into a single parametrized script. Each old script header
    // should no longer appear as a section title.
    expect($this->prompt)
        ->not->toContain('### Sensible al precio')
        ->not->toContain('### Orientado al servicio')
        ->not->toContain('### Urgente')
        ->not->toContain('### Sin perfil marcado');
});

it('is significantly shorter than the legacy prompt', function () {
    $lineCount = substr_count($this->prompt, "\n");

    // Legacy was ~356 lines. New target is ~150–250 (includes few-shot
    // examples which take space but are high-value). Hard ceiling at 400
    // so we notice if the prompt balloons in future edits. Bumped from
    // 300 → 350 → 400 as high-value sections were added: cross-grade
    // contrast, then the "factual-answer-stop" rule of oro.
    expect($lineCount)->toBeLessThan(400);
});

it('QuoteAgent has the dead-code presentation section removed', function () {
    $quoteAgent = (string) file_get_contents(
        resource_path('prompts/agents/QuoteAgent.md')
    );

    // The orchestrator discards QuoteAgent responses when quote_ready flips,
    // so its presentation instructions were never consumed. They have been
    // removed to avoid documentation drift.
    expect($quoteAgent)
        ->not->toContain('### Paso 1: Inferir perfil del cliente desde el historial')
        ->not->toContain('### Paso 2: Filtrar alternativas')
        ->not->toContain('### Paso 3: Presentar EXACTAMENTE 2 opciones');
});

it('CoveragePreferenceAgent no longer contains commercial profile detection', function () {
    $coverageAgent = (string) file_get_contents(
        resource_path('prompts/agents/CoveragePreferenceAgent.md')
    );

    // Profile detection is now done on-the-fly by CheckoutAgent at presentation
    // time. The CoveragePreferenceTool schema has no profile fields, so the
    // old detection section in this prompt was dead scope.
    expect($coverageAgent)
        ->not->toContain('DETECCIÓN DE PERFIL COMERCIAL')
        ->not->toContain('price_sensitive: true')
        ->not->toContain('service_oriented: true');
});

it('CheckCoverageRuleTool description contains the usage rules', function () {
    $tool = new CheckCoverageRuleTool;
    $description = $tool->description();

    expect($description)
        ->toContain('REGLA ABSOLUTA')
        ->toContain('NO avises que vas a consultar')
        ->toContain('Parametros obligatorios')
        ->toContain('liability->A')
        ->toContain('MAL:')
        ->toContain('BIEN:');
});
