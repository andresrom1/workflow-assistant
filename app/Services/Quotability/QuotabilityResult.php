<?php

namespace App\Services\Quotability;

/**
 * Resultado tri-estado del match auto → proveedor.
 *
 * VO inmutable. La capa de canal lee SOLO `status`, `resolvedVersion` (hecho de
 * dominio) y, si `NeedsFact`, `missingFact`/`options` para repreguntar. El par
 * `provider`/`externalRef` es OPACO: lo usa el adapter únicamente para persistir
 * el token en `risk_provider_refs` — nunca entra a `ai_state` ni a un mensaje.
 */
final class QuotabilityResult
{
    /**
     * @param  list<string>  $options  Opciones legibles para el hecho que falta (NeedsFact).
     */
    private function __construct(
        public readonly QuotabilityStatus $status,
        public readonly ?string $resolvedVersion = null,
        public readonly ?string $provider = null,
        public readonly ?string $externalRef = null,
        public readonly ?string $missingFact = null,
        public readonly array $options = [],
    ) {}

    /**
     * Quotable: el proveedor `$provider` resolvió el auto al token `$externalRef`,
     * y la versión refinada (hecho de dominio) es `$resolvedVersion`.
     */
    public static function quotable(string $resolvedVersion, string $provider, string $externalRef): self
    {
        return new self(
            status: QuotabilityStatus::Quotable,
            resolvedVersion: $resolvedVersion,
            provider: $provider,
            externalRef: $externalRef,
        );
    }

    /**
     * NeedsFact: ambigüedad reencuadrada como hecho de dominio faltante (p.ej.
     * "transmisión", con opciones ["automática", "manual"]).
     *
     * @param  list<string>  $options
     */
    public static function needsFact(string $missingFact, array $options = []): self
    {
        return new self(
            status: QuotabilityStatus::NeedsFact,
            missingFact: $missingFact,
            options: $options,
        );
    }

    public static function notQuotable(): self
    {
        return new self(status: QuotabilityStatus::NotQuotable);
    }

    public function isQuotable(): bool
    {
        return $this->status === QuotabilityStatus::Quotable;
    }
}
