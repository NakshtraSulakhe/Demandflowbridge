<?php
/**
 * DemandFlow Bridge - External Lead & Client Leads REST API
 * 
 * Allows external applications (such as Next.js frontends/backends, CRM integrations, etc.)
 * to fetch lead details and call recordings.
 * 
 * Features:
 * 1. Single Lead Lookup: Pass `id`, `lead_id`, `ref`, `reference`, `lead_code`.
 * 2. Client Leads Lookup: Pass `client_code`, `client_id`, or `client`.
 *    Returns all leads belonging to the specified client code along with call recordings.
 * 3. Search & Filtering: Filter by `qa_status`, `client_delivery_status`, `campaign_id`, `date_from`, `date_to`, `search`/`q`.
 * 4. Pagination: Support `page` and `limit` parameters (e.g. `limit=50`, `limit=all`).
 */

// Enable CORS for cross-origin requests
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
    // Include configuration and functions
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/constants.php';
    require_once __DIR__ . '/../includes/functions.php';

    // Parse input parameters from GET, POST, or JSON body
    $inputParams = $_GET;
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $jsonDecoded = json_decode($rawBody, true);
        if (is_array($jsonDecoded)) {
            $inputParams = array_merge($inputParams, $_POST, $jsonDecoded);
        } else {
            $inputParams = array_merge($inputParams, $_POST);
        }
    } else {
        $inputParams = array_merge($inputParams, $_POST);
    }

    // Extract client code parameter
    $clientCodeInput = '';
    $clientKeys = ['client_code', 'clientCode', 'client_id', 'clientId', 'client', 'account_code', 'account_id'];
    foreach ($clientKeys as $ck) {
        if (isset($inputParams[$ck]) && trim((string)$inputParams[$ck]) !== '') {
            $clientCodeInput = trim((string)$inputParams[$ck]);
            break;
        }
    }

    // Extract lead reference/ID parameter
    $leadIdInput = '';
    $leadKeys = ['ref', 'reference', 'ref_no', 'lead_ref', 'lead_code', 'lead_id', 'id'];
    foreach ($leadKeys as $lk) {
        if (isset($inputParams[$lk]) && trim((string)$inputParams[$lk]) !== '') {
            $leadIdInput = trim((string)$inputParams[$lk]);
            break;
        }
    }

    // Extract search / filter parameters
    $searchKey = trim((string)($inputParams['search'] ?? ($inputParams['q'] ?? '')));
    $qaStatusFilter = trim((string)($inputParams['qa_status'] ?? ($inputParams['status'] ?? '')));
    $deliveryStatusFilter = trim((string)($inputParams['client_delivery_status'] ?? ($inputParams['delivery_status'] ?? '')));
    $campaignIdFilter = isset($inputParams['campaign_id']) ? (int)$inputParams['campaign_id'] : 0;
    $dateFromFilter = trim((string)($inputParams['date_from'] ?? ($inputParams['start_date'] ?? '')));
    $dateToFilter = trim((string)($inputParams['date_to'] ?? ($inputParams['end_date'] ?? '')));

    // Quick time period helpers (days, period, timeframe)
    $daysInput = isset($inputParams['days']) ? (int)$inputParams['days'] : 0;
    $periodInput = strtolower(trim((string)($inputParams['period'] ?? ($inputParams['timeframe'] ?? ''))));

    if ($daysInput > 0 && $dateFromFilter === '') {
        $dateFromFilter = date('Y-m-d', strtotime("-{$daysInput} days"));
    }

    if ($periodInput !== '' && $dateFromFilter === '' && $dateToFilter === '') {
        switch ($periodInput) {
            case 'today':
                $dateFromFilter = date('Y-m-d');
                $dateToFilter = date('Y-m-d');
                break;
            case 'yesterday':
                $dateFromFilter = date('Y-m-d', strtotime('-1 day'));
                $dateToFilter = date('Y-m-d', strtotime('-1 day'));
                break;
            case 'this_week':
                $dateFromFilter = date('Y-m-d', strtotime('monday this week'));
                $dateToFilter = date('Y-m-d');
                break;
            case 'this_month':
                $dateFromFilter = date('Y-m-01');
                $dateToFilter = date('Y-m-d');
                break;
            case 'last_month':
                $dateFromFilter = date('Y-m-01', strtotime('first day of last month'));
                $dateToFilter = date('Y-m-t', strtotime('last day of last month'));
                break;
            case 'all_time':
            case 'all':
                $dateFromFilter = '';
                $dateToFilter = '';
                break;
        }
    }

    // Pagination parameters
    $page = isset($inputParams['page']) ? max(1, (int)$inputParams['page']) : 1;
    $limitRaw = strtolower(trim((string)($inputParams['limit'] ?? '50')));
    if ($limitRaw === 'all' || $limitRaw === '0' || $limitRaw === '-1') {
        $limit = 1000; // Cap at 1000 for safety
    } else {
        $limit = max(1, min(1000, (int)$limitRaw));
    }
    $offset = ($page - 1) * $limit;

    // Check if missing both lead_id and client_code and search keyword
    if ($clientCodeInput === '' && $leadIdInput === '' && $searchKey === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing required parameter. Please provide "client_code" (or "client_id" / "client") to get leads for a client, or "lead_id" (or "id" / "ref") to get a specific lead.'
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    $conn = getDbConnection();

    // Dynamically construct base URL matching caller's domain/host for recording stream links
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (str_contains($scriptName, '/api/')) {
        $apiPos = strpos($scriptName, '/api/');
        $baseFolder = substr($scriptName, 0, $apiPos);
        $baseUrl = $scheme . '://' . $host . rtrim($baseFolder, '/');
    } else {
        $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : $scheme . '://' . $host . '/demandflowbridge';
    }
    $streamApiUrl = $baseUrl . '/api/get_recording.php';

    // Helper function to format lead row into full lead API object with recordings
    $formatLeadObject = function($lead, $allRecordingsMap = null) use ($conn, $streamApiUrl) {
        $leadDbId = (int)($lead['id'] ?? 0);
        $campaignId = (int)($lead['campaign_id'] ?? 0);

        // Fetch dynamic lead table data if campaign_id & db id exist
        if ($campaignId > 0 && $leadDbId > 0 && function_exists('getLeadByIdDynamic')) {
            $dynLead = getLeadByIdDynamic($leadDbId, $campaignId);
            if (!empty($dynLead) && is_array($dynLead)) {
                $lead = array_merge($dynLead, $lead);
            }
        }

        // Enrich lead row if function exists
        if (function_exists('enrichLeadRow')) {
            $lead = enrichLeadRow($lead);
        }

        // Fetch recordings
        $rawRecordings = [];
        if ($allRecordingsMap !== null && isset($allRecordingsMap[$leadDbId])) {
            $rawRecordings = $allRecordingsMap[$leadDbId];
        } elseif ($leadDbId > 0 && function_exists('getLeadRecordings')) {
            $rawRecordings = getLeadRecordings($leadDbId);
        }

        $processedRecordings = [];
        foreach ($rawRecordings as $r) {
            $path = trim((string)($r['file_path'] ?? ''));
            $fid = (int)($r['id'] ?? 0);
            if ($path !== '' && strtolower($path) !== 'null') {
                $url = $streamApiUrl . '?file=' . rawurlencode($path);
                if ($fid > 0) {
                    $url .= '&id=' . $fid;
                }
                $processedRecordings[] = array_merge($r, [
                    'url' => $url,
                    'download_url' => $url . '&download=1'
                ]);
            }
        }

        // Primary recording check
        $primaryPath = trim((string)($lead['recording_path'] ?? ($lead['recording_file_path'] ?? '')));
        $primaryUrl = '';
        if ($primaryPath !== '' && strtolower($primaryPath) !== 'null') {
            $primaryUrl = $streamApiUrl . '?file=' . rawurlencode($primaryPath);
            
            // Ensure primary recording is in recordings list
            $alreadyInList = false;
            foreach ($processedRecordings as $pr) {
                if (($pr['file_path'] ?? '') === $primaryPath) {
                    $alreadyInList = true;
                    break;
                }
            }
            if (!$alreadyInList) {
                array_unshift($processedRecordings, [
                    'id' => 0,
                    'file_path' => $primaryPath,
                    'uploaded_at' => $lead['created_at'] ?? null,
                    'url' => $primaryUrl,
                    'download_url' => $primaryUrl . '&download=1'
                ]);
            }
        }

        // Disk fallback check if no DB recording found
        if ($leadDbId > 0 && empty($processedRecordings)) {
            $uploadDir = realpath(__DIR__ . '/../uploads/lead_files');
            if ($uploadDir && is_dir($uploadDir)) {
                $globPattern = $uploadDir . DIRECTORY_SEPARATOR . 'L' . $leadDbId . '_*';
                $matchingFiles = glob($globPattern);
                if (!empty($matchingFiles)) {
                    foreach ($matchingFiles as $mf) {
                        $relP = 'uploads/lead_files/' . basename($mf);
                        $urlP = $streamApiUrl . '?file=' . rawurlencode($relP);
                        $processedRecordings[] = [
                            'id' => 0,
                            'file_path' => $relP,
                            'uploaded_at' => date('Y-m-d H:i:s', filemtime($mf)),
                            'url' => $urlP,
                            'download_url' => $urlP . '&download=1'
                        ];
                    }
                }
            }
        }

        if ($primaryUrl === '' && !empty($processedRecordings)) {
            $primaryUrl = $processedRecordings[0]['url'];
            $primaryPath = $processedRecordings[0]['file_path'];
        }

        // Fetch dynamic form submission answers
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
        $refNumber = (string)($lead['lead_id'] ?? ($lead['lead_code'] ?? ($lead['id'] ?? '')));

        return [
            'id' => $leadDbId,
            'lead_reference_number' => $refNumber,
            'lead_id' => $refNumber,
            'campaign_id' => $campaignId > 0 ? $campaignId : null,
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
        ];
    };

    // SCENARIO 1: CLIENT CODE IS PROVIDED (OR SEARCH QUERY) -> Return list of leads with recordings
    if ($clientCodeInput !== '' || ($leadIdInput === '' && $searchKey !== '')) {
        
        $resolvedClientId = is_numeric($clientCodeInput) ? (int)$clientCodeInput : 0;
        $resolvedClientCode = $clientCodeInput;
        $clientMeta = [
            'client_code' => $clientCodeInput,
            'name' => ''
        ];

        // Resolve client metadata from `clients` table if available
        if ($clientCodeInput !== '') {
            $stmtCl = $conn->prepare("
                SELECT id, client_code, name 
                FROM clients 
                WHERE client_code = ? OR (id = ? AND ? > 0) OR name = ?
                LIMIT 1
            ");
            if ($stmtCl) {
                $stmtCl->bind_param("siis", $clientCodeInput, $resolvedClientId, $resolvedClientId, $clientCodeInput);
                $stmtCl->execute();
                $clRow = $stmtCl->get_result()->fetch_assoc();
                $stmtCl->close();
                if ($clRow) {
                    $resolvedClientId = (int)$clRow['id'];
                    $resolvedClientCode = (string)$clRow['client_code'];
                    $clientMeta = [
                        'id' => $resolvedClientId,
                        'client_code' => $resolvedClientCode,
                        'name' => (string)$clRow['name']
                    ];
                }
            }
        }

        // Build WHERE clauses for SQL query
        $whereClauses = [];
        $params = [];
        $types = "";

        if ($clientCodeInput !== '') {
            $whereClauses[] = "(
                (d.client_code = ?)
                OR (d.client_id = ? AND ? > 0)
                OR (l.client_id = ? AND ? > 0)
                OR (cl.client_code = ?)
                OR (cl.id = ? AND ? > 0)
            )";
            $params[] = $resolvedClientCode;
            $params[] = $resolvedClientId;
            $params[] = $resolvedClientId;
            $params[] = $resolvedClientId;
            $params[] = $resolvedClientId;
            $params[] = $resolvedClientCode;
            $params[] = $resolvedClientId;
            $params[] = $resolvedClientId;
            $types .= "siiiisii";
        }

        // Specific lead ID under client
        if ($leadIdInput !== '') {
            $intLeadId = is_numeric($leadIdInput) ? (int)$leadIdInput : 0;
            $whereClauses[] = "(l.lead_id = ? OR (l.id = ? AND ? > 0))";
            $params[] = $leadIdInput;
            $params[] = $intLeadId;
            $params[] = $intLeadId;
            $types .= "sii";
        }

        // Filter: QA Status
        if ($qaStatusFilter !== '') {
            $mappedQa = $qaStatusFilter;
            if (strtolower($qaStatusFilter) === 'approved') $mappedQa = 'Qualified';
            if (strtolower($qaStatusFilter) === 'rejected') $mappedQa = 'Disqualified';
            $whereClauses[] = "l.qa_status = ?";
            $params[] = $mappedQa;
            $types .= "s";
        }

        // Filter: Client Delivery Status
        if ($deliveryStatusFilter !== '') {
            $whereClauses[] = "l.client_delivery_status = ?";
            $params[] = $deliveryStatusFilter;
            $types .= "s";
        }

        // Filter: Campaign ID
        if ($campaignIdFilter > 0) {
            $whereClauses[] = "l.campaign_id = ?";
            $params[] = $campaignIdFilter;
            $types .= "i";
        }

        // Filter: Search keyword
        if ($searchKey !== '') {
            $whereClauses[] = "(l.lead_id LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ? OR l.contact_phone LIKE ?)";
            $likeSearch = "%" . $searchKey . "%";
            for ($i = 0; $i < 6; $i++) {
                $params[] = $likeSearch;
                $types .= "s";
            }
        }

        // Filter: Date Range
        if ($dateFromFilter !== '') {
            $whereClauses[] = "l.created_at >= ?";
            $params[] = $dateFromFilter . (strlen($dateFromFilter) === 10 ? ' 00:00:00' : '');
            $types .= "s";
        }
        if ($dateToFilter !== '') {
            $whereClauses[] = "l.created_at <= ?";
            $params[] = $dateToFilter . (strlen($dateToFilter) === 10 ? ' 23:59:59' : '');
            $types .= "s";
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        // 1. Get total leads count
        $countSql = "
            SELECT COUNT(DISTINCT l.id) AS total_count
            FROM leads l
            LEFT JOIN campaigns c ON l.campaign_id = c.id
            LEFT JOIN campaign_details d ON d.campaign_id = c.id
            LEFT JOIN clients cl ON (cl.id = l.client_id OR cl.client_code = d.client_code)
            {$whereSql}
        ";
        $stmtCount = $conn->prepare($countSql);
        if ($stmtCount && !empty($types)) {
            $stmtCount->bind_param($types, ...$params);
        }
        if ($stmtCount) {
            $stmtCount->execute();
            $totalLeads = (int)($stmtCount->get_result()->fetch_assoc()['total_count'] ?? 0);
            $stmtCount->close();
        } else {
            $totalLeads = 0;
        }

        // 2. Fetch leads list for current page
        $selectSql = "
            SELECT DISTINCT l.*,
                   c.name AS campaign_name,
                   u.full_name AS agent_name,
                   r.full_name AS reviewer_name
            FROM leads l
            LEFT JOIN campaigns c ON l.campaign_id = c.id
            LEFT JOIN campaign_details d ON d.campaign_id = c.id
            LEFT JOIN clients cl ON (cl.id = l.client_id OR cl.client_code = d.client_code)
            LEFT JOIN users u ON l.agent_id = u.id
            LEFT JOIN users r ON l.qa_reviewed_by = r.id
            {$whereSql}
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT ? OFFSET ?
        ";

        $selectParams = $params;
        $selectParams[] = $limit;
        $selectParams[] = $offset;
        $selectTypes = $types . "ii";

        $stmtSelect = $conn->prepare($selectSql);
        if ($stmtSelect) {
            $stmtSelect->bind_param($selectTypes, ...$selectParams);
            $stmtSelect->execute();
            $leadRows = $stmtSelect->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtSelect->close();
        } else {
            $leadRows = [];
        }

        // Batch fetch all recordings for retrieved leads in 1 query
        $allRecordingsMap = [];
        if (!empty($leadRows)) {
            $leadDbIds = array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $leadRows));
            if (!empty($leadDbIds)) {
                $inClause = implode(',', $leadDbIds);
                $recRes = $conn->query("
                    SELECT id, lead_id, file_path, uploaded_at 
                    FROM lead_files 
                    WHERE lead_id IN ({$inClause}) AND field_id = 'call_recording'
                    ORDER BY uploaded_at ASC, id ASC
                ");
                if ($recRes) {
                    while ($recRow = $recRes->fetch_assoc()) {
                        $lid = (int)$recRow['lead_id'];
                        if (!isset($allRecordingsMap[$lid])) {
                            $allRecordingsMap[$lid] = [];
                        }
                        $allRecordingsMap[$lid][] = $recRow;
                    }
                }
            }
        }

        // Format all leads with audio recordings
        $formattedLeads = [];
        foreach ($leadRows as $row) {
            $formattedLeads[] = $formatLeadObject($row, $allRecordingsMap);
        }

        $totalPages = $totalLeads > 0 ? (int)ceil($totalLeads / $limit) : 0;

        echo json_encode([
            'status' => 'success',
            'client' => $clientMeta,
            'total' => $totalLeads,
            'count' => count($formattedLeads),
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'leads' => $formattedLeads
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // SCENARIO 2: SINGLE LEAD LOOKUP BY ID / REFERENCE NUMBER
    $lead = null;
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
        $intId = is_numeric($leadIdInput) ? (int)$leadIdInput : 0;
        $stmt->bind_param("sii", $leadIdInput, $intId, $intId);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // Fallbacks if not found
    if (empty($lead) && function_exists('getLeadByCode')) {
        $lead = getLeadByCode($leadIdInput);
    }
    if (empty($lead) && is_numeric($leadIdInput) && (int)$leadIdInput > 0 && function_exists('getLeadById')) {
        $lead = getLeadById((int)$leadIdInput);
    }

    if (empty($lead)) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Lead not found for reference number: ' . $leadIdInput
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    $formattedSingleLead = $formatLeadObject($lead);

    echo json_encode([
        'status' => 'success',
        'lead' => $formattedSingleLead
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
