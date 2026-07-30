<?php

namespace App\Services\CaseEngine;

use App\Enums\AbuseType;
use App\Enums\ActionType;
use App\Enums\SeverityLevel;
use App\Models\AbuseCase;
use App\Models\CaseAction;
use App\Models\User;

class RoutingService
{
    /**
     * Assign a processing queue based on severity and type.
     */
    public function assignQueue(AbuseCase $case): string
    {
        if ($case->abuse_type === AbuseType::Csam) {
            return 'csam-escalation';
        }

        return match ($case->severity_level) {
            SeverityLevel::Critical => 'critical',
            SeverityLevel::High => 'high-priority',
            SeverityLevel::Medium => 'standard',
            SeverityLevel::Low => 'low-priority',
        };
    }

    /**
     * Calculate SLA deadline based on severity.
     */
    public function calculateSlaDeadline(AbuseCase $case): \Carbon\Carbon
    {
        $level = $case->severity_level->value;
        $hours = config("abusedesk.sla.{$level}", 72);

        return now()->addHours($hours);
    }

    /**
     * Update SLA deadline.
     */
    public function updateSla(AbuseCase $case): AbuseCase
    {
        $case->update([
            'sla_deadline' => $this->calculateSlaDeadline($case),
        ]);

        return $case;
    }

    /**
     * Skill-based routing: assign case to appropriate agent based on type and severity.
     */
    public function autoAssign(AbuseCase $case): AbuseCase
    {
        // CSAM: must go to senior/legal team
        if ($case->abuse_type === AbuseType::Csam) {
            $assignee = $this->findAgentWithRole('admin');
            if ($assignee) {
                return $this->assignTo($case, $assignee, 'Auto-assigned: CSAM requires senior agent');
            }
        }

        // Critical severity: assign to admin
        if ($case->severity_level === SeverityLevel::Critical) {
            $assignee = $this->findLeastLoadedAgent('admin');
            if ($assignee) {
                return $this->assignTo($case, $assignee, 'Auto-assigned: critical severity');
            }
        }

        // High severity: assign to any agent
        if ($case->severity_level === SeverityLevel::High) {
            $assignee = $this->findLeastLoadedAgent();
            if ($assignee) {
                return $this->assignTo($case, $assignee, 'Auto-assigned: high severity');
            }
        }

        // Medium/Low: leave unassigned for manual pickup
        return $case;
    }

    /**
     * Full routing: SLA + queue + auto-assign.
     */
    public function route(AbuseCase $case): AbuseCase
    {
        $this->updateSla($case);
        $this->autoAssign($case);

        $case->update([
            'metadata' => array_merge($case->metadata ?? [], [
                'routing' => [
                    'queue' => $this->assignQueue($case),
                    'routed_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        return $case->fresh();
    }

    protected function assignTo(AbuseCase $case, User $user, string $reason): AbuseCase
    {
        $case->update(['assignee_id' => $user->id]);

        CaseAction::create([
            'case_id' => $case->id,
            'action_type' => ActionType::Assigned,
            'payload' => ['assignee_id' => $user->id, 'assignee_name' => $user->name],
            'note' => $reason,
            'created_at' => now(),
        ]);

        return $case;
    }

    protected function findAgentWithRole(string $role): ?User
    {
        return User::role($role)->first();
    }

    protected function findLeastLoadedAgent(?string $role = null): ?User
    {
        $query = User::query();

        if ($role) {
            $query->role($role);
        }

        return $query->withCount(['assignedCases' => function ($q) {
            $q->whereNotIn('status', ['closed', 'resolved']);
        }])->orderBy('assigned_cases_count', 'asc')->first();
    }
}
