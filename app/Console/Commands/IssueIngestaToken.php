<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emite un personal access token de Sanctum para el ingestor local de documentos.
 *
 * El ingestor (repo `ingestor/`) lo manda en `Authorization: Bearer <token>` y lo
 * resuelve desde su variable de entorno `SANCTUM_TOKEN`. Se emite contra el `User`
 * del productor; revocable desde `personal_access_tokens`. Ver
 * docs/v3/04-ingesta-local-documentos.md §2-bis.
 */
class IssueIngestaToken extends Command
{
    protected $signature = 'ingesta:token {email : Email del User al que se le emite el token}';

    protected $description = 'Emite un Sanctum token para que el ingestor local autentique la subida de documentos';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No existe un usuario con email {$email}.");

            return self::FAILURE;
        }

        $token = $user->createToken('ingesta-local')->plainTextToken;

        $this->info("Token emitido para {$user->email}. Copialo a SANCTUM_TOKEN del ingestor (no se vuelve a mostrar):");
        $this->line($token);

        return self::SUCCESS;
    }
}
