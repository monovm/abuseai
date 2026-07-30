<?php

namespace App\Livewire\Admin;

use App\Models\AutomationRule;
use Livewire\Component;
use Livewire\WithPagination;

class RuleEditor extends Component
{
    use WithPagination;

    // Form fields
    public ?string $editingId = null;
    public string $name = '';
    public bool $isActive = true;
    public string $triggerEvent = 'score_changed';
    public string $conditionsJson = '[]';
    public string $actionsJson = '[]';
    public int $priority = 0;
    public string $abuseTypesJson = '';
    public ?float $minScore = null;

    public bool $showForm = false;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $rule = AutomationRule::findOrFail($id);
        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->isActive = $rule->is_active;
        $this->triggerEvent = $rule->trigger_event;
        $this->conditionsJson = json_encode($rule->conditions, JSON_PRETTY_PRINT);
        $this->actionsJson = json_encode($rule->actions, JSON_PRETTY_PRINT);
        $this->priority = $rule->priority;
        $this->abuseTypesJson = $rule->abuse_types ? implode(', ', $rule->abuse_types) : '';
        $this->minScore = $rule->min_score ? (float) $rule->min_score : null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'triggerEvent' => ['required', 'string'],
            'conditionsJson' => ['required', 'json'],
            'actionsJson' => ['required', 'json'],
            'priority' => ['required', 'integer', 'min:0'],
        ]);

        $abuseTypes = $this->abuseTypesJson
            ? array_map('trim', explode(',', $this->abuseTypesJson))
            : null;

        $data = [
            'name' => $this->name,
            'is_active' => $this->isActive,
            'trigger_event' => $this->triggerEvent,
            'conditions' => json_decode($this->conditionsJson, true),
            'actions' => json_decode($this->actionsJson, true),
            'priority' => $this->priority,
            'abuse_types' => $abuseTypes,
            'min_score' => $this->minScore,
        ];

        if ($this->editingId) {
            AutomationRule::findOrFail($this->editingId)->update($data);
            session()->flash('success', 'Rule updated.');
        } else {
            AutomationRule::create($data);
            session()->flash('success', 'Rule created.');
        }

        $this->resetForm();
    }

    public function delete(string $id): void
    {
        AutomationRule::findOrFail($id)->delete();
        session()->flash('success', 'Rule deleted.');
    }

    public function toggleActive(string $id): void
    {
        $rule = AutomationRule::findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->isActive = true;
        $this->triggerEvent = 'score_changed';
        $this->conditionsJson = '[]';
        $this->actionsJson = '[]';
        $this->priority = 0;
        $this->abuseTypesJson = '';
        $this->minScore = null;
        $this->showForm = false;
    }

    public function render()
    {
        return view('livewire.admin.rule-editor', [
            'rules' => AutomationRule::orderByDesc('priority')->paginate(25),
        ]);
    }
}
