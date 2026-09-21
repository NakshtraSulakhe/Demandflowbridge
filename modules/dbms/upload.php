<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('dbms.upload', ['admin']);
ensureDatabaseSchema();
ensureCsrfToken();

$user = getCurrentUser() ?: [];
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$tableColsCache = [];
$getTableColumns = function(string $table) use (&$tableColsCache, $conn): array {
    $t = strtolower(trim($table));
    if ($t === '') return [];
    if (isset($tableColsCache[$t]) && is_array($tableColsCache[$t])) return $tableColsCache[$t];
    $cols = [];
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if ($stmt) {
        $stmt->bind_param('s', $t);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $c = strtolower((string)($row['COLUMN_NAME'] ?? ''));
            if ($c !== '') $cols[$c] = true;
        }
        $stmt->close();
    }
    $tableColsCache[$t] = $cols;
    return $cols;
};
$tableHasColumn = function(string $table, string $col) use ($getTableColumns): bool {
    $cols = $getTableColumns($table);
    return isset($cols[strtolower($col)]);
};
$prepareDynamicInsert = function(string $table, array $data) use ($conn, $tableHasColumn): mysqli_stmt {
    $cols = [];
    $vals = [];
    foreach ($data as $k => $v) {
        $k = trim((string)$k);
        if ($k === '') continue;
        if (!$tableHasColumn($table, $k)) continue;
        $cols[] = $k;
        $vals[] = $v;
    }
    if (empty($cols)) {
        throw new RuntimeException('DBMS schema mismatch (no insertable columns found).');
    }
    $q = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($q)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Database error.');

    $types = '';
    $bindValues = [];
    foreach ($vals as $i => $v) {
        if (is_int($v)) $types .= 'i';
        elseif (is_float($v)) $types .= 'd';
        else $types .= 's';
        $bindValues[$i] = $v;
    }
    $params = [$types];
    foreach ($bindValues as $i => &$bv) {
        $params[] = &$bv;
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
    return $stmt;
};

$bindStmt = function(mysqli_stmt $stmt, string $types, array &$values): void {
    $params = [$types];
    foreach ($values as $i => &$v) {
        $params[] = &$v;
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
};

$prepareInsertForColumns = function(string $table, array $cols) use ($conn, $tableHasColumn): mysqli_stmt {
    $use = [];
    foreach ($cols as $c) {
        $c = trim((string)$c);
        if ($c === '') continue;
        if ($tableHasColumn($table, $c)) $use[] = $c;
    }
    if (empty($use)) throw new RuntimeException('DBMS schema mismatch (no insertable columns found).');
    $q = implode(',', array_fill(0, count($use), '?'));
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $use) . "`) VALUES ($q)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Database error.');
    return $stmt;
};

$templateHeaders = [
    'First Name',
    'Last Name',
    'Email',
    'Job Title',
    'Seniority',
    'Departments',
    'Company Name',
    'Employees',
    'Employee Size Bracket',
    'Industry',
    'Prospect Linkedin URL',
    'Website',
    'Company Linkedin URL',
    'Prospect Location',
    'Prospect Country',
    'Company Address',
    'Company City',
    'Company State',
    'Post Code',
    'Company Country',
    'Company Phone',
    'Phone Extension',
    'Direct Number',
    'Revenue Link',
    'Annual Revenue',
    'Revenue Bracket',
    'Total Funding',
    'Industry Keywords',
    'Technologies',
    'SIC Codes',
    'NAICS Codes',
    'Number of Retail Locations',
    'Email Status',
    'Last Email Status',
    'Email Timestamp',
    'Intent Topics',
    'Phone Connection Status',
    'Phone Timestamp',
    'Data Status',
    'Ranking',
];

$templateFieldLabels = [
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'email' => 'Email',
    'job_title' => 'Job Title',
    'seniority' => 'Seniority',
    'departments' => 'Departments',
    'company_name' => 'Company Name',
    'employees' => 'Employees',
    'employee_size_bracket' => 'Employee Size Bracket',
    'industry' => 'Industry',
    'prospect_linkedin_url' => 'Prospect Linkedin URL',
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
    'phone_extension' => 'Phone Extension',
    'direct_number' => 'Direct Number',
    'revenue_link' => 'Revenue Link',
    'annual_revenue' => 'Annual Revenue',
    'revenue_bracket' => 'Revenue Bracket',
    'total_funding' => 'Total Funding',
    'industry_keywords' => 'Industry Keywords',
    'technologies' => 'Technologies',
    'sic_codes' => 'SIC Codes',
    'naics_codes' => 'NAICS Codes',
    'retail_locations' => 'Number of Retail Locations',
    'email_status' => 'Email Status',
    'last_email_status' => 'Last Email Status',
    'email_timestamp' => 'Email Timestamp',
    'intent_topics' => 'Intent Topics',
    'phone_connection_status' => 'Phone Connection Status',
    'phone_timestamp' => 'Phone Timestamp',
    'data_status' => 'Data Status',
    'ranking' => 'Ranking',
];
$optionalUploadFields = function_exists('dbmsUploadOptionalFields')
    ? dbmsUploadOptionalFields()
    : ['email_status','last_email_status','email_timestamp','intent_topics','phone_connection_status','phone_timestamp','data_status','ranking'];
$canonicalKey = static function(string $k): string {
    $k = strtolower(trim($k));
    $aliases = [
        'departments' => 'department',
        'company' => 'company_name',
        'sic_codes' => 'sic',
        'naics_codes' => 'naics',
        'phone_extension' => 'extension',
        'phone_connection_status' => 'phone_status',
        'email_timestamp' => 'email_status_at',
        'phone_timestamp' => 'phone_status_at',
        'annual_revenue' => 'revenue',
        'employees' => 'employee_count',
        'revenue_link' => 'revenue_link',
        'prospect_linkedin_url' => 'linkedin_url',
        'company_linkedin_url' => 'company_linkedin_url',
        'ranking' => 'confidence_score',
    ];
    return $aliases[$k] ?? $k;
};
$requiredFieldLabels = [];
foreach ($templateFieldLabels as $fieldKey => $fieldLabel) {
    if (in_array($fieldKey, $optionalUploadFields, true)) {
        continue;
    }
    $requiredFieldLabels[$canonicalKey($fieldKey)] = $fieldLabel;
}
$requiredFields = function_exists('dbmsUploadRequiredFields')
    ? dbmsUploadRequiredFields()
    : array_keys($requiredFieldLabels);
$optionalFieldLabels = [];
foreach ($templateFieldLabels as $fieldKey => $fieldLabel) {
    if (in_array($fieldKey, $optionalUploadFields, true)) {
        $optionalFieldLabels[] = $fieldLabel;
    }
}

if (isset($_GET['download_template']) && (string)$_GET['download_template'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dbms_upload_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $templateHeaders);
    fputcsv($out, array_fill(0, count($templateHeaders), ''));
    fclose($out);
    exit;
}

$masterFields = [
    'name' => 'Name',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'email' => 'Email',
    'direct_number' => 'Direct Number',
    'company_phone' => 'Company Phone',
    'extension' => 'Extension',
    'job_title' => 'Job Title',
    'seniority' => 'Seniority',
    'department' => 'Department',
    'departments' => 'Departments',
    'company_name' => 'Company Name',
    'company' => 'Company',
    'employee_size_bracket' => 'Employee Size Bracket',
    'industry' => 'Industry',
    'website' => 'Website',
    'revenue_link' => 'Revenue Link',
    'prospect_location' => 'Prospect Location',
    'prospect_country' => 'Prospect Country',
    'company_address' => 'Company Address',
    'company_city' => 'Company City',
    'company_state' => 'Company State',
    'post_code' => 'Post Code',
    'company_country' => 'Company Country',
    'annual_revenue' => 'Annual Revenue',
    'revenue_bracket' => 'Revenue Bracket',
    'total_funding' => 'Total funding',
    'funding' => 'Funding',
    'employees' => 'Employees',
    'employee_count' => 'Employee Count',
    'city' => 'City',
    'state' => 'State',
    'country' => 'Country',
    'address' => 'Address',
    'postal_code' => 'Postal Code',
    'linkedin_url' => 'LinkedIn URL',
    'prospect_linkedin_url' => 'Prospect Linkedin URL',
    'company_linkedin_url' => 'Company Linkedin URL',
    'technologies' => 'Technologies',
    'sic' => 'SIC',
    'sic_codes' => 'SIC codes',
    'naics' => 'NAICS',
    'naics_codes' => 'NAICS codes',
    'retail_locations' => 'Number of Retail Locations',
    'email_status' => 'Email Status',
    'last_email_status' => 'Last Email Status',
    'email_timestamp' => 'Email Timestamp',
    'phone_connection_status' => 'Phone Connection Status',
    'phone_timestamp' => 'Phone Timestamp',
    'data_status' => 'Data Status',
    'industry_keywords' => 'Industry Keywords',
    'intent_topics' => 'Intent Topics',
    'ranking' => 'Ranking',
];

$isCdqaReady = function(array $row): bool {
    if (function_exists('dbmsIsCdqaReadyRecord')) {
        return dbmsIsCdqaReadyRecord($row);
    }
    foreach (['first_name','last_name','job_title','company_name','industry','prospect_country'] as $field) {
        if (trim((string)($row[$field] ?? '')) === '') return false;
    }
    return trim((string)($row['email'] ?? '')) !== ''
        || trim((string)($row['linkedin_url'] ?? '')) !== ''
        || trim((string)($row['website'] ?? '')) !== ''
        || trim((string)($row['direct_number'] ?? '')) !== '';
};

$normKey = function(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return $s;
};

$sanitize = function($v, int $maxLen = 255): string {
    $v = (string)$v;
    $v = str_replace(["\0", "\r"], ['', "\n"], $v);
    $v = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $v);
    $v = trim($v);
    $v = preg_replace("/[ \\t]+/", ' ', $v);
    $v = preg_replace("/\\n{3,}/", "\n\n", $v);
    if ($maxLen > 0 && strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
    return $v;
};

$normEmail = function(?string $v) use ($sanitize): array {
    $v = $sanitize((string)$v, 190);
    if ($v === '') return ['', ''];
    $v = strtolower($v);
    $v = preg_replace('/\\s+/', '', $v);
    return [$v, $v];
};

$normUrl = function(?string $v) use ($sanitize): array {
    $v = $sanitize((string)$v, 255);
    if ($v === '') return ['', ''];
    $v = preg_replace('/\\s+/', '', $v);
    $v = preg_replace('/\\?.*$/', '', $v);
    if (str_starts_with(strtolower($v), 'www.')) $v = 'https://' . $v;
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    $v = rtrim($v, '/');
    return [$v, strtolower($v)];
};

$normLinkedIn = function(?string $v) use ($sanitize): array {
    $v = $sanitize((string)$v, 255);
    if ($v === '') return ['', ''];
    $v = preg_replace('/\\s+/', '', $v);
    if (str_starts_with(strtolower($v), 'linkedin.com')) $v = 'https://' . $v;
    if (str_starts_with(strtolower($v), 'www.linkedin.com')) $v = 'https://' . $v;
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    $v = preg_replace('~^https?://(www\\.)?linkedin\\.com~i', 'https://www.linkedin.com', $v);
    $v = preg_replace('~\\?.*$~', '', $v);
    $v = rtrim($v, '/');
    return [$v, strtolower($v)];
};

$normPhone = function(?string $v) use ($sanitize): array {
    $raw = $sanitize((string)$v, 40);
    if ($raw === '') return ['', ''];
    $t = preg_replace('/[^0-9+]+/', '', $raw);
    if ($t !== '' && $t[0] !== '+') $t = '+' . ltrim($t, '+');
    return [$raw, $t];
};

$normCountry = function(?string $v) use ($sanitize): string {
    $v = $sanitize((string)$v, 120);
    if ($v === '') return '';
    $lc = strtolower($v);
    if (in_array($lc, ['usa','us','u.s.','united states','united states of america'], true)) return 'United States';
    if (in_array($lc, ['uae','u.a.e','united arab emirates'], true)) return 'United Arab Emirates';
    if ($lc === 'uk' || $lc === 'u.k.' || $lc === 'united kingdom') return 'United Kingdom';
    return ucwords($lc);
};

$isValidEmail = function(string $email): bool {
    if ($email === '') return false;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    return true;
};

$parseXlsxRows = function(string $filePath): iterable {
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
        throw new RuntimeException('XLSX support is not enabled on this server.');
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) throw new RuntimeException('Unable to read XLSX file.');
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $xr = new XMLReader();
        $xr->XML($sharedXml);
        $cur = '';
        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 't') {
                $cur .= $xr->readInnerXML();
            }
            if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'si') {
                $shared[] = html_entity_decode($cur, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cur = '';
            }
        }
        $xr->close();
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!is_string($sheetXml) || $sheetXml === '') {
        $zip->close();
        throw new RuntimeException('XLSX sheet1.xml not found.');
    }
    $zip->close();

    $colToIndex = function(string $ref): int {
        $col = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        if ($col === '') return 0;
        $n = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $n = ($n * 26) + (ord($col[$i]) - 64);
        }
        return $n - 1;
    };

    $xr = new XMLReader();
    $xr->XML($sheetXml);
    $row = [];
    $cellRef = '';
    $cellType = '';
    $inV = false;
    $v = '';
    $inInline = false;
    $inline = '';
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'row') {
            $row = [];
        } elseif ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'c') {
            $cellRef = (string)$xr->getAttribute('r');
            $cellType = (string)$xr->getAttribute('t');
            $inV = false;
            $v = '';
            $inInline = false;
            $inline = '';
        } elseif ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'v') {
            $inV = true;
            $v = '';
        } elseif ($xr->nodeType === XMLReader::TEXT && $inV) {
            $v .= $xr->value;
        } elseif ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'v') {
            $inV = false;
        } elseif ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'is') {
            $inInline = true;
            $inline = '';
        } elseif ($xr->nodeType === XMLReader::ELEMENT && $inInline && $xr->name === 't') {
            $inline .= $xr->readInnerXML();
        } elseif ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'is') {
            $inInline = false;
        } elseif ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'c') {
            $idx = $cellRef !== '' ? $colToIndex($cellRef) : 0;
            $val = '';
            if ($cellType === 's') {
                $si = (int)$v;
                $val = isset($shared[$si]) ? (string)$shared[$si] : '';
            } elseif ($cellType === 'inlineStr') {
                $val = html_entity_decode($inline, ENT_QUOTES | ENT_XML1, 'UTF-8');
            } else {
                $val = (string)$v;
            }
            $row[$idx] = trim(preg_replace('/\s+/', ' ', $val));
        } elseif ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'row') {
            if (empty($row)) continue;
            $max = max(array_keys($row));
            $out = array_fill(0, $max + 1, '');
            foreach ($row as $i => $vv) $out[(int)$i] = (string)$vv;
            yield $out;
        }
    }
    $xr->close();
};

$readCsvRow = function($fh): array|false {
    $row = fgetcsv($fh);
    if ($row === false) return false;
    if (!is_array($row)) return false;
    $out = [];
    foreach ($row as $c) $out[] = trim(preg_replace('/\s+/', ' ', (string)$c));
    return $out;
};

$readHtmlTable = function(string $tmpPath): array {
    $data = file_get_contents($tmpPath);
    if ($data === false) throw new RuntimeException('Unable to read XLS content.');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $dom->loadHTML($data);
    libxml_clear_errors();
    if (!$ok) throw new RuntimeException('Unable to parse XLS content. Please Save As XLSX or CSV.');
    $rows = [];
    $trs = $dom->getElementsByTagName('tr');
    foreach ($trs as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            if (!($td instanceof DOMElement)) continue;
            $tag = strtolower($td->tagName);
            if ($tag !== 'td' && $tag !== 'th') continue;
            $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent ?? ''));
        }
        if (!empty($cells)) $rows[] = $cells;
    }
    return $rows;
};

$message = '';
$messageType = 'success';
$stage = isset($_POST['stage']) ? (string)$_POST['stage'] : 'upload';
$preview = null;
$mapping = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) throw new RuntimeException('Invalid request token.');

        if ($stage === 'process') {
            $origName = (string)($_POST['orig_name'] ?? '');
            $storedName = (string)($_POST['stored_name'] ?? '');
            $ext = strtolower((string)($_POST['ext'] ?? ''));
            if (!in_array($ext, ['csv','xlsx','xls'], true)) throw new RuntimeException('Invalid upload session.');
            $tmp = __DIR__ . '/../../uploads/dbms_uploads/' . basename($storedName);
            if (!is_file($tmp)) throw new RuntimeException('Upload file not found. Please upload again.');

            $map = $_POST['map'] ?? [];
            if (!is_array($map)) $map = [];
            $mapping = [];
            foreach ($map as $src => $dst) {
                $src = trim((string)$src);
                $dst = trim((string)$dst);
                if ($src === '' || $dst === '') continue;
                if (!array_key_exists($dst, $masterFields)) continue;
                $mapping[$src] = $canonicalKey($dst);
            }
            if (empty($mapping)) throw new RuntimeException('Map at least one column.');
            $mappedTargets = array_values(array_unique(array_values($mapping)));
            $missingRequiredMappings = [];
            foreach ($requiredFields as $requiredField) {
                if (!in_array($requiredField, $mappedTargets, true)) {
                    $missingRequiredMappings[] = $requiredFieldLabels[$requiredField] ?? $requiredField;
                }
            }
            if (!empty($missingRequiredMappings)) {
                throw new RuntimeException('Map all mandatory columns before processing: ' . implode(', ', $missingRequiredMappings));
            }

            $getRows = function() use ($ext, $tmp, $readCsvRow, $readHtmlTable, $parseXlsxRows): array {
                if ($ext === 'csv') {
                    $fh = fopen($tmp, 'r');
                    if (!$fh) throw new RuntimeException('Unable to read uploaded file.');
                    $rows = [];
                    while (($r = $readCsvRow($fh)) !== false) {
                        if (empty($r)) continue;
                        $rows[] = $r;
                    }
                    fclose($fh);
                    return $rows;
                }
                if ($ext === 'xls') return $readHtmlTable($tmp);
                $rows = [];
                foreach ($parseXlsxRows($tmp) as $r) $rows[] = $r;
                return $rows;
            };

            $rowsAll = $getRows();
            if (empty($rowsAll)) throw new RuntimeException('Header row is missing.');
            $rawHeader = $rowsAll[0] ?? [];
            if (!is_array($rawHeader) || empty($rawHeader)) throw new RuntimeException('Header row is missing.');

            $header = [];
            foreach ($rawHeader as $h) {
                $hn = $sanitize((string)$h, 120);
                if ($hn === '') $hn = 'col_' . count($header);
                $header[] = $hn;
            }
            $idxByHeader = [];
            foreach ($header as $i => $hn) $idxByHeader[$hn] = (int)$i;

            $dir = __DIR__ . '/../../uploads/dbms_batches';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $batchNotes = trim((string)($_POST['notes'] ?? ''));
            $batchData = [
                'original_filename' => $origName,
                'stored_filename' => $storedName,
                'uploaded_by' => $userId,
            ];
            if ($tableHasColumn('dbms_batches', 'source')) $batchData['source'] = 'upload';
            if ($tableHasColumn('dbms_batches', 'status')) $batchData['status'] = 'uploaded';
            if ($tableHasColumn('dbms_batches', 'notes')) $batchData['notes'] = $batchNotes;
            if ($tableHasColumn('dbms_batches', 'started_at')) $batchData['started_at'] = date('Y-m-d H:i:s');
            $stmtB = $prepareDynamicInsert('dbms_batches', $batchData);
            $stmtB->execute();
            $batchId = (int)$stmtB->insert_id;
            $stmtB->close();
            if ($batchId <= 0) throw new RuntimeException('Database error.');

            $dupEmailStmt = null;
            $dupLiStmt = null;
            if ($tableHasColumn('dbms_contacts', 'email_norm')) {
                $dupEmailStmt = $conn->prepare("SELECT id FROM dbms_contacts WHERE email_norm = ? LIMIT 1");
            }
            if ($tableHasColumn('dbms_contacts', 'linkedin_norm')) {
                $dupLiStmt = $conn->prepare("SELECT id FROM dbms_contacts WHERE linkedin_norm = ? LIMIT 1");
            }

            $contactColsWanted = [
                'batch_id',
                'duplicate_of_contact_id',
                'source',
                'stage',
                'review_status',
                'workflow_status',
                'workflow_updated_at',
                'queue_entered_at',
                'data_status',
                'name',
                'first_name',
                'last_name',
                'email',
                'email_norm',
                'email_status',
                'last_email_status',
                'email_status_at',
                'direct_number',
                'direct_number_norm',
                'company_phone',
                'company_phone_norm',
                'extension',
                'job_title',
                'seniority',
                'department',
                'company_name',
                'industry',
                'website',
                'website_norm',
                'revenue_link',
                'employee_size_bracket',
                'employee_count',
                'revenue',
                'revenue_bracket',
                'total_funding',
                'prospect_location',
                'prospect_country',
                'company_address',
                'company_city',
                'company_state',
                'post_code',
                'company_country',
                'address',
                'city',
                'state',
                'country',
                'postal_code',
                'linkedin_url',
                'linkedin_norm',
                'company_linkedin_url',
                'company_linkedin_norm',
                'technologies',
                'sic',
                'naics',
                'industry_keywords',
                'retail_locations',
                'intent_topics',
                'phone_status',
                'phone_status_at',
                'confidence_score',
                'created_by',
                'created_at',
            ];
            $contactColsUsed = [];
            foreach ($contactColsWanted as $c) {
                if ($tableHasColumn('dbms_contacts', $c)) $contactColsUsed[] = $c;
            }
            if (empty($contactColsUsed)) throw new RuntimeException('DBMS schema mismatch (dbms_contacts missing).');
            $ins = $prepareInsertForColumns('dbms_contacts', $contactColsUsed);

            $batchRowColsWanted = ['batch_id','row_num','status','reason','contact_id','raw_json','normalized_json','created_at'];
            $batchRowColsUsed = [];
            foreach ($batchRowColsWanted as $c) {
                if ($tableHasColumn('dbms_batch_rows', $c)) $batchRowColsUsed[] = $c;
            }
            if (empty($batchRowColsUsed)) throw new RuntimeException('DBMS schema mismatch (dbms_batch_rows missing).');
            $insRow = $prepareInsertForColumns('dbms_batch_rows', $batchRowColsUsed);

            $total = 0; $pendingCdqa = 0; $pendingResearch = 0; $rej = 0; $dup = 0;
            $valid = 0; $append = 0;
            $rowNum = 1;
            foreach (array_slice($rowsAll, 1) as $r) {
                $rowNum++;
                if (!is_array($r)) continue;
                $total++;

                $src = [];
                foreach ($idxByHeader as $hn => $i) {
                    $src[$hn] = $sanitize((string)($r[$i] ?? ''), 500);
                }

                $rec = [];
                foreach ($mapping as $srcHdr => $dstKey) {
                    $rec[$dstKey] = $src[$srcHdr] ?? '';
                }

                $rec['name'] = $sanitize((string)($rec['name'] ?? ''), 240);
                $rec['first_name'] = $sanitize((string)($rec['first_name'] ?? ''), 120);
                $rec['last_name'] = $sanitize((string)($rec['last_name'] ?? ''), 120);
                $rec['company_name'] = $sanitize((string)($rec['company_name'] ?? ''), 200);
                $rec['job_title'] = $sanitize((string)($rec['job_title'] ?? ''), 180);
                $rec['department'] = $sanitize((string)($rec['department'] ?? ''), 120);
                $rec['industry'] = $sanitize((string)($rec['industry'] ?? ''), 160);
                $rec['seniority'] = $sanitize((string)($rec['seniority'] ?? ''), 80);
                $rec['employee_size_bracket'] = $sanitize((string)($rec['employee_size_bracket'] ?? ''), 60);
                $rec['revenue'] = $sanitize((string)($rec['revenue'] ?? ''), 80);
                $rec['revenue_link'] = $sanitize((string)($rec['revenue_link'] ?? ''), 500);
                $rec['revenue_bracket'] = $sanitize((string)($rec['revenue_bracket'] ?? ''), 80);
                $rec['total_funding'] = $sanitize((string)($rec['total_funding'] ?? ''), 80);
                $rec['funding'] = $sanitize((string)($rec['funding'] ?? ''), 80);
                $rec['employee_count'] = $sanitize((string)($rec['employee_count'] ?? ''), 60);
                $rec['prospect_location'] = $sanitize((string)($rec['prospect_location'] ?? ''), 180);
                $rec['city'] = $sanitize((string)($rec['city'] ?? ''), 120);
                $rec['state'] = $sanitize((string)($rec['state'] ?? ''), 120);
                $rec['country'] = $normCountry((string)($rec['country'] ?? ''));
                $rec['address'] = $sanitize((string)($rec['address'] ?? ''), 255);
                $rec['postal_code'] = $sanitize((string)($rec['postal_code'] ?? ''), 40);
                $rec['company_address'] = $sanitize((string)($rec['company_address'] ?? ''), 255);
                $rec['company_city'] = $sanitize((string)($rec['company_city'] ?? ''), 120);
                $rec['company_state'] = $sanitize((string)($rec['company_state'] ?? ''), 120);
                $rec['post_code'] = $sanitize((string)($rec['post_code'] ?? ''), 40);
                $rec['company_country'] = $normCountry((string)($rec['company_country'] ?? ''));
                $rec['prospect_country'] = $normCountry((string)($rec['prospect_country'] ?? ''));
                $rec['retail_locations'] = $sanitize((string)($rec['retail_locations'] ?? ''), 60);
                $rec['technologies'] = $sanitize((string)($rec['technologies'] ?? ''), 2000);
                $rec['sic'] = $sanitize((string)($rec['sic'] ?? ''), 40);
                $rec['naics'] = $sanitize((string)($rec['naics'] ?? ''), 40);
                $rec['industry_keywords'] = $sanitize((string)($rec['industry_keywords'] ?? ''), 2000);
                $rec['intent_topics'] = $sanitize((string)($rec['intent_topics'] ?? ''), 2000);
                $rec['email_status'] = $sanitize((string)($rec['email_status'] ?? ''), 20);
                $rec['last_email_status'] = $sanitize((string)($rec['last_email_status'] ?? ''), 20);
                $rec['phone_status'] = $sanitize((string)($rec['phone_status'] ?? ''), 30);
                $rec['data_status'] = $sanitize((string)($rec['data_status'] ?? ''), 20);
                $rec['confidence_score'] = (int)preg_replace('/[^0-9]+/', '', $sanitize((string)($rec['confidence_score'] ?? ''), 16));

                if ($rec['name'] !== '' && $rec['first_name'] === '' && $rec['last_name'] === '') {
                    $parts = preg_split('/\s+/', $rec['name']) ?: [];
                    if (count($parts) === 1) {
                        $rec['first_name'] = $parts[0];
                    } elseif (count($parts) > 1) {
                        $rec['first_name'] = array_shift($parts);
                        $rec['last_name'] = implode(' ', $parts);
                    }
                }

                [$email, $emailNorm] = $normEmail((string)($rec['email'] ?? ''));
                $rec['email'] = $email;
                $rec['email_norm'] = $emailNorm;

                [$direct, $directNorm] = $normPhone((string)($rec['direct_number'] ?? ''));
                $rec['direct_number'] = $direct;
                $rec['direct_number_norm'] = $directNorm;

                [$cp, $cpNorm] = $normPhone((string)($rec['company_phone'] ?? ''));
                $rec['company_phone'] = $cp;
                $rec['company_phone_norm'] = $cpNorm;
                $rec['extension'] = $sanitize((string)($rec['extension'] ?? ''), 20);

                [$web, $webNorm] = $normUrl((string)($rec['website'] ?? ''));
                $rec['website'] = $web;
                $rec['website_norm'] = $webNorm;

                [$revLink, $revLinkNorm] = $normUrl((string)($rec['revenue_link'] ?? ''));
                $rec['revenue_link'] = $revLink;

                [$li, $liNorm] = $normLinkedIn((string)($rec['linkedin_url'] ?? ''));
                $rec['linkedin_url'] = $li;
                $rec['linkedin_norm'] = $liNorm;

                [$cli, $cliNorm] = $normLinkedIn((string)($rec['company_linkedin_url'] ?? ''));
                $rec['company_linkedin_url'] = $cli;
                $rec['company_linkedin_norm'] = $cliNorm;

                $emailAt = null;
                if (isset($rec['email_status_at'])) {
                    $t = $sanitize((string)$rec['email_status_at'], 40);
                    if ($t !== '') {
                        $ts = strtotime($t);
                        if ($ts !== false) $emailAt = date('Y-m-d H:i:s', $ts);
                    }
                }

                $phoneAt = null;
                if (isset($rec['phone_status_at'])) {
                    $t = $sanitize((string)$rec['phone_status_at'], 40);
                    if ($t !== '') {
                        $ts = strtotime($t);
                        if ($ts !== false) $phoneAt = date('Y-m-d H:i:s', $ts);
                    }
                }

                $reason = '';
                $missingRequiredFields = [];
                foreach ($requiredFields as $rf) {
                    if (trim((string)($rec[$rf] ?? '')) === '') {
                        $missingRequiredFields[] = $requiredFieldLabels[$rf] ?? $rf;
                    }
                }
                if ($rec['email'] !== '' && !$isValidEmail($rec['email'])) {
                    $reason = 'Invalid email format';
                }

                $workflowStatus = 'pending_cdqa';
                $legacyState = dbmsWorkflowLegacyStateForStatus($workflowStatus);
                $contactStage = (string)($legacyState['stage'] ?? 'ready');
                $reviewStatus = (string)($legacyState['review_status'] ?? 'pending_cdqa');
                $batchRowStatus = 'valid';
                $dataStatus = 'Valid';

                if ($reason !== '') {
                    $workflowStatus = 'rejected';
                    $legacyState = dbmsWorkflowLegacyStateForStatus($workflowStatus);
                    $contactStage = (string)($legacyState['stage'] ?? 'rejected');
                    $reviewStatus = (string)($legacyState['review_status'] ?? 'rejected');
                    $batchRowStatus = 'rejected';
                    $dataStatus = 'Invalid';
                } else {
                    if (!empty($missingRequiredFields) || !$isCdqaReady($rec)) {
                        $workflowStatus = 'pending_research';
                        $legacyState = dbmsWorkflowLegacyStateForStatus($workflowStatus);
                        $contactStage = (string)($legacyState['stage'] ?? 'append');
                        $reviewStatus = (string)($legacyState['review_status'] ?? 'append_queue');
                        $batchRowStatus = 'append';
                        $dataStatus = 'Needs Research';
                        if (!empty($missingRequiredFields)) {
                            $reason = 'Missing fields: ' . implode(', ', $missingRequiredFields);
                        }
                    } else {
                    }
                }

                $duplicateOfId = 0;
                $dupType = '';
                if ($batchRowStatus !== 'rejected') {
                    if ($rec['email_norm'] !== '' && $dupEmailStmt) {
                        $dupEmailStmt->bind_param('s', $rec['email_norm']);
                        $dupEmailStmt->execute();
                        $duplicateOfId = (int)(($dupEmailStmt->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                        if ($duplicateOfId > 0) $dupType = 'email';
                    }
                    if ($duplicateOfId <= 0 && $rec['linkedin_norm'] !== '' && $dupLiStmt) {
                        $dupLiStmt->bind_param('s', $rec['linkedin_norm']);
                        $dupLiStmt->execute();
                        $duplicateOfId = (int)(($dupLiStmt->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                        if ($duplicateOfId > 0) $dupType = 'linkedin';
                    }
                    if ($duplicateOfId > 0) {
                        $workflowStatus = 'duplicate';
                        $legacyState = dbmsWorkflowLegacyStateForStatus($workflowStatus);
                        $contactStage = (string)($legacyState['stage'] ?? 'duplicate');
                        $reviewStatus = (string)($legacyState['review_status'] ?? 'duplicate');
                        $batchRowStatus = 'duplicate';
                        $dataStatus = 'Duplicate';
                        $reason = $dupType === 'linkedin' ? ('Duplicate LinkedIn (existing #' . $duplicateOfId . ')') : ('Duplicate email (existing #' . $duplicateOfId . ')');
                    }
                }

                $now = date('Y-m-d H:i:s');
                $emailNormSave = ($batchRowStatus === 'duplicate') ? null : ((string)($rec['email_norm'] ?? '') !== '' ? (string)$rec['email_norm'] : null);
                $liNormSave = ($batchRowStatus === 'duplicate') ? null : ((string)($rec['linkedin_norm'] ?? '') !== '' ? (string)$rec['linkedin_norm'] : null);

                $contactAssoc = [
                    'batch_id' => $batchId,
                    'duplicate_of_contact_id' => $duplicateOfId > 0 ? (int)$duplicateOfId : null,
                    'source' => 'upload',
                    'stage' => $contactStage,
                    'review_status' => $reviewStatus,
                    'workflow_status' => $workflowStatus,
                    'workflow_updated_at' => $now,
                    'queue_entered_at' => $now,
                    'data_status' => $dataStatus,
                    'name' => (string)($rec['name'] ?? ''),
                    'first_name' => (string)($rec['first_name'] ?? ''),
                    'last_name' => (string)($rec['last_name'] ?? ''),
                    'email' => (string)($rec['email'] ?? ''),
                    'email_norm' => $emailNormSave,
                    'email_status' => (string)($rec['email_status'] ?? ''),
                    'last_email_status' => (string)($rec['last_email_status'] ?? ''),
                    'email_status_at' => $emailAt,
                    'direct_number' => (string)($rec['direct_number'] ?? ''),
                    'direct_number_norm' => (string)($rec['direct_number_norm'] ?? ''),
                    'company_phone' => (string)($rec['company_phone'] ?? ''),
                    'company_phone_norm' => (string)($rec['company_phone_norm'] ?? ''),
                    'extension' => (string)($rec['extension'] ?? ''),
                    'job_title' => (string)($rec['job_title'] ?? ''),
                    'seniority' => (string)($rec['seniority'] ?? ''),
                    'department' => (string)($rec['department'] ?? ''),
                    'company_name' => (string)($rec['company_name'] ?? ''),
                    'industry' => (string)($rec['industry'] ?? ''),
                    'website' => (string)($rec['website'] ?? ''),
                    'website_norm' => (string)($rec['website_norm'] ?? ''),
                    'revenue_link' => (string)($rec['revenue_link'] ?? ''),
                    'employee_size_bracket' => (string)($rec['employee_size_bracket'] ?? ''),
                    'employee_count' => (string)($rec['employee_count'] ?? ''),
                    'revenue' => (string)($rec['revenue'] ?? ''),
                    'revenue_bracket' => (string)($rec['revenue_bracket'] ?? ''),
                    'total_funding' => (string)($rec['total_funding'] ?? ''),
                    'prospect_location' => (string)($rec['prospect_location'] ?? ''),
                    'prospect_country' => (string)($rec['prospect_country'] ?? ''),
                    'company_address' => (string)($rec['company_address'] ?? ''),
                    'company_city' => (string)($rec['company_city'] ?? ''),
                    'company_state' => (string)($rec['company_state'] ?? ''),
                    'post_code' => (string)($rec['post_code'] ?? ''),
                    'company_country' => (string)($rec['company_country'] ?? ''),
                    'address' => (string)($rec['address'] ?? ''),
                    'city' => (string)($rec['city'] ?? ''),
                    'state' => (string)($rec['state'] ?? ''),
                    'country' => (string)($rec['country'] ?? ''),
                    'postal_code' => (string)($rec['postal_code'] ?? ''),
                    'linkedin_url' => (string)($rec['linkedin_url'] ?? ''),
                    'linkedin_norm' => $liNormSave,
                    'company_linkedin_url' => (string)($rec['company_linkedin_url'] ?? ''),
                    'company_linkedin_norm' => (string)($rec['company_linkedin_norm'] ?? ''),
                    'technologies' => (string)($rec['technologies'] ?? ''),
                    'sic' => (string)($rec['sic'] ?? ''),
                    'naics' => (string)($rec['naics'] ?? ''),
                    'industry_keywords' => (string)($rec['industry_keywords'] ?? ''),
                    'retail_locations' => (string)($rec['retail_locations'] ?? ''),
                    'intent_topics' => (string)($rec['intent_topics'] ?? ''),
                    'phone_status' => (string)($rec['phone_status'] ?? ''),
                    'phone_status_at' => $phoneAt ?? null,
                    'confidence_score' => (int)($rec['confidence_score'] ?? 0),
                    'created_by' => $userId,
                    'created_at' => $now,
                ];

                $contactValues = [];
                foreach ($contactColsUsed as $c) {
                    $contactValues[] = $contactAssoc[$c] ?? null;
                }
                if (!isset($contactTypes)) {
                    $intCols = ['batch_id','created_by','confidence_score'];
                    $contactTypes = '';
                    foreach ($contactColsUsed as $c) {
                        $contactTypes .= in_array($c, $intCols, true) ? 'i' : 's';
                    }
                }
                $bindStmt($ins, $contactTypes, $contactValues);
                $okIns = $ins->execute();
                if (!$okIns) {
                    $errno = (int)($ins->errno ?: $conn->errno);
                    if ($errno === 1062) {
                        $workflowStatus = 'duplicate';
                        $legacyState = dbmsWorkflowLegacyStateForStatus($workflowStatus);
                        $contactStage = (string)($legacyState['stage'] ?? 'duplicate');
                        $reviewStatus = (string)($legacyState['review_status'] ?? 'duplicate');
                        $batchRowStatus = 'duplicate';
                        $dataStatus = 'Duplicate';
                        if ($reason === '') $reason = 'Duplicate identifier';
                        $emailNormSave = null;
                        $liNormSave = null;
                        if ($duplicateOfId <= 0 && $rec['email_norm'] !== '' && $dupEmailStmt) {
                            $dupEmailStmt->bind_param('s', $rec['email_norm']);
                            $dupEmailStmt->execute();
                            $duplicateOfId = (int)(($dupEmailStmt->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                        }
                        if ($duplicateOfId <= 0 && $rec['linkedin_norm'] !== '' && $dupLiStmt) {
                            $dupLiStmt->bind_param('s', $rec['linkedin_norm']);
                            $dupLiStmt->execute();
                            $duplicateOfId = (int)(($dupLiStmt->get_result()->fetch_assoc() ?: [])['id'] ?? 0);
                        }
                        if ($duplicateOfId > 0 && $reason === 'Duplicate identifier') {
                            $reason = 'Duplicate identifier (existing #' . $duplicateOfId . ')';
                        }
                        $contactAssoc['stage'] = $contactStage;
                        $contactAssoc['review_status'] = $reviewStatus;
                        $contactAssoc['workflow_status'] = $workflowStatus;
                        $contactAssoc['duplicate_of_contact_id'] = $duplicateOfId > 0 ? (int)$duplicateOfId : null;
                        $contactAssoc['data_status'] = $dataStatus;
                        $contactAssoc['email_norm'] = null;
                        $contactAssoc['linkedin_norm'] = null;
                        $contactValues = [];
                        foreach ($contactColsUsed as $c) {
                            $contactValues[] = $contactAssoc[$c] ?? null;
                        }
                        $bindStmt($ins, $contactTypes, $contactValues);
                        $okRetry = $ins->execute();
                        if (!$okRetry) throw new RuntimeException('Database error inserting contact.');
                    } else {
                        throw new RuntimeException('Database error inserting contact.');
                    }
                }
                $contactId = (int)$ins->insert_id;

                switch ($workflowStatus) {
                    case 'pending_cdqa':
                        $pendingCdqa++;
                        $valid++;
                        break;
                    case 'pending_research':
                        $pendingResearch++;
                        $append++;
                        break;
                    case 'rejected':
                        $rej++;
                        break;
                    case 'duplicate':
                        $dup++;
                        break;
                }

                $rec['workflow_status'] = $workflowStatus;
                $rec['legacy_stage'] = $contactStage;
                $rec['legacy_review_status'] = $reviewStatus;

                $rawJson = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $normJson = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $rowAssoc = [
                    'batch_id' => $batchId,
                    'row_num' => $rowNum,
                    'status' => $batchRowStatus,
                    'reason' => $reason,
                    'contact_id' => $contactId,
                    'raw_json' => $rawJson,
                    'normalized_json' => $normJson,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                $rowValues = [];
                foreach ($batchRowColsUsed as $c) {
                    $rowValues[] = $rowAssoc[$c] ?? null;
                }
                if (!isset($batchRowTypes)) {
                    $intCols = ['batch_id','row_num'];
                    $batchRowTypes = '';
                    foreach ($batchRowColsUsed as $c) {
                        $batchRowTypes .= in_array($c, $intCols, true) ? 'i' : 's';
                    }
                }
                $bindStmt($insRow, $batchRowTypes, $rowValues);
                $insRow->execute();
            }

            if ($dupEmailStmt) $dupEmailStmt->close();
            if ($dupLiStmt) $dupLiStmt->close();
            $ins->close();
            $insRow->close();

            $updatePairs = [];
            $updateValues = [];
            $updateTypes = '';
            $addUpdate = function(string $col, $val, string $type) use (&$updatePairs, &$updateValues, &$updateTypes, $tableHasColumn): void {
                if (!$tableHasColumn('dbms_batches', $col)) return;
                $updatePairs[] = "`$col` = ?";
                $updateValues[] = $val;
                $updateTypes .= $type;
            };
            $addUpdate('total_rows', $total, 'i');
            $addUpdate('valid_rows', $valid, 'i');
            $addUpdate('append_rows', $append, 'i');
            $addUpdate('pending_cdqa_rows', $pendingCdqa, 'i');
            $addUpdate('pending_research_rows', $pendingResearch, 'i');
            $addUpdate('processed_rows', $total, 'i');
            $addUpdate('rejected_rows', $rej, 'i');
            $addUpdate('duplicate_rows', $dup, 'i');
            $addUpdate('status', 'processed', 's');
            $addUpdate('completed_at', date('Y-m-d H:i:s'), 's');
            if (!empty($updatePairs)) {
                $sqlU = "UPDATE dbms_batches SET " . implode(', ', $updatePairs) . " WHERE id = ?";
                $stmtU = $conn->prepare($sqlU);
                if ($stmtU) {
                    $updateValues[] = $batchId;
                    $updateTypes .= 'i';
                    $bindStmt($stmtU, $updateTypes, $updateValues);
                    $stmtU->execute();
                    $stmtU->close();
                }
            }

            $title = 'DBMS Upload Complete';
            $body = 'Batch #' . $batchId . ': ' . number_format($total) . ' rows · Pending CDQA ' . number_format($pendingCdqa) . ' · Pending Research ' . number_format($pendingResearch) . ' · Rejected ' . number_format($rej) . ' · Duplicates ' . number_format($dup);
            $link = appBasePath() . '/modules/dbms/batch?id=' . $batchId;
            notifyUsers([$userId], 'dbms.upload_complete', $title, $body, $link, ['dedup_key' => 'dbms.upload.' . $batchId, 'importance' => 'normal', 'show_toast' => true]);

            $message = 'Upload processed. Batch #' . $batchId . ' created with ' . number_format($pendingResearch) . ' Pending Research and ' . number_format($pendingCdqa) . ' Pending CDQA records.';
            $messageType = 'success';
            header('Location: ' . appBasePath() . '/modules/dbms/batch?id=' . $batchId);
            exit;
        }

        if (!isset($_FILES['db_file']) || (int)($_FILES['db_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a CSV/XLSX/XLS file.');
        }

        $info = pathinfo((string)($_FILES['db_file']['name'] ?? ''));
        $ext = strtolower((string)($info['extension'] ?? ''));
        if (!in_array($ext, ['csv','xlsx','xls'], true)) throw new RuntimeException('Allowed formats: CSV, XLSX, XLS.');

        $tmpUpload = (string)($_FILES['db_file']['tmp_name'] ?? '');
        if ($tmpUpload === '' || !is_file($tmpUpload)) throw new RuntimeException('Upload failed.');

        $dir = __DIR__ . '/../../uploads/dbms_uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $storedName = 'dbms_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $dest = $dir . '/' . $storedName;
        if (!@move_uploaded_file($tmpUpload, $dest)) {
            if (!@rename($tmpUpload, $dest)) throw new RuntimeException('Unable to store uploaded file.');
        }

        $getRows = function() use ($ext, $dest, $readCsvRow, $readHtmlTable, $parseXlsxRows): array {
            if ($ext === 'csv') {
                $fh = fopen($dest, 'r');
                if (!$fh) throw new RuntimeException('Unable to read uploaded file.');
                $rows = [];
                while (($r = $readCsvRow($fh)) !== false) {
                    if (empty($r)) continue;
                    $rows[] = $r;
                    if (count($rows) >= 30) break;
                }
                fclose($fh);
                return $rows;
            }
            if ($ext === 'xls') return array_slice($readHtmlTable($dest), 0, 30);
            $rows = [];
            foreach ($parseXlsxRows($dest) as $r) {
                $rows[] = $r;
                if (count($rows) >= 30) break;
            }
            return $rows;
        };

        $rowsAll = $getRows();
        if (empty($rowsAll)) throw new RuntimeException('Header row is missing.');
        $rawHeader = $rowsAll[0] ?? [];
        if (!is_array($rawHeader) || empty($rawHeader)) throw new RuntimeException('Header row is missing.');

        $header = [];
        foreach ($rawHeader as $h) {
            $hn = $sanitize((string)$h, 120);
            if ($hn === '') $hn = 'col_' . count($header);
            $header[] = $hn;
        }

        $autoMap = [];
        $masterKeysByNorm = [];
        foreach (array_keys($masterFields) as $k) $masterKeysByNorm[$normKey($k)] = $k;
        $headerAliasMap = [
            'phone_extension' => 'extension',
        ];
        foreach ($header as $h) {
            $n = $normKey($h);
            $k = $masterKeysByNorm[$n] ?? ($headerAliasMap[$n] ?? null);
            if ($k) $autoMap[$h] = $k;
        }

        $preview = [
            'orig_name' => (string)($_FILES['db_file']['name'] ?? ''),
            'stored_name' => $storedName,
            'ext' => $ext,
            'header' => $header,
            'rows' => array_slice($rowsAll, 1, 5),
            'auto_map' => $autoMap,
        ];

        $stage = 'map';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

$pageTitle = 'DBMS Upload';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="h3 mb-1">DBMS Upload</div>
      <div class="text-muted small">Upload Engine</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge bg-primary">DBMS</span>
      <span class="badge bg-light text-dark border">Upload</span>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12">
      <div class="card hr-card">
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-lg-5">
              <div class="card hr-card h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Upload Template</div>
                <div class="card-body">
                  <div class="text-muted small mb-2">Use this template to upload DBMS data. First row must be headers.</div>
                  <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/upload?download_template=1">
                    <i class="bi bi-download me-1"></i>Download Template (CSV)
                  </a>
                  <div class="small text-muted mt-3">Mandatory</div>
                  <div class="small text-muted">All upload columns are mandatory except the excluded operational status fields below.</div>
                  <div class="d-flex flex-wrap gap-1 mt-2">
                    <span class="badge bg-danger">All Remaining Columns</span>
                  </div>
                  <div class="small text-muted mt-3">Optional Only</div>
                  <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($optionalFieldLabels as $optionalLabel): ?>
                      <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($optionalLabel); ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-lg-7">
              <div class="card hr-card h-100">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-shield-check me-1"></i>Upload Rules (Sanitization & Standards)</div>
                <div class="card-body">
                  <div class="row g-2">
                    <div class="col-md-6">
                      <div class="small text-muted">Sanitization</div>
                      <ul class="small mb-0">
                        <li>Trims spaces, removes hidden characters, removes double spaces</li>
                        <li>Emails are lowercased</li>
                        <li>Websites normalized to https:// and trimmed</li>
                        <li>LinkedIn URLs normalized to canonical format</li>
                        <li>Phone numbers normalized to +digits format</li>
                        <li>Countries standardized (US/USA → United States)</li>
                      </ul>
                    </div>
                    <div class="col-md-6">
                      <div class="small text-muted">Workflow Classification</div>
                      <ul class="small mb-0">
                        <li><span class="badge bg-success">Pending CDQA</span> complete records ready for quality review</li>
                        <li><span class="badge bg-warning text-dark">Pending Research</span> accepted records missing business enrichment fields</li>
                        <li><span class="badge bg-danger">Rejected</span> invalid / missing mandatory</li>
                        <li><span class="badge bg-secondary">Duplicate</span> duplicate email or LinkedIn detected</li>
                      </ul>
                    </div>
                    <div class="col-12 mt-2">
                      <div class="small text-muted">Allowed Values</div>
                      <div class="row g-2 small">
                        <div class="col-md-6">
                          <div class="text-muted">Employee Size Bracket</div>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['1-10','11-50','51-200','201-500','501-1000','1000-5000','5000-10000','10000+'] as $v): ?>
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v); ?></span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="text-muted">Revenue Bracket</div>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['Less than $1 Million','$1 Million – $10 Million','$10 Million – $20 Million','$20 Million – $50 Million','$50 Million – $100 Million','$100 Million – $250 Million','$250 Million – $500 Million','$500 Million – $1 Billion','$1 Billion – $5 Billion','More than $5 Billion'] as $v): ?>
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v); ?></span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="text-muted">Email Status</div>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['Valid','Invalid','Accept All','Bounced','Unknown'] as $v): ?>
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v); ?></span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="text-muted">Last Email Status</div>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['Delivered','Open','Clicked','Bounced'] as $v): ?>
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v); ?></span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="text-muted">Phone Connection Status</div>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['Connected','Connected - VM','Connected - Company VM','Invalid','Replaced'] as $v): ?>
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v); ?></span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="text-muted">Data Status</div>
                          <div class="d-flex flex-wrap gap-1">
                            <?php foreach (['Valid','Needs Research','Invalid','Duplicate'] as $v): ?>
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($v); ?></span>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
          <?php endif; ?>

          <?php if ($stage === 'map' && is_array($preview)): ?>
            <div class="row g-3">
              <div class="col-lg-8">
                <div class="card hr-card">
                  <div class="card-header bg-light fw-semibold"><i class="bi bi-ui-checks-grid me-1"></i>Header Mapping</div>
                  <div class="card-body">
                    <form method="post" id="dbmsProcessForm">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                      <input type="hidden" name="stage" value="process">
                      <input type="hidden" name="orig_name" value="<?php echo htmlspecialchars((string)($preview['orig_name'] ?? '')); ?>">
                      <input type="hidden" name="stored_name" value="<?php echo htmlspecialchars((string)($preview['stored_name'] ?? '')); ?>">
                      <input type="hidden" name="ext" value="<?php echo htmlspecialchars((string)($preview['ext'] ?? '')); ?>">
                      <div class="row g-2">
                        <?php foreach (($preview['header'] ?? []) as $h): ?>
                          <?php $sel = (string)(($preview['auto_map'] ?? [])[$h] ?? ''); ?>
                          <div class="col-md-6">
                            <label class="form-label small text-muted"><?php echo htmlspecialchars($h); ?></label>
                            <select class="form-select form-select-sm" name="map[<?php echo htmlspecialchars($h); ?>]">
                              <option value="">Ignore</option>
                              <?php foreach ($masterFields as $k => $lbl): ?>
                                <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $sel === $k ? 'selected' : ''; ?>>
                                  <?php echo htmlspecialchars($lbl); ?> (<?php echo htmlspecialchars($k); ?>)
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        <?php endforeach; ?>
                      </div>

                      <div class="mt-3">
                        <label class="form-label small text-muted">Batch Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Source / client / campaign / notes..."></textarea>
                      </div>

                      <div class="d-flex gap-2 mt-3">
                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(appBasePath()); ?>/modules/dbms/upload"><i class="bi bi-arrow-left"></i> Back</a>
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-play-circle me-1"></i>Process Upload</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>

              <div class="col-lg-4">
                <div class="card hr-card">
                  <div class="card-header bg-light fw-semibold"><i class="bi bi-eye me-1"></i>Preview</div>
                  <div class="card-body">
                    <div class="small text-muted">File</div>
                    <div class="fw-semibold text-truncate"><?php echo htmlspecialchars((string)($preview['orig_name'] ?? '')); ?></div>
                    <div class="small text-muted mt-2">Sample Rows</div>
                    <div class="table-responsive">
                      <table class="table table-sm table-striped align-middle hr-table mb-0">
                        <tbody>
                          <?php foreach (($preview['rows'] ?? []) as $r): ?>
                            <tr>
                              <td class="text-truncate" style="max-width:320px"><?php echo htmlspecialchars(implode(' | ', array_slice(array_map(fn($x) => (string)$x, (array)$r), 0, 6))); ?></td>
                            </tr>
                          <?php endforeach; ?>
                          <?php if (empty($preview['rows'])): ?>
                            <tr><td class="text-muted">No sample rows</td></tr>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                    <div class="small text-muted mt-2">
                      All columns are expected except Email Status, Last Email Status, Email Timestamp, Intent Topics, Phone Connection Status, Phone Timestamp, Data Status, and Ranking. Blank required values now route the row to Pending Research; only fully complete rows route to Pending CDQA.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="row g-3" id="dbmsUploadForm">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <div class="col-lg-8">
                <label class="form-label fw-semibold">Upload Database File</label>
                <input type="file" class="form-control" name="db_file" accept=".csv,.xlsx,.xls" required>
                <div class="form-text">Supported: CSV, XLSX, XLS. First row must be header.</div>
              </div>
              <div class="col-lg-4 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-upload me-1"></i>Upload & Map</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
<div class="position-fixed top-0 start-0 w-100 h-100 d-none" id="dbmsUploadOverlay" style="background:rgba(0,0,0,.35); z-index: 2000;">
  <div class="position-absolute top-50 start-50 translate-middle bg-white border rounded-4 p-4" style="width:min(520px,calc(100vw - 24px));max-width:calc(100vw - 24px);">
    <div class="d-flex align-items-center gap-2 mb-2">
      <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
      <div class="fw-semibold" id="dbmsUploadOverlayTitle">Processing…</div>
    </div>
    <div class="text-muted small" id="dbmsUploadOverlayText">Please wait. Do not close this page.</div>
    <div class="progress mt-3" style="height: 8px;">
      <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div>
    </div>
  </div>
</div>

<script>
(() => {
  const overlay = document.getElementById('dbmsUploadOverlay');
  const title = document.getElementById('dbmsUploadOverlayTitle');
  const text = document.getElementById('dbmsUploadOverlayText');
  const show = (t, msg) => {
    if (!overlay) return;
    if (title) title.textContent = t;
    if (text) text.textContent = msg;
    overlay.classList.remove('d-none');
  };
  const uploadForm = document.getElementById('dbmsUploadForm');
  if (uploadForm) {
    uploadForm.addEventListener('submit', () => show('Uploading…', 'Uploading file and reading headers. Please wait.'));
  }
  const processForm = document.getElementById('dbmsProcessForm');
  if (processForm) {
    processForm.addEventListener('submit', () => show('Processing Upload…', 'Sanitizing, standardizing, validating, and classifying records into Pending Research and Pending CDQA.'));
  }
})();
</script>
