<?php
/**
 * GeminiFormatter.php
 * Handles LLM transcript formatting using Google Gemini API.
 */

if (!defined('GEMINI_FORMATTER_LOADED')) {
    define('GEMINI_FORMATTER_LOADED', true);

    require_once __DIR__ . '/GeminiClient.php';
    require_once __DIR__ . '/TranscriptPromptBuilder.php';

    class GeminiFormatter {
        private GeminiClient $client;
        private string $model;
        private TranscriptPromptBuilder $promptBuilder;

        public function __construct(GeminiClient $client, string $model = 'gemini-1.5-flash') {
            $this->client = $client;
            $this->model = trim($model) !== '' ? trim($model) : 'gemini-1.5-flash';
            $this->promptBuilder = new TranscriptPromptBuilder();
        }

        /**
         * Format transcript using dynamic settings
         */
        public function format(string $transcriptText, array $settings): array {
            if (trim($transcriptText) === '') {
                return [
                    'success' => false,
                    'error' => 'Source transcript text is empty.'
                ];
            }

            $fullPrompt = $this->promptBuilder->buildPrompt($transcriptText, $settings);
            $rulesSnapshot = $this->promptBuilder->buildRulesSnapshot($settings);

            $contents = [
                [
                    'parts' => [
                        [
                            'text' => $fullPrompt
                        ]
                    ]
                ]
            ];

            $res = $this->client->generateContent($this->model, $contents, [
                'temperature' => 0.2,
                'topP' => 0.95
            ]);

            if (!$res['success']) {
                return $res;
            }

            $formatted = trim((string)($res['text'] ?? ''));
            if ($formatted === '') {
                return [
                    'success' => false,
                    'error' => 'Gemini API returned an empty formatted response.'
                ];
            }

            return [
                'success' => true,
                'formatted_transcript' => $formatted,
                'prompt_snapshot' => $fullPrompt,
                'rules_snapshot' => $rulesSnapshot,
                'model' => $this->model
            ];
        }
    }
}
