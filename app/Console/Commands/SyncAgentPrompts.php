<?php

namespace App\Console\Commands;

use App\Models\AgentPrompt;
use Illuminate\Console\Command;

class SyncAgentPrompts extends Command
{
    protected $signature = 'agent:sync-prompts
                            {--force : Create new version even if content is identical}
                            {--key= : Sync only a specific agent key}';

    protected $description = 'Sync agent prompts from .md files to the database and clear prompt caches';

    /** @var array<string, string> */
    private const FILE_MAP = [
        'customer_identifier' => 'CustomerIdentifierAgent.md',
        'vehicle_identifier' => 'VehicleIdentifierAgent.md',
        'coverage_preference' => 'CoveragePreferenceAgent.md',
        'quote_reception' => 'QuoteAgent.md',
        'checkout_closer' => 'CheckoutAgent.md',
        'coverage_check' => 'CoverageCheckAgent.md',
    ];

    public function handle(): int
    {
        $onlyKey = $this->option('key');
        $force = $this->option('force');

        $agents = $onlyKey
            ? array_filter(self::FILE_MAP, fn ($k) => $k === $onlyKey, ARRAY_FILTER_USE_KEY)
            : self::FILE_MAP;

        if (empty($agents)) {
            $this->error("Agent key '{$onlyKey}' not found. Valid keys: ".implode(', ', array_keys(self::FILE_MAP)));

            return self::FAILURE;
        }

        foreach ($agents as $key => $filename) {
            $this->syncAgent($key, $filename, $force);
        }

        return self::SUCCESS;
    }

    private function syncAgent(string $key, string $filename, bool $force): void
    {
        $path = resource_path("prompts/agents/{$filename}");

        if (! file_exists($path)) {
            $this->warn("  [{$key}] Skipped — file not found: {$path}");

            return;
        }

        $newContent = file_get_contents($path);
        $active = AgentPrompt::activeFor($key);

        if (! $force && $active && $active->content === $newContent) {
            $this->line("  [{$key}] Up to date (v{$active->version})");

            return;
        }

        $version = AgentPrompt::nextVersionFor($key);

        $prompt = AgentPrompt::create([
            'agent_key' => $key,
            'content' => $newContent,
            'version' => $version,
            'is_active' => false,
            'notes' => "Synced from {$filename}",
        ]);

        $prompt->activate();

        $this->info("  [{$key}] Updated to v{$version} and cache cleared");
    }
}
