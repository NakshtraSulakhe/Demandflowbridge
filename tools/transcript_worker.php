<?php
/**
 * tools/transcript_worker.php
 * Background worker process for AI audio transcription and prompt formatting.
 *
 * MUST be executed via CLI / Cron:
 * php tools/transcript_worker.php
 */

// CLI Guard: Do not allow execution via web browser
if (php_sapi_name() !== 'cli' && empty($_SERVER['SHELL']) && empty($_SERVER['TERM'])) {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/AIConfig.php';
require_once __DIR__ . '/../includes/ai/GeminiClient.php';
require_once __DIR__ . '/../includes/ai/GeminiTranscriber.php';
require_once __DIR__ . '/../includes/ai/TranscriptRuleEngine.php';
require_once __DIR__ . '/../includes/ai/TranscriptPromptBuilder.php';
require_once __DIR__ . '/../includes/ai/GeminiFormatter.php';
require_once __DIR__ . '/../includes/ai/TranscriptRepository.php';

function runTranscriptWorker(): void {
    $settings = AIConfig::getSettings();
    if (empty($settings['qa.ai.enabled']) || (string)$settings['qa.ai.enabled'] === '0') {
        echo "[" . date('Y-m-d H:i:s') . "] AI transcription is currently disabled in app settings.\n";
        return;
    }

    $apiKey = trim((string)($settings['qa.ai.gemini_api_key'] ?? ''));
    if ($apiKey === '') {
        echo "[" . date('Y-m-d H:i:s') . "] Gemini API key is missing. Worker exiting.\n";
        return;
    }

    $repo = new TranscriptRepository();
    $maxRetries = max(1, (int)($settings['qa.ai.max_retries'] ?? 3));

    // Claim one pending job atomically
    $job = $repo->claimPendingJob($maxRetries);
    if (!$job) {
        // No pending jobs
        return;
    }

    $jobId = (int)$job['id'];
    $leadId = (int)$job['lead_id'];
    $fileId = isset($job['file_id']) ? (int)$job['file_id'] : null;
    $rawAudioPath = trim((string)$job['audio_path']);

    echo "[" . date('Y-m-d H:i:s') . "] Processing Job #{$jobId} (Lead #{$leadId}, File #{$fileId})...\n";

    // Resolve absolute path to audio file
    $baseDir = dirname(__DIR__);
    $audioAbsPath = $rawAudioPath;
    if (!str_starts_with($audioAbsPath, '/') && !preg_match('#^[a-zA-Z]:\\\\#', $audioAbsPath)) {
        $audioAbsPath = $baseDir . '/' . ltrim($rawAudioPath, '/');
    }

    if (!file_exists($audioAbsPath)) {
        $err = "Audio file not found at: " . $audioAbsPath;
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: {$err}\n";
        $repo->incrementRetry($jobId, $err, $maxRetries);
        return;
    }

    $client = new GeminiClient($apiKey);
    $transcribeModel = (string)($settings['qa.ai.transcription_model'] ?? 'gemini-1.5-flash');
    $formatModel = (string)($settings['qa.ai.formatting_model'] ?? 'gemini-1.5-flash');
    $speakerLabels = !empty($settings['qa.ai.speaker_labels']);

    // STAGE 1: Audio Transcription (raw_transcript)
    if (empty($job['raw_transcript'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Transcribing audio file using model '{$transcribeModel}'...\n";
        $transcriber = new GeminiTranscriber($client, $transcribeModel, $speakerLabels);
        $tRes = $transcriber->transcribe($audioAbsPath);

        if (!$tRes['success']) {
            $err = $tRes['error'] ?? 'Audio transcription failed.';
            echo "[" . date('Y-m-d H:i:s') . "] Transcription Error: {$err}\n";
            $repo->incrementRetry($jobId, $err, $maxRetries);
            return;
        }

        $rawTranscript = (string)$tRes['text'];
        $language = (string)($tRes['language'] ?? 'English');
        $repo->saveRawTranscript($jobId, $rawTranscript, $language, $transcribeModel);
        echo "[" . date('Y-m-d H:i:s') . "] Raw transcript saved (" . strlen($rawTranscript) . " bytes).\n";
        $job['raw_transcript'] = $rawTranscript;
    }

    // STAGE 2: Rule Engine Preprocessing (filtered_transcript)
    $rawText = (string)$job['raw_transcript'];
    echo "[" . date('Y-m-d H:i:s') . "] Running deterministic Rule Engine...\n";
    $ruleEngine = new TranscriptRuleEngine();
    $filteredText = $ruleEngine->process($rawText, $settings);
    $repo->saveFilteredTranscript($jobId, $filteredText);
    echo "[" . date('Y-m-d H:i:s') . "] Filtered transcript saved.\n";

    // STAGE 3: LLM Formatting (formatted_transcript)
    echo "[" . date('Y-m-d H:i:s') . "] Formatting transcript using model '{$formatModel}'...\n";
    $formatter = new GeminiFormatter($client, $formatModel);
    $fRes = $formatter->format($filteredText, $settings);

    if (!$fRes['success']) {
        $err = $fRes['error'] ?? 'Transcript formatting failed.';
        echo "[" . date('Y-m-d H:i:s') . "] Formatting Error: {$err}\n";
        $repo->incrementRetry($jobId, $err, $maxRetries);
        return;
    }

    $formattedText = (string)$fRes['formatted_transcript'];
    $promptSnapshot = (string)$fRes['prompt_snapshot'];
    $rulesSnapshot = (string)$fRes['rules_snapshot'];

    $repo->saveFormattedTranscript($jobId, $formattedText, $promptSnapshot, $rulesSnapshot, $formatModel);
    echo "[" . date('Y-m-d H:i:s') . "] SUCCESS: Job #{$jobId} completed successfully!\n";
}

// Execute worker logic
runTranscriptWorker();
