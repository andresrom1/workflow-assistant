<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentExecutionLog;
use App\Models\AgentPrompt;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\PromptReevaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class StudioController extends Controller
{
    /** @var array<string, string> */
    private const AGENT_KEY_FROM_CLASS = [
        'CustomerIdentifierAgent' => 'customer_identifier',
        'VehicleIdentifierAgent' => 'vehicle_identifier',
        'CoveragePreferenceAgent' => 'coverage_preference',
        'QuoteAgent' => 'quote_reception',
        'CheckoutAgent' => 'checkout_closer',
    ];

    public function show(Request $request, AgentExecutionLog $log): Response
    {
        $agentKey = self::AGENT_KEY_FROM_CLASS[$log->agent_name] ?? null;
        abort_if($agentKey === null, 404, "Agent no soportado para reevaluación: {$log->agent_name}");

        $conversation = Conversation::findOrFail($log->conversation_id);

        $active = AgentPrompt::activeFor($agentKey);
        $draft = AgentPrompt::where('agent_key', $agentKey)
            ->where('status', 'draft')
            ->first();

        $historicalPrompt = $log->agent_prompt_id !== null
            ? AgentPrompt::find($log->agent_prompt_id)
            : null;

        return Inertia::render('Admin/Studio/Reevaluate', [
            'log' => [
                'id' => $log->id,
                'agent_name' => $log->agent_name,
                'agent_key' => $agentKey,
                'step' => $log->step,
                'status' => $log->status,
                'state_before' => $log->state_before,
                'state_after' => $log->state_after,
                'tool_calls' => $log->tool_calls,
                'created_at' => $log->created_at?->toIso8601String(),
            ],
            'conversation' => [
                'id' => $conversation->id,
                'external_conversation_id' => $conversation->external_conversation_id,
            ],
            'messages' => Message::query()
                ->where('conversation_id', $conversation->id)
                ->when($log->created_at !== null, fn ($q) => $q->where('created_at', '<=', $log->created_at))
                ->orderBy('id')
                ->get(['id', 'direction', 'content', 'created_at'])
                ->map(fn (Message $m): array => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'content' => $m->content,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->all(),
            'active_prompt' => $active !== null ? [
                'id' => $active->id,
                'version' => $active->version,
                'content' => $active->content,
            ] : null,
            'draft_prompt' => $draft !== null ? [
                'id' => $draft->id,
                'version' => $draft->version,
                'content' => $draft->content,
                'owner_id' => $draft->owner_id,
                'is_mine' => $draft->owner_id === (int) $request->user()?->id,
            ] : null,
            'historical_prompt' => $historicalPrompt !== null ? [
                'id' => $historicalPrompt->id,
                'version' => $historicalPrompt->version,
                'status' => $historicalPrompt->status,
            ] : null,
        ]);
    }

    public function reevaluate(Request $request, PromptReevaluationService $service): JsonResponse
    {
        $validated = $request->validate([
            'agent_key' => 'required|string',
            'conversation_id' => 'required|integer',
            'agent_execution_log_id' => 'required|integer',
            'draft_instructions' => 'required|string',
            'override_prompt' => 'nullable|string',
        ]);

        try {
            $result = $service->reevaluate(
                agentKey: $validated['agent_key'],
                conversationId: (int) $validated['conversation_id'],
                agentExecutionLogId: (int) $validated['agent_execution_log_id'],
                draftInstructions: $validated['draft_instructions'],
                overridePrompt: $validated['override_prompt'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
