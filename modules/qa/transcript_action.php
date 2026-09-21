<?php
/**
 * modules/qa/transcript_action.php
 * AJAX endpoint for QA Transcript status, re-formatting, manual retry, and human edits.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai/AIConfig.php';
require_once __DIR__ . '/../../includes/ai/GeminiClient.php';
require_once __DIR__ . '/../../includes/ai/TranscriptRuleEngine.php';
require_once __DIR__ . '/../../includes/ai/TranscriptPromptBuilder.php';
require_once __DIR__ . '/../../includes/ai/GeminiFormatter.php';
require_once __DIR__ . '/../../includes/ai/TranscriptRepository.php';

header('Content-Type: application/json');

requireLogin();
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');

$action = trim((string)($_REQUEST['action'] ?? ''));

// CSRF Protection for state-modifying POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
}

// Special Admin Action: Test Formatting with Sample Text
if ($action === 'test_format') {
    if (!isAdmin() && !hasRole(['qa_director','qa_manager'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $sampleText = trim((string)($_POST['sample_text'] ?? ''));
    if ($sampleText === '') {
        echo json_encode(['success' => false, 'error' => 'Sample raw transcript text is required.']);
        exit;
    }

    $overrideSettings = AIConfig::getSettings();
    if (isset($_POST['prompt_template'])) $overrideSettings['qa.ai.transcript_prompt'] = (string)$_POST['prompt_template'];
    if (isset($_POST['exclusions'])) $overrideSettings['qa.ai.exclusions'] = (string)$_POST['exclusions'];
    if (isset($_POST['replacements'])) $overrideSettings['qa.ai.replacements'] = (string)$_POST['replacements'];
    if (isset($_POST['exceptions'])) $overrideSettings['qa.ai.exceptions'] = (string)$_POST['exceptions'];

    $apiKey = trim((string)($overrideSettings['qa.ai.gemini_api_key'] ?? ''));
    if ($apiKey === '') {
        echo json_encode(['success' => false, 'error' => 'Gemini API key is missing in settings.']);
        exit;
    }

    $ruleEngine = new TranscriptRuleEngine();
    $filtered = $ruleEngine->process($sampleText, $overrideSettings);

    $client = new GeminiClient($apiKey);
    $formatModel = (string)($overrideSettings['qa.ai.formatting_model'] ?? 'gemini-1.5-flash');
    $formatter = new GeminiFormatter($client, $formatModel);
    $res = $formatter->format($filtered, $overrideSettings);

    if (!$res['success']) {
        echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Formatting failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'filtered_text' => $filtered,
        'formatted_text' => $res['formatted_transcript']
    ]);
    exit;
}

// QA Lead-specific actions
$leadId = (int)($_REQUEST['lead_id'] ?? 0);
if ($leadId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid lead ID.']);
    exit;
}

$lead = getLeadById($leadId);
if (!$lead) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Lead not found.']);
    exit;
}

// RBAC & Campaign Access Checks
if (!isQA() && !isAdmin() && !canUserAccessLead($user, $lead)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to this lead.']);
    exit;
}

$repo = new TranscriptRepository();

if ($action === 'get_status' || $action === 'get_transcript') {
    $transcripts = $repo->findByLead($leadId);
    if (empty($transcripts)) {
        // If recording exists but no job yet, create job on demand
        $recUrl = trim((string)($lead['recording_path'] ?? ''));
        $leadRecordings = function_exists('getLeadRecordings') ? getLeadRecordings($leadId) : [];
        if (!empty($leadRecordings)) {
            foreach ($leadRecordings as $rec) {
                $fId = (int)($rec['id'] ?? 0);
                $p = trim((string)($rec['file_path'] ?? ''));
                if ($p !== '') registerRecordingForTranscription($leadId, $fId, $p);
            }
            $transcripts = $repo->findByLead($leadId);
        } elseif ($recUrl !== '') {
            registerRecordingForTranscription($leadId, null, $recUrl);
            $transcripts = $repo->findByLead($leadId);
        }
    }

    $out = [];
    foreach ($transcripts as $t) {
        $out[] = [
            'id' => (int)$t['id'],
            'lead_id' => (int)$t['lead_id'],
            'file_id' => isset($t['file_id']) ? (int)$t['file_id'] : null,
            'audio_path' => (string)$t['audio_path'],
            'status' => (string)$t['status'],
            'raw_transcript' => (string)($t['raw_transcript'] ?? ''),
            'filtered_transcript' => (string)($t['filtered_transcript'] ?? ''),
            'formatted_transcript' => (string)($t['formatted_transcript'] ?? ''),
            'final_transcript' => (string)($t['final_transcript'] ?? ''),
            'error_message' => (string)($t['error_message'] ?? ''),
            'retry_count' => (int)($t['retry_count'] ?? 0),
            'reviewed_by' => isset($t['reviewed_by']) ? (int)$t['reviewed_by'] : null,
            'reviewed_at' => (string)($t['reviewed_at'] ?? ''),
            'created_at' => (string)($t['created_at'] ?? ''),
            'updated_at' => (string)($t['updated_at'] ?? '')
        ];
    }

    echo json_encode([
        'success' => true,
        'count' => count($out),
        'transcripts' => $out
    ]);
    exit;
}

if ($action === 'reformat') {
    $transcriptId = (int)($_POST['transcript_id'] ?? 0);
    $job = $repo->findById($transcriptId);
    if (!$job || (int)$job['lead_id'] !== $leadId) {
        echo json_encode(['success' => false, 'error' => 'Transcript job not found.']);
        exit;
    }

    $rawText = trim((string)($job['raw_transcript'] ?? ''));
    if ($rawText === '') {
        echo json_encode(['success' => false, 'error' => 'No raw transcript exists yet to re-format.']);
        exit;
    }

    $settings = AIConfig::getSettings();
    $apiKey = trim((string)($settings['qa.ai.gemini_api_key'] ?? ''));
    if ($apiKey === '') {
        echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured.']);
        exit;
    }

    // Process rule engine & formatter
    $ruleEngine = new TranscriptRuleEngine();
    $filtered = $ruleEngine->process($rawText, $settings);
    $repo->saveFilteredTranscript($transcriptId, $filtered);

    $client = new GeminiClient($apiKey);
    $formatModel = (string)($settings['qa.ai.formatting_model'] ?? 'gemini-1.5-flash');
    $formatter = new GeminiFormatter($client, $formatModel);
    $fRes = $formatter->format($filtered, $settings);

    if (!$fRes['success']) {
        echo json_encode(['success' => false, 'error' => $fRes['error'] ?? 'Re-formatting failed.']);
        exit;
    }

    $repo->saveFormattedTranscript($transcriptId, $fRes['formatted_transcript'], $fRes['prompt_snapshot'], $fRes['rules_snapshot'], $formatModel);

    $updated = $repo->findById($transcriptId);
    echo json_encode([
        'success' => true,
        'formatted_transcript' => $updated['formatted_transcript'],
        'updated_at' => $updated['updated_at']
    ]);
    exit;
}

if ($action === 'retry') {
    $transcriptId = (int)($_POST['transcript_id'] ?? 0);
    $job = $repo->findById($transcriptId);
    if (!$job || (int)$job['lead_id'] !== $leadId) {
        echo json_encode(['success' => false, 'error' => 'Transcript job not found.']);
        exit;
    }

    // Reset status to pending so background worker picks it up
    $repo->updateStatus($transcriptId, 'pending', null);
    $conn = getDbConnection();
    $conn->query("UPDATE lead_transcripts SET retry_count = 0 WHERE id = " . (int)$transcriptId);

    echo json_encode(['success' => true, 'message' => 'Transcript job reset to pending for retry.']);
    exit;
}

if ($action === 'save_final') {
    $transcriptId = (int)($_POST['transcript_id'] ?? 0);
    $finalText = (string)($_POST['final_transcript'] ?? '');

    $job = $repo->findById($transcriptId);
    if (!$job || (int)$job['lead_id'] !== $leadId) {
        echo json_encode(['success' => false, 'error' => 'Transcript job not found.']);
        exit;
    }

    $ok = $repo->saveFinalTranscript($transcriptId, $finalText, $userId);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Final transcript saved.' : 'Failed to save final transcript.'
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);
