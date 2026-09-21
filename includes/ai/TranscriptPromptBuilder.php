<?php
/**
 * TranscriptPromptBuilder.php
 * Dynamically constructs the system instructions and formatting prompt for Gemini LLM.
 */

if (!defined('TRANSCRIPT_PROMPT_BUILDER_LOADED')) {
    define('TRANSCRIPT_PROMPT_BUILDER_LOADED', true);

    class TranscriptPromptBuilder {

        private const BASE_SYSTEM_INSTRUCTION = <<<'EOT'
You are the transcript formatting engine for the DemandFlow Bridge Quality Assurance system.

Your task is to format an existing call transcript according to administrator-configured rules.

CRITICAL ACCURACY RULES:

1. Never invent information.
2. Never infer information that was not actually stated.
3. Never change the factual meaning of a speaker's statement.
4. Never convert a negative response into a positive response or vice versa.
5. Preserve numerical values unless an explicit administrator replacement rule requires otherwise.
6. Preserve email addresses exactly unless an explicit administrator replacement rule requires otherwise.
7. Preserve phone numbers exactly unless an explicit administrator replacement rule requires otherwise.
8. Preserve names and company names unless an explicit administrator replacement rule requires otherwise.
9. Preserve configured protected terms.
10. Remove only words or phrases explicitly configured for exclusion.
11. Apply configured replacement rules exactly and carefully.
12. Do not create a summary unless the configured output template explicitly requests one.
13. Do not create qualification answers that were not present in the conversation.
14. Preserve conversation order.
15. Keep Agent and Prospect statements attributed to the correct speaker.
16. Do not silently omit meaningful statements.

ADMIN CONFIGURED OUTPUT FORMAT:

{{TRANSCRIPT_PROMPT}}

EXCLUDED WORDS/PHRASES:

{{EXCLUSIONS}}

REPLACEMENT RULES:

{{REPLACEMENTS}}

PROTECTED TERMS / EXCEPTIONS:

{{EXCEPTIONS}}

PRESERVATION SETTINGS:

Preserve numbers: {{PRESERVE_NUMBERS}}
Preserve emails: {{PRESERVE_EMAILS}}
Preserve phone numbers: {{PRESERVE_PHONES}}
Preserve names: {{PRESERVE_NAMES}}
Speaker labels: {{SPEAKER_LABELS}}

SOURCE TRANSCRIPT:

{{TRANSCRIPT}}

Return only the requested formatted transcript.
Do not include explanations about your work.
EOT;

        /**
         * Build the prompt string for Gemini LLM
         */
        public function buildPrompt(string $transcriptText, array $settings): string {
            $prompt = trim($settings['qa.ai.transcript_prompt'] ?? '');
            if ($prompt === '') {
                $prompt = "Format the transcript cleanly into a structured QA report with Speaker Dialogue, Key Customer Verification Answers, and Call Outcome.";
            }

            $exclusions = trim($settings['qa.ai.exclusions'] ?? '');
            $replacements = trim($settings['qa.ai.replacements'] ?? '');
            $exceptions = trim($settings['qa.ai.exceptions'] ?? '');

            $preserveNumbers = !empty($settings['qa.ai.preserve_numbers']) ? 'Yes' : 'No';
            $preserveEmails = !empty($settings['qa.ai.preserve_emails']) ? 'Yes' : 'No';
            $preservePhones = !empty($settings['qa.ai.preserve_phones']) ? 'Yes' : 'No';
            $preserveNames = !empty($settings['qa.ai.preserve_names']) ? 'Yes' : 'No';
            $speakerLabels = !empty($settings['qa.ai.speaker_labels']) ? 'Yes' : 'No';

            $template = self::BASE_SYSTEM_INSTRUCTION;

            $replacementsMap = [
                '{{TRANSCRIPT_PROMPT}}' => $prompt,
                '{{EXCLUSIONS}}' => $exclusions !== '' ? $exclusions : 'None',
                '{{REPLACEMENTS}}' => $replacements !== '' ? $replacements : 'None',
                '{{EXCEPTIONS}}' => $exceptions !== '' ? $exceptions : 'None',
                '{{PRESERVE_NUMBERS}}' => $preserveNumbers,
                '{{PRESERVE_EMAILS}}' => $preserveEmails,
                '{{PRESERVE_PHONES}}' => $preservePhones,
                '{{PRESERVE_NAMES}}' => $preserveNames,
                '{{SPEAKER_LABELS}}' => $speakerLabels,
                '{{TRANSCRIPT}}' => $transcriptText
            ];

            return strtr($template, $replacementsMap);
        }

        /**
         * Build rules snapshot for persistence
         */
        public function buildRulesSnapshot(array $settings): string {
            return json_encode([
                'exclusions' => $settings['qa.ai.exclusions'] ?? '',
                'replacements' => $settings['qa.ai.replacements'] ?? '',
                'exceptions' => $settings['qa.ai.exceptions'] ?? '',
                'preserve_numbers' => $settings['qa.ai.preserve_numbers'] ?? '1',
                'preserve_emails' => $settings['qa.ai.preserve_emails'] ?? '1',
                'preserve_phones' => $settings['qa.ai.preserve_phones'] ?? '1',
                'preserve_names' => $settings['qa.ai.preserve_names'] ?? '1',
                'speaker_labels' => $settings['qa.ai.speaker_labels'] ?? '1',
                'output_language' => $settings['qa.ai.output_language'] ?? 'English'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }
}
