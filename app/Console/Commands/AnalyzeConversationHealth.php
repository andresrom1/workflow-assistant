<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeConversationHealthJob;
use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('conversations:analyze-health
    {--all : Reanaliza todas las conversaciones}
    {--id=* : IDs específicos a reanalizar}
    {--sync : Ejecuta sincrónicamente en lugar de encolar}')]
#[Description('Backfill de flags de salud (loops, stuck, tool_errors, abandoned, long)')]
class AnalyzeConversationHealth extends Command
{
    public function handle(): int
    {
        $query = Conversation::query();

        $ids = (array) $this->option('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif (! $this->option('all')) {
            $this->error('Especifica --all o --id=<id> (repetible).');

            return self::FAILURE;
        }

        $total = $query->count();
        $this->info("Analizando {$total} conversaciones...");

        $sync = (bool) $this->option('sync');
        $bar = $this->output->createProgressBar($total);

        $query->select('id')->chunkById(200, function ($batch) use ($sync, $bar): void {
            foreach ($batch as $conversation) {
                if ($sync) {
                    (new AnalyzeConversationHealthJob($conversation->id))->handle();
                } else {
                    AnalyzeConversationHealthJob::dispatch($conversation->id);
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Listo.');

        return self::SUCCESS;
    }
}
