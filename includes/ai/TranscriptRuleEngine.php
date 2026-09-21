<?php
/**
 * TranscriptRuleEngine.php
 * Handles deterministic preprocessing of raw transcripts.
 * Applies exclusions, replacements, and protected terms with word-boundary awareness.
 */

if (!defined('TRANSCRIPT_RULE_ENGINE_LOADED')) {
    define('TRANSCRIPT_RULE_ENGINE_LOADED', true);

    class TranscriptRuleEngine {

        /**
         * Process raw transcript into filtered transcript
         */
        public function process(string $rawTranscript, array $settings): string {
            if (trim($rawTranscript) === '') {
                return '';
            }

            $text = $rawTranscript;

            // 1. Extract & Protect special patterns (Emails, URLs, Phones) if preservation settings enabled
            $protectedMap = [];
            $placeholderIndex = 0;

            $preserveEmails = !empty($settings['qa.ai.preserve_emails']);
            $preservePhones = !empty($settings['qa.ai.preserve_phones']);
            $preserveNumbers = !empty($settings['qa.ai.preserve_numbers']);

            if ($preserveEmails) {
                $text = preg_replace_callback('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', function($matches) use (&$protectedMap, &$placeholderIndex) {
                    $key = '___PROTECTED_EMAIL_' . ($placeholderIndex++) . '___';
                    $protectedMap[$key] = $matches[0];
                    return $key;
                }, $text);
            }

            if ($preservePhones) {
                // Phone number pattern (e.g., +1-800-555-0199 or (555) 000-1122 or +1 800 555 1234)
                $text = preg_replace_callback('/(?:\+\d{1,3}[\s-]?)?\(?\d{3}\)?[\s-]?\d{3}[\s-]?\d{4}/', function($matches) use (&$protectedMap, &$placeholderIndex) {
                    $key = '___PROTECTED_PHONE_' . ($placeholderIndex++) . '___';
                    $protectedMap[$key] = $matches[0];
                    return $key;
                }, $text);
            }

            // 2. Protect Admin-Configured Protected Terms / Exceptions
            $exceptions = $this->parseList($settings['qa.ai.exceptions'] ?? '');
            foreach ($exceptions as $ex) {
                $exTrim = trim($ex);
                if ($exTrim === '') continue;
                $pattern = '/\b' . preg_quote($exTrim, '/') . '\b/i';
                $text = preg_replace_callback($pattern, function($matches) use (&$protectedMap, &$placeholderIndex) {
                    $key = '___PROTECTED_TERM_' . ($placeholderIndex++) . '___';
                    $protectedMap[$key] = $matches[0]; // Preserve original case or exact term
                    return $key;
                }, $text);
            }

            // 3. Apply Replacement Rules (Word-Aware)
            $replacements = $this->parseReplacements($settings['qa.ai.replacements'] ?? '');
            foreach ($replacements as $rule) {
                $from = trim($rule['from'] ?? '');
                $to = trim($rule['to'] ?? '');
                if ($from === '') continue;

                // Check if from contains non-word chars or standard word
                if (preg_match('/^\w+$/u', $from)) {
                    $pattern = '/\b' . preg_quote($from, '/') . '\b/i';
                } else {
                    $pattern = '/' . preg_quote($from, '/') . '/i';
                }
                $text = preg_replace($pattern, $to, $text);
            }

            // 4. Apply Exclusion Words/Phrases (Word-Aware)
            $exclusions = $this->parseList($settings['qa.ai.exclusions'] ?? '');
            foreach ($exclusions as $excl) {
                $exclTrim = trim($excl);
                if ($exclTrim === '') continue;

                // Handle bracketed noise tags like [coughing] or [background noise]
                if (str_starts_with($exclTrim, '[') && str_ends_with($exclTrim, ']')) {
                    $pattern = '/' . preg_quote($exclTrim, '/') . '/i';
                } elseif (preg_match('/^\w+$/u', $exclTrim)) {
                    $pattern = '/\b' . preg_quote($exclTrim, '/') . '\b\s*/i';
                } else {
                    $pattern = '/' . preg_quote($exclTrim, '/') . '\s*/i';
                }
                $text = preg_replace($pattern, '', $text);
            }

            // 5. Restore Protected Terms, Emails, Phones
            if (!empty($protectedMap)) {
                $text = strtr($text, $protectedMap);
            }

            // Clean up extra spaces
            $text = preg_replace('/[ \t]{2,}/', ' ', $text);
            $text = preg_replace('/(\r?\n){3,}/', "\n\n", $text);

            return trim($text);
        }

        /**
         * Parse comma or newline separated list
         */
        public function parseList(string $raw): array {
            if (trim($raw) === '') return [];
            $lines = preg_split('/[\r\n,]+/', $raw);
            $items = [];
            foreach ($lines as $line) {
                $t = trim((string)$line);
                if ($t !== '') $items[] = $t;
            }
            return array_values(array_unique($items));
        }

        /**
         * Parse replacement rules (format: Detected -> Replacement or line per rule)
         */
        public function parseReplacements(string $raw): array {
            if (trim($raw) === '') return [];
            $lines = preg_split('/[\r\n]+/', $raw);
            $rules = [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') continue;

                $parts = preg_split('/\s*(?:->|=>|=)\s*/', $line, 2);
                if (count($parts) === 2) {
                    $from = trim($parts[0]);
                    $to = trim($parts[1]);
                    if ($from !== '') {
                        $rules[] = ['from' => $from, 'to' => $to];
                    }
                }
            }
            return $rules;
        }
    }
}
