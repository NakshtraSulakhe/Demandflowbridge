<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.export', ['admin']);
ensureDatabaseSchema();

$conn = getDbConnection();

$safeCsvCell = function($v): string {
    $s = (string)$v;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = trim(preg_replace('/\\s+/', ' ', $s));
    $first = $s !== '' ? $s[0] : '';
    if ($first !== '' && in_array($first, ['=','+','-','@'], true)) $s = "'" . $s;
    return $s;
};

$filters = dbmsExplorerFiltersFromRequest();
$params = [];
$types = '';
$whereSql = dbmsBuildExplorerWhere($filters, $params, $types);

$limit = 50000;
$workflowExpr = dbmsWorkflowStatusSql('c');
$queueEnteredExpr = dbmsContactsHasColumn('queue_entered_at')
    ? "COALESCE(c.queue_entered_at, c.assigned_at, c.created_at)"
    : "COALESCE(c.assigned_at, c.created_at)";

$hasResearchSubmittedAt = dbmsContactsHasColumn('research_submitted_at');
$hasResearchSubmittedBy = dbmsContactsHasColumn('research_submitted_by');
$hasCdqaDecision = dbmsContactsHasColumn('cdqa_decision');
$hasCdqaDecisionAt = dbmsContactsHasColumn('cdqa_decision_at');
$hasCdqaDecisionBy = dbmsContactsHasColumn('cdqa_decision_by');
$hasCdqaComment = dbmsContactsHasColumn('cdqa_comment');
$hasReworkCount = dbmsContactsHasColumn('rework_count');

$sql = "
  SELECT
    c.*,
    {$workflowExpr} AS workflow_queue,
    {$queueEnteredExpr} AS queue_entered_at_effective,
    TIMESTAMPDIFF(DAY, {$queueEnteredExpr}, NOW()) AS queue_age_days,
    u_assigned.full_name AS assigned_to_name,
    u_owner.full_name AS owner_name,
    u_created.full_name AS created_by_name,
    " . ($hasResearchSubmittedBy ? "u_research.full_name AS research_submitted_by_name," : "'' AS research_submitted_by_name,") . "
    " . ($hasCdqaDecisionBy ? "u_cdqa.full_name AS cdqa_decision_by_name," : "'' AS cdqa_decision_by_name,") . "
    b.original_filename AS batch_filename,
    b.uploaded_at AS batch_uploaded_at,
    u_batch.full_name AS batch_uploaded_by_name
  FROM dbms_contacts c
  LEFT JOIN users u_assigned ON u_assigned.id = c.assigned_to_user_id
  LEFT JOIN users u_owner ON u_owner.id = c.owner_user_id
  LEFT JOIN users u_created ON u_created.id = c.created_by
  " . ($hasResearchSubmittedBy ? "LEFT JOIN users u_research ON u_research.id = c.research_submitted_by" : "") . "
  " . ($hasCdqaDecisionBy ? "LEFT JOIN users u_cdqa ON u_cdqa.id = c.cdqa_decision_by" : "") . "
  LEFT JOIN dbms_batches b ON b.id = c.batch_id
  LEFT JOIN users u_batch ON u_batch.id = b.uploaded_by
  $whereSql
  ORDER BY c.id DESC
  LIMIT $limit
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database error.";
    exit;
}
if ($types !== '') mysqliBindParams($stmt, $types, $params);
$stmt->execute();
$rs = $stmt->get_result();

@set_time_limit(0);
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
$fname = 'dbms_export_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
$cols = [
    'id' => 'DBMS Record ID',
    'workflow_queue' => 'Workflow Status',
    'assigned_department' => 'Assigned Department',
    'assigned_to_name' => 'Assigned To',
    'assigned_at' => 'Assigned At',
    'queue_entered_at_effective' => 'Queue Entered At',
    'queue_age_days' => 'Queue Age (Days)',
    'batch_id' => 'Upload Batch ID',
    'batch_filename' => 'Batch File',
    'batch_uploaded_at' => 'Batch Uploaded At',
    'batch_uploaded_by_name' => 'Batch Uploaded By',
    'created_at' => 'Record Created At',
    'updated_at' => 'Record Updated At',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'email' => 'Email',
    'job_title' => 'Job Title',
    'seniority' => 'Seniority',
    'department' => 'Departments',
    'company_name' => 'Company Name',
    'employee_count' => 'Employees',
    'employee_size_bracket' => 'Employee Size Bracket',
    'industry' => 'Industry',
    'linkedin_url' => 'Prospect Linkedin URL',
    'website' => 'Website',
    'company_linkedin_url' => 'Company Linkedin URL',
    'prospect_location' => 'Prospect Location',
    'prospect_country' => 'Prospect Country',
    'company_address' => 'Company Address',
    'company_city' => 'Company City',
    'company_state' => 'Company State',
    'post_code' => 'Post Code',
    'company_country' => 'Company Country',
    'company_phone' => 'Company Phone',
    'extension' => 'Phone Extension',
    'direct_number' => 'Direct Number',
    'revenue_link' => 'Revenue Link',
    'revenue' => 'Annual Revenue',
    'revenue_bracket' => 'Revenue Bracket',
    'total_funding' => 'Total Funding',
    'industry_keywords' => 'Industry Keywords',
    'technologies' => 'Technologies',
    'sic' => 'SIC Codes',
    'naics' => 'NAICS Codes',
    'retail_locations' => 'Number of Retail Locations',
    'email_status' => 'Email Status',
    'last_email_status' => 'Last Email Status',
    'email_status_at' => 'Email Timestamp',
    'intent_topics' => 'Intent Topics',
    'phone_status' => 'Phone Connection Status',
    'phone_status_at' => 'Phone Timestamp',
    'data_status' => 'Data Status',
    'confidence_score' => 'Ranking',
    'duplicate_of_contact_id' => 'Duplicate Of Contact ID',
    'owner_name' => 'Owner',
    'created_by_name' => 'Created By',
    'email_norm' => 'Email (Normalized)',
    'linkedin_norm' => 'LinkedIn (Normalized)',
    'website_norm' => 'Website (Normalized)',
    'stage' => 'Legacy Stage',
    'review_status' => 'Legacy Review Status',
];

if ($hasResearchSubmittedAt) $cols['research_submitted_at'] = 'Research Submitted At';
if ($hasResearchSubmittedBy) $cols['research_submitted_by_name'] = 'Research Submitted By';
if ($hasCdqaDecision) $cols['cdqa_decision'] = 'CDQA Decision';
if ($hasCdqaDecisionAt) $cols['cdqa_decision_at'] = 'CDQA Decision At';
if ($hasCdqaDecisionBy) $cols['cdqa_decision_by_name'] = 'CDQA Decision By';
if ($hasCdqaComment) $cols['cdqa_comment'] = 'CDQA Comment';
if ($hasReworkCount) $cols['rework_count'] = 'Rework Count';

fputcsv($out, array_values($cols));
while ($row = $rs->fetch_assoc()) {
    $line = [];
    foreach (array_keys($cols) as $k) {
        $line[] = $safeCsvCell($row[$k] ?? '');
    }
    fputcsv($out, $line);
}
fclose($out);
$stmt->close();
exit;
