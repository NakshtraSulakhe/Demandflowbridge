<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: audit');
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
    header('Location: audit?error=invalid_token');
    exit;
}

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$canUseQaAction = userHasPermission('qa.action.endpoint');
$canManageLeads = userHasPermission('leads.list.page');

$leadId = (int)($_POST['lead_id'] ?? 0);
$qaStatusRaw = trim((string)($_POST['qa_status'] ?? ''));
$qaStatus = $qaStatusRaw !== '' ? normalizeQaStatus($qaStatusRaw) : '';
$qaComment = (string)($_POST['qa_comment_internal'] ?? ($_POST['qa_comment'] ?? ''));
$qaClientComment = (string)($_POST['qa_comment_client'] ?? '');
$clientDeliveryStatusRaw = trim((string)($_POST['client_delivery_status'] ?? ''));
$clientDeliveryStatus = $clientDeliveryStatusRaw !== '' ? normalizeClientDeliveryStatus($clientDeliveryStatusRaw) : '';
$returnUrl = (string)($_POST['return_url'] ?? '');
$returnPath = strtolower((string)parse_url($returnUrl, PHP_URL_PATH));
$isManageLeadsRequest = str_contains($returnPath, '/modules/leads/list') || str_contains($returnPath, '/modules/leads/leads-edit.php');

if ($leadId <= 0) {
    header('Location: audit?error=invalid_lead');
    exit;
}

$lead = getLeadById($leadId);
if (!$lead) {
    header('Location: audit?error=not_found');
    exit;
}

$isPrivInternalManageLeads = isAdmin() || hasRole([
    'director',
    'manager_director',
    'operations_director',
    'operations_manager',
    'sales_director',
    'sales_manager',
    'sdr',
    'email_marketing_manager',
]) || isQA();
$isOwnLead = (int)($lead['agent_id'] ?? 0) === $userId;
$canUseManageLeadsAction = $isManageLeadsRequest && $canManageLeads && ($isPrivInternalManageLeads || $isOwnLead);

// The Manage Leads page posts here for status updates, but its visibility model is
// different from the QA queue. Mirror that page's privilege rules for those requests.
if (!$canUseQaAction && !$canUseManageLeadsAction) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$cid = (int)($lead['campaign_id'] ?? 0);
if ($canUseQaAction) {
    $visible = getQaVisibleCampaignIdsForUser($userId, $role);
    $visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
    if ($visible !== null && $cid > 0 && !isset($visible[$cid])) {
        http_response_code(403);
        echo 'Not allowed';
        exit;
    }
}

updateLeadQuality($leadId, $qaStatus, $qaComment, $userId, $qaClientComment, $clientDeliveryStatus);

if ($qaStatus === 'Rework Needed' && function_exists('ensureTaskManagementSchema')) {
    ensureTaskManagementSchema();
    $conn = getDbConnection();
    $agentId = (int)($lead['agent_id'] ?? 0);
    if ($agentId > 0) {
        $existingId = 0;
        $chk = $conn->prepare("SELECT id FROM tasks WHERE lead_id = ? AND task_type = 'QA Task' AND status NOT IN ('Completed','Cancelled','Rejected') ORDER BY id DESC LIMIT 1");
        if ($chk) {
            $chk->bind_param('i', $leadId);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc() ?: [];
            $chk->close();
            $existingId = (int)($row['id'] ?? 0);
        }

        if ($existingId <= 0) {
            $agentDept = '';
            $st = $conn->prepare("SELECT department FROM users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $agentId);
                $st->execute();
                $r = $st->get_result()->fetch_assoc() ?: [];
                $st->close();
                $agentDept = trim((string)($r['department'] ?? ''));
            }
            $department = $agentDept !== '' ? $agentDept : 'QA';
            $title = 'QA Rework Required: Lead ' . (string)($lead['lead_id'] ?? ('#' . $leadId));
            $desc = trim((string)$qaComment);
            if ($desc === '') $desc = 'QA marked this lead as Rework Needed.';
            $dueAt = date('Y-m-d H:i:s', time() + 24 * 3600);

            $taskCode = '';
            $taskId = 0;
            $tries = 0;
            while ($tries < 5) {
                $tries++;
                $taskCode = function_exists('generateTaskCode') ? generateTaskCode() : ('TSK-' . date('YmdHis'));
                $stmt = $conn->prepare("INSERT INTO tasks (task_code, title, category, department, priority, status, progress, task_type, description, due_at, campaign_id, lead_id, created_by, created_at) VALUES (?,?,?,?, 'High', 'Assigned', 0, 'QA Task', ?, ?, ?, ?, ?, NOW())");
                if (!$stmt) { $taskCode = ''; break; }
                $cat = 'QA Rework';
                $cid = (int)($lead['campaign_id'] ?? 0);
                $cid = $cid > 0 ? $cid : null;
                $stmt->bind_param('ssssssiii', $taskCode, $title, $cat, $department, $desc, $dueAt, $cid, $leadId, $userId);
                $ok = @$stmt->execute();
                $taskId = (int)$stmt->insert_id;
                $stmt->close();
                if ($ok && $taskId > 0) break;
                $taskCode = '';
            }

            if ($taskId > 0) {
                $stmt = $conn->prepare("INSERT IGNORE INTO task_assignees (task_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,NOW())");
                if ($stmt) {
                    $stmt->bind_param('iii', $taskId, $agentId, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
                if (function_exists('logTaskActivity')) {
                    logTaskActivity($taskId, $userId, 'task_created', ['source' => 'qa_rework', 'lead_id' => $leadId]);
                    logTaskActivity($taskId, $userId, 'assigned', ['assignees' => [$agentId]]);
                }
                if (function_exists('notifyTaskUsers')) {
                    $link = appBasePath() . '/modules/tasks/view?id=' . $taskId;
                    notifyTaskUsers([$agentId], 'task.assigned', 'New QA rework task assigned', $taskCode . ' · ' . $title, $link, [
                        'importance' => 'high',
                        'show_toast' => true,
                        'dedup_key' => 'qa_rework_task:' . $leadId,
                        'dedup_window_min' => 60,
                    ]);
                }
            }
        }
    }
}

$parsed = $returnUrl !== '' ? parse_url($returnUrl) : null;
$path = is_array($parsed) ? (string)($parsed['path'] ?? '') : '';
if ($path !== '' && str_contains($path, '/modules/qa/')) {
    header('Location: ' . $returnUrl);
    exit;
}
if ($returnUrl !== '' && !preg_match('/^https?:\\/\\//i', $returnUrl) && !str_contains($returnUrl, "\n") && !str_contains($returnUrl, "\r")) {
    header('Location: ' . $returnUrl);
    exit;
}

header('Location: audit?updated=1');
exit;
