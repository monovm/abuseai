<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * AI Translation service — detects language and translates non-English reports to English.
 */
class TranslationAiService
{
    public function __construct(
        protected ClaudeService $claude,
    ) {}

    /**
     * Detect language and translate if not English.
     * Returns ['language' => 'xx', 'is_english' => bool, 'original' => text, 'translated' => text]
     */
    public function detectAndTranslate(string $text): array
    {
        if (empty(trim($text))) {
            return ['language' => 'en', 'is_english' => true, 'original' => $text, 'translated' => $text];
        }

        $result = $this->claude->completeJson(
            'Detect the language of the following text and translate it to English if it is not English. Return JSON only: {"language_code": "xx", "language_name": "English", "is_english": true/false, "translated": "the text in English"}. If the text is already English, set translated to the original text.',
            $text,
        );

        if (! $result) {
            return ['language' => 'en', 'is_english' => true, 'original' => $text, 'translated' => $text];
        }

        return [
            'language' => $result['language_code'] ?? 'en',
            'language_name' => $result['language_name'] ?? 'English',
            'is_english' => $result['is_english'] ?? true,
            'original' => $text,
            'translated' => $result['translated'] ?? $text,
        ];
    }
}
