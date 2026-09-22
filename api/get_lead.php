<?php
/**
 * DemandFlow Bridge - External Lead Details REST API
 * 
 * Allows external applications (such as Next.js frontends/backends) to fetch
 * complete lead details by passing any Lead Reference Number (e.g. LD20260922C0ED0E)
 * or numeric Lead ID.
 * 
 * NOTE: This is a standalone file that does NOT modify any existing codebase file.
 */

// Enable CORS for cross-origin requests (Next.js client or server)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Disable direct HTML error rendering in JSON response
ini_set('display_errors', 0);

try {
    // Include existing configuration and functions safely
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/constants.php';
    require_once __DIR__ . '/../includes/functions.php';

    // Parse lead reference number or ID from query parameters or POST body JSON
    $rawId = '';
    $possibleKeys = ['ref', 'reference', 'ref_no', 'lead_ref', 'lead_code', 'lead_id', 'id', 'search', 'q'];
    
    foreach ($possibleKeys as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $rawId = trim((string)$_GET[$key]);
            break;
        }
    }

    if ($rawId === '') {
        $inputRaw = file_get_contents('php://input');
        if (!empty($inputRaw)) {
            $jsonInput = json_decode($inputRaw, true);
            if (is_array($jsonInput)) {
                foreach ($possibleKeys as $key) {
                    if (isset($jsonInput[$key]) && trim((string)$jsonInput[$key]) !== '') {
                        $rawId = trim((string)$jsonInput[$key]);
                        break;
                    }
                }
            }
        }
    }

    if ($rawId === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing lead reference number. Please provide "id" or "lead_id" or "reference" parameter.'
        ]);
        exit;
    }

    $lead = null;
    $conn = getDbConnection();

    // 1. Direct query matching lead_id (reference number) or numeric database id
    $stmt = $conn->prepare("
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.lead_id = ? OR (l.id = ? AND ? > 0)
        LIMIT 1
    ");
    if ($stmt) {
        $intId = is_numeric($rawId) ? (int)$rawId : 0;
        $stmt->bind_param("sii", $rawId, $intId, $intId);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // 2. Try fetching dynamic lead table data if campaign_id & db id exist
    if (!empty($lead['campaign_id']) && !empty($lead['id']) && function_exists('getLeadByIdDynamic')) {
        $dynLead = getLeadByIdDynamic((int)$lead['id'], (int)$lead['campaign_id']);
        if (!empty($dynLead) && is_array($dynLead)) {
            $lead = array_merge($dynLead, $lead);
        }
    }

    // 3. Fallback: Try helper getLeadByCode if still not found
    if (empty($lead) && function_exists('getLeadByCode')) {
        $lead = getLeadByCode($rawId);
    }

    // 4. Fallback: Try getLeadById if input is numeric
    if (empty($lead) && is_numeric($rawId) && (int)$rawId > 0 && function_exists('getLeadById')) {
        $lead = getLeadById((int)$rawId);
    }

    if (empty($lead)) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Lead not found for reference number: ' . $rawId
        ]);
        exit;
    }

    // Enrich lead row if function exists
    if (function_exists('enrichLeadRow')) {
        $lead = enrichLeadRow($lead);
    }

    // Fetch call recordings
    $leadDbId = (int)($lead['id'] ?? 0);
    $recordings = ($leadDbId > 0 && function_exists('getLeadRecordings')) ? getLeadRecordings($leadDbId) : [];

    // Construct base URL for absolute recording links
    $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/demandflowbridge';
    $processedRecordings = [];
    foreach ($recordings as $r) {
        $path = trim((string)($r['file_path'] ?? ''));
        if ($path !== '' && strtolower($path) !== 'null') {
            $url = preg_match('/^https?:\/\//i', $path) ? $path : $baseUrl . '/' . ltrim($path, '/');
            $processedRecordings[] = array_merge($r, [
                'url' => $url,
                'download_url' => $url
            ]);
        }
    }

    $primaryPath = trim((string)($lead['recording_path'] ?? ''));
    $primaryUrl = '';
    if ($primaryPath !== '' && strtolower($primaryPath) !== 'null') {
        $primaryUrl = preg_match('/^https?:\/\//i', $primaryPath) ? $primaryPath : $baseUrl . '/' . ltrim($primaryPath, '/');
    }

    // Fetch dynamic form submission answers
    $campaignId = (int)($lead['campaign_id'] ?? 0);
    $formData = [];
    if ($leadDbId > 0 && $campaignId > 0 && function_exists('getLatestFormSubmissionForLead')) {
        $submission = getLatestFormSubmissionForLead($leadDbId, $campaignId);
        if (!empty($submission['data']) && is_array($submission['data'])) {
            $formData = $submission['data'];
        }
    }

    // Quality status mapping
    $qaStatus = $lead['qa_status'] ?? 'Pending';
    $qualityStatus = 'pending';
    switch ($qaStatus) {
        case 'Qualified':
        case 'Rectified':
            $qualityStatus = 'approved';
            break;
        case 'Disqualified':
        case 'Duplicate':
            $qualityStatus = 'rejected';
            break;
        default:
            $qualityStatus = 'pending';
    }

    $firstName = (string)($lead['first_name'] ?? '');
    $lastName = (string)($lead['last_name'] ?? '');
    $fullName = trim($firstName . ' ' . $lastName);
    $refNumber = (string)($lead['lead_id'] ?? ($lead['lead_code'] ?? $rawId));

    // Build success response
    echo json_encode([
        'status' => 'success',
        'lead' => [
            'id' => $leadDbId,
            'lead_reference_number' => $refNumber,
            'lead_id' => $refNumber,
            'campaign_id' => isset($lead['campaign_id']) ? (int)$lead['campaign_id'] : null,
            'campaign_name' => (string)($lead['campaign_name'] ?? ''),
            'agent_id' => isset($lead['agent_id']) ? (int)$lead['agent_id'] : null,
            'agent_name' => (string)($lead['agent_name'] ?? ''),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'email' => (string)($lead['email'] ?? ''),
            'phone' => (string)($lead['contact_phone'] ?? ($lead['phone'] ?? '')),
            'contact_phone' => (string)($lead['contact_phone'] ?? ($lead['phone'] ?? '')),
            'job_title' => (string)($lead['job_title'] ?? ''),
            'company_name' => (string)($lead['company_name'] ?? ''),
            'company_size' => (string)($lead['company_size'] ?? ''),
            'industry' => (string)($lead['industry'] ?? ''),
            'country' => (string)($lead['country'] ?? ''),
            'linkedin_link' => (string)($lead['linkedin_link'] ?? ''),
            'company_linkedin' => (string)($lead['company_linkedin'] ?? ''),
            'qa_status' => $qaStatus,
            'quality_status' => $qualityStatus,
            'quality_comments' => (string)($lead['qa_comment'] ?? ''),
            'quality_client_comments' => (string)($lead['qa_client_comment'] ?? ''),
            'client_delivery_status' => (string)($lead['client_delivery_status'] ?? 'Pending'),
            'qa_reviewed_by' => isset($lead['qa_reviewed_by']) ? (int)$lead['qa_reviewed_by'] : null,
            'reviewer_name' => (string)($lead['reviewer_name'] ?? ''),
            'form_done' => function_exists('normalizeFormDone') ? normalizeFormDone((string)($lead['form_done'] ?? 'No')) : ($lead['form_done'] ?? 'No'),
            'form_filled_time' => $lead['form_filled_time'] ?? null,
            'software_implementation_timeline' => (string)($lead['software_implementation_timeline'] ?? ($lead['implementation_timeline'] ?? '')),
            'lead_comment' => (string)($lead['lead_comment'] ?? ''),
            'recording_path' => $primaryPath,
            'recording_url' => $primaryUrl,
            'recordings' => $processedRecordings,
            'form_data' => $formData,
            'created_at' => $lead['created_at'] ?? null,
            'updated_at' => $lead['updated_at'] ?? null,
            'raw_lead_data' => $lead
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
