<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('campaigns.export.page', []);
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = $_GET['status'] ?? '';
$format = strtolower($_GET['format'] ?? 'csv');
$filters = [];
if (!empty($dateFrom)) { $filters['date_from'] = $dateFrom; }
if (!empty($dateTo)) { $filters['date_to'] = $dateTo; }
if (!empty($search)) { $filters['search'] = $search; }
if ($status !== '') { $filters['status'] = $status; }
$visible = getScopedVisibleCampaignIdsForUser($userId, $role);
if ($visible !== null) $filters['campaign_ids'] = array_keys($visible);
$data = getCampaignsList($filters, 10000, 1);
$rows = $data['campaigns'];
$format = $format === 'xls' ? 'xls' : 'csv';
@set_time_limit(0);
while (ob_get_level() > 0) { @ob_end_clean(); }
if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="campaigns_'.date('Y-m-d').'.xls"');
    echo "<table border=1><tr><th>ID</th><th>Name</th><th>Code</th><th>Status</th><th>Start</th><th>End</th><th>Total Leads</th></tr>";
    foreach($rows as $r){
        echo '<tr>'
            .'<td>'.(int)($r['id']??0).'</td>'
            .'<td>'.htmlspecialchars($r['name']??'').'</td>'
            .'<td>'.htmlspecialchars($r['code']??'').'</td>'
            .'<td>'.htmlspecialchars($r['status']??'').'</td>'
            .'<td>'.htmlspecialchars($r['start_date']??'').'</td>'
            .'<td>'.htmlspecialchars($r['end_date']??'').'</td>'
            .'<td>'.(int)($r['total_leads']??0).'</td>'
            .'</tr>';
    }
    echo '</table>';
    exit;
}
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="campaigns_'.date('Y-m-d').'.csv"');
$out = fopen('php://output','w');
fputcsv($out,['id','name','code','status','start_date','end_date','total_leads']);
foreach($rows as $r){
    fputcsv($out,[
        $r['id']??'',
        $r['name']??'',
        $r['code']??'',
        $r['status']??'',
        $r['start_date']??'',
        $r['end_date']??'',
        $r['total_leads']??'',
    ]);
}
fclose($out);
exit;
