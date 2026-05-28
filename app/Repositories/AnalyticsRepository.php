<?php

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agrega métricas del funnel de cotización para la vista de Analytics.
 *
 * Cada "step" corresponde a un estadio del ai_state del orquestador:
 *   1 = customer_identified
 *   2 = vehicle_identified
 *   3 = coverage_set
 *   4 = quote_ready
 *   5 = checkout_done
 *
 * La fuente de verdad es agent_execution_logs.step (1–5).
 * No se toca conversations.metadata para evitar N+1 en aggregations.
 */
class AnalyticsRepository
{
    /** @var array<int, array{key: string, label: string}> */
    private const STEPS = [
        1 => ['key' => 'customer_identified', 'label' => 'Identificación de cliente'],
        2 => ['key' => 'vehicle_identified', 'label' => 'Identificación de vehículo'],
        3 => ['key' => 'coverage_set', 'label' => 'Preferencia de cobertura'],
        4 => ['key' => 'quote_ready', 'label' => 'Cotización'],
        5 => ['key' => 'checkout_done', 'label' => 'Checkout'],
    ];

    /**
     * Devuelve las métricas del funnel para el rango de fechas dado.
     *
     * @return array<int, array{
     *   step: int,
     *   key: string,
     *   label: string,
     *   entered: int,
     *   completed: int,
     *   abandonment_rate: float,
     *   avg_turns: float,
     *   avg_time_seconds: float|null,
     *   negative_annotations: int
     * }>
     */
    public function funnelSteps(Carbon $from, Carbon $to): array
    {
        // 1. Conversations que tuvieron al menos un log en el rango (por step).
        $enteredByStep = $this->enteredPerStep($from, $to);

        // 2. Conversations que completaron cada step (lograron state_changes con su flag).
        $completedByStep = $this->completedPerStep($from, $to);

        // 3. Promedio de turns (logs) por conversación para cada step.
        $avgTurnsByStep = $this->avgTurnsPerStep($from, $to);

        // 4. Tiempo promedio de permanencia en cada step.
        $avgTimeByStep = $this->avgTimePerStep($from, $to);

        // 5. Anotaciones negativas por step.
        $negativeByStep = $this->negativeAnnotationsPerStep($from, $to);

        $result = [];
        foreach (self::STEPS as $stepNumber => $meta) {
            $entered = (int) ($enteredByStep[$stepNumber] ?? 0);
            $completed = (int) ($completedByStep[$stepNumber] ?? 0);
            $abandonmentRate = $entered > 0
                ? round(($entered - $completed) / $entered, 4)
                : 0.0;

            $result[] = [
                'step' => $stepNumber,
                'key' => $meta['key'],
                'label' => $meta['label'],
                'entered' => $entered,
                'completed' => $completed,
                'abandonment_rate' => $abandonmentRate,
                'avg_turns' => (float) ($avgTurnsByStep[$stepNumber] ?? 0),
                'avg_time_seconds' => isset($avgTimeByStep[$stepNumber])
                    ? (float) $avgTimeByStep[$stepNumber]
                    : null,
                'negative_annotations' => (int) ($negativeByStep[$stepNumber] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Count distinct conversations that had at least one execution log per step.
     *
     * @return array<int, int>
     */
    private function enteredPerStep(Carbon $from, Carbon $to): array
    {
        return DB::table('agent_execution_logs')
            ->select('step', DB::raw('COUNT(DISTINCT conversation_id) as cnt'))
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('step', array_keys(self::STEPS))
            ->groupBy('step')
            ->pluck('cnt', 'step')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Count distinct conversations that generated a state_change for each step's flag.
     *
     * Uses state_changes JSON column: a row where state_changes->{flag} = true means
     * the agent successfully advanced the conversation through that step.
     *
     * @return array<int, int>
     */
    private function completedPerStep(Carbon $from, Carbon $to): array
    {
        // PostgreSQL JSONB: cast to text then boolean.
        // state_changes is stored as jsonb — use ->> operator.
        $counts = [];
        foreach (self::STEPS as $stepNumber => $meta) {
            $flag = $meta['key'];
            $counts[$stepNumber] = (int) DB::table('agent_execution_logs')
                ->whereBetween('created_at', [$from, $to])
                ->where('step', $stepNumber)
                ->whereRaw('(state_changes->>?)::boolean = true', [$flag])
                ->distinct()
                ->count('conversation_id');
        }

        return $counts;
    }

    /**
     * Average number of execution logs per conversation for each step.
     *
     * @return array<int, float>
     */
    private function avgTurnsPerStep(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('agent_execution_logs')
            ->select('step', 'conversation_id', DB::raw('COUNT(*) as turns'))
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('step', array_keys(self::STEPS))
            ->groupBy('step', 'conversation_id')
            ->get();

        $byStep = [];
        foreach ($rows as $row) {
            $byStep[$row->step][] = (int) $row->turns;
        }

        $result = [];
        foreach ($byStep as $step => $turnsArr) {
            $result[$step] = round(array_sum($turnsArr) / count($turnsArr), 2);
        }

        return $result;
    }

    /**
     * Average time (in seconds) a conversation spent on each step.
     *
     * Computed as: (MIN created_at of step N+1) - (MIN created_at of step N)
     * Only conversations that progressed to the next step are included.
     * Step 5 uses the conversation's ended_at vs first log of step 5.
     *
     * @return array<int, float>
     */
    private function avgTimePerStep(Carbon $from, Carbon $to): array
    {
        $result = [];

        // Steps 1–4: time between first log of step N and first log of step N+1.
        // Use two nested subqueries to avoid aggregate-inside-aggregate error in PG.
        for ($step = 1; $step <= 4; $step++) {
            $nextStep = $step + 1;

            $row = DB::selectOne(
                'SELECT AVG(EXTRACT(EPOCH FROM (b.first_next - a.first_cur))) AS avg_secs
                 FROM (
                   SELECT conversation_id, MIN(created_at) AS first_cur
                   FROM agent_execution_logs
                   WHERE step = ? AND created_at BETWEEN ? AND ?
                   GROUP BY conversation_id
                 ) a
                 JOIN (
                   SELECT conversation_id, MIN(created_at) AS first_next
                   FROM agent_execution_logs
                   WHERE step = ?
                   GROUP BY conversation_id
                 ) b ON a.conversation_id = b.conversation_id',
                [$step, $from, $to, $nextStep]
            );

            if ($row !== null && $row->avg_secs !== null) {
                $result[$step] = round((float) $row->avg_secs, 1);
            }
        }

        // Step 5: time between first log of step 5 and conversation ended_at.
        $row5 = DB::selectOne(
            'SELECT AVG(EXTRACT(EPOCH FROM (c.ended_at - a.first_cur))) AS avg_secs
             FROM (
               SELECT conversation_id, MIN(created_at) AS first_cur
               FROM agent_execution_logs
               WHERE step = 5 AND created_at BETWEEN ? AND ?
               GROUP BY conversation_id
             ) a
             JOIN conversations c ON a.conversation_id = c.id
             WHERE c.ended_at IS NOT NULL
               AND c.deleted_at IS NULL',
            [$from, $to]
        );

        if ($row5 !== null && $row5->avg_secs !== null) {
            $result[5] = round((float) $row5->avg_secs, 1);
        }

        return $result;
    }

    /**
     * Count negative annotations (verdict = false) per step.
     *
     * @return array<int, int>
     */
    private function negativeAnnotationsPerStep(Carbon $from, Carbon $to): array
    {
        return DB::table('agent_execution_log_annotations as ann')
            ->join('agent_execution_logs as log', 'ann.agent_execution_log_id', '=', 'log.id')
            ->where('ann.verdict', false)
            ->whereBetween('log.created_at', [$from, $to])
            ->whereIn('log.step', array_keys(self::STEPS))
            ->select('log.step', DB::raw('COUNT(*) as cnt'))
            ->groupBy('log.step')
            ->pluck('cnt', 'step')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
