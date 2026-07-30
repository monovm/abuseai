<?php

namespace App\Livewire\Admin;

use App\Services\AI\AiProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class AiSettings extends Component
{
    public string $activeProvider = 'claude';
    public string $claudeModel = '';
    public string $openaiModel = '';

    public function mount(): void
    {
        $this->activeProvider = AiProviderFactory::getActiveProvider();
        $this->claudeModel = Cache::get('ai_claude_model', config('ai.providers.claude.model', 'claude-sonnet-5'));
        $this->openaiModel = Cache::get('ai_openai_model', config('ai.providers.openai.model', 'gpt-4o'));
    }

    public function save(): void
    {
        AiProviderFactory::setActiveProvider($this->activeProvider);

        Cache::forever('ai_claude_model', $this->claudeModel);
        Cache::forever('ai_openai_model', $this->openaiModel);

        session()->flash('success', "AI provider set to {$this->activeProvider}.");
    }

    public function testProvider(string $provider): void
    {
        // Save model selection first so the test uses the right model
        Cache::forever('ai_claude_model', $this->claudeModel);
        Cache::forever('ai_openai_model', $this->openaiModel);

        try {
            $ai = AiProviderFactory::make($provider);

            $response = $ai->complete(
                'You are a test assistant. Reply with exactly: OK',
                'Test connection. Reply with exactly: OK',
                50,
            );

            if ($response && str_contains(strtoupper($response), 'OK')) {
                session()->flash("test-{$provider}", "Connection successful! Model: {$ai->getModelName()}");
            } elseif ($response) {
                session()->flash("test-{$provider}", "Connected. Response: " . Str::limit($response, 80));
            } else {
                session()->flash("test-{$provider}-error", 'No response received. Check API key and model name.');
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            // Extract useful part from long error messages
            if (str_contains($message, ' - {')) {
                $jsonPart = substr($message, strpos($message, ' - {') + 3);
                $decoded = json_decode($jsonPart, true);
                if ($decoded) {
                    $message = $decoded['error']['message'] ?? $decoded['message'] ?? $message;
                }
            }
            session()->flash("test-{$provider}-error", 'Error: ' . Str::limit($message, 200));
        }
    }

    public function render()
    {
        $providers = AiProviderFactory::availableProviders();

        return view('livewire.admin.ai-settings', [
            'providers' => $providers,
            'claudeModels' => config('ai.providers.claude.models', []),
            'openaiModels' => config('ai.providers.openai.models', []),
        ]);
    }
}
