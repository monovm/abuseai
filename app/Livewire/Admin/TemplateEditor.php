<?php

namespace App\Livewire\Admin;

use App\Models\EmailTemplate;
use Livewire\Component;
use Livewire\WithPagination;

class TemplateEditor extends Component
{
    use WithPagination;

    public ?string $editingId = null;
    public string $slug = '';
    public string $name = '';
    public string $type = 'reporter_ack';
    public ?string $abuseType = null;
    public string $subject = '';
    public string $bodyHtml = '';
    public string $bodyText = '';
    public string $variablesText = '';
    public bool $isActive = true;

    public bool $showForm = false;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $t = EmailTemplate::findOrFail($id);
        $this->editingId = $t->id;
        $this->slug = $t->slug;
        $this->name = $t->name;
        $this->type = $t->type;
        $this->abuseType = $t->abuse_type?->value;
        $this->subject = $t->subject;
        $this->bodyHtml = $t->body_html;
        $this->bodyText = $t->body_text;
        $this->variablesText = implode(', ', $t->variables ?? []);
        $this->isActive = $t->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'slug' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'bodyHtml' => ['required', 'string'],
            'bodyText' => ['required', 'string'],
        ]);

        $variables = $this->variablesText
            ? array_map('trim', explode(',', $this->variablesText))
            : [];

        $data = [
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'abuse_type' => $this->abuseType ?: null,
            'subject' => $this->subject,
            'body_html' => $this->bodyHtml,
            'body_text' => $this->bodyText,
            'variables' => $variables,
            'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            EmailTemplate::findOrFail($this->editingId)->update($data);
            session()->flash('success', 'Template updated.');
        } else {
            EmailTemplate::create($data);
            session()->flash('success', 'Template created.');
        }

        $this->resetForm();
    }

    public function delete(string $id): void
    {
        EmailTemplate::findOrFail($id)->delete();
        session()->flash('success', 'Template deleted.');
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->slug = '';
        $this->name = '';
        $this->type = 'reporter_ack';
        $this->abuseType = null;
        $this->subject = '';
        $this->bodyHtml = '';
        $this->bodyText = '';
        $this->variablesText = '';
        $this->isActive = true;
        $this->showForm = false;
        $this->showPreview = false;
    }

    public bool $showPreview = false;

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }

    /**
     * Render the current draft against a small set of sample merge values
     * so the agent can see what the email will actually look like before
     * saving. Variables not present in the sample fall through unchanged.
     */
    public function previewRendered(): array
    {
        $sample = [
            'case_number' => 'ABU-2026-00042',
            'case_url' => url('/admin/cases/00000000-0000-0000-0000-000000000000'),
            'abuse_type' => 'phishing',
            'severity_level' => 'high',
            'severity_score' => '72.0',
            'target_ip' => '203.0.113.45',
            'target_domain' => 'example.com',
            'reporter_name' => 'Sample Reporter',
            'reporter_email' => 'reporter@example.org',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Sample Customer',
            'brand_name' => 'AbuseDesk',
            'brand_from' => 'abuse@abuse-desk.example',
            'evidence_summary' => 'Three reports of phishing pages hosted on the target IP within the last 6 hours.',
            'date' => now()->format('M j, Y'),
        ];

        $replace = function (string $text) use ($sample): string {
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($m) use ($sample) {
                return $sample[$m[1]] ?? '{{ ' . $m[1] . ' }}';
            }, $text);
        };

        return [
            'subject' => $replace($this->subject),
            'body_html' => $replace($this->bodyHtml),
            'body_text' => $replace($this->bodyText),
        ];
    }

    public function render()
    {
        return view('livewire.admin.template-editor', [
            'templates' => EmailTemplate::orderBy('type')->paginate(25),
        ]);
    }
}
