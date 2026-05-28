<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentExecutionLog;
use App\Models\AgentExecutionLogAnnotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AgentExecutionLogAnnotationController extends Controller
{
    public function store(Request $request, AgentExecutionLog $log): RedirectResponse
    {
        $validated = $request->validate([
            'verdict' => 'required|boolean',
            'note' => 'nullable|string|max:1000',
        ]);

        AgentExecutionLogAnnotation::updateOrCreate(
            [
                'agent_execution_log_id' => $log->id,
                'user_id' => $request->user()->id,
            ],
            [
                'verdict' => $validated['verdict'],
                'note' => $validated['note'] ?? null,
            ],
        );

        return back();
    }

    public function destroy(Request $request, AgentExecutionLog $log): RedirectResponse
    {
        AgentExecutionLogAnnotation::where('agent_execution_log_id', $log->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return back();
    }
}
