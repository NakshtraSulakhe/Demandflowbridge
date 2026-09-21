<?php
/**
 * AIConfig.php
 * Helper to fetch and normalize AI settings from app_settings table.
 */

if (!defined('AI_CONFIG_LOADED')) {
    define('AI_CONFIG_LOADED', true);

    require_once __DIR__ . '/../functions.php';

    class AIConfig {

        public static function getSettings(): array {
            ensureAppSettingsSchema();

            $defaults = [
                'qa.ai.enabled' => '0',
                'qa.ai.gemini_api_key' => '',
                'qa.ai.processing_mode' => 'automatic',
                'qa.ai.transcription_model' => 'gemini-1.5-flash',
                'qa.ai.formatting_model' => 'gemini-1.5-flash',
                'qa.ai.output_language' => 'English',
                'qa.ai.transcript_prompt' => "Format the transcript cleanly into a structured QA report with Speaker Dialogue, Key Customer Verification Answers, and Call Outcome.",
                'qa.ai.exclusions' => "um, uh, hmm, you know, [background noise], [coughing]",
                'qa.ai.replacements' => "Demand Flow -> DemandFlow Bridge\nM Q L -> MQL\nT G S Tech -> TGS Tech Info",
                'qa.ai.exceptions' => "Microsoft Azure, AWS, Power BI, Microsoft 365, BANT, MQL, CPL",
                'qa.ai.preserve_numbers' => '1',
                'qa.ai.preserve_emails' => '1',
                'qa.ai.preserve_phones' => '1',
                'qa.ai.preserve_names' => '1',
                'qa.ai.speaker_labels' => '1',
                'qa.ai.max_retries' => '3'
            ];

            $settings = [];
            foreach ($defaults as $key => $fallback) {
                $val = getAppSetting($key, $fallback);
                $settings[$key] = ($val !== null && $val !== '') ? $val : $fallback;
            }

            return $settings;
        }

        public static function saveSettings(array $input): bool {
            ensureAppSettingsSchema();

            $allowedKeys = [
                'qa.ai.enabled',
                'qa.ai.gemini_api_key',
                'qa.ai.processing_mode',
                'qa.ai.transcription_model',
                'qa.ai.formatting_model',
                'qa.ai.output_language',
                'qa.ai.transcript_prompt',
                'qa.ai.exclusions',
                'qa.ai.replacements',
                'qa.ai.exceptions',
                'qa.ai.preserve_numbers',
                'qa.ai.preserve_emails',
                'qa.ai.preserve_phones',
                'qa.ai.preserve_names',
                'qa.ai.speaker_labels',
                'qa.ai.max_retries'
            ];

            foreach ($allowedKeys as $key) {
                if (array_key_exists($key, $input)) {
                    $val = (string)$input[$key];
                    // Do not overwrite API key if submitted as masked string (e.g. ********)
                    if ($key === 'qa.ai.gemini_api_key' && (str_contains($val, '****') || trim($val) === '')) {
                        continue;
                    }
                    setAppSetting($key, $val);
                }
            }

            return true;
        }

        public static function maskApiKey(string $key): string {
            $key = trim($key);
            if ($key === '') return '';
            $len = strlen($key);
            if ($len <= 8) return '********';
            return substr($key, 0, 4) . str_repeat('*', $len - 8) . substr($key, -4);
        }
    }
}
