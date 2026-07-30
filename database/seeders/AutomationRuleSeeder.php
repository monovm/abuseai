<?php

namespace Database\Seeders;

use App\Models\AutomationRule;
use Illuminate\Database\Seeder;

class AutomationRuleSeeder extends Seeder
{
    public function run(): void
    {
        AutomationRule::updateOrCreate(
            ['name' => 'Auto-suspend on critical score'],
            [
                'is_active' => true,
                'trigger_event' => 'score_changed',
                'conditions' => [
                    ['field' => 'severity_score', 'operator' => '>=', 'value' => 60],
                ],
                'actions' => [
                    ['type' => 'suspend', 'params' => ['grace_minutes' => 60]],
                    ['type' => 'notify', 'params' => ['channel' => 'slack']],
                ],
                'priority' => 100,
                'min_score' => 60,
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'Warn customer on high score'],
            [
                'is_active' => true,
                'trigger_event' => 'score_changed',
                'conditions' => [
                    ['field' => 'severity_score', 'operator' => '>=', 'value' => 40],
                    ['field' => 'severity_score', 'operator' => '<', 'value' => 60],
                ],
                'actions' => [
                    ['type' => 'notify', 'params' => ['channel' => 'email', 'template' => 'customer_warning']],
                ],
                'priority' => 90,
                'min_score' => 40,
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'CSAM immediate escalation'],
            [
                'is_active' => true,
                'trigger_event' => 'report_received',
                'conditions' => [],
                'actions' => [
                    ['type' => 'escalate', 'params' => ['notify_le' => true]],
                    ['type' => 'suspend', 'params' => ['immediate' => true]],
                ],
                'priority' => 1000,
                'abuse_types' => ['csam'],
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'Block IP on DDoS with high score'],
            [
                'is_active' => true,
                'trigger_event' => 'score_changed',
                'conditions' => [
                    ['field' => 'severity_score', 'operator' => '>=', 'value' => 60],
                ],
                'actions' => [
                    ['type' => 'block_ip', 'params' => []],
                    ['type' => 'notify', 'params' => ['channel' => 'pagerduty']],
                ],
                'priority' => 95,
                'abuse_types' => ['ddos'],
                'min_score' => 60,
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'Auto-ack reporter on new case'],
            [
                'is_active' => true,
                'trigger_event' => 'report_received',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'params' => ['channel' => 'email', 'template' => 'reporter_ack']],
                ],
                'priority' => 10,
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'Trusted reporter + WHMCS service — auto-suspend'],
            [
                'is_active' => true,
                'trigger_event' => 'score_changed',
                'conditions' => [
                    // Reporter we trust (reputable FBL, ISP forwarder, etc.)
                    ['field' => 'metadata.from_trusted_reporter', 'operator' => '=', 'value' => true],
                    // Never auto-suspend on a law-enforcement inquiry.
                    ['field' => 'metadata.from_law_enforcement', 'operator' => '!=', 'value' => true],
                    // Only when we've resolved the target to an actual WHMCS service.
                    ['field' => 'metadata.whmcs_service_id', 'operator' => '!=', 'value' => null],
                ],
                'actions' => [
                    // SuspendCustomer now: suspends in WHMCS, opens a client ticket,
                    // and emails the reporter. Reporter identity is not disclosed
                    // to the client.
                    ['type' => 'suspend', 'params' => []],
                ],
                'priority' => 400,
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'Law enforcement — escalate and notify'],
            [
                'is_active' => true,
                'trigger_event' => 'score_changed',
                'conditions' => [
                    ['field' => 'metadata.from_law_enforcement', 'operator' => '=', 'value' => true],
                ],
                'actions' => [
                    ['type' => 'escalate', 'params' => ['notify_le' => true, 'legal_hold' => true]],
                    ['type' => 'notify', 'params' => ['channel' => 'slack']],
                ],
                'priority' => 500,
            ],
        );

        AutomationRule::updateOrCreate(
            ['name' => 'Escalate SLA breach'],
            [
                'is_active' => true,
                'trigger_event' => 'time_elapsed',
                'conditions' => [
                    ['field' => 'sla_deadline', 'operator' => '<', 'value' => 'now'],
                ],
                'actions' => [
                    ['type' => 'escalate', 'params' => []],
                    ['type' => 'notify', 'params' => ['channel' => 'slack']],
                ],
                'priority' => 80,
            ],
        );
    }
}
