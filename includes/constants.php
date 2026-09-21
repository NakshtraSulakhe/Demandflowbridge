<?php
// Centralized constants and helpers for roles, statuses, and field normalization

// Roles used across the application
$ROLES = [
    'admin' => 'Admin',
    'qa' => 'QA',
    'agent' => 'Agent',
    'form_filler' => 'Form Filler',
];

// Allowed QA statuses for lead review filters and forms
$QA_STATUSES = ['Pending', 'TBD', 'In Progress', 'Qualified', 'Disqualified', 'Rework Needed', 'Reopened', 'Rectified', 'Duplicate/Supp'];

$CLIENT_DELIVERY_STATUSES = ['Pending', 'Delivered', 'Accepted', 'Rejected', 'TBD(To be discussed)', 'In Progress'];

function getClientDeliveryStatuses(): array {
    global $CLIENT_DELIVERY_STATUSES;
    return $CLIENT_DELIVERY_STATUSES;
}

function getClientDeliveryStatusOptions(): array {
    return [
        'Pending' => 'Pending',
        'Delivered' => 'Delivered',
        'Accepted' => 'Accepted',
        'Rejected' => 'Rejected',
        'TBD(To be discussed)' => 'TBD',
        'In Progress' => 'In Progress',
    ];
}

/**
 * Expose QA statuses for templates.
 */
function getQaStatuses(): array {
    global $QA_STATUSES;
    return $QA_STATUSES;
}

function getQaStatusOptions(): array {
    return [
        'Pending' => 'Pending QA',
        'TBD' => 'TBD',
        'In Progress' => 'In Progress',
        'Qualified' => 'Qualified',
        'Disqualified' => 'Disqualified',
        'Rework Needed' => 'Rework Needed',
        'Reopened' => 'Reopened',
        'Rectified' => 'Rectified',
        'Duplicate/Supp' => 'Duplicate/Supp',
    ];
}

function getQaOpenStatuses(): array {
    return ['Pending', 'In Progress', 'Rework Needed', 'Reopened', 'Rectified'];
}

function getQaFinalStatuses(): array {
    return ['Qualified', 'Disqualified', 'Duplicate/Supp'];
}

function getQaCountBuckets(): array {
    $out = [];
    foreach (getQaStatusOptions() as $status => $label) $out[$status] = 0;
    return $out;
}

function getClientDeliveryCountBuckets(): array {
    $out = [];
    foreach (getClientDeliveryStatusOptions() as $status => $label) $out[$status] = 0;
    return $out;
}

// Allowed form completion values
$FORM_DONE_VALUES = ['Yes', 'No'];

/**
 * Incentive Constants
 */
define('DAILY_INCENTIVE_AMOUNT', 500);
define('MONTHLY_BONUS_AMOUNT', 10000);

/**
 * Lead Status Normalization
 */

/**
 * Normalize QA status input to a canonical display value.
 */
function normalizeQaStatus(?string $status): string
{
    if ($status === null) return 'Pending';
    $status = trim($status);
    if ($status === '') return 'Pending';
    $map = [
        'qualified' => 'Qualified',
        'approved' => 'Qualified',
        'disqualified' => 'Disqualified',
        'rejected' => 'Disqualified',
        'in progress' => 'In Progress',
        'in_progress' => 'In Progress',
        'pending qa' => 'Pending',
        'pending' => 'Pending',
        'tbd' => 'TBD',
        'tbs' => 'TBD',
        'tbd(to be discussed)' => 'TBD',
        'tbs(to be discussed)' => 'TBD',
        'to be discussed' => 'TBD',
        'reopened' => 'Reopened',
        'rework needed' => 'Rework Needed',
        'needs correction' => 'Rework Needed',
        'rework' => 'Rework Needed',
        'duplicate' => 'Duplicate/Supp',
        'duplicate/supp' => 'Duplicate/Supp',
        'duplicate supp' => 'Duplicate/Supp',
        'supp' => 'Duplicate/Supp',
        'rectified' => 'Rectified',
    ];
    $key = strtolower($status);
    return $map[$key] ?? $status;
}

function qaStatusDisplayLabel(?string $status): string
{
    $norm = normalizeQaStatus($status);
    $options = getQaStatusOptions();
    return $options[$norm] ?? $norm;
}

function qaStatusBadgeClass(?string $status): string
{
    $norm = normalizeQaStatus($status);
    if ($norm === 'Qualified' || $norm === 'Rectified') return 'bg-success-subtle text-success';
    if ($norm === 'Disqualified') return 'bg-danger-subtle text-danger';
    if ($norm === 'Rework Needed') return 'bg-info-subtle text-info';
    if ($norm === 'In Progress') return 'bg-primary-subtle text-primary';
    if ($norm === 'Duplicate/Supp') return 'bg-dark-subtle text-dark';
    if ($norm === 'Reopened') return 'bg-warning-subtle text-warning';
    return 'bg-warning-subtle text-warning';
}

function qaStatusFilterValues(?string $status): array
{
    $norm = normalizeQaStatus($status);
    if ($norm === 'Pending') return ['Pending', 'Pending QA', 'TBD', 'TBD(To be discussed)'];
    if ($norm === 'Duplicate/Supp') return ['Duplicate/Supp', 'Duplicate'];
    return [$norm];
}

function normalizeClientDeliveryStatus(?string $status): string
{
    if ($status === null) return 'Pending';
    $status = trim($status);
    $lower = strtolower($status);
    if (in_array($lower, ['delivered', 'yes', 'y', 'true', '1'], true)) return 'Delivered';
    if (in_array($lower, ['pending', 'no', 'n', 'false', '0'], true)) return 'Pending';
    if ($lower === 'accepted') return 'Accepted';
    if ($lower === 'rejected') return 'Rejected';
    if ($lower === 'tbd' || $lower === 'tbd(to be discussed)') return 'TBD(To be discussed)';
    if ($lower === 'in progress' || $lower === 'in_progress') return 'In Progress';
    return $status;
}

function clientDeliveryStatusDisplayLabel(?string $status): string
{
    $norm = normalizeClientDeliveryStatus($status);
    $options = getClientDeliveryStatusOptions();
    return $options[$norm] ?? $norm;
}

function clientDeliveryStatusFilterValues(?string $status): array
{
    $norm = normalizeClientDeliveryStatus($status);
    if ($norm === 'TBD(To be discussed)') return ['TBD(To be discussed)', 'TBD'];
    if ($norm === 'In Progress') return ['In Progress', 'In_Progress'];
    return [$norm];
}

function accumulateQaStatusCount(array &$counts, ?string $status, int $amount = 1): void
{
    $norm = normalizeQaStatus($status);
    if (!array_key_exists($norm, $counts)) $counts[$norm] = 0;
    $counts[$norm] += max(0, $amount);
}

function accumulateClientDeliveryStatusCount(array &$counts, ?string $status, int $amount = 1): void
{
    $norm = normalizeClientDeliveryStatus($status);
    if (!array_key_exists($norm, $counts)) $counts[$norm] = 0;
    $counts[$norm] += max(0, $amount);
}

/**
 * Normalize form_done/form_filled values to 'Yes'/'No'.
 */
function normalizeFormDone(?string $value): string
{
    if ($value === null) return 'No';
    $value = strtolower(trim($value));
    if (in_array($value, ['yes', 'y', 'true', '1'], true)) return 'Yes';
    if (in_array($value, ['no', 'n', 'false', '0'], true)) return 'No';
    return $value;
}

/**
 * Derive legacy aliases expected in some templates.
 * Adds form_filled alias from form_done and ensures qa_status is normalized.
 */
function enrichLeadRow(array $row): array
{
    if (array_key_exists('form_done', $row) && !array_key_exists('form_filled', $row)) {
        $row['form_filled'] = normalizeFormDone($row['form_done']);
    }
    // Legacy alias: ensure phone field is available from contact_phone
    if (array_key_exists('contact_phone', $row) && !array_key_exists('phone', $row)) {
        $row['phone'] = $row['contact_phone'];
    }
    if (array_key_exists('qa_status', $row)) {
        $row['qa_status'] = normalizeQaStatus($row['qa_status']);
    }
    if (array_key_exists('client_delivery_status', $row)) {
        $row['client_delivery_status'] = normalizeClientDeliveryStatus($row['client_delivery_status']);
    }
    return $row;
}

/**
 * Build WHERE fragments for filters, mapping legacy keys to canonical columns.
 */
function buildLeadFilterWhereClauses(array $filters, array &$params, string &$types): array
{
    $where = [];
    if (!empty($filters['campaign'])) {
        $where[] = 'campaign_name = ?';
        $params[] = $filters['campaign'];
        $types .= 's';
    }
    if (!empty($filters['agent'])) {
        $where[] = 'agent_name = ?';
        $params[] = $filters['agent'];
        $types .= 's';
    }
    if (!empty($filters['country'])) {
        $where[] = 'country = ?';
        $params[] = $filters['country'];
        $types .= 's';
    }
    if (!empty($filters['qa_status'])) {
        $where[] = 'qa_status = ?';
        $params[] = normalizeQaStatus($filters['qa_status']);
        $types .= 's';
    }
    if (!empty($filters['client_delivery_status'])) {
        $where[] = 'client_delivery_status = ?';
        $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']);
        $types .= 's';
    }
    // Legacy alias support
    if (!empty($filters['form_filled'])) {
        $where[] = 'form_done = ?';
        $params[] = normalizeFormDone($filters['form_filled']);
        $types .= 's';
    }
    if (!empty($filters['form_done'])) {
        $where[] = 'form_done = ?';
        $params[] = normalizeFormDone($filters['form_done']);
        $types .= 's';
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'DATE(created_at) >= ?';
        $params[] = $filters['date_from'];
        $types .= 's';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'DATE(created_at) <= ?';
        $params[] = $filters['date_to'];
        $types .= 's';
    }
    return $where;
}

?>
