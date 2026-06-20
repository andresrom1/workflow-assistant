<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAudit;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Consolida en el `Customer` canónico los datos que se van capturando en distintas
 * fuentes (chat `identifyCustomer`, checkout, edición admin). Es **agnóstico de canal**:
 * depende solo del modelo de dominio `Customer`, no del orquestador, agentes, tools ni
 * `conversations.metadata.ai_state`.
 *
 * Política por fuente (peso checkout ≈ admin > chat):
 *  - **admin**: edición manual deliberada → siempre aplica y queda auditada.
 *  - **checkout** (declaración jurada): rellena vacíos y pisa valores de origen chat o
 *    checkout previo; si choca con un valor curado por **admin** → NO pisa, marca
 *    divergencia para resolución manual.
 *  - **chat**: solo rellena campos vacíos; nunca pisa.
 *
 * La provenance por campo vive en `metadata['field_sources'][campo] = {source, at}`.
 * Ver docs/v2/11.
 */
class CustomerConsolidationService
{
    /** Campos canónicos que el servicio consolida (alineados con `person_holder` + domicilio del tomador). */
    private const FIELDS = [
        'first_name', 'last_name', 'dni', 'document_type_id', 'person_type_id',
        'email', 'phone', 'birthdate', 'sex_id', 'tax_condition_id',
        'domicilio_calle', 'domicilio_numero', 'domicilio_cp',
        'domicilio_provincia', 'domicilio_localidad',
    ];

    /**
     * Claves de negocio con índice único en `customers`. La consolidación NUNCA debe
     * escribir una de estas si el valor ya pertenece a otro customer: sería una colisión
     * de identidad (mismo DNI/email en dos filas) que rompería la transacción entera del
     * checkout. Ante el choque se saltea el campo y se registra para resolución manual.
     */
    private const UNIQUE_FIELDS = ['dni', 'email'];

    /**
     * Campos canónicos que el servicio consolida. Lo usa {@see CustomerMergeService} para
     * arrastrar los datos del customer absorbido al survivor con la misma política.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * Aplica los datos entrantes y devuelve las divergencias detectadas (checkout vs admin).
     *
     * El source `merge` se comporta como `chat` (solo rellena vacíos, nunca pisa): al
     * fusionar dos filas el survivor ya es la fuente de verdad, el perdedor solo completa.
     *
     * @param  array<string, mixed>  $incoming
     * @param  'chat'|'checkout'|'admin'|'merge'  $source
     * @return list<array{field: string, current: string, incoming: string, current_source: string|null, incoming_source: string}>
     */
    public function apply(Customer $customer, array $incoming, string $source, ?int $userId = null): array
    {
        /** @var array<string, array{source: string, at: string}> $sources */
        $sources = $customer->metadata['field_sources'] ?? [];
        $divergences = [];
        /** @var list<array{field: string, old: string|null, new: string}> $changes */
        $changes = [];
        $nameTouched = false;

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $value = $this->normalize($field, $incoming[$field]);
            if ($value === null) {
                continue;
            }

            $current = $this->comparable($field, $customer->{$field});
            $currentSource = $sources[$field]['source'] ?? null;

            if ($current !== null && $current === $value) {
                continue; // mismo valor, nada que hacer
            }

            if ($current !== null) {
                // Campo no vacío que difiere: aplicar política por fuente.
                if ($source === 'chat' || $source === 'merge') {
                    continue; // chat/merge nunca pisan un valor existente del survivor
                }
                if ($source === 'checkout' && $currentSource === 'admin') {
                    $divergences[] = [
                        'field' => $field,
                        'current' => $current,
                        'incoming' => $value,
                        'current_source' => $currentSource,
                        'incoming_source' => $source,
                    ];

                    continue; // no pisar lo curado por admin
                }
                // admin (manual) o checkout sobre chat/checkout → cae al set de abajo.
            }

            // Guard de clave única: si otro customer ya posee este DNI/email, escribirlo
            // violaría el índice único y abortaría toda la transacción. Se saltea el campo
            // (el resto de los datos sí se consolidan) y se deja rastro del conflicto de
            // identidad — dos filas para la misma persona se resuelven con un merge manual.
            if (in_array($field, self::UNIQUE_FIELDS, true)
                && Customer::where($field, $value)->whereKeyNot($customer->id)->exists()) {
                Log::warning('CustomerConsolidation: clave única en conflicto, campo no aplicado', [
                    'field' => $field,
                    'value' => $value,
                    'customer_id' => $customer->id,
                    'source' => $source,
                    'owned_by_customer_id' => Customer::where($field, $value)->value('id'),
                ]);

                continue;
            }

            $changes[] = ['field' => $field, 'old' => $current, 'new' => $value];
            $customer->setAttribute($field, $value);
            $sources[$field] = ['source' => $source, 'at' => now()->toIso8601String()];

            if ($field === 'first_name' || $field === 'last_name') {
                $nameTouched = true;
            }
        }

        if ($changes === []) {
            return $divergences;
        }

        if ($nameTouched) {
            $customer->syncName();
        }

        $customer->metadata = array_merge($customer->metadata ?? [], ['field_sources' => $sources]);
        $customer->save();

        foreach ($changes as $change) {
            CustomerAudit::create([
                'customer_id' => $customer->id,
                'user_id' => $userId,
                'source' => $source,
                'field' => $change['field'],
                'old_value' => $change['old'],
                'new_value' => $change['new'],
            ]);
        }

        return $divergences;
    }

    /**
     * Fusiona los campos del customer absorbido (`$loser`) en `$survivor` aplicando
     * survivorship **por campo**: gana la fuente más confiable (admin > checkout > chat);
     * a igual confianza, el valor más reciente (`at`). Preserva la provenance ganadora y
     * audita tanto los valores aplicados como los descartados. Lo usa
     * {@see CustomerMergeService::merge()} (que ya hizo `forceDelete()` del perdedor, así
     * que su slot de clave única quedó libre).
     *
     * @param  array<string, mixed>  $loserValues  valores canónicos del perdedor
     * @param  array<string, array{source: string, at: string}>  $loserSources  field_sources del perdedor
     */
    public function mergeFields(Customer $survivor, array $loserValues, array $loserSources): void
    {
        /** @var array<string, array{source: string, at: string}> $sources */
        $sources = $survivor->metadata['field_sources'] ?? [];
        /** @var list<array{field: string, old: string|null, new: string}> $changes */
        $changes = [];
        /** @var list<array{field: string, old: string, new: string}> $discards */
        $discards = [];
        $nameTouched = false;

        foreach (self::FIELDS as $field) {
            $loserValue = $this->normalize($field, $loserValues[$field] ?? null);
            if ($loserValue === null) {
                continue; // el perdedor no aporta nada en este campo
            }

            $survivorValue = $this->comparable($field, $survivor->{$field});

            if ($survivorValue !== null) {
                if ($survivorValue === $loserValue) {
                    continue; // mismo valor, nada que hacer
                }
                if (! $this->loserWins($sources[$field] ?? null, $loserSources[$field] ?? null)) {
                    // Gana el survivor: conserva su valor, se audita el descarte del perdedor.
                    $discards[] = ['field' => $field, 'old' => $loserValue, 'new' => $survivorValue];

                    continue;
                }
            }

            // Guard de clave única: si un TERCER customer ya posee este dni/email, no escribir
            // (violaría el índice único). El perdedor ya fue eliminado, así que su propio valor
            // no cuenta como conflicto.
            if (in_array($field, self::UNIQUE_FIELDS, true)
                && Customer::where($field, $loserValue)->whereKeyNot($survivor->id)->exists()) {
                Log::warning('CustomerMerge: clave única en conflicto, campo no fusionado', [
                    'field' => $field,
                    'value' => $loserValue,
                    'survivor_id' => $survivor->id,
                ]);

                continue;
            }

            $changes[] = ['field' => $field, 'old' => $survivorValue, 'new' => $loserValue];
            $survivor->setAttribute($field, $loserValue);
            // Preserva la provenance original del perdedor (no la aplana a 'merge').
            $sources[$field] = $loserSources[$field] ?? ['source' => 'merge', 'at' => now()->toIso8601String()];

            if ($field === 'first_name' || $field === 'last_name') {
                $nameTouched = true;
            }
        }

        if ($changes !== []) {
            if ($nameTouched) {
                $survivor->syncName();
            }

            $survivor->metadata = array_merge($survivor->metadata ?? [], ['field_sources' => $sources]);
            $survivor->save();
        }

        foreach (array_merge($changes, $discards) as $entry) {
            CustomerAudit::create([
                'customer_id' => $survivor->id,
                'user_id' => null,
                'source' => 'merge',
                'field' => $entry['field'],
                'old_value' => $entry['old'],
                'new_value' => $entry['new'],
            ]);
        }
    }

    /**
     * ¿El valor del perdedor debe pisar al del survivor en un merge? Gana la mayor confianza
     * (admin > checkout > chat); a igual confianza, el `at` más reciente. Sin provenance = 0.
     *
     * @param  array{source: string, at: string}|null  $survivorSource
     * @param  array{source: string, at: string}|null  $loserSource
     */
    private function loserWins(?array $survivorSource, ?array $loserSource): bool
    {
        $survivorRank = $this->sourceRank($survivorSource['source'] ?? null);
        $loserRank = $this->sourceRank($loserSource['source'] ?? null);

        if ($loserRank !== $survivorRank) {
            return $loserRank > $survivorRank;
        }

        return ($loserSource['at'] ?? '') > ($survivorSource['at'] ?? '');
    }

    private function sourceRank(?string $source): int
    {
        return match ($source) {
            'admin' => 3,
            'checkout' => 2,
            'chat' => 1,
            default => 0, // merge legacy, null o desconocido
        };
    }

    /**
     * Normaliza el valor entrante a string comparable (o null si está vacío).
     */
    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if ($field === 'email') {
            return mb_strtolower($string);
        }

        if ($field === 'birthdate') {
            return substr($string, 0, 10); // "Y-m-d" aunque venga con hora
        }

        return $string;
    }

    /**
     * Representa el valor actual del Customer como string comparable (o null si vacío).
     */
    private function comparable(string $field, mixed $current): ?string
    {
        if ($current instanceof DateTimeInterface) {
            return $current->format('Y-m-d');
        }

        return $this->normalize($field, $current);
    }
}
