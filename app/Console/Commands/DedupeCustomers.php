<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Customer;
use App\Services\CustomerIdentificationService;
use App\Services\CustomerMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Colapsa los clientes duplicados que quedaron de antes de que todas las puertas identificaran
 * por {@see CustomerIdentificationService} — cada conversación nueva del mismo
 * usuario de WhatsApp acuñaba una fila nueva. Ver ROADMAP, bitácora 2026-07-26.
 *
 * Corre en seco por defecto: imprime lo que haría y no toca nada hasta que se le pase --apply.
 */
class DedupeCustomers extends Command
{
    protected $signature = 'customers:dedupe {--apply : Ejecuta las fusiones; sin este flag solo las lista}';

    protected $description = 'Fusiona clientes duplicados que comparten teléfono o BSUID';

    public function handle(CustomerMergeService $merge): int
    {
        $grupos = collect()
            ->merge($this->gruposPorTelefono())
            ->merge($this->gruposPorBsuid())
            ->map(fn (array $ids): array => array_values(array_unique($ids)))
            ->filter(fn (array $ids): bool => count($ids) > 1);

        if ($grupos->isEmpty()) {
            $this->info('No hay clientes duplicados.');

            return self::SUCCESS;
        }

        $filas = [];
        $fusiones = 0;

        foreach ($grupos as $clave => $ids) {
            $clientes = Customer::whereIn('id', $ids)->get();
            $superviviente = $this->elegirSuperviviente($clientes);
            $perdedores = $clientes->reject(fn (Customer $c): bool => $c->id === $superviviente->id);

            foreach ($perdedores as $perdedor) {
                $filas[] = [$clave, $superviviente->id, $perdedor->id, $perdedor->name ?? '—', $perdedor->phone ?? '—'];

                if ($this->option('apply')) {
                    $merge->merge($superviviente, $perdedor);
                }

                $fusiones++;
            }
        }

        $this->table(['Coincidencia', 'Sobrevive', 'Se absorbe', 'Nombre', 'Teléfono'], $filas);

        if (! $this->option('apply')) {
            $this->warn("{$fusiones} fusión(es) pendiente(s). Volvé a correrlo con --apply para ejecutarlas.");

            return self::SUCCESS;
        }

        $this->info("{$fusiones} fusión(es) aplicada(s).");

        return self::SUCCESS;
    }

    /**
     * Grupos de clientes que comparten teléfono. El valor ya está normalizado en la columna
     * (lo normaliza `CustomerRepository` en toda alta o edición).
     *
     * @return Collection<string, list<int>>
     */
    private function gruposPorTelefono(): Collection
    {
        return Customer::query()
            ->whereNotNull('phone')
            ->get(['id', 'phone'])
            ->groupBy('phone')
            ->mapWithKeys(fn (Collection $clientes, string $phone): array => [
                "teléfono {$phone}" => $this->ids($clientes, 'id'),
            ]);
    }

    /**
     * Grupos de clientes alcanzados por el mismo BSUID, mirando **todas** las conversaciones
     * incluidas las archivadas por el Reset del admin, que es justamente lo que partía al
     * mismo usuario en varias filas.
     *
     * @return Collection<string, list<int>>
     */
    private function gruposPorBsuid(): Collection
    {
        return Conversation::query()
            ->whereNotNull('ext_user_id')
            ->whereNotNull('customer_id')
            ->get(['ext_user_id', 'customer_id'])
            ->groupBy('ext_user_id')
            ->mapWithKeys(fn (Collection $conversaciones, string $bsuid): array => [
                "BSUID {$bsuid}" => $this->ids($conversaciones, 'customer_id'),
            ]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $modelos
     * @return list<int>
     */
    private function ids(Collection $modelos, string $columna): array
    {
        return $modelos
            ->pluck($columna)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Gana el que traiga más identificadores fuertes (documento y email); a igualdad, el más
     * antiguo. Los datos del perdedor no se pierden: {@see CustomerMergeService::merge()}
     * arrastra campo por campo al superviviente.
     *
     * @param  Collection<int, Customer>  $clientes
     */
    private function elegirSuperviviente(Collection $clientes): Customer
    {
        // Clave ordenable única: primero la fuerza (invertida, para que 2 gane), después el id.
        return $clientes
            ->sortBy(fn (Customer $c): string => sprintf('%d-%012d', 2 - $this->fuerzaIdentidad($c), $c->id))
            ->first();
    }

    private function fuerzaIdentidad(Customer $customer): int
    {
        return ($customer->dni === null ? 0 : 1) + ($customer->email === null ? 0 : 1);
    }
}
