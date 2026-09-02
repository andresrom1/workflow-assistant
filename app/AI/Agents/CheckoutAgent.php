<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Models\AgentPrompt;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[UseSmartestModel]
/**
 * El tope de la llamada al LLM tiene que entrar en el `$timeout` del job junto con el
 * agente que lo encadena: este agente corre encadenado detrás de QuoteAgent (tope por
 * defecto del SDK, 60s) dentro de jobs de 400s. 60 + 300 < 400.
 *
 * Eran 360s contra un job de 180s: el alarm del job mataba el proceso mucho antes de que
 * el tope del SDK pudiera cortar la llamada, y una muerte por alarm no deja excepción, ni
 * log, ni fila en `failed_jobs`. Ver ROADMAP, bitácora 2026-09-02.
 */
#[Timeout(300)]
class CheckoutAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'checkout_closer';

    /** @var array<int, string> */
    protected array $sharedBlocks = ['shared_style', 'shared_grounding', 'shared_siniestro'];

    public function __construct(
        private readonly CheckoutTool $tool,
        private readonly CheckCoverageRuleTool $coverageTool,
        private readonly RevertStageTool $revertStageTool,
        private readonly PresentQuoteOptionsTool $presentQuoteOptionsTool,
        private readonly SiniestroGuidanceTool $siniestroTool,
    ) {}

    public function instructions(): Stringable|string
    {
        return AgentPrompt::compose($this->agentKey, $this->sharedBlocks);
    }

    /**
     * @return array<CheckoutTool|CheckCoverageRuleTool|RevertStageTool|PresentQuoteOptionsTool|SiniestroGuidanceTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool, $this->revertStageTool, $this->presentQuoteOptionsTool, $this->siniestroTool];
    }
}
