<?php
/**
 * DemandFlow Bridge - Live CRM Webhook Lead Receiver Endpoint
 * 
 * Target Path: /api/v1/webhook_lead.php
 * 
 * Description:
 * Receives live lead creation and update events via HTTP POST from external CRMs or integrations.
 * Performs database upserts (Insert or Update if exists) to update DemandFlow Bridge in real-time
 * without needing continuous REST polling.
 * 
 * Security:
 * Supply secret key via query parameter `?secret=YOUR_SECRET` or HTTP Header `X-Webhook-Secret: YOUR_SECRET`.
 * 
 * Supported Input Formats:
 * - Single JSON Lead Object
 * - Array of JSON Lead Objects (Batch Sync)
 */

// Enable CORS for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Webhook-Secret, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Disable HTML error rendering in JSON API response
ini_set('display_errors', 0);

// Default Webhook Secret Key (Can be overridden in config or request)
if (!defined('WEBHOOK_SECRET_KEY')) {
    define('WEBHOOK_SECRET_KEY', 'whsec_demandflow_live_sync_2026');
}

try {
    // Include database connection configuration
    require_once __DIR__ . '/../../config/database.php';

    // 1. Authenticate Request
    $providedSecret = trim((string)(
        $_GET['secret'] 
        ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] 
        ?? $_SERVER['HTTP_X_API_KEY'] 
        ?? ''
    ));

    // Optional Bearer token extraction
    if (empty($providedSecret) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $providedSecret = trim($matches[1]);
        }
    }

    // Verify Secret Key (Skip check if WEBHOOK_SECRET_KEY is empty string for development)
    if (WEBHOOK_SECRET_KEY !== '' && !hash_equals(WEBHOOK_SECRET_KEY, $providedSecret)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthorized: Invalid or missing Webhook Secret key.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // 2. Read Payload
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Bad Request: Received empty payload body.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    $decodedData = json_decode($rawInput, true);
    if (!is_array($decodedData)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Bad Request: Invalid JSON syntax in payload.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // Detect if payload is a single object or batch array
    $leadItems = [];
    if (isset($decodedData[0]) && is_array($decodedData[0])) {
        $leadItems = $decodedData;
    } elseif (isset($decodedData['data']) && is_array($decodedData['data'])) {
        $leadItems = isset($decodedData['data'][0]) ? $decodedData['data'] : [$decodedData['data']];
    } else {
        $leadItems = [$decodedData];
    }

    if (empty($leadItems)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Bad Request: No lead data items found in payload.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    $conn = getDbConnection();

    // Prepared SQL query for Database Upsert (Insert or Update if lead_id exists)
    $upsertSql = "
        INSERT INTO leads (
            lead_id,
            campaign_id,
            client_id,
            agent_id,
            first_name,
            last_name,
            email,
            contact_phone,
            job_title,
            company_name,
            company_size,
            industry,
            country,
            linkedin_link,
            company_linkedin,
            qa_status,
            client_delivery_status,
            form_done,
            software_implementation_timeline,
            lead_comment,
            recording_path,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            campaign_id = IF(VALUES(campaign_id) > 0, VALUES(campaign_id), campaign_id),
            client_id = IF(VALUES(client_id) > 0, VALUES(client_id), client_id),
            agent_id = IF(VALUES(agent_id) > 0, VALUES(agent_id), agent_id),
            first_name = IF(VALUES(first_name) != '', VALUES(first_name), first_name),
            last_name = IF(VALUES(last_name) != '', VALUES(last_name), last_name),
            email = IF(VALUES(email) != '', VALUES(email), email),
            contact_phone = IF(VALUES(contact_phone) != '', VALUES(contact_phone), contact_phone),
            job_title = IF(VALUES(job_title) != '', VALUES(job_title), job_title),
            company_name = IF(VALUES(company_name) != '', VALUES(company_name), company_name),
            company_size = IF(VALUES(company_size) != '', VALUES(company_size), company_size),
            industry = IF(VALUES(industry) != '', VALUES(industry), industry),
            country = IF(VALUES(country) != '', VALUES(country), country),
            linkedin_link = IF(VALUES(linkedin_link) != '', VALUES(linkedin_link), linkedin_link),
            company_linkedin = IF(VALUES(company_linkedin) != '', VALUES(company_linkedin), company_linkedin),
            qa_status = IF(VALUES(qa_status) != '', VALUES(qa_status), qa_status),
            client_delivery_status = IF(VALUES(client_delivery_status) != '', VALUES(client_delivery_status), client_delivery_status),
            form_done = IF(VALUES(form_done) != '', VALUES(form_done), form_done),
            software_implementation_timeline = IF(VALUES(software_implementation_timeline) != '', VALUES(software_implementation_timeline), software_implementation_timeline),
            lead_comment = IF(VALUES(lead_comment) != '', VALUES(lead_comment), lead_comment),
            recording_path = IF(VALUES(recording_path) != '', VALUES(recording_path), recording_path),
            updated_at = NOW()
    ";

    $stmt = $conn->prepare($upsertSql);
    if (!$stmt) {
        throw new Exception("Database statement preparation failed: " . $conn->error);
    }

    $processedCount = 0;
    $processedLeads = [];
    $errors = [];

    foreach ($leadItems as $idx => $item) {
        // Extract Lead Reference Code / ID
        $leadRef = trim((string)(
            $item['lead_id'] 
            ?? $item['lead_reference_number'] 
            ?? $item['lead_code'] 
            ?? $item['reference'] 
            ?? $item['ref'] 
            ?? $item['id'] 
            ?? ''
        ));

        if ($leadRef === '') {
            $errors[] = "Item index {$idx}: Missing lead_id reference.";
            continue;
        }

        $campaignId = isset($item['campaign_id']) ? (int)$item['campaign_id'] : 0;
        $clientId   = isset($item['client_id']) ? (int)$item['client_id'] : 0;
        $agentId    = isset($item['agent_id']) ? (int)$item['agent_id'] : 0;

        $firstName   = trim((string)($item['first_name'] ?? ''));
        $lastName    = trim((string)($item['last_name'] ?? ''));
        $email       = trim((string)($item['email'] ?? ''));
        $phone       = trim((string)($item['contact_phone'] ?? $item['phone'] ?? ''));
        $jobTitle    = trim((string)($item['job_title'] ?? $item['title'] ?? ''));
        $companyName = trim((string)($item['company_name'] ?? $item['company'] ?? ''));
        $companySize = trim((string)($item['company_size'] ?? ''));
        $industry    = trim((string)($item['industry'] ?? ''));
        $country     = trim((string)($item['country'] ?? ''));
        $linkedin    = trim((string)($item['linkedin_link'] ?? ''));
        $compLinkedin= trim((string)($item['company_linkedin'] ?? ''));
        
        $qaStatus    = trim((string)($item['qa_status'] ?? $item['quality_status'] ?? 'Pending'));
        // Normalize QA status if needed
        if (strtolower($qaStatus) === 'approved') { $qaStatus = 'Qualified'; }
        if (strtolower($qaStatus) === 'rejected') { $qaStatus = 'Disqualified'; }

        $deliveryStatus = trim((string)($item['client_delivery_status'] ?? $item['delivery_status'] ?? 'Pending'));
        $formDone       = trim((string)($item['form_done'] ?? 'No'));
        $timeline       = trim((string)($item['software_implementation_timeline'] ?? $item['timeline'] ?? ''));
        $leadComment    = trim((string)($item['lead_comment'] ?? $item['comment'] ?? ''));
        $recordingPath  = trim((string)($item['recording_path'] ?? $item['recording_url'] ?? ''));

        $stmt->bind_param(
            "siiisssssssssssssssss",
            $leadRef,
            $campaignId,
            $clientId,
            $agentId,
            $firstName,
            $lastName,
            $email,
            $phone,
            $jobTitle,
            $companyName,
            $companySize,
            $industry,
            $country,
            $linkedin,
            $compLinkedin,
            $qaStatus,
            $deliveryStatus,
            $formDone,
            $timeline,
            $leadComment,
            $recordingPath
        );

        if ($stmt->execute()) {
            $processedCount++;
            $processedLeads[] = [
                'lead_id' => $leadRef,
                'status' => 'processed'
            ];
        } else {
            $errors[] = "Item {$leadRef}: Execution error - " . $stmt->error;
        }
    }

    $stmt->close();

    // 3. Return JSON Response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "Successfully processed {$processedCount} lead(s) via webhook.",
        'processed_count' => $processedCount,
        'leads' => $processedLeads,
        'errors' => $errors
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error processing webhook: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
