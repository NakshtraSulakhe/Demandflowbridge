<?php
/**
 * Export Leads
 * 
 * Allows exporting leads data in CSV format
 */

// Include required files
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce manifest-driven lead export access.
requirePermissionOrRole('leads.export.page', []);
// Ensure CSRF token is available for form submission
ensureCsrfToken();
$error = '';

// Current user + visibility scope
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();
$isPrivInternal = isAdmin() || hasRole(['director','manager_director','operations_director','operations_manager','sales_director','sales_manager','sdr']) || isQA();
$isOperationsManager = hasRole('operations_manager');
$exportMode = 'full';
$canSeeAllCampaigns = $isPrivInternal;
$visibleCampaignIds = null;
if (!$canSeeAllCampaigns) {
    $assigned = getUserAssignedCampaignIds($userId);
    $assigned = getTeamVisibleCampaignIdsForUser($userId, $assigned);
    $visibleCampaignIds = $assigned === null ? null : array_keys($assigned);
}
$hasCampaignExportScope = $canSeeAllCampaigns || ($visibleCampaignIds !== null && !empty($visibleCampaignIds));
$allowAllCampaignsExportUi = $hasCampaignExportScope;
$campaignSelectionRequired = !$allowAllCampaignsExportUi;

// Campaigns dropdown (scoped)
$campaigns = [];
if ($visibleCampaignIds !== null) {
    if (!empty($visibleCampaignIds)) {
        $in = implode(',', array_fill(0, count($visibleCampaignIds), '?'));
        $types = str_repeat('i', count($visibleCampaignIds));
        $stmt = $conn->prepare("
            SELECT c.id, c.name, d.code, d.client_code, d.status
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE c.active = 1 AND c.id IN ($in)
            ORDER BY d.created_at DESC, c.name ASC
        ");
        mysqliBindParams($stmt, $types, $visibleCampaignIds);
        $stmt->execute();
        $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
} else {
    $rs = $conn->query("
        SELECT c.id, c.name, d.code, d.client_code, d.status
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.active = 1
        ORDER BY d.created_at DESC, c.name ASC
    ");
    $campaigns = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

// Agents dropdown follows the same internal-privileged scope as the leads page.
$agents = [];
if ($isPrivInternal) {
    $agents = getAgents();
}

$requestFilters = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$selectedCampaignId = isset($requestFilters['campaign_id']) ? (int)$requestFilters['campaign_id'] : 0;
$selectedAgentId = isset($requestFilters['agent_id']) ? (int)$requestFilters['agent_id'] : 0;
$selectedClientCode = trim((string)($requestFilters['client_code'] ?? ''));
$selectedDateFrom = trim((string)($requestFilters['date_from'] ?? ''));
$selectedDateTo = trim((string)($requestFilters['date_to'] ?? ''));
$selectedQaStatus = trim((string)($requestFilters['qa_status'] ?? ''));
$selectedClientDeliveryStatus = trim((string)($requestFilters['client_delivery_status'] ?? ''));
$selectedFormDone = trim((string)($requestFilters['form_done'] ?? ($requestFilters['form_filled'] ?? '')));
$selectedSearch = trim((string)($requestFilters['search'] ?? ''));

// Define available columns for selection
$availableColumns = [
    'lead_id' => 'Lead ID',
    'campaign_name' => 'Campaign',
    'campaign_code' => 'Campaign Code',
    'campaign_status' => 'Campaign Status',
    'campaign_type' => 'Campaign Type',
    'campaign_start_date' => 'Campaign Start Date',
    'campaign_end_date' => 'Campaign End Date',
    'client_code' => 'Client Code',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'job_title' => 'Job Title',
    'email' => 'Email',
    'contact_phone' => 'Phone Number',
    'linkedin_link' => 'LinkedIn URL',
    'company_name' => 'Company Name',
    'company_website' => 'Company Website',
    'company_domain' => 'Company Domain',
    'company_linkedin' => 'Company LinkedIn URL',
    'industry' => 'Industry',
    'company_size' => 'Employee Size',
    'country' => 'Country',
    'software_implementation_timeline' => 'Implementation Timeline',
    'lead_source' => 'Lead Source',
    'lead_comment' => 'Lead Notes',
    'agent_name' => 'Assigned Agent',
    'created_by_user' => 'Lead Created By User',
    'email_status' => 'Email Status',
    'email_status_comment' => 'Email Status Notes',
    'email_status_updated_at' => 'Email Status Updated At',
    'client_delivery_status' => 'Delivery Status',
    'qa_status' => 'QA Status',
    'qa_updated_at' => 'QA Updated At',
    'qa_reviewed_by_name' => 'QA Reviewed By',
    'qa_comment' => 'QA Notes',
    'qa_client_comment' => 'Client Feedback',
    'form_done' => 'Form Completion Status',
    'form_filled_time' => 'Form Filled At',
    'form_submitted_at' => 'Form Submitted At',
    'created_at' => 'Lead Created At',
    'updated_at' => 'Last Updated At',
];

// Get selected columns from request or default to all
$selectedColumns = isset($requestFilters['columns']) ? (array)$requestFilters['columns'] : array_keys($availableColumns);
if (empty($selectedColumns)) {
    $selectedColumns = array_keys($availableColumns);
}

// For Operations Manager, restrict to specific columns
if ($isOperationsManager) {
    $opsManagerColumns = ['campaign_name', 'created_at', 'email', 'created_by_user', 'qa_status'];
    $selectedColumns = array_intersect($selectedColumns, $opsManagerColumns);
    $availableColumns = array_intersect_key($availableColumns, array_flip($opsManagerColumns));
}

// Process export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'export') {
    // Validate CSRF token
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token.';
    } else {
        // Get filter parameters
        $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
        $agentId = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
        $clientCode = trim((string)($_POST['client_code'] ?? ''));
        $dateFrom = isset($_POST['date_from']) ? $_POST['date_from'] : '';
        $dateTo = isset($_POST['date_to']) ? $_POST['date_to'] : '';
        $qaStatus = isset($_POST['qa_status']) ? $_POST['qa_status'] : '';
        $clientDeliveryStatus = isset($_POST['client_delivery_status']) ? $_POST['client_delivery_status'] : '';
        $formFilled = isset($_POST['form_filled']) ? $_POST['form_filled'] : '';
        $formDone = isset($_POST['form_done']) ? $_POST['form_done'] : '';
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';

        $allowAllCampaignsExport = $hasCampaignExportScope;

        if ($campaignId <= 0 && !$allowAllCampaignsExport) {
            $error = 'Please select a campaign to export.';
        } elseif ($campaignId > 0 && $visibleCampaignIds !== null && !in_array($campaignId, $visibleCampaignIds, true)) {
            $error = 'Not allowed for this campaign.';
        }

        // Build filters array
        $filters = [];
        if ($campaignId > 0) {
            $filters['campaign_id'] = $campaignId;
        } elseif (!$canSeeAllCampaigns && $visibleCampaignIds !== null) {
            $filters['campaign_ids'] = $visibleCampaignIds;
        }
        if ($agentId > 0) $filters['agent_id'] = $agentId;
        if ($clientCode !== '') $filters['client_code'] = $clientCode;
        if (!empty($dateFrom)) $filters['date_from'] = $dateFrom;
        if (!empty($dateTo)) $filters['date_to'] = $dateTo;
        if (!empty($qaStatus)) $filters['qa_status'] = $qaStatus;
        if (!empty($clientDeliveryStatus)) $filters['client_delivery_status'] = $clientDeliveryStatus;
        // Prefer normalized form_done; fall back to legacy form_filled
        if (!empty($formDone)) {
            $filters['form_done'] = $formDone;
        } elseif (!empty($formFilled)) {
            $filters['form_filled'] = $formFilled;
        }
        if (!empty($search)) $filters['search'] = $search;

        if (empty($error)) {
            ensureLeadsTrackingColumns();

            $campMeta = [];
            if ($campaignId > 0) {
                $stmt = $conn->prepare("
                    SELECT c.id, c.name, d.code, d.status, d.campaign_type, d.delivery_format, d.pacing_type, d.pacing_count,
                           d.start_date, d.end_date, d.total_leads,
                           d.client_id, cl.client_code, cl.name AS client_name
                    FROM campaigns c
                    JOIN campaign_details d ON d.campaign_id = c.id
                    LEFT JOIN clients cl ON cl.id = d.client_id
                    WHERE c.id = ?
                    LIMIT 1
                ");
                $stmt->bind_param('i', $campaignId);
                $stmt->execute();
                $campMeta = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
                if (empty($campMeta)) {
                    $error = 'Campaign not found.';
                }
            }
        }

        if (empty($error)) {
            @set_time_limit(0);
            while (ob_get_level() > 0) { @ob_end_clean(); }

            $chunkSize = 2000;

            $schemaKeys = [];
            $schemaKeyLabels = [];
            if ($campaignId > 0) {
                $form = getFormForCampaign($campaignId);
                $schemaFields = (array)(($form['schema']['fields'] ?? []) ?: []);
                foreach ($schemaFields as $f) {
                    if (!is_array($f)) continue;
                    $key = trim((string)($f['key'] ?? ''));
                    if ($key === '') continue;
                    $schemaKeys[] = $key;
                    $schemaKeyLabels[$key] = (string)($f['label'] ?? '');
                }
            }

            $fetchLatestSubmissions = function(array $leadIds) use ($conn, $campaignId): array {
                $leadIds = array_values(array_filter(array_map('intval', $leadIds), fn($v) => $v > 0));
                if (empty($leadIds)) return [];
                $in = implode(',', array_fill(0, count($leadIds), '?'));
                if ($campaignId > 0) {
                    $types = 'i' . str_repeat('i', count($leadIds));
                    $sql = "
                        SELECT fs.lead_id, fs.submitted_at, fs.data_json
                        FROM form_submissions fs
                        JOIN (
                            SELECT lead_id, MAX(id) AS max_id
                            FROM form_submissions
                            WHERE campaign_id = ? AND lead_id IN ($in)
                            GROUP BY lead_id
                        ) x ON x.max_id = fs.id
                    ";
                    $bind = array_merge([$campaignId], $leadIds);
                } else {
                    $types = str_repeat('i', count($leadIds));
                    $sql = "
                        SELECT fs.lead_id, fs.submitted_at, fs.data_json
                        FROM form_submissions fs
                        JOIN (
                            SELECT lead_id, MAX(id) AS max_id
                            FROM form_submissions
                            WHERE lead_id IN ($in)
                            GROUP BY lead_id
                        ) x ON x.max_id = fs.id
                    ";
                    $bind = $leadIds;
                }
                $stmt = $conn->prepare($sql);
                if (!$stmt) return [];
                mysqliBindParams($stmt, $types, $bind);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt->close();
                $out = [];
                foreach ($rows as $r) {
                    $lid = (int)($r['lead_id'] ?? 0);
                    $data = json_decode((string)($r['data_json'] ?? ''), true);
                    if (!is_array($data)) $data = [];
                    $out[$lid] = ['submitted_at' => $r['submitted_at'] ?? null, 'data' => $data];
                }
                return $out;
            };

            $qaPreferredFormKeys = [
                'first_name',
                'last_name',
                'job_title',
                'email',
                'company_website',
                'phone',
                'prospect_linkedin_link',
                'company_name',
                'industry',
                'employee_size',
                'country',
                'company_linkedin_link',
                '0_to_3_months3_to_6_months6_to_9_months9_to_12_months',
            ];

            $firstPage = getLeads($filters, $chunkSize, 1);
            $totalPages = (int)($firstPage['totalPages'] ?? 1);

            $dynamicKeys = $schemaKeys;
            if ($exportMode === 'qa' && $campaignId > 0) {
                $dynamicKeys = $qaPreferredFormKeys;
            } elseif ($campaignId > 0) {
                $extraKeys = [];
                for ($p = 1; $p <= $totalPages; $p++) {
                    $chunk = $p === 1 ? ($firstPage['leads'] ?? []) : (getLeads($filters, $chunkSize, $p)['leads'] ?? []);
                    $leadIds = array_values(array_filter(array_map(fn($l) => (int)($l['id'] ?? 0), $chunk), fn($v) => $v > 0));
                    $subs = $fetchLatestSubmissions($leadIds);
                    foreach ($subs as $s) {
                        $data = $s['data'] ?? [];
                        if (!is_array($data)) continue;
                        foreach ($data as $k => $_v) {
                            $kk = (string)$k;
                            if ($kk === '') continue;
                            if (!in_array($kk, $schemaKeys, true)) $extraKeys[$kk] = true;
                        }
                    }
                }
                if (!empty($extraKeys)) {
                    $extras = array_keys($extraKeys);
                    sort($extras, SORT_NATURAL | SORT_FLAG_CASE);
                    $dynamicKeys = array_merge($dynamicKeys, $extras);
                }
            }

            // Define base column keys that should be excluded from form fields to avoid duplication
            $baseColumnKeysToExclude = [
                'first_name', 'last_name', 'job_title', 'email', 'contact_phone', 'phone',
                'linkedin_link', 'linkedin', 'prospect_linkedin_link', 'company_name',
                'company_website', 'company_domain', 'company_linkedin', 'industry',
                'company_size', 'employee_size', 'country', 'software_implementation_timeline',
                'lead_source', 'lead_comment', 'agent_name', 'qa_status', 'qa_comment',
                'qa_client_comment', 'client_delivery_status', 'form_done', 'form_filled_time',
                'created_at', 'updated_at', 'created_by', 'created_by_user'
            ];

            // Filter out duplicate form fields that match base columns
            $dynamicKeys = array_filter($dynamicKeys, function($key) use ($baseColumnKeysToExclude) {
                $normalizedKey = strtolower(trim(str_replace(['_', '-', ' '], '', $key)));
                foreach ($baseColumnKeysToExclude as $excludeKey) {
                    $normalizedExclude = strtolower(trim(str_replace(['_', '-', ' '], '', $excludeKey)));
                    if ($normalizedKey === $normalizedExclude || strpos($normalizedKey, $normalizedExclude) !== false) {
                        return false;
                    }
                }
                return true;
            });
            $dynamicKeys = array_values($dynamicKeys);

        $baseCols = [];
        if ($isOperationsManager) {
            // Operations Manager restricted fields
            $baseCols = [
                'campaign_name',
                'created_at',
                'email',
                'created_by_user',
                'qa_status',
            ];
        } elseif ($exportMode === 'qa') {
            $baseCols = [
                'qa_status','qa_updated_at','qa_comment','qa_client_comment','client_delivery_status',
                'form_done','form_filled_time','form_submitted_at',
                'created_at','updated_at',
            ];
        } else {
            // Use selected columns from form, fallback to all available columns
            $exportColumns = isset($_POST['columns']) ? (array)$_POST['columns'] : array_keys($availableColumns);
            if (empty($exportColumns)) {
                $exportColumns = array_keys($availableColumns);
            }
            $baseCols = $exportColumns;
        }

        $columnLabels = [
            'lead_id' => 'Lead ID',
            'campaign_name' => 'Campaign',
            'campaign_code' => 'Campaign Code',
            'campaign_status' => 'Campaign Status',
            'campaign_type' => 'Campaign Type',
            'campaign_start_date' => 'Campaign Start Date',
            'campaign_end_date' => 'Campaign End Date',
            'client_code' => 'Client Code',
            'agent_name' => 'Assigned Agent',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'job_title' => 'Job Title',
            'email' => 'Email',
            'contact_phone' => 'Phone Number',
            'linkedin_link' => 'LinkedIn URL',
            'company_name' => 'Company Name',
            'company_website' => 'Company Website',
            'company_domain' => 'Company Domain',
            'company_linkedin' => 'Company LinkedIn URL',
            'industry' => 'Industry',
            'company_size' => 'Employee Size',
            'country' => 'Country',
            'software_implementation_timeline' => 'Implementation Timeline',
            'lead_source' => 'Lead Source',
            'lead_comment' => 'Lead Notes',
            'qa_status' => 'QA Status',
            'qa_updated_at' => 'QA Updated At',
            'qa_reviewed_by_name' => 'QA Reviewed By',
            'qa_comment' => 'QA Notes',
            'qa_client_comment' => 'Client Feedback',
            'client_delivery_status' => 'Delivery Status',
            'email_status' => 'Email Status',
            'email_status_comment' => 'Email Status Notes',
            'email_status_updated_at' => 'Email Status Updated At',
            'form_done' => 'Form Completion Status',
            'form_filled_time' => 'Form Filled At',
            'form_submitted_at' => 'Form Submitted At',
            'created_at' => 'Lead Created At',
            'updated_at' => 'Last Updated At',
            'created_by_user' => 'Lead Created By User',
        ];

        $humanizeLabel = function(string $key) use ($columnLabels): string {
            if (isset($columnLabels[$key])) {
                return $columnLabels[$key];
            }
            $label = trim($key);
            if ($label === '') {
                return '';
            }
            $label = preg_replace('/[^a-zA-Z0-9]+/', ' ', $label);
            $label = ucwords(strtolower(trim((string)$label)));
            $label = str_replace([' Qa ', ' Ip ', ' Id ', ' Url '], [' QA ', ' IP ', ' ID ', ' URL '], ' ' . $label . ' ');
            return trim($label);
        };

        $dynamicHeaderLabel = function(string $key) use ($schemaKeyLabels, $humanizeLabel): string {
            $lbl = trim((string)($schemaKeyLabels[$key] ?? ''));
            if ($lbl === '') {
                $lbl = $humanizeLabel($key);
            }
            return 'Form - ' . $lbl;
        };

        $headers = [];
        if ($isOperationsManager) {
            // Operations Manager only gets base columns, no form fields
            foreach ($baseCols as $c) {
                $headers[] = $humanizeLabel((string)$c);
            }
        } elseif ($exportMode === 'qa') {
            foreach ($dynamicKeys as $k) {
                $headers[] = $dynamicHeaderLabel((string)$k);
            }
            foreach ($baseCols as $c) {
                $headers[] = $humanizeLabel((string)$c);
            }
        } else {
            foreach ($baseCols as $c) {
                $headers[] = $humanizeLabel((string)$c);
            }
            foreach ($dynamicKeys as $k) {
                $headers[] = $dynamicHeaderLabel((string)$k);
            }
        }

        $fnPrefix = $exportMode === 'qa' ? 'leads_qa_export' : 'leads_export';
        $fnCamp = $campaignId > 0 ? ('_campaign_' . $campaignId) : '_all_campaigns';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fnPrefix . $fnCamp . '_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);

        $cell = function($v, bool $forceExcelText = false): string {
            if (is_array($v)) {
                $flat = array_map('strval', $v);
                $flat = array_map('trim', $flat);
                $flat = array_values(array_filter($flat, fn($x) => $x !== ''));
                $v = implode(', ', $flat);
            }
            if ($v === null) {
                $v = '';
            }
            $v = (string)$v;
            if ($forceExcelText && $v !== '') {
                return '="' . str_replace('"', '""', $v) . '"';
            }
            return $v;
        };

        $submissionScalar = function(array $data, array $keys): string {
            $v = extractSubmissionValue($data, $keys);
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', $v));
            }
            return trim((string)($v ?? ''));
        };

        $normalizeFieldKeyLocal = function(string $key): string {
            $key = strtolower(trim($key));
            $key = preg_replace('/[^a-z0-9]+/', '_', $key);
            $key = preg_replace('/_+/', '_', $key);
            return trim((string)$key, '_');
        };

        $forceTextColumns = [
            'company_size',
            'employee_size',
            'employee_sizes',
            'company_size_range',
            'headcount',
            'employees',
            'employee_size_bracket',
        ];

        for ($p = 1; $p <= $totalPages; $p++) {
            $chunk = $p === 1 ? ($firstPage['leads'] ?? []) : (getLeads($filters, $chunkSize, $p)['leads'] ?? []);
            if (empty($chunk)) continue;

            $leadIds = [];
            foreach ($chunk as $l) {
                $lid = (int)($l['id'] ?? 0);
                if ($lid > 0) $leadIds[] = $lid;
            }

            $formDataByLead = $fetchLatestSubmissions($leadIds);

            $campMetaMap = [];
            if ($campaignId <= 0) {
                $cids = array_values(array_unique(array_values(array_filter(array_map(fn($l) => (int)($l['campaign_id'] ?? 0), $chunk), fn($v) => $v > 0))));
                if (!empty($cids)) {
                    $in = implode(',', array_fill(0, count($cids), '?'));
                    $types = str_repeat('i', count($cids));
                    $stmt = $conn->prepare("
                        SELECT c.id, c.name, d.code, d.status, d.campaign_type, d.start_date, d.end_date,
                               d.client_id, cl.client_code, cl.name AS client_name
                        FROM campaigns c
                        JOIN campaign_details d ON d.campaign_id = c.id
                        LEFT JOIN clients cl ON cl.id = d.client_id
                        WHERE c.id IN ($in)
                    ");
                    if ($stmt) {
                        mysqliBindParams($stmt, $types, $cids);
                        $stmt->execute();
                        $tmp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                        $stmt->close();
                        foreach ($tmp as $r) $campMetaMap[(int)($r['id'] ?? 0)] = $r;
                    }
                }
            }

            foreach ($chunk as $l) {
                $lid = (int)($l['id'] ?? 0);
                $row = [];
                $fs = $formDataByLead[$lid] ?? null;
                $data = $fs ? ($fs['data'] ?? []) : [];
                if (!is_array($data)) $data = [];
                $submissionJson = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                $leadView = deriveLeadDisplayFieldsFromSubmission($l, $submissionJson);
                $submissionCompanySize = $submissionScalar($data, [
                    'employee_size',
                    'company_size',
                    'company_size_range',
                    'employee_sizes',
                    'employees',
                    'headcount',
                ]);

                $row['lead_db_id'] = $lid;
                $row['lead_id'] = (string)($l['lead_id'] ?? '');
                $cid = (int)($l['campaign_id'] ?? $campaignId);
                $cm = $campaignId > 0 ? $campMeta : ($campMetaMap[$cid] ?? []);
                $row['campaign_id'] = $cid;
                $row['campaign_code'] = (string)($l['campaign_code'] ?? ($cm['code'] ?? ''));
                $row['campaign_name'] = (string)($l['campaign_name'] ?? ($cm['name'] ?? ''));
                $row['campaign_status'] = (string)($cm['status'] ?? '');
                $row['campaign_type'] = (string)($cm['campaign_type'] ?? '');
                $row['campaign_start_date'] = (string)($cm['start_date'] ?? '');
                $row['campaign_end_date'] = (string)($cm['end_date'] ?? '');
                $row['client_id'] = (int)($cm['client_id'] ?? ($l['client_id'] ?? 0));
                $row['client_code'] = (string)($cm['client_code'] ?? ($l['client_code'] ?? ''));
                $row['agent_id'] = (int)($l['agent_id'] ?? 0);
                $row['agent_name'] = (string)($l['agent_name'] ?? '');
                $row['first_name'] = (string)($leadView['first_name'] ?? $l['first_name'] ?? '');
                $row['last_name'] = (string)($leadView['last_name'] ?? $l['last_name'] ?? '');
                $row['job_title'] = (string)($leadView['job_title'] ?? $l['job_title'] ?? '');
                $row['email'] = (string)($leadView['email'] ?? $l['email'] ?? '');
                $row['company_website'] = (string)($leadView['company_website'] ?? $l['company_website'] ?? '');
                $row['company_domain'] = (string)($l['company_domain'] ?? '');
                $row['linkedin_link'] = (string)($leadView['linkedin_link'] ?? $leadView['linkedin_profile'] ?? $l['linkedin_link'] ?? '');
                $row['contact_phone'] = (string)($leadView['contact_phone'] ?? $leadView['phone'] ?? $l['contact_phone'] ?? '');
                $row['industry'] = (string)($leadView['industry'] ?? $l['industry'] ?? '');
                $row['company_linkedin'] = (string)($leadView['company_linkedin'] ?? $l['company_linkedin'] ?? '');
                $row['company_name'] = (string)($leadView['company_name'] ?? $l['company_name'] ?? '');
                $row['company_size'] = $submissionCompanySize !== '' ? $submissionCompanySize : (string)($leadView['company_size'] ?? $leadView['employee_size'] ?? $l['company_size'] ?? '');
                $row['country'] = (string)($leadView['country'] ?? $l['country'] ?? '');
                $row['software_implementation_timeline'] = (string)($leadView['software_implementation_timeline'] ?? $l['software_implementation_timeline'] ?? '');
                $row['lead_source'] = (string)($l['lead_source'] ?? '');
                $row['lead_comment'] = (string)($l['lead_comment'] ?? '');
                $row['qa_status'] = (string)($l['qa_status'] ?? '');
                $row['qa_comment'] = (string)($l['qa_comment'] ?? '');
                $row['qa_client_comment'] = (string)($l['qa_client_comment'] ?? '');
                $row['client_delivery_status'] = (string)($l['client_delivery_status'] ?? '');
                $row['qa_updated_at'] = (string)($l['qa_updated_at'] ?? '');
                $row['email_status'] = (string)($l['email_status'] ?? '');
                $row['email_status_comment'] = (string)($l['email_status_comment'] ?? '');
                $row['email_status_updated_at'] = (string)($l['email_status_updated_at'] ?? '');
                $row['form_done'] = (string)($l['form_done'] ?? '');
                $row['form_filled_time'] = (string)($l['form_filled_time'] ?? '');
                $row['created_at'] = (string)($l['created_at'] ?? '');
                $row['updated_at'] = (string)($l['updated_at'] ?? '');

                $row['form_submitted_at'] = $fs ? (string)($fs['submitted_at'] ?? '') : '';

                // Get created by user name (optimized to fetch all at once)
                $createdById = (int)($l['created_by'] ?? 0);
                $createdByName = (string)($l['created_by_name'] ?? '');
                $row['created_by_user'] = $createdByName;

                $outRow = [];
                if ($isOperationsManager) {
                    // Operations Manager only gets base columns, no form fields
                    foreach ($baseCols as $c) {
                        $outRow[] = $cell($row[$c] ?? '', in_array((string)$c, $forceTextColumns, true));
                    }
                } elseif ($exportMode === 'qa') {
                    foreach ($dynamicKeys as $k) {
                        $nk = $normalizeFieldKeyLocal((string)$k);
                        $outRow[] = $cell($data[$k] ?? '', in_array($nk, $forceTextColumns, true));
                    }
                    foreach ($baseCols as $c) $outRow[] = $cell($row[$c] ?? '');
                } else {
                    foreach ($baseCols as $c) {
                        $outRow[] = $cell($row[$c] ?? '', in_array((string)$c, $forceTextColumns, true));
                    }
                    foreach ($dynamicKeys as $k) {
                        $nk = $normalizeFieldKeyLocal((string)$k);
                        $outRow[] = $cell($data[$k] ?? '', in_array($nk, $forceTextColumns, true));
                    }
                }
                fputcsv($output, $outRow);
            }
        }

        fclose($output);
        exit;
    }
    }
}

?>
<?php $pageTitle = 'Export Leads'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Export Leads</div>
            <div class="text-muted small">
                <?php echo $exportMode === 'qa' ? 'QA export (Lead + QA + Form fields)' : 'Full export'; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="<?php echo htmlspecialchars(appBackUrl('list')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light fw-semibold"><i class="bi bi-download me-1"></i>Export Filters</div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="col-lg-4">
                    <label for="campaign_id" class="form-label small text-muted">Campaign<?php echo $campaignSelectionRequired ? ' (Required)' : ''; ?></label>
                    <select class="form-select form-select-sm" id="campaign_id" name="campaign_id" <?php echo $campaignSelectionRequired ? 'required' : ''; ?>>
                        <option value=""><?php echo $allowAllCampaignsExportUi ? ($canSeeAllCampaigns ? 'All Campaigns' : 'All Accessible Campaigns') : 'Select Campaign'; ?></option>
                        <?php foreach ($campaigns as $campaign): ?>
                            <option value="<?php echo (int)$campaign['id']; ?>" <?php echo $selectedCampaignId === (int)$campaign['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($campaign) . (!empty($campaign['status']) ? (' · ' . (string)$campaign['status']) : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (isAdmin() || isQA()): ?>
                    <div class="col-lg-3">
                        <label for="agent_id" class="form-label small text-muted">Agent</label>
                        <select class="form-select form-select-sm" id="agent_id" name="agent_id">
                            <option value="">All Agents</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?php echo (int)($agent['id'] ?? 0); ?>" <?php echo $selectedAgentId === (int)($agent['id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($agent['full_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-lg-2">
                    <label for="client_code" class="form-label small text-muted">Client Code</label>
                    <input type="text" class="form-control form-control-sm" id="client_code" name="client_code" value="<?php echo htmlspecialchars($selectedClientCode); ?>" placeholder="Client code">
                </div>

                <div class="col-lg-2">
                    <label for="date_from" class="form-label small text-muted">Date From</label>
                    <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($selectedDateFrom); ?>">
                </div>

                <div class="col-lg-2">
                    <label for="date_to" class="form-label small text-muted">Date To</label>
                    <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($selectedDateTo); ?>">
                </div>

                <div class="col-lg-3">
                    <label for="qa_status" class="form-label small text-muted">QA Status</label>
                    <select class="form-select form-select-sm" id="qa_status" name="qa_status">
                        <option value="">All</option>
                        <?php foreach (getQaStatuses() as $st): ?>
                            <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $selectedQaStatus === (string)$st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label for="client_delivery_status" class="form-label small text-muted">Delivery Status</label>
                    <select class="form-select form-select-sm" id="client_delivery_status" name="client_delivery_status">
                        <option value="">All</option>
                        <?php foreach (getClientDeliveryStatuses() as $st): ?>
                            <option value="<?php echo htmlspecialchars($st); ?>" <?php echo normalizeClientDeliveryStatus($selectedClientDeliveryStatus) === normalizeClientDeliveryStatus((string)$st) ? 'selected' : ''; ?>><?php echo htmlspecialchars(clientDeliveryStatusDisplayLabel((string)$st)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label for="form_done" class="form-label small text-muted">Form Done</label>
                    <select class="form-select form-select-sm" id="form_done" name="form_done">
                        <option value="">All</option>
                        <option value="Yes" <?php echo $selectedFormDone === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                        <option value="No" <?php echo $selectedFormDone === 'No' ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>

                <div class="col-lg-4">
                    <label for="search" class="form-label small text-muted">Search</label>
                    <input type="text" class="form-control form-control-sm" id="search" name="search" placeholder="Lead ID, name, email, company" value="<?php echo htmlspecialchars($selectedSearch); ?>">
                </div>

                <?php if (!$isOperationsManager): ?>
                <div class="col-12">
                    <label class="form-label small text-muted">Select Columns to Export</label>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-2">
                            <div class="row g-2">
                                <?php foreach ($availableColumns as $colKey => $colLabel): ?>
                                    <div class="col-md-4 col-lg-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="columns[]" value="<?php echo htmlspecialchars($colKey); ?>" id="col_<?php echo htmlspecialchars($colKey); ?>" <?php echo in_array($colKey, $selectedColumns) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="col_<?php echo htmlspecialchars($colKey); ?>">
                                                <?php echo htmlspecialchars($colLabel); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllColumns()">Select All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllColumns()">Clear All</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function selectAllColumns() {
    const checkboxes = document.querySelectorAll('input[name="columns[]"]');
    checkboxes.forEach(cb => cb.checked = true);
}

function clearAllColumns() {
    const checkboxes = document.querySelectorAll('input[name="columns[]"]');
    checkboxes.forEach(cb => cb.checked = false);
}
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
