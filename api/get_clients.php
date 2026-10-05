<?php
/**
 * DemandFlow Bridge - REST API for Clients & Client Campaigns
 * 
 * Allows external applications (Next.js, React, CRM, mobile apps, reporting tools)
 * to fetch all client codes along with all campaigns created under each client.
 * 
 * Features:
 * 1. Fetch All Clients & Campaigns: Returns hierarchical list of clients and their campaigns.
 * 2. Fetch Single Client: Pass `client_code` (or `client_id`, `client`, `account_code`) to get a specific client's details and campaigns.
 * 3. Search & Filter: Filter by `search`/`q` (matches client name/code or campaign name/code) and campaign `status`.
 * 4. Pagination: Supports `page` and `limit` parameters.
 * 5. Lead Counts: Includes total leads count and qualified leads count for each campaign.
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
    // Include database connection and core functions
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

    // Extract client filter parameter if provided
    $clientCodeInput = '';
    $clientKeys = ['client_code', 'clientCode', 'client_id', 'clientId', 'client', 'account_code', 'account_id', 'code'];
    foreach ($clientKeys as $ck) {
        if (isset($inputParams[$ck]) && trim((string)$inputParams[$ck]) !== '') {
            $clientCodeInput = trim((string)$inputParams[$ck]);
            break;
        }
    }

    // Filter parameters
    $searchKey = trim((string)($inputParams['search'] ?? ($inputParams['q'] ?? '')));
    $statusFilter = trim((string)($inputParams['status'] ?? ($inputParams['campaign_status'] ?? '')));
    $includeEmpty = isset($inputParams['include_empty']) ? filter_var($inputParams['include_empty'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false : true;
    $includeStats = isset($inputParams['include_stats']) ? filter_var($inputParams['include_stats'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false : true;

    // Pagination parameters
    $page = isset($inputParams['page']) ? max(1, (int)$inputParams['page']) : 1;
    $limitRaw = strtolower(trim((string)($inputParams['limit'] ?? '100')));
    if ($limitRaw === 'all' || $limitRaw === '0' || $limitRaw === '-1') {
        $limit = 1000; // Cap at 1000 for safety
    } else {
        $limit = max(1, min(1000, (int)$limitRaw));
    }
    $offset = ($page - 1) * $limit;

    $conn = getDbConnection();

    // 1. Fetch Clients from database
    $clientWhereClauses = [];
    $clientParams = [];
    $clientTypes = "";

    if ($clientCodeInput !== '') {
        $intClientId = is_numeric($clientCodeInput) ? (int)$clientCodeInput : 0;
        $clientWhereClauses[] = "(c.client_code = ? OR (c.id = ? AND ? > 0) OR c.name = ?)";
        $clientParams[] = $clientCodeInput;
        $clientParams[] = $intClientId;
        $clientParams[] = $intClientId;
        $clientParams[] = $clientCodeInput;
        $clientTypes .= "siis";
    }

    if ($searchKey !== '') {
        $clientWhereClauses[] = "(c.name LIKE ? OR c.client_code LIKE ? OR EXISTS (
            SELECT 1 FROM campaign_details cd2 
            JOIN campaigns cmp2 ON cmp2.id = cd2.campaign_id
            WHERE (cd2.client_id = c.id OR cd2.client_code = c.client_code)
            AND (cmp2.name LIKE ? OR cd2.code LIKE ?)
        ))";
        $likeSearch = "%" . $searchKey . "%";
        $clientParams[] = $likeSearch;
        $clientParams[] = $likeSearch;
        $clientParams[] = $likeSearch;
        $clientParams[] = $likeSearch;
        $clientTypes .= "ssss";
    }

    $clientWhereSql = !empty($clientWhereClauses) ? "WHERE " . implode(" AND ", $clientWhereClauses) : "";

    // Count total clients matching query
    $countSql = "SELECT COUNT(DISTINCT c.id) AS total_count FROM clients c {$clientWhereSql}";
    $stmtCount = $conn->prepare($countSql);
    if ($stmtCount && !empty($clientTypes)) {
        $stmtCount->bind_param($clientTypes, ...$clientParams);
    }
    if ($stmtCount) {
        $stmtCount->execute();
        $totalClients = (int)($stmtCount->get_result()->fetch_assoc()['total_count'] ?? 0);
        $stmtCount->close();
    } else {
        $totalClients = 0;
    }

    // Select page of clients
    $selectClientSql = "
        SELECT c.id, c.client_code, c.name, c.website, c.industry, c.notes, c.created_at, c.updated_at
        FROM clients c
        {$clientWhereSql}
        ORDER BY c.name ASC, c.id ASC
        LIMIT ? OFFSET ?
    ";

    $selectClientParams = $clientParams;
    $selectClientParams[] = $limit;
    $selectClientParams[] = $offset;
    $selectClientTypes = $clientTypes . "ii";

    $stmtClientSelect = $conn->prepare($selectClientSql);
    $clientsList = [];
    if ($stmtClientSelect) {
        $stmtClientSelect->bind_param($selectClientTypes, ...$selectClientParams);
        $stmtClientSelect->execute();
        $clientsList = $stmtClientSelect->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtClientSelect->close();
    }

    // Fallback: If searching or looking up single client code not in `clients` table yet, check `campaign_details`
    if (empty($clientsList) && $clientCodeInput !== '') {
        $stmtFallback = $conn->prepare("
            SELECT DISTINCT cd.client_code, cd.client_id
            FROM campaign_details cd
            WHERE cd.client_code = ? OR (cd.client_id = ? AND ? > 0)
            LIMIT 1
        ");
        if ($stmtFallback) {
            $intCid = is_numeric($clientCodeInput) ? (int)$clientCodeInput : 0;
            $stmtFallback->bind_param("sii", $clientCodeInput, $intCid, $intCid);
            $stmtFallback->execute();
            $fbRow = $stmtFallback->get_result()->fetch_assoc();
            $stmtFallback->close();

            if ($fbRow) {
                $clientsList[] = [
                    'id' => (int)($fbRow['client_id'] ?? 0),
                    'client_code' => (string)($fbRow['client_code'] ?? $clientCodeInput),
                    'name' => 'Client ' . ($fbRow['client_code'] ?? $clientCodeInput),
                    'website' => null,
                    'industry' => null,
                    'notes' => null,
                    'created_at' => null,
                    'updated_at' => null
                ];
                $totalClients = 1;
            }
        }
    }

    // Collect all Client IDs and Client Codes for batch campaign query
    $clientIdsMap = [];
    $clientCodesMap = [];
    foreach ($clientsList as $c) {
        $cid = (int)($c['id'] ?? 0);
        $ccode = trim((string)($c['client_code'] ?? ''));
        if ($cid > 0) {
            $clientIdsMap[] = $cid;
        }
        if ($ccode !== '') {
            $clientCodesMap[] = $conn->real_escape_string($ccode);
        }
    }

    // 2. Fetch All Campaigns associated with the retrieved clients
    $campaignsByClientKey = [];
    if (!empty($clientIdsMap) || !empty($clientCodesMap)) {
        $campWhereClauses = [];
        if (!empty($clientIdsMap)) {
            $campWhereClauses[] = "cd.client_id IN (" . implode(',', $clientIdsMap) . ")";
        }
        if (!empty($clientCodesMap)) {
            $quotedCodes = array_map(fn($c) => "'{$c}'", $clientCodesMap);
            $campWhereClauses[] = "TRIM(cd.client_code) IN (" . implode(',', $quotedCodes) . ")";
        }

        $campWhereSql = "WHERE (" . implode(" OR ", $campWhereClauses) . ")";

        if ($statusFilter !== '') {
            $campWhereSql .= " AND (cd.status = '{$conn->real_escape_string($statusFilter)}' OR cmp.active = " . (strtolower($statusFilter) === 'active' ? 1 : 0) . ")";
        }

        $leadsCountSubquery = $includeStats ? ", (SELECT COUNT(*) FROM leads l WHERE l.campaign_id = cmp.id) AS total_leads_count, (SELECT COUNT(*) FROM leads l WHERE l.campaign_id = cmp.id AND l.qa_status = 'Qualified') AS qualified_leads_count" : "";

        $campSql = "
            SELECT 
                cmp.id AS campaign_id,
                cmp.name AS campaign_name,
                cmp.active AS campaign_active,
                cd.code AS campaign_code,
                cd.status AS campaign_status,
                cd.campaign_type,
                cd.client_id,
                cd.client_code,
                cd.start_date,
                cd.end_date,
                cd.total_leads,
                cd.pacing_type,
                cd.cpl,
                cd.cpl_currency,
                cd.created_at AS campaign_created_at
                {$leadsCountSubquery}
            FROM campaigns cmp
            INNER JOIN campaign_details cd ON cd.campaign_id = cmp.id
            {$campWhereSql}
            ORDER BY cmp.id DESC
        ";

        $resCamps = $conn->query($campSql);
        if ($resCamps) {
            while ($cRow = $resCamps->fetch_assoc()) {
                $cId = (int)($cRow['client_id'] ?? 0);
                $cCode = trim((string)($cRow['client_code'] ?? ''));

                $campObject = [
                    'id' => (int)$cRow['campaign_id'],
                    'campaign_id' => (int)$cRow['campaign_id'],
                    'campaign_code' => (string)($cRow['campaign_code'] ?? ''),
                    'code' => (string)($cRow['campaign_code'] ?? ''),
                    'name' => (string)($cRow['campaign_name'] ?? ''),
                    'status' => (string)($cRow['campaign_status'] ?? 'Draft'),
                    'is_active' => (bool)$cRow['campaign_active'],
                    'campaign_type' => (string)($cRow['campaign_type'] ?? ''),
                    'client_id' => $cId > 0 ? $cId : null,
                    'client_code' => $cCode,
                    'start_date' => $cRow['start_date'] ?? null,
                    'end_date' => $cRow['end_date'] ?? null,
                    'total_leads_assigned' => isset($cRow['total_leads']) ? (int)$cRow['total_leads'] : null,
                    'pacing_type' => (string)($cRow['pacing_type'] ?? ''),
                    'cpl' => isset($cRow['cpl']) ? (float)$cRow['cpl'] : null,
                    'cpl_currency' => (string)($cRow['cpl_currency'] ?? 'USD'),
                    'total_leads_count' => isset($cRow['total_leads_count']) ? (int)$cRow['total_leads_count'] : 0,
                    'qualified_leads_count' => isset($cRow['qualified_leads_count']) ? (int)$cRow['qualified_leads_count'] : 0,
                    'created_at' => $cRow['campaign_created_at'] ?? null
                ];

                if ($cId > 0) {
                    $campaignsByClientKey["id_{$cId}"][] = $campObject;
                }
                if ($cCode !== '') {
                    $campaignsByClientKey["code_{$cCode}"][] = $campObject;
                }
            }
        }
    }

    // 3. Structure Final Hierarchy
    $formattedClients = [];
    foreach ($clientsList as $cl) {
        $clientId = (int)($cl['id'] ?? 0);
        $clientCode = trim((string)($cl['client_code'] ?? ''));

        // Match campaigns by client ID first, then fallback to client code
        $clientCampaigns = [];
        $seenCampIds = [];

        if ($clientId > 0 && isset($campaignsByClientKey["id_{$clientId}"])) {
            foreach ($campaignsByClientKey["id_{$clientId}"] as $cmp) {
                if (!isset($seenCampIds[$cmp['id']])) {
                    $clientCampaigns[] = $cmp;
                    $seenCampIds[$cmp['id']] = true;
                }
            }
        }

        if ($clientCode !== '' && isset($campaignsByClientKey["code_{$clientCode}"])) {
            foreach ($campaignsByClientKey["code_{$clientCode}"] as $cmp) {
                if (!isset($seenCampIds[$cmp['id']])) {
                    $clientCampaigns[] = $cmp;
                    $seenCampIds[$cmp['id']] = true;
                }
            }
        }

        // Filter out empty clients if include_empty is set to false
        if (!$includeEmpty && empty($clientCampaigns)) {
            continue;
        }

        $formattedClients[] = [
            'id' => $clientId > 0 ? $clientId : null,
            'client_code' => $clientCode,
            'name' => (string)($cl['name'] ?? ''),
            'website' => (string)($cl['website'] ?? ''),
            'industry' => (string)($cl['industry'] ?? ''),
            'notes' => (string)($cl['notes'] ?? ''),
            'created_at' => $cl['created_at'] ?? null,
            'total_campaigns' => count($clientCampaigns),
            'campaigns' => $clientCampaigns
        ];
    }

    $totalPages = $totalClients > 0 ? (int)ceil($totalClients / $limit) : 0;

    // SCENARIO: Single Client Detail Response
    if ($clientCodeInput !== '' && count($formattedClients) === 1) {
        echo json_encode([
            'status' => 'success',
            'client' => $formattedClients[0]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // SCENARIO: Multiple Clients Response
    echo json_encode([
        'status' => 'success',
        'total' => $totalClients,
        'count' => count($formattedClients),
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages,
        'clients' => $formattedClients
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
