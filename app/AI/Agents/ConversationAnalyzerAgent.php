<?php

namespace App\AI\Agents;

use App\Models\AgentPrompt;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Model('deepseek-reasoner')]
class ConversationAnalyzerAgent implements Agent
{
    use Promptable;

    protected string $agentKey = 'conversation_analyzer';

    public function instructions(): Stringable|string
    {
        $prompt = AgentPrompt::compose($this->agentKey);

        if ($prompt !== '') {
            return $prompt;
        }

        // Fallback al archivo .md si el seeder aún no corrió.
        $path = resource_path('prompts/agents/ConversationAnalyzerAgent.md');

        return file_exists($path) ? (string) file_get_contents($path) : '';
    }
}
