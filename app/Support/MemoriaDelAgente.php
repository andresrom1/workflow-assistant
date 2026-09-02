<?php

namespace App\Support;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Escrituras sobre la memoria del SDK (`agent_conversation_messages`) que no pasan por el
 * agente.
 *
 * Hay dos caminos que generan una respuesta y después deciden no entregarla: la intercepción
 * del inbox (el cliente siguió escribiendo mientras el LLM generaba) y el encadenamiento de
 * turnos del orquestador (el texto del primer agente se descarta y sale el del segundo). Los
 * dos necesitan lo mismo: dejar la fila en la memoria pero avisarle al modelo que el cliente
 * nunca la leyó.
 */
class MemoriaDelAgente
{
    /**
     * Marca la última respuesta del assistant como no entregada al cliente.
     *
     * Se marca y no se borra: la fila carga también `tool_calls` y `tool_results`, y el
     * contexto del modelo se reconstruye desde ahí. Borrarla le sacaría el registro de que la
     * tool ya corrió y qué devolvió, y el turno siguiente tendería a re-ejecutarla. La marca
     * va en `content` porque `meta` no se reconstruye en el contexto.
     */
    public static function marcarNoEntregada(Conversation $conversation, string $marca): void
    {
        $fila = DB::table('agent_conversation_messages')
            ->where('user_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first(['id', 'content']);

        if (! $fila) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $fila->id)
            ->update(['content' => $marca."\n".$fila->content]);
    }
}
