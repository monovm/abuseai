<?php

namespace App\Listeners;

use App\Enums\AbuseType;
use App\Events\CaseScored;
use App\Jobs\AI\AnalyseEvidence;
use App\Jobs\AI\DetectPatterns;
use App\Jobs\AI\DraftReporterReply;
use App\Jobs\AI\RescoreCase;
use App\Jobs\Automation\EscalateCase;
use App\Jobs\Automation\SendReporterAck;
use App\Models\AutomationRule;
use App\Services\CaseEngine\RoutingService;

class HandleCaseScored
{
    public function __construct(
        protected RoutingService $router,
    ) {}

    public function handle(CaseScored $event): void
    {
        $case = $event->case;

        // Step 1: Route — SLA + queue assignment + auto-assign agent
        $this->router->route($case);

        // CSAM: immediate escalation, skip normal flow
        if ($case->abuse_type === AbuseType::Csam) {
            EscalateCase::dispatch($case)->onQueue('automation');
            return;
        }

        // Step 2: AI Intelligence layer
        RescoreCase::dispatch($case);                       // AI re-score with full context
        AnalyseEvidence::dispatch($case);                   // AI evidence summary
        DetectPatterns::dispatch($case);                     // AI pattern detection

        // Step 3: Communications — ACK for new cases, draft reply
        if ($case->report_count === 1) {
            SendReporterAck::dispatch($case)->onQueue('notifications');
            DraftReporterReply::dispatch($case, 'ack');      // AI draft ACK email
        }

        // Step 4: Evaluate automation rules
        $this->evaluateAutomationRules($case);
    }

    protected function evaluateAutomationRules($case): void
    {
        $rules = AutomationRule::active()
            ->forEvent('score_changed')
            ->byPriority()
            ->get();

        // Dedupe action types across ALL matching rules for this single event
        // invocation. Otherwise a case that matches both "critical score
        // suspend" and "trusted reporter suspend" dispatches SuspendCustomer
        // twice → duplicate WHMCS API calls + duplicate reporter emails.
        $dispatchedTypes = [];

        foreach ($rules as $rule) {
            if (! $this->ruleMatchesCase($rule, $case)) {
                continue;
            }

            foreach ($rule->actions as $action) {
                $type = $action['type'] ?? null;
                if (! $type || in_array($type, $dispatchedTypes, true)) {
                    continue;
                }
                $this->dispatchAction($type, $action['params'] ?? [], $case);
                $dispatchedTypes[] = $type;
            }

            $rule->update([
                'last_triggered_at' => now(),
                'trigger_count' => $rule->trigger_count + 1,
            ]);
        }
    }

    protected function ruleMatchesCase($rule, $case): bool
    {
        if ($rule->abuse_types && ! in_array($case->abuse_type->value, $rule->abuse_types)) {
            return false;
        }

        if ($rule->min_score !== null && $case->severity_score < $rule->min_score) {
            return false;
        }

        foreach ($rule->conditions as $condition) {
            if (! $this->evaluateCondition($condition, $case)) {
                return false;
            }
        }

        return true;
    }

    protected function evaluateCondition(array $condition, $case): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? '=';
        $value = $condition['value'] ?? null;

        if (! $field) {
            return true;
        }

        $actual = data_get($case, $field);

        return match ($operator) {
            '=' => $actual == $value,
            '!=' => $actual != $value,
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            'in' => in_array($actual, (array) $value),
            default => true,
        };
    }

    /** Dispatches a single action by type. Dedupe is handled by the caller. */
    protected function dispatchAction(string $type, array $params, $case): void
    {
        match ($type) {
            'suspend' => \App\Jobs\Automation\SuspendCustomer::dispatch($case)->onQueue('automation'),
            'block_ip' => \App\Jobs\Automation\BlockIp::dispatch($case)->onQueue('automation'),
            'escalate' => EscalateCase::dispatch($case)->onQueue('automation'),
            'notify' => \App\Jobs\Automation\SendStatusUpdate::dispatch($case, $params)->onQueue('notifications'),
            'takedown_urls' => \App\Jobs\Automation\TakedownUrls::dispatch($case)->onQueue('automation'),
            default => null,
        };
    }
}
