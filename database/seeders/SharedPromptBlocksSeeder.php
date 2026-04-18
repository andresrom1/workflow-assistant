<?php

namespace Database\Seeders;

use App\Models\AgentPrompt;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class SharedPromptBlocksSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            ['shared_style', 'shared', 'shared/shared_style.md', 'Initial seed from file'],
            ['shared_grounding', 'shared', 'shared/shared_grounding.md', 'Initial seed from file'],
            ['quote_reception', 'agent', 'agents/QuoteAgent.md', 'Initial seed from file'],
        ];

        foreach ($entries as [$key, $type, $relativePath, $notes]) {
            $path = resource_path("prompts/{$relativePath}");

            if (! is_file($path)) {
                $this->command?->warn("Skipping {$key}: file {$relativePath} not found");

                continue;
            }

            $content = (string) file_get_contents($path);

            $current = AgentPrompt::activeFor($key);

            if ($current instanceof AgentPrompt && $current->content === $content) {
                $this->command?->line("Skipping {$key}: identical content already active (v{$current->version})");

                continue;
            }

            $prompt = AgentPrompt::create([
                'agent_key' => $key,
                'type' => $type,
                'content' => $content,
                'version' => AgentPrompt::nextVersionFor($key),
                'is_active' => false,
                'notes' => $notes,
            ]);

            $prompt->activate();

            Cache::forget("agent_prompt:{$key}");

            $this->command?->info("Seeded {$key} (v{$prompt->version})");
        }
    }
}
