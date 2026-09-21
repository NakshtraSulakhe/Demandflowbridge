<?php
ob_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('leads.bulk.page', []);
ensureCsrfToken();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$userName = (string)($user['full_name'] ?? '');
$userRole = (string)($user['role'] ?? '');
$userVendorId = (int)($user['vendor_id'] ?? 0);
$conn = getDbConnection();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

$norm = function(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
};

$baseFields = [
    ['key' => 'first_name', 'label' => 'First Name', 'required' => true],
    ['key' => 'last_name', 'label' => 'Last Name', 'required' => true],
    ['key' => 'job_title', 'label' => 'Job Title', 'required' => true],
    ['key' => 'email', 'label' => 'Email', 'required' => true],
    ['key' => 'prospect_linkedin_link', 'label' => 'LinkedIn', 'required' => true],
    ['key' => 'contact_phone', 'label' => 'Phone', 'required' => true],
    ['key' => 'industry', 'label' => 'Industry', 'required' => true],
    ['key' => 'company_linkedin_link', 'label' => 'Company LinkedIn', 'required' => true],
    ['key' => 'company_name', 'label' => 'Company Name', 'required' => true],
    ['key' => 'company_website', 'label' => 'Company Website', 'required' => true],
    ['key' => 'employee_size', 'label' => 'Employee Size', 'required' => true],
    ['key' => 'country', 'label' => 'Country', 'required' => true],
    ['key' => 'software_implementation_timeline', 'label' => 'Implementation Timeline', 'required' => true],
    ['key' => 'lead_comment', 'label' => 'Comment', 'required' => true],
    ['key' => 'recording_file', 'label' => 'Recording File (ZIP)', 'required' => false],
];

$skipNorms = array_fill_keys(array_map($norm, [
    'first_name','firstname','first','given_name',
    'last_name','lastname','last','surname','family_name',
    'full_name','name','contact_name',
    'job_title','jobtitle','title','designation',
    'email','email_address','emailaddress',
    'linkedin','linkedin_link','linkedin_url','linkedin_profile','linkedinprofile',
    'phone','contact_phone','phone_number','mobile','mobile_number','contact_number',
    'company','company_name','companyname','organization','organisation','account_name',
    'company_linkedin','company_linkedin_link','company_linkedin_url','companylinkedin','companylinkedinurl',
    'company_size','employee_size','employee_sizes','employees','headcount',
    'country','country_name','location',
    'industry',
    'company_website','website','company_site','domain',
    'implementation_timeline','software_implementation_timeline','timeline',
    'when_is_your_company_planning_to_implement_new_software',
    'lead_comment','comment','notes',
]), true);

$normalizeDomain = function(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';
    $s = preg_replace('/^\s*https?:\/\//i', '', $s);
    $s = preg_replace('/^\s*www\./i', '', $s);
    $s = preg_replace('/[\/?#].*$/', '', $s);
    $s = trim($s);
    $s = rtrim($s, '.');
    return $s;
};

$getCampaignMeta = function(int $campaignId) use ($conn, $baseFields, $norm, $skipNorms): array {
    $stmt = $conn->prepare("SELECT id, name FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $camp = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$camp) return ['ok' => false, 'message' => 'Campaign not found'];

    $form = getFormForCampaign($campaignId);
    $custom = [];
    $schemaFields = ($form && isset($form['schema']['fields']) && is_array($form['schema']['fields'])) ? $form['schema']['fields'] : [];
    $schemaByKey = [];
    foreach ($schemaFields as $sf) {
        if (!is_array($sf)) continue;
        if (array_key_exists('visible', $sf) && empty($sf['visible'])) continue;
        $k = (string)($sf['key'] ?? '');
        if ($k === '') continue;
        $schemaByKey[$norm($k)] = $sf;
    }

    $base = [];
    foreach ($baseFields as $bf) {
        if (!is_array($bf)) continue;
        $k = (string)($bf['key'] ?? '');
        if ($k === '') continue;
        $req = !empty($bf['required']);
        $sf = $schemaByKey[$norm($k)] ?? null;
        if (is_array($sf) && array_key_exists('required', $sf)) $req = !empty($sf['required']);
        $base[] = ['key' => $k, 'label' => (string)($bf['label'] ?? $k), 'required' => $req];
    }

    if (!empty($schemaFields)) {
        $seen = [];
        foreach ($schemaFields as $ff) {
            if (array_key_exists('visible', $ff) && empty($ff['visible'])) continue;
            $k = (string)($ff['key'] ?? '');
            if ($k === '') continue;
            $lbl = (string)($ff['label'] ?? $k);
            $t = strtolower(trim((string)($ff['type'] ?? 'text')));
            if ($t === 'file_upload') continue;
            $kn = $norm($k);
            $ln = $norm($lbl);
            if (isset($skipNorms[$kn]) || isset($skipNorms[$ln])) continue;
            if ($kn !== '' && isset($seen[$kn])) continue;
            if ($ln !== '' && isset($seen[$ln])) continue;
            if ($kn !== '') $seen[$kn] = true;
            if ($ln !== '') $seen[$ln] = true;
            $custom[] = [
                'key' => $k,
                'label' => $lbl,
                'type' => (string)($ff['type'] ?? 'text'),
                'options' => (function($ff){
                    $opts = $ff['options'] ?? [];
                    if (!is_array($opts)) return [];
                    $out = [];
                    foreach ($opts as $o) {
                        if (is_array($o)) {
                            $v = (string)($o['value'] ?? ($o['label'] ?? ''));
                        } else {
                            $v = (string)$o;
                        }
                        $v = trim($v);
                        if ($v !== '') $out[] = $v;
                    }
                    return array_values(array_unique($out));
                })($ff),
                'required' => !empty($ff['required']),
            ];
        }
    }

    $headers = array_map(fn($f) => $f['key'], $base);
    foreach ($custom as $c) $headers[] = $c['key'];
    $headers[] = 'error_reason';

    return [
        'ok' => true,
        'campaign' => ['id' => (int)$camp['id'], 'name' => (string)$camp['name']],
        'form' => $form ? ['form_id' => (int)$form['form_id'], 'name' => (string)$form['name']] : null,
        'duplicate_rules' => ($form && isset($form['schema']) && is_array($form['schema'])) ? dfGetDuplicateRulesFromSchema((array)$form['schema']) : [],
        'base_fields' => $base,
        'custom_fields' => $custom,
        'template_headers' => array_values(array_unique($headers)),
    ];
};

if (isset($_GET['action']) && $_GET['action'] === 'meta') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $res = $campaignId > 0 ? $getCampaignMeta($campaignId) : ['ok' => false, 'message' => 'Invalid campaign'];
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
    if (!in_array($format, ['xlsx','xls','csv'], true)) $format = 'xlsx';
    $meta = $campaignId > 0 ? $getCampaignMeta($campaignId) : ['ok' => false, 'message' => 'Invalid campaign'];
    if (empty($meta['ok'])) {
        http_response_code(400);
        echo htmlspecialchars((string)($meta['message'] ?? 'Invalid request'));
        exit;
    }
    ob_clean();
    $headers = $meta['template_headers'] ?? [];
    $headers = array_values(array_filter($headers, fn($h) => $h !== 'error_reason'));

    $idx = array_flip($headers);
    $customFields = $meta['custom_fields'] ?? [];

    $formatOptionsList = function(array $opts): array {
        $clean = [];
        foreach ($opts as $o) {
            $v = trim((string)$o);
            if ($v !== '') $clean[] = $v;
        }
        $clean = array_values(array_unique($clean));
        return $clean;
    };

    $acceptedInfo = [];
    if (isset($idx['email'])) $acceptedInfo['email'] = 'valid email';
    if (isset($idx['contact_phone'])) $acceptedInfo['contact_phone'] = 'digits only';
    if (isset($idx['prospect_linkedin_link'])) $acceptedInfo['prospect_linkedin_link'] = 'URL';
    if (isset($idx['company_website'])) $acceptedInfo['company_website'] = 'domain or URL';
    if (isset($idx['recording_file'])) $acceptedInfo['recording_file'] = 'file name inside ZIP (optional)';

    $formForCampaign = getFormForCampaign($campaignId);
    $schema = (array)($formForCampaign['schema'] ?? []);
    $baseOptions = $formForCampaign ? getSelectOptionsByFormSchema($schema, ['industry','employee_size','company_size','country','software_implementation_timeline']) : [];

    $optionMap = [];
    if (isset($idx['industry']) && !empty($baseOptions['industry'])) {
        $optionMap['industry'] = $formatOptionsList($baseOptions['industry']);
    }
    if (isset($idx['employee_size'])) {
        $emp = $baseOptions['employee_size'] ?? ($baseOptions['company_size'] ?? []);
        if (!empty($emp)) $optionMap['employee_size'] = $formatOptionsList($emp);
    }
    if (isset($idx['country']) && !empty($baseOptions['country'])) {
        $optionMap['country'] = $formatOptionsList($baseOptions['country']);
    }
    if (isset($idx['software_implementation_timeline']) && !empty($baseOptions['software_implementation_timeline'])) {
        $optionMap['software_implementation_timeline'] = $formatOptionsList($baseOptions['software_implementation_timeline']);
    }

    foreach ($customFields as $cf) {
        $k = (string)($cf['key'] ?? '');
        if ($k === '' || !isset($idx[$k])) continue;
        $opts = $cf['options'] ?? [];
        if (is_array($opts) && !empty($opts)) {
            $optionMap[$k] = $formatOptionsList($opts);
        }
    }
    $maxOptRows = 0;
    foreach ($headers as $h) {
        $h = (string)$h;
        $cnt = isset($optionMap[$h]) ? count($optionMap[$h]) : 0;
        if ($cnt > $maxOptRows) $maxOptRows = $cnt;
    }
    $maxOptRows = max(1, $maxOptRows);
    $acceptedRows = [];
    for ($i = 0; $i < $maxOptRows; $i++) {
        $r = array_fill(0, count($headers), '');
        if ($i === 0) $r[0] = '#ACCEPTED_VALUES';
        foreach ($headers as $ci => $h) {
            $h = (string)$h;
            if (isset($optionMap[$h]) && isset($optionMap[$h][$i])) {
                $r[$ci] = (string)$optionMap[$h][$i];
            } elseif ($i === 0 && isset($acceptedInfo[$h])) {
                $r[$ci] = (string)$acceptedInfo[$h];
            }
        }
        $acceptedRows[] = $r;
    }

    $sample = array_fill(0, count($headers), '');
    $set = function(string $k, string $v) use (&$sample, $idx) {
        if (!isset($idx[$k])) return;
        $sample[$idx[$k]] = $v;
    };
    $set('first_name', '#SAMPLE');
    $set('last_name', 'ROW');
    $set('job_title', 'Manager');
    $set('email', 'john.doe@example.com');
    $set('contact_phone', '15550123');
    $set('company_name', 'Example Inc');
    $set('country', 'United States');
    $set('recording_file', 'LD20260529ABC123.mp3');

    foreach ($customFields as $cf) {
        $k = (string)($cf['key'] ?? '');
        if ($k === '' || !isset($idx[$k])) continue;
        $opts = $cf['options'] ?? [];
        if (is_array($opts) && !empty($opts)) {
            $sample[$idx[$k]] = (string)$opts[0];
        }
    }

    $rowsOut = array_merge([$headers], $acceptedRows, [$sample]);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        $fname = 'bulk_upload_template_campaign_' . (int)$campaignId . '.csv';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        foreach ($rowsOut as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }

    if ($format === 'xls') {
        header('Content-Type: application/vnd.ms-excel');
        $fname = 'bulk_upload_template_campaign_' . (int)$campaignId . '.xls';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "<table border=1>";
        foreach ($rowsOut as $ri => $r) {
            echo "<tr>";
            foreach ($r as $cell) {
                $tag = $ri === 0 ? 'th' : 'td';
                echo "<{$tag}>" . htmlspecialchars((string)$cell) . "</{$tag}>";
            }
            echo "</tr>";
        }
        echo "</table>";
        exit;
    }

    $xmlEscape = function(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $colName = function(int $n): string {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = (int)floor(($n - 1) / 26);
        }
        return $s;
    };
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';
    foreach ($rowsOut as $ri => $r) {
        $rowNum = $ri + 1;
        $sheetXml .= '<row r="' . $rowNum . '">';
        foreach ($r as $ci => $cell) {
            $col = $colName($ci + 1);
            $ref = $col . $rowNum;
            $val = (string)$cell;
            $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $xmlEscape($val) . '</t></is></c>';
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Template" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
    $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $tmpXlsx = tempnam(sys_get_temp_dir(), 'bulk_tpl_');
    if ($tmpXlsx === false || !class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'XLSX generation not available on this server.';
        exit;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpXlsx, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Unable to create XLSX.';
        exit;
    }
    $zip->addFromString('[Content_Types].xml', $typesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRelsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $fname = 'bulk_upload_template_campaign_' . (int)$campaignId . '.xlsx';
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . (string)filesize($tmpXlsx));
    readfile($tmpXlsx);
    @unlink($tmpXlsx);
    exit;
}

$message = '';
$messageType = 'success';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $streamProgressStarted = false;
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
            throw new RuntimeException('Invalid request token.');
        }

        ensureLeadsTrackingColumns();
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) throw new RuntimeException('Select a campaign.');
        $meta = $getCampaignMeta($campaignId);
        if (empty($meta['ok'])) throw new RuntimeException((string)($meta['message'] ?? 'Campaign not found'));
        $dupRules = is_array($meta['duplicate_rules'] ?? null) ? (array)$meta['duplicate_rules'] : [];
        $campaignDetails = getCampaignDetailsById($campaignId);
        $campaignCpcRaw = is_array($campaignDetails) ? ($campaignDetails['cpc'] ?? 0) : 0;
        $campaignCpc = max(0, (int)round((float)$campaignCpcRaw));
        if ($campaignCpc > 0) {
            // CPC is the campaign-level cap for how many contacts can be accepted per company.
            $dupRules['company_name'] = [
                'key' => 'company_name',
                'label' => 'Company Name',
                'limit' => $campaignCpc,
            ];
        }
        $dupBaseCounts = !empty($dupRules) ? dfDuplicatePreloadCountsForCampaign($campaignId, $dupRules) : [];
        $dupBatchCounts = [];

        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a valid upload file (CSV/XLSX/XLS).');
        }
        $fileInfo = pathinfo((string)($_FILES['csv_file']['name'] ?? ''));
        $ext = strtolower((string)($fileInfo['extension'] ?? ''));
        if (!in_array($ext, ['csv','xlsx','xls'], true)) throw new RuntimeException('Allowed formats: CSV, XLSX, XLS.');

        $tmp = (string)($_FILES['csv_file']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Unable to read uploaded file.');

        $readCsvRow = function($fh): array|false {
            $r = fgetcsv($fh);
            if ($r === false) return false;
            return is_array($r) ? $r : false;
        };

        $readHtmlTable = function(string $filePath): array {
            $data = file_get_contents($filePath);
            if ($data === false) throw new RuntimeException('Unable to read uploaded file.');
            $head = substr($data, 0, 8);
            if ($head !== '' && strlen($head) >= 8 && $head[0] === "\xD0" && $head[1] === "\xCF") {
                throw new RuntimeException('XLS (binary) is not supported. Please Save As XLSX or CSV and upload again.');
            }
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
            if ($ext === 'xls') {
                return $readHtmlTable($tmp);
            }
            $rows = [];
            foreach ($parseXlsxRows($tmp) as $r) $rows[] = $r;
            return $rows;
        };

        $rowsAll = $getRows();
        if (empty($rowsAll)) throw new RuntimeException('Header row is missing.');

        $rawHeader = $rowsAll[0];
        if (!$rawHeader || !is_array($rawHeader)) throw new RuntimeException('Header row is missing.');

        $header = [];
        foreach ($rawHeader as $h) {
            $h = (string)$h;
            $hn = $norm($h);
            if ($hn === '') $hn = 'col_' . count($header);
            $header[] = $hn;
        }

        $map = [];
        foreach ($header as $i => $hn) {
            if ($hn === 'error_reason') continue;
            $map[$hn] = $i;
        }

        $expectedCols = [];
        $requiredKeys = [];
        foreach (($meta['base_fields'] ?? []) as $bf) {
            if (!is_array($bf)) continue;
            $k = (string)($bf['key'] ?? '');
            if ($k === '') continue;
            if ($k !== 'recording_file') $expectedCols[] = $k;
            if (!empty($bf['required']) && $k !== 'recording_file') $requiredKeys[] = $k;
        }
        foreach (($meta['custom_fields'] ?? []) as $cf) {
            if (!is_array($cf)) continue;
            $k = (string)($cf['key'] ?? '');
            if ($k === '') continue;
            $expectedCols[] = $k;
            if (!empty($cf['required'])) $requiredKeys[] = $k;
        }

        $customOptions = [];
        $customTypes = [];
        foreach ($meta['custom_fields'] as $cf) {
            $k = (string)($cf['key'] ?? '');
            if ($k === '') continue;
            $customTypes[$k] = (string)($cf['type'] ?? 'text');
            $opts = $cf['options'] ?? [];
            if (is_array($opts) && !empty($opts)) {
                $customOptions[$k] = array_values(array_unique(array_map(fn($x) => trim((string)$x), $opts)));
            }
        }

        $missingCols = [];
        foreach ($expectedCols as $k) {
            if (!isset($map[$norm($k)])) $missingCols[] = $k;
        }
        if (!empty($missingCols)) throw new RuntimeException('Missing required columns: ' . implode(', ', $missingCols));

        $sanitize = function(?string $v, int $maxLen = 180): string {
            $v = (string)$v;
            $v = str_replace(["\0", "\r"], ['', "\n"], $v);
            $v = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', '', $v);
            $v = trim($v);
            $v = preg_replace("/[ \\t]+/", ' ', $v);
            $v = preg_replace("/\\n{3,}/", "\n\n", $v);
            if ($maxLen > 0 && strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
            return $v;
        };
        $safeCsvCell = function($v) use ($sanitize): string {
            $s = $sanitize((string)$v, 500);
            $first = $s !== '' ? $s[0] : '';
            if ($first !== '' && in_array($first, ['=','+','-','@'], true)) $s = "'" . $s;
            return $s;
        };

        $writeXlsRow = function($fh, array $cells, int $colCount): void {
            if ($colCount > 0) {
                $cells = array_values($cells);
                if (count($cells) < $colCount) $cells = array_pad($cells, $colCount, '');
                if (count($cells) > $colCount) $cells = array_slice($cells, 0, $colCount);
            }
            $out = '<tr>';
            foreach ($cells as $v) {
                $s = (string)$v;
                $s = str_replace("\0", '', $s);
                $out .= '<td style="mso-number-format:\\@;">' . htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            }
            $out .= '</tr>' . "\r\n";
            fwrite($fh, $out);
        };

        $zipProvided = isset($_FILES['recordings_zip']) && ($_FILES['recordings_zip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        $zip = null;
        $zipEntryByName = [];
        $zipAllowedExt = ['mp3','wav','m4a','aac','ogg'];
        if ($zipProvided) {
            if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP support is not enabled on this server.');
            if (!isset($map['recording_file'])) throw new RuntimeException('When uploading a Recordings ZIP, the CSV must include the column: recording_file');
            $zipInfo = pathinfo((string)($_FILES['recordings_zip']['name'] ?? ''));
            $zipExt = strtolower((string)($zipInfo['extension'] ?? ''));
            if ($zipExt !== 'zip') throw new RuntimeException('Recordings file must be a .zip archive.');
            $zipTmp = (string)($_FILES['recordings_zip']['tmp_name'] ?? '');
            $zipObj = new ZipArchive();
            $openRes = $zipObj->open($zipTmp);
            if ($openRes !== true) throw new RuntimeException('Unable to read the recordings ZIP.');
            $zip = $zipObj;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if (!is_array($st)) continue;
                $name = (string)($st['name'] ?? '');
                if ($name === '' || str_ends_with($name, '/')) continue;
                $base = basename(str_replace(['\\', "\0"], ['/', ''], $name));
                $base = trim($base);
                if ($base === '') continue;
                $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                if ($ext === '' || !in_array($ext, $zipAllowedExt, true)) continue;
                $k = strtolower($base);
                if (array_key_exists($k, $zipEntryByName)) {
                    $zipEntryByName[$k] = null;
                } else {
                    $zipEntryByName[$k] = $name;
                }
            }
        }

        $reportDir = __DIR__ . '/../../uploads/bulk_upload_reports';
        if (!is_dir($reportDir)) @mkdir($reportDir, 0775, true);
        $token = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        $okPath = $reportDir . "/bulk_{$token}_success.xls";
        $badPath = $reportDir . "/bulk_{$token}_rejected.xls";
        ensureDatabaseSchema();
        $runId = 0;
        $stmtRun = $conn->prepare("INSERT INTO lead_upload_runs (token, source, campaign_id, uploaded_by, original_filename, total_rows, created_at) VALUES (?,?,?,?,?,0,NOW())");
        if ($stmtRun) {
            $src = 'bulk_upload';
            $orig = (string)($_FILES['csv_file']['name'] ?? '');
            $stmtRun->bind_param('ssiis', $token, $src, $campaignId, $userId, $orig);
            if ($stmtRun->execute()) {
                $runId = (int)$conn->insert_id;
            }
            $stmtRun->close();
        }
        $stmtRejLog = $runId > 0 ? $conn->prepare("INSERT INTO lead_upload_rejections (run_id, row_num, row_json, reason_json) VALUES (?,?,?,?)") : null;
        $logRejection = function(int $rowNum, array $row, string $reason, array $meta = []) use ($stmtRejLog, $runId, $rawHeader): void {
            if (!$stmtRejLog || $runId <= 0) return;
            $rowAssoc = [];
            foreach ((array)$rawHeader as $i => $h) {
                $hh = trim((string)$h);
                if ($hh === '') continue;
                $rowAssoc[$hh] = isset($row[$i]) ? (string)$row[$i] : '';
            }
            $rowJson = json_encode($rowAssoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($rowJson === false) $rowJson = '{}';
            $reasonJson = json_encode(array_merge(['message' => $reason], $meta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($reasonJson === false) $reasonJson = json_encode(['message' => $reason]);
            $rid = (int)$runId;
            $rn = (int)$rowNum;
            $stmtRejLog->bind_param('iiss', $rid, $rn, $rowJson, $reasonJson);
            $stmtRejLog->execute();
        };
        $okOut = fopen($okPath, 'w');
        $badOut = fopen($badPath, 'w');
        if (!$okOut || !$badOut) throw new RuntimeException('Unable to create report files.');

        fwrite($okOut, "\xEF\xBB\xBF" . '<html><head><meta charset="UTF-8"></head><body><table border="1">' . "\r\n");
        fwrite($badOut, "\xEF\xBB\xBF" . '<html><head><meta charset="UTF-8"></head><body><table border="1">' . "\r\n");

        $okHeader = array_merge($rawHeader, ['lead_db_id', 'lead_code']);
        $badHeader = array_merge($rawHeader, ['error_reason']);
        $okCols = count($okHeader);
        $badCols = count($badHeader);
        $writeXlsRow($okOut, array_map($safeCsvCell, $okHeader), $okCols);
        $writeXlsRow($badOut, array_map($safeCsvCell, $badHeader), $badCols);

        $inserted = 0;
        $rejected = 0;
        $processed = 0;

        $campaignName = (string)($meta['campaign']['name'] ?? '');
        $formForCampaign = getFormForCampaign($campaignId);
        $formId = (int)($formForCampaign['form_id'] ?? 0);
        if (!$formForCampaign || $formId <= 0) {
            throw new RuntimeException('Form is not assigned to this campaign. Assign a form before uploading leads.');
        }
        $strictSelectOptions = $formForCampaign ? getSelectOptionsByFormSchema((array)($formForCampaign['schema'] ?? []), ['industry','employee_size','company_size','country','software_implementation_timeline']) : [];
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $clientId = getCampaignClientId($campaignId);

        $leadFilesDir = __DIR__ . '/../../uploads/lead_files';
        if (!is_dir($leadFilesDir)) @mkdir($leadFilesDir, 0775, true);
        $stmtLeadFile = $conn->prepare("INSERT INTO lead_files (lead_id, field_id, file_path, uploaded_at) VALUES (?,?,?,NOW())");
        if (!$stmtLeadFile) throw new RuntimeException('Failed to prepare file insert.');
        $stmtUpdateRec = $conn->prepare("UPDATE leads SET recording_path = ?, updated_by = ?, updated_at = NOW(), updated_at_utc = ?, updated_at_source = 'bulk_upload_recording' WHERE id = ? LIMIT 1");
        if (!$stmtUpdateRec) throw new RuntimeException('Failed to prepare recording update.');

        $stmtLead = $conn->prepare("
            INSERT INTO leads (
                lead_id, campaign_id, campaign_name, client_id, agent_id, agent_name,
                first_name, last_name, job_title, email, company_domain,
                contact_phone, industry, company_name,
                company_size, country, lead_comment,
                recording_path, ip_address, created_by, updated_by, form_done, form_filled_time,
                created_at_utc, updated_at_utc, created_at_source, updated_at_source, timezone_migrated,
                vendor_id
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stmtLead) throw new RuntimeException('Failed to prepare insert.');

        $totalInFile = 0;
        for ($i = 1; $i < count($rowsAll); $i++) {
            $rCount = $rowsAll[$i];
            if (!is_array($rCount) || empty($rCount)) continue;
            $firstCell = trim((string)($rCount[0] ?? ''));
            if ($firstCell !== '' && str_starts_with($firstCell, '#')) continue;
            $totalInFile++;
        }

        $emitProgress = function(int $processed, int $total, int $inserted, int $rejected, string $phase) {};
        $streamProgress = !$isAjax;
        if ($streamProgress) {
            if (ob_get_level() > 0) { @ob_end_clean(); }
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @ob_implicit_flush(true);

            $pageTitle = 'Bulk Upload';
            include __DIR__ . '/../../includes/layout/app_start.php';
            $streamProgressStarted = true;
            ?>
            <div class="container-fluid px-0">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="h3 mb-1">Bulk Upload Processing</h2>
                        <div class="text-muted small">
                            Campaign: <?php echo htmlspecialchars($campaignName); ?>
                            <span class="mx-1">•</span>
                            Total rows detected: <span id="bulk_total"><?php echo (int)$totalInFile; ?></span>
                        </div>
                    </div>
                    <a href="bulk-upload.php" class="btn btn-outline-secondary btn-sm">Back</a>
                </div>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="fw-semibold">Processing</div>
                                    <div class="small text-muted" id="bulk_phase">Starting…</div>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar" id="bulk_bar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mt-3">
                                    <div><span class="text-muted small">Processed</span><div class="fw-semibold" id="bulk_processed">0</div></div>
                                    <div><span class="text-muted small">Uploaded</span><div class="fw-semibold text-success" id="bulk_inserted">0</div></div>
                                    <div><span class="text-muted small">Rejected</span><div class="fw-semibold text-danger" id="bulk_rejected">0</div></div>
                                </div>
                                <div class="small text-muted mt-3" id="bulk_last"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="fw-semibold mb-2">Notes</div>
                                <ul class="small text-muted mb-0 ps-3">
                                    <li>Leave this page open while processing.</li>
                                    <li>Results will appear automatically when finished.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function bulkUpdateProgress(processed, total, inserted, rejected, phase, lastMsg) {
                    const p = Math.max(0, parseInt(processed || 0, 10));
                    const t = Math.max(0, parseInt(total || 0, 10));
                    const pct = t > 0 ? Math.min(100, Math.round((p / t) * 100)) : 0;
                    const bar = document.getElementById('bulk_bar');
                    if (bar) bar.style.width = pct + '%';
                    const ph = document.getElementById('bulk_phase');
                    if (ph) ph.textContent = phase || '';
                    const elP = document.getElementById('bulk_processed');
                    if (elP) elP.textContent = String(p);
                    const elI = document.getElementById('bulk_inserted');
                    if (elI) elI.textContent = String(inserted || 0);
                    const elR = document.getElementById('bulk_rejected');
                    if (elR) elR.textContent = String(rejected || 0);
                    const last = document.getElementById('bulk_last');
                    if (last) last.textContent = lastMsg || '';
                }
                bulkUpdateProgress(0, <?php echo (int)$totalInFile; ?>, 0, 0, 'Starting…', '');
            </script>
            <?php
            echo str_repeat(' ', 4096);
            flush();

            $emitProgress = function(int $processed, int $total, int $inserted, int $rejected, string $phase) {
                $payload = json_encode([$processed, $total, $inserted, $rejected, $phase], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                echo "<script>bulkUpdateProgress(...$payload);</script>";
                echo str_repeat(' ', 1024);
                flush();
            };
            $emitProgress(0, $totalInFile, 0, 0, 'Reading file…');
        }

        for ($ri = 1; $ri < count($rowsAll); $ri++) {
            $row = $rowsAll[$ri];
            if (!is_array($row) || empty($row)) continue;
            $firstCell = trim((string)($row[0] ?? ''));
            if ($firstCell !== '' && str_starts_with($firstCell, '#')) continue;
            $processed++;
            $row = array_pad($row, count($rawHeader), '');
            $get = function(string $key) use ($row, $map, $norm): string {
                $idx = $map[$norm($key)] ?? null;
                if ($idx === null) return '';
                return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            };
            $getAny = function(array $keys) use ($get): string {
                foreach ($keys as $k) {
                    $v = $get((string)$k);
                    if (trim((string)$v) !== '') return (string)$v;
                }
                return '';
            };

            foreach ($requiredKeys as $reqKey) {
                $v = $sanitize($get($reqKey), 500);
                if ($v === '') {
                    $rejected++;
                    $r = array_map($safeCsvCell, $row);
                    $reason = 'Missing required field: ' . $reqKey;
                    $r[] = $reason;
                    $writeXlsRow($badOut, $r, $badCols);
                    if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'missing_required', 'field' => $reqKey]);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue 2;
                }
            }

            if ($zipProvided) {
                $rf = $sanitize($getAny(['recording_file']), 500);
                if ($rf === '') {
                    $rejected++;
                    $r = array_map($safeCsvCell, $row);
                    $reason = 'recording_file is required when uploading Recordings ZIP';
                    $r[] = $reason;
                    $writeXlsRow($badOut, $r, $badCols);
                    if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'missing_recording_file']);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue;
                }
            }

            $firstName = $sanitize($get('first_name'), 80);
            $lastName = $sanitize($get('last_name'), 80);

            $email = $sanitize($getAny(['email','email_address','work_email']), 160);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $reason = 'Invalid email address';
                $r[] = $reason;
                $writeXlsRow($badOut, $r, $badCols);
                if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_email', 'value' => $email]);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $company = $sanitize($getAny(['company_name','company']), 180);
            if (!empty($dupRules)) {
                $dupInput = [];
                foreach ($dupRules as $rk => $rule) {
                    $v = '';
                    switch ((string)$rk) {
                        case 'first_name':
                            $v = $firstName;
                            break;
                        case 'last_name':
                            $v = $lastName;
                            break;
                        case 'email':
                            $v = $email;
                            break;
                        case 'company_name':
                            $v = $company;
                            break;
                        case 'phone':
                        case 'contact_phone':
                            $v = $getAny(['contact_phone','phone']);
                            break;
                        case 'employee_size':
                        case 'company_size':
                            $v = $getAny(['employee_size','company_size','company_size_range','employees','headcount']);
                            break;
                        case 'prospect_linkedin_link':
                        case 'linkedin_link':
                            $v = $getAny(['prospect_linkedin_link','linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin_url']);
                            break;
                        case 'company_linkedin_link':
                        case 'company_linkedin':
                            $v = $getAny(['company_linkedin_link','company_linkedin','company_linkedin_url']);
                            break;
                        case 'software_implementation_timeline':
                            $v = $getAny(['software_implementation_timeline','implementation_timeline','timeline','when_is_your_company_planning_to_implement_new_software']);
                            break;
                        default:
                            $v = $get((string)$rk);
                            break;
                    }
                    $dupInput[(string)$rk] = $v;
                }
                $dupRes = dfDuplicateValidateInput($campaignId, $dupRules, $dupInput, $dupBaseCounts, $dupBatchCounts);
                if (empty($dupRes['ok'])) {
                    $rejected++;
                    $r = array_map($safeCsvCell, $row);
                    $reason = preg_replace('/\s+/', ' ', (string)($dupRes['message'] ?? 'Duplicate rule failed'));
                    if (
                        $campaignCpc > 0
                        && (string)($dupRes['field_key'] ?? '') === 'company_name'
                    ) {
                        $companyLabel = trim((string)$company);
                        if ($companyLabel === '') {
                            $companyLabel = 'this company';
                        }
                        $reason = 'CPC limit reached for company: ' . $companyLabel . '. Allowed contacts per company: ' . $campaignCpc . '.';
                    }
                    $r[] = $reason;
                    $writeXlsRow($badOut, $r, $badCols);
                    if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'duplicate_rule_failed', 'details' => $dupRes]);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue;
                }
            }

            $leadCode = 'LD' . date('Ymd') . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            $jobTitle = $sanitize($getAny(['job_title','title','designation']), 140);
            $jobTitle = $jobTitle !== '' ? $jobTitle : null;
            $linkedin = $sanitize($getAny(['prospect_linkedin_link','linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin_url']), 420);
            $linkedin = $linkedin !== '' ? $linkedin : null;
            $phoneRaw = $sanitize($getAny(['contact_phone','phone']), 30);
            if ($phoneRaw !== '' && preg_match('/[^0-9]/', $phoneRaw)) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $reason = 'Invalid contact_phone (digits only)';
                $r[] = $reason;
                $writeXlsRow($badOut, $r, $badCols);
                if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_phone', 'value' => $phoneRaw]);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $phone = $phoneRaw !== '' ? $phoneRaw : null;
            $industry = $sanitize($getAny(['industry']), 120);
            $industry = $industry !== '' ? $industry : null;
            $companyLinkedin = $sanitize($getAny(['company_linkedin_link','company_linkedin','company_linkedin_url']), 420);
            $companyLinkedin = $companyLinkedin !== '' ? $companyLinkedin : null;
            $companyWebsiteRaw = $sanitize($getAny(['company_website','website','domain']), 220);
            $companyWebsite = $companyWebsiteRaw !== '' ? $normalizeDomain($companyWebsiteRaw) : null;
            $companyDomain = extractDomain((string)$email);
            if ($companyDomain === '' && $companyWebsite) $companyDomain = extractDomain((string)$companyWebsite);
            $companyDomain = $companyDomain !== '' ? $companyDomain : null;

            if ($companyDomain) {
                $hit = findClientDomainSuppressionLead($campaignId, (string)$companyDomain, 90);
                if ($hit) {
                    $rejected++;
                    $r = array_map($safeCsvCell, $row);
                    $reason = 'Client domain cooldown (90 days): ' . (string)($companyDomain);
                    $r[] = $reason;
                    $writeXlsRow($badOut, $r, $badCols);
                    if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'client_domain_cooldown', 'domain' => (string)$companyDomain]);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue;
                }
            }
            $employeeSize = $sanitize($getAny(['employee_size','company_size','company_size_range']), 120);
            $employeeSize = $employeeSize !== '' ? $employeeSize : null;
            $country = $sanitize($getAny(['country','country_name','location_country']), 120);
            $country = $country !== '' ? $country : null;
            $timelineAnswer = $sanitize($get('software_implementation_timeline'), 120);
            $timelineAnswer = $timelineAnswer !== '' ? $timelineAnswer : null;
            $timeline = null;
            $comment = $sanitize($get('lead_comment'), 600);
            $comment = $comment !== '' ? $comment : null;

            if ($industry !== null && isset($strictSelectOptions['industry']) && !valueInAllowedOptions($industry, $strictSelectOptions['industry'])) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $reason = 'Invalid industry. Allowed: ' . implode(' | ', $strictSelectOptions['industry']);
                $r[] = $reason;
                $writeXlsRow($badOut, $r, $badCols);
                if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_option', 'field' => 'industry', 'value' => (string)$industry]);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $empAllowed = $strictSelectOptions['employee_size'] ?? ($strictSelectOptions['company_size'] ?? null);
            if ($employeeSize !== null && is_array($empAllowed) && !empty($empAllowed) && !valueInAllowedOptions($employeeSize, $empAllowed)) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $reason = 'Invalid employee_size. Allowed: ' . implode(' | ', $empAllowed);
                $r[] = $reason;
                $writeXlsRow($badOut, $r, $badCols);
                if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_option', 'field' => 'employee_size', 'value' => (string)$employeeSize]);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            if ($country !== null && isset($strictSelectOptions['country']) && !valueInAllowedOptions($country, $strictSelectOptions['country'])) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $reason = 'Invalid country. Allowed: ' . implode(' | ', $strictSelectOptions['country']);
                $r[] = $reason;
                $writeXlsRow($badOut, $r, $badCols);
                if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_option', 'field' => 'country', 'value' => (string)$country]);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            if ($timelineAnswer !== null && isset($strictSelectOptions['software_implementation_timeline']) && !valueInAllowedOptions($timelineAnswer, $strictSelectOptions['software_implementation_timeline'])) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $reason = 'Invalid software_implementation_timeline. Allowed: ' . implode(' | ', $strictSelectOptions['software_implementation_timeline']);
                $r[] = $reason;
                $writeXlsRow($badOut, $r, $badCols);
                if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_option', 'field' => 'software_implementation_timeline', 'value' => (string)$timelineAnswer]);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }

            $recording = null;
            $recordingNames = [];
            $recRaw = $getAny(['recording_file']);
            if ($recRaw !== '') {
                $parts = str_contains($recRaw, '|') ? explode('|', $recRaw) : explode(',', $recRaw);
                $parts = array_values(array_filter(array_map(fn($x) => trim((string)$x), $parts), fn($x) => $x !== ''));
                foreach ($parts as $p) {
                    $b = basename(str_replace(['\\', "\0"], ['/', ''], $p));
                    $b = trim($b);
                    if ($b !== '') $recordingNames[strtolower($b)] = $b;
                }
                $recordingNames = array_values($recordingNames);
            }

            if (!empty($recordingNames) && !$zipProvided) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $r[] = 'recording_file provided but no Recordings ZIP uploaded';
                $writeXlsRow($badOut, $r, $badCols);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            if ($zipProvided && !empty($recordingNames)) {
                foreach ($recordingNames as $fn) {
                    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if ($ext === '' || !in_array($ext, $zipAllowedExt, true)) {
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $r[] = 'Invalid recording extension: ' . $fn;
                        $writeXlsRow($badOut, $r, $badCols);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                    $key = strtolower($fn);
                    if (!array_key_exists($key, $zipEntryByName)) {
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $r[] = 'Recording not found in ZIP: ' . $fn;
                        $writeXlsRow($badOut, $r, $badCols);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                    if ($zipEntryByName[$key] === null) {
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $r[] = 'Duplicate recording name in ZIP: ' . $fn;
                        $writeXlsRow($badOut, $r, $badCols);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                }
            }
            $formDone = 'No';
            $formFilledTime = null;

            $customData = [];
            foreach ($meta['custom_fields'] as $cf) {
                $k = (string)($cf['key'] ?? '');
                if ($k === '') continue;
                $v = $sanitize($get($k), 500);
                if ($v === '') continue;
                $type = (string)($customTypes[$k] ?? ($cf['type'] ?? 'text'));
                if (($type === 'number' || $type === 'numeric') && preg_match('/[^0-9]/', $v)) {
                    $rejected++;
                    $r = array_map($safeCsvCell, $row);
                    $reason = 'Invalid value for ' . $k . ' (digits only)';
                    $r[] = $reason;
                    $writeXlsRow($badOut, $r, $badCols);
                    if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_custom_number', 'field' => $k, 'value' => (string)$v]);
                    if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                    continue 2;
                }
                if (isset($customOptions[$k]) && !empty($customOptions[$k])) {
                    $allowed = $customOptions[$k];
                    $ok = false;
                    foreach ($allowed as $a) {
                        if (strcasecmp(trim((string)$a), trim((string)$v)) === 0) { $ok = true; break; }
                    }
                    if (!$ok) {
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $reason = 'Invalid value for ' . $k . '. Allowed: ' . implode(' | ', $allowed);
                        $r[] = $reason;
                        $writeXlsRow($badOut, $r, $badCols);
                        if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'invalid_custom_option', 'field' => $k, 'value' => (string)$v]);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                }
                $customData[$k] = $v;
            }
            if ($formId > 0 && !empty($customData)) {
                $formDone = 'Yes';
                $formFilledTime = date('Y-m-d H:i:s');
            }

            $submissionData = [];
            $schemaFields = (array)($formForCampaign['schema']['fields'] ?? []);
            $parseMulti = function(string $raw): array {
                $s = trim($raw);
                if ($s === '') return [];
                if (str_contains($s, '|')) $parts = explode('|', $s);
                else $parts = explode(',', $s);
                $parts = array_values(array_filter(array_map(fn($x) => trim((string)$x), $parts), fn($x) => $x !== ''));
                return array_values(array_unique($parts));
            };
            foreach ($schemaFields as $sf) {
                if (!is_array($sf)) continue;
                if (array_key_exists('visible', $sf) && empty($sf['visible'])) continue;
                $key = (string)($sf['key'] ?? '');
                if ($key === '') continue;
                $type = strtolower(trim((string)($sf['type'] ?? 'text')));
                if ($type === 'file_upload') continue;
                $raw = $sanitize($get($key), 500);
                if ($raw === '') continue;
                if ($type === 'checkbox') {
                    $arr = $parseMulti($raw);
                    if (!empty($arr)) $submissionData[$key] = $arr;
                } else {
                    $submissionData[$key] = $raw;
                }
            }
            foreach ($customData as $k => $v) {
                if (!array_key_exists($k, $submissionData)) $submissionData[$k] = $v;
            }
            if ($timelineAnswer !== null && !array_key_exists('software_implementation_timeline', $submissionData)) {
                $submissionData['software_implementation_timeline'] = $timelineAnswer;
            }
            if ($companyWebsiteRaw !== '' && !array_key_exists('company_website', $submissionData) && !array_key_exists('website', $submissionData) && !array_key_exists('domain', $submissionData)) {
                $submissionData['company_website'] = $companyWebsiteRaw;
            }
            if ($linkedin !== null && !array_key_exists('prospect_linkedin_link', $submissionData) && !array_key_exists('linkedin_link', $submissionData) && !array_key_exists('linkedin_url', $submissionData)) {
                $submissionData['prospect_linkedin_link'] = $linkedin;
            }
            if ($companyLinkedin !== null && !array_key_exists('company_linkedin', $submissionData) && !array_key_exists('company_linkedin_url', $submissionData) && !array_key_exists('company_linkedin_link', $submissionData)) {
                $submissionData['company_linkedin'] = $companyLinkedin;
            }

            $timestampSeed = function_exists('leadCanonicalNowSeed') ? leadCanonicalNowSeed('bulk_upload_utc') : [];
            $createdAtUtc = (string)($timestampSeed['created_at_utc'] ?? gmdate('Y-m-d H:i:s'));
            $updatedAtUtc = (string)($timestampSeed['updated_at_utc'] ?? $createdAtUtc);
            $createdAtSource = (string)($timestampSeed['created_at_source'] ?? 'bulk_upload_utc');
            $updatedAtSource = (string)($timestampSeed['updated_at_source'] ?? 'bulk_upload_utc');
            $timezoneMigrated = (int)($timestampSeed['timezone_migrated'] ?? 1);
            $types = "sisiis" . str_repeat("s", 13) . "iissssssii";
            $stmtLead->bind_param(
                $types,
                $leadCode, $campaignId, $campaignName, $clientId, $userId, $userName,
                $firstName, $lastName, $jobTitle, $email, $companyDomain,
                $phone, $industry, $company,
                $employeeSize, $country, $comment,
                $recording, $ip, $userId, $userId, $formDone, $formFilledTime,
                $createdAtUtc, $updatedAtUtc, $createdAtSource, $updatedAtSource, $timezoneMigrated,
                $userVendorId
            );
            if (!$stmtLead->execute()) {
                $rejected++;
                $r = array_map($safeCsvCell, $row);
                $r[] = 'DB insert failed';
                $writeXlsRow($badOut, $r, $badCols);
                if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                continue;
            }
            $leadDbId = (int)$conn->insert_id;
            $recordingPaths = [];
            $primaryRecordingRel = '';
            if ($zipProvided && !empty($recordingNames) && $zip instanceof ZipArchive) {
                $fieldId = 'call_recording';
                foreach ($recordingNames as $fn) {
                    $key = strtolower($fn);
                    $entry = $zipEntryByName[$key] ?? null;
                    if (!is_string($entry) || $entry === '') {
                        if (function_exists('deleteSingleLead')) deleteSingleLead($leadDbId, true);
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $r[] = 'Recording not found in ZIP: ' . $fn;
                        $writeXlsRow($badOut, $r, $badCols);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }

                    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($fn, PATHINFO_FILENAME));
                    $safeBase = $safeBase !== '' ? $safeBase : 'recording';
                    $destName = 'L' . $leadDbId . '_' . $fieldId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $safeBase . ($ext !== '' ? ('.' . $ext) : '');
                    $destAbs = $leadFilesDir . '/' . $destName;
                    $rel = 'uploads/lead_files/' . $destName;

                    $in = $zip->getStream($entry);
                    if ($in === false) {
                        if (function_exists('deleteSingleLead')) deleteSingleLead($leadDbId, true);
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $reason = 'Unable to read recording from ZIP: ' . $fn;
                        $r[] = $reason;
                        $writeXlsRow($badOut, $r, $badCols);
                        if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'zip_recording_read_failed', 'file' => $fn]);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                    $out = fopen($destAbs, 'wb');
                    if ($out === false) {
                        @fclose($in);
                        if (function_exists('deleteSingleLead')) deleteSingleLead($leadDbId, true);
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $reason = 'Unable to write recording file: ' . $fn;
                        $r[] = $reason;
                        $writeXlsRow($badOut, $r, $badCols);
                        if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'zip_recording_write_failed', 'file' => $fn]);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);

                    $stmtLeadFile->bind_param('iss', $leadDbId, $fieldId, $rel);
                    if (!$stmtLeadFile->execute()) {
                        @unlink($destAbs);
                        if (function_exists('deleteSingleLead')) deleteSingleLead($leadDbId, true);
                        $rejected++;
                        $r = array_map($safeCsvCell, $row);
                        $reason = 'DB save failed for recording: ' . $fn;
                        $r[] = $reason;
                        $writeXlsRow($badOut, $r, $badCols);
                        if (isset($logRejection)) $logRejection($processed, $row, $reason, ['type' => 'zip_recording_db_failed', 'file' => $fn]);
                        if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');
                        continue 2;
                    }
                    $recordingFileId = (int)$stmtLeadFile->insert_id;
                    $recordingPaths[] = $rel;
                    if ($primaryRecordingRel === '') $primaryRecordingRel = $rel;
                    if (function_exists('registerRecordingForTranscription')) {
                        registerRecordingForTranscription($leadDbId, $recordingFileId, $rel);
                    }
                }
                if ($primaryRecordingRel !== '') {
                    $recordingUpdatedAtUtc = function_exists('appNowUtc') ? appNowUtc() : gmdate('Y-m-d H:i:s');
                    $stmtUpdateRec->bind_param('sisi', $primaryRecordingRel, $userId, $recordingUpdatedAtUtc, $leadDbId);
                    logLeadActivity($leadDbId, $userId, 'call_recordings_uploaded', ['count' => count($recordingPaths), 'source' => 'bulk_upload']);
                }
            }

            $inserted++;
            if (!empty($dupRules)) {
                $dupCountInput = [];
                foreach ($dupRules as $rk => $rule) {
                    $v = '';
                    switch ((string)$rk) {
                        case 'first_name':
                            $v = $firstName;
                            break;
                        case 'last_name':
                            $v = $lastName;
                            break;
                        case 'email':
                            $v = $email;
                            break;
                        case 'company_name':
                            $v = $company;
                            break;
                        case 'phone':
                        case 'contact_phone':
                            $v = $getAny(['contact_phone','phone']);
                            break;
                        case 'employee_size':
                        case 'company_size':
                            $v = $getAny(['employee_size','company_size','company_size_range','employees','headcount']);
                            break;
                        case 'prospect_linkedin_link':
                        case 'linkedin_link':
                            $v = $getAny(['prospect_linkedin_link','linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin_url']);
                            break;
                        case 'company_linkedin_link':
                        case 'company_linkedin':
                            $v = $getAny(['company_linkedin_link','company_linkedin','company_linkedin_url']);
                            break;
                        case 'software_implementation_timeline':
                            $v = $getAny(['software_implementation_timeline','implementation_timeline','timeline','when_is_your_company_planning_to_implement_new_software']);
                            break;
                        default:
                            $v = $get((string)$rk);
                            break;
                    }
                    $dupCountInput[(string)$rk] = $v;
                }
                dfDuplicateIncrementBatchCounts($dupRules, $dupCountInput, $dupBatchCounts);
            }
            if ($streamProgress && ($processed % 10 === 0 || $processed === $totalInFile)) $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Processing…');

            logLeadActivity($leadDbId, $userId, 'lead_created', ['source' => 'bulk_upload', 'campaign_id' => $campaignId]);
            notifyLeadCreated($leadDbId, $campaignId, $userId);

            if ($formId > 0 && !empty($submissionData)) {
                saveFormSubmission($formId, $campaignId, $leadDbId, $userId, $submissionData);
                logLeadActivity($leadDbId, $userId, 'form_submission_saved', ['form_id' => $formId, 'source' => 'bulk_upload']);
            }

            // Sync to campaign-specific lead table
            syncLeadToCampaignTable($leadDbId);

            $writeXlsRow($okOut, array_merge(array_map($safeCsvCell, $row), [(string)$leadDbId, $safeCsvCell($leadCode)]), $okCols);
        }

        fwrite($okOut, '</table></body></html>');
        fwrite($badOut, '</table></body></html>');
        fclose($okOut);
        fclose($badOut);
        $stmtLead->close();
        if (isset($stmtLeadFile) && $stmtLeadFile) $stmtLeadFile->close();
        if (isset($stmtUpdateRec) && $stmtUpdateRec) $stmtUpdateRec->close();
        if (isset($stmtRejLog) && $stmtRejLog) $stmtRejLog->close();
        if (isset($zip) && $zip instanceof ZipArchive) $zip->close();

        $okFile = basename($okPath);
        $badFile = basename($badPath);
        if (isset($runId) && (int)$runId > 0) {
            $stmtUpRun = $conn->prepare("UPDATE lead_upload_runs SET total_rows = ?, inserted_rows = ?, rejected_rows = ?, success_report_file = ?, rejected_report_file = ?, finished_at = NOW() WHERE id = ? LIMIT 1");
            if ($stmtUpRun) {
                $rid = (int)$runId;
                $t = (int)$totalInFile;
                $i = (int)$inserted;
                $rj = (int)$rejected;
                $stmtUpRun->bind_param('iiissi', $t, $i, $rj, $okFile, $badFile, $rid);
                $stmtUpRun->execute();
                $stmtUpRun->close();
            }
        }
        $okName = $okFile;
        $badName = $badFile;
        $okUrl = '../files/download?kind=bulk_report&file=' . rawurlencode($okFile) . '&name=' . rawurlencode($okName);
        $badUrl = '../files/download?kind=bulk_report&file=' . rawurlencode($badFile) . '&name=' . rawurlencode($badName);

        $result = [
            'success' => $rejected === 0,
            'message' => "Upload finished. Total: {$totalInFile}, Uploaded: {$inserted}, Rejected: {$rejected}.",
            'stats' => ['total' => $totalInFile, 'uploaded' => $inserted, 'rejected' => $rejected],
            'success_report_url' => $okUrl,
            'failed_report_url' => $badUrl,
        ];

        if ($streamProgress) {
            $emitProgress($processed, $totalInFile, $inserted, $rejected, 'Finished');
            ?>
            <div class="container-fluid px-0 mt-4">
                <div class="alert alert-<?php echo $rejected === 0 ? 'success' : 'warning'; ?> border-0 shadow-sm">
                    <?php echo htmlspecialchars($result['message']); ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-success btn-sm" href="<?php echo htmlspecialchars($okUrl); ?>">
                        <i class="bi bi-download me-1"></i>Download Success Report
                    </a>
                    <a class="btn btn-outline-danger btn-sm" href="<?php echo htmlspecialchars($badUrl); ?>">
                        <i class="bi bi-download me-1"></i>Download Rejected Report
                    </a>
                    <a class="btn btn-outline-secondary btn-sm" href="bulk-upload.php">
                        Back to Bulk Upload
                    </a>
                </div>
            </div>
            <?php
            include __DIR__ . '/../../includes/layout/app_end.php';
            exit;
        }

        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $message = (string)$result['message'];
        $messageType = $result['success'] ? 'success' : 'warning';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        if ($isAjax) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($streamProgressStarted)) {
            ?>
            <div class="container-fluid px-0 mt-4">
                <div class="alert alert-danger border-0 shadow-sm">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="bulk-upload.php">Back to Bulk Upload</a>
            </div>
            <?php
            include __DIR__ . '/../../includes/layout/app_end.php';
            exit;
        }
    }
}

$campaigns = [];
$res = $conn->query("
    SELECT c.id, c.name, COALESCE(MAX(d.code), '') AS code, COALESCE(MAX(d.client_code), '') AS client_code
    FROM campaigns c
    LEFT JOIN campaign_details d ON d.campaign_id = c.id
    WHERE c.active = 1
    GROUP BY c.id, c.name
    ORDER BY c.name
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $campaigns[] = $row;
    }
}

if ($userRole === 'vendor_admin' || $userRole === 'vendor_user') {
    if ($userVendorId <= 0) { $campaigns = []; }
    else {
        $allowedIds = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM vendor_campaign_map WHERE vendor_id = ? AND uploads_enabled = 1");
        $stmt->bind_param('i', $userVendorId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) { $allowedIds[(int)$r['campaign_id']] = true; }
        $stmt->close();
        
        if ($userRole === 'vendor_user') {
            $userAssigned = [];
            $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) { $userAssigned[(int)$r['campaign_id']] = true; }
            $stmt->close();
            
            $campaigns = array_values(array_filter($campaigns, function($c) use ($allowedIds, $userAssigned) {
                $cid = (int)($c['id'] ?? 0);
                return isset($allowedIds[$cid]) && isset($userAssigned[$cid]);
            }));
        } else {
            $campaigns = array_values(array_filter($campaigns, function($c) use ($allowedIds) {
                return isset($allowedIds[(int)($c['id'] ?? 0)]);
            }));
        }
    }
} else {
    $opsVisible = getOpsVisibleCampaignIdsForUser($userId, $userRole);
    $opsVisible = getTeamVisibleCampaignIdsForUser($userId, $opsVisible);
    if ($opsVisible !== null) {
        $campaigns = array_values(array_filter($campaigns, fn($c) => isset($opsVisible[(int)($c['id'] ?? 0)])));
    }
}

ensureDatabaseSchema();
$recentRuns = [];
$stmt = $conn->prepare("
    SELECT r.id, r.campaign_id, c.name AS campaign_name, r.total_rows, r.inserted_rows, r.rejected_rows,
           r.success_report_file, r.rejected_report_file, r.created_at, r.finished_at
    FROM lead_upload_runs r
    LEFT JOIN campaigns c ON c.id = r.campaign_id
    WHERE r.uploaded_by = ?
    ORDER BY r.id DESC
    LIMIT 15
");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recentRuns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}
ob_end_clean();
?>
<?php $pageTitle = 'Bulk Upload'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 leads-workspace">
    <div class="leads-page-header leads-hero mb-4">
        <div>
            <div class="leads-eyebrow mb-2"><i class="bi bi-cloud-upload"></i>Bulk Operations</div>
            <h2 class="leads-page-title mb-0">Bulk Upload Leads</h2>
            <div class="leads-page-subtitle">Upload campaign-ready lead files, attach recordings in ZIP format, and download clean success or rejection reports from the same screen.</div>
        </div>
        <div class="leads-page-actions">
            <a href="leads-purge.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Delete All Leads</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm leads-panel">
                <div class="card-header bg-light fw-semibold">Upload</div>
                <div class="card-body">
                    <form id="uploadForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label small text-muted">Campaign</label>
                                <select class="form-select form-select-sm" name="campaign_id" id="campaign_id" required>
                                    <option value="">Select Campaign</option>
                                    <?php foreach ($campaigns as $c): ?>
                                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(crmCampaignDisplayNameFromRow($c)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-grid">
                                <div class="btn-group">
                                    <a id="templateBtn" class="btn btn-outline-secondary btn-sm disabled" href="#"><i class="bi bi-download me-1"></i>Download Template</a>
                                    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span class="visually-hidden">Toggle Dropdown</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" id="tplXlsx" href="#">XLSX (Recommended)</a></li>
                                        <li><a class="dropdown-item" id="tplXls" href="#">XLS</a></li>
                                        <li><a class="dropdown-item" id="tplCsv" href="#">CSV</a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Upload File (CSV / XLSX / XLS)</label>
                                <input type="file" class="form-control form-control-sm" name="csv_file" id="csv_file" accept=".csv,.xlsx,.xls" required>
                                <div class="text-muted small mt-1">XLSX is recommended for large option lists.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Call Recordings ZIP (Optional)</label>
                                <input type="file" class="form-control form-control-sm" name="recordings_zip" id="recordings_zip" accept=".zip">
                                <div class="text-muted small mt-1">If you upload a ZIP, put the exact file name in the CSV column <span class="fw-semibold">recording_file</span> to auto-link the recording to that lead.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Feedback Mode</label>
                                <select class="form-select form-select-sm" id="feedbackMode">
                                    <option value="realtime" selected>Realtime (Recommended)</option>
                                    <option value="toast">Toast</option>
                                    <option value="modal">Modal</option>
                                </select>
                            </div>
                        </div>

                        <div class="progress mt-3 d-none" id="uploadProgress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" id="progressBar">0%</div>
                        </div>

                        <div class="d-grid mt-3">
                            <button type="submit" class="btn btn-primary" id="uploadBtn"><i class="bi bi-upload"></i> Upload</button>
                        </div>
                    </form>

                    <div id="uploadStatus" class="mt-3"></div>
                    <div id="uploadResult" class="mt-2"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm leads-panel mb-3">
                <div class="card-header bg-light fw-semibold">Upload History</div>
                <div class="card-body p-0">
                    <div class="table-responsive leads-table-wrap">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Campaign</th>
                                    <th>Total</th>
                                    <th>Uploaded</th>
                                    <th>Rejected</th>
                                    <th>Reports</th>
                                    <th class="text-end pe-3">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentRuns)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">No uploads yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentRuns as $rr): ?>
                                        <?php
                                            $okF = trim((string)($rr['success_report_file'] ?? ''));
                                            $badF = trim((string)($rr['rejected_report_file'] ?? ''));
                                            $createdAt = (string)($rr['created_at'] ?? '');
                                        ?>
                                        <tr>
                                            <td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)($rr['campaign_name'] ?? ('Campaign #' . (int)($rr['campaign_id'] ?? 0)))); ?></td>
                                            <td><?php echo (int)($rr['total_rows'] ?? 0); ?></td>
                                            <td class="text-success"><?php echo (int)($rr['inserted_rows'] ?? 0); ?></td>
                                            <td class="text-danger"><?php echo (int)($rr['rejected_rows'] ?? 0); ?></td>
                                            <td>
                                                <div class="leads-action-group">
                                                    <?php if ($okF !== ''): ?>
                                                        <a class="btn btn-outline-success" href="../files/download?kind=bulk_report&file=<?php echo rawurlencode($okF); ?>&name=<?php echo rawurlencode($okF); ?>">Success</a>
                                                    <?php endif; ?>
                                                    <?php if ($badF !== ''): ?>
                                                        <a class="btn btn-outline-danger" href="../files/download?kind=bulk_report&file=<?php echo rawurlencode($badF); ?>&name=<?php echo rawurlencode($badF); ?>">Rejected</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="text-end pe-3 text-muted small"><?php echo htmlspecialchars($createdAt !== '' ? date('Y-m-d H:i', strtotime($createdAt)) : ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm leads-panel">
                <div class="card-header bg-light fw-semibold">Campaign Fields</div>
                <div class="card-body">
                    <div class="text-muted small mb-2">Select a campaign to view required fields. If you upload a recordings ZIP, <span class="fw-semibold">recording_file</span> becomes required.</div>
                    <div id="expectedCols"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="uploadToastContainer" style="z-index: 1080;"></div>
<div class="modal fade" id="uploadCompleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">Upload Summary</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="uploadCompleteSummary"></div>
        <div class="mt-3 d-flex flex-wrap gap-2" id="uploadCompleteLinks"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
 
<script>
(function(){
    function escapeHtml(str){
        return String(str).replace(/[&<>"']/g, function(s){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s] || s;
        });
    }

    const campaignSel = document.getElementById('campaign_id');
    const templateBtn = document.getElementById('templateBtn');
    const expectedCols = document.getElementById('expectedCols');
    const pagePath = (window.location.pathname || '').replace(/\/$/, '');

    async function loadMeta(campaignId){
        expectedCols.innerHTML = '<div class="text-muted small">Loading...</div>';
        templateBtn.classList.add('disabled');
        templateBtn.href = '#';
        if (!campaignId) {
            expectedCols.innerHTML = '<div class="text-muted small">Select a campaign.</div>';
            return;
        }
        const metaUrl = pagePath + '?action=meta&campaign_id=' + encodeURIComponent(campaignId);
        const res = await fetch(metaUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const data = await res.json().catch(() => null);
        if (!data || !data.ok) {
            expectedCols.innerHTML = '<div class="text-danger small">Unable to load form fields.</div>';
            return;
        }

        templateBtn.classList.remove('disabled');
        const base = pagePath + '?action=template&campaign_id=' + encodeURIComponent(campaignId);
        const tplXlsx = document.getElementById('tplXlsx');
        const tplXls = document.getElementById('tplXls');
        const tplCsv = document.getElementById('tplCsv');
        templateBtn.href = base + '&format=xlsx';
        if (tplXlsx) tplXlsx.href = base + '&format=xlsx';
        if (tplXls) tplXls.href = base + '&format=xls';
        if (tplCsv) tplCsv.href = base + '&format=csv';

        let html = '';
        html += '<div class="fw-semibold mb-2">Campaign Fields</div>';
        if (!data.form) {
            html += '<div class="text-muted small">No form assigned to this campaign.</div>';
        } else {
            html += '<div class="text-muted small mb-2">' + escapeHtml(data.form.name || '') + '</div>';
            html += '<div class="d-flex flex-wrap gap-2">';
            const all = []
                .concat(Array.isArray(data.base_fields) ? data.base_fields : [])
                .concat(Array.isArray(data.custom_fields) ? data.custom_fields : []);
            all.forEach(f => {
                const req = f.required ? ' <span class="text-danger">*</span>' : '';
                html += '<span class="badge bg-light text-dark border">' + escapeHtml(f.key) + req + '</span>';
            });
            html += '</div>';
        }

        expectedCols.innerHTML = html;
    }

    campaignSel?.addEventListener('change', () => loadMeta(campaignSel.value));
    loadMeta(campaignSel?.value || '');

    const form = document.getElementById('uploadForm');
    const fileInput = document.getElementById('csv_file');
    const progressEl = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const statusDiv = document.getElementById('uploadStatus');
    const resultDiv = document.getElementById('uploadResult');
    const uploadBtn = document.getElementById('uploadBtn');
    const modeEl = document.getElementById('feedbackMode');

    form?.addEventListener('submit', function(e){
        statusDiv.innerHTML = '';
        resultDiv.innerHTML = '';

        const cid = (campaignSel?.value || '').trim();
        if (!cid) {
            e.preventDefault();
            statusDiv.innerHTML = '<div class="alert alert-danger">Select a campaign.</div>';
            return;
        }
        const file = fileInput?.files?.[0];
        if (!file) {
            e.preventDefault();
            statusDiv.innerHTML = '<div class="alert alert-danger">Select a file (CSV/XLSX/XLS).</div>';
            return;
        }

        const mode = modeEl ? modeEl.value : 'realtime';
        if (mode === 'realtime') {
            e.preventDefault();
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Starting...';
            progressEl.classList.add('d-none');
            form.submit();
            return;
        }

        e.preventDefault();
        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';

        const fd = new FormData(form);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', pagePath, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = function(ev){
            if (ev.lengthComputable){
                const percent = Math.round((ev.loaded/ev.total) * 100);
                progressEl.classList.remove('d-none');
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }
        };

        xhr.onreadystatechange = function(){
            if (xhr.readyState !== 4) return;
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Upload';
            progressEl.classList.add('d-none');

            const ct = xhr.getResponseHeader('Content-Type') || '';
            const body = xhr.responseText || '';
            try {
                if (!ct.includes('application/json')) throw new Error('Server returned non-JSON');
                const res = JSON.parse(body);
                const cls = res.success ? 'success' : 'warning';
                statusDiv.innerHTML = '<div class="alert alert-' + cls + ' border-0 shadow-sm">' + escapeHtml(res.message || '') + '</div>';

                let links = '';
                if (res.success_report_url) {
                    links += '<a class="btn btn-sm btn-outline-success me-2" href="' + escapeHtml(res.success_report_url) + '" download><i class="bi bi-file-earmark-check"></i> Download Uploaded</a>';
                }
                if (res.failed_report_url) {
                    links += '<a class="btn btn-sm btn-outline-danger" href="' + escapeHtml(res.failed_report_url) + '" download><i class="bi bi-file-earmark-excel"></i> Download Rejected</a>';
                }
                if (links) {
                    resultDiv.innerHTML = '<div class="d-flex flex-wrap gap-2">' + links + '</div>';
                }
                const mode = modeEl ? modeEl.value : 'toast';
                const total = (res.stats && typeof res.stats.total !== 'undefined') ? res.stats.total : 0;
                const uploaded = (res.stats && typeof res.stats.uploaded !== 'undefined') ? res.stats.uploaded : 0;
                const rejected = (res.stats && typeof res.stats.rejected !== 'undefined') ? res.stats.rejected : 0;
                if (mode === 'modal') {
                  const summaryEl = document.getElementById('uploadCompleteSummary');
                  const linksEl = document.getElementById('uploadCompleteLinks');
                  if (summaryEl && linksEl) {
                    summaryEl.innerHTML = '<div>Total: '+escapeHtml(String(total))+'</div><div>Uploaded: '+escapeHtml(String(uploaded))+'</div><div>Rejected: '+escapeHtml(String(rejected))+'</div>';
                    linksEl.innerHTML = '';
                    if (res.success_report_url) {
                      linksEl.insertAdjacentHTML('beforeend', '<a class=\"btn btn-sm btn-outline-success\" href=\"'+escapeHtml(res.success_report_url)+'\" download><i class=\"bi bi-file-earmark-check\"></i> Uploaded CSV</a>');
                    }
                    if (res.failed_report_url) {
                      linksEl.insertAdjacentHTML('beforeend', '<a class=\"btn btn-sm btn-outline-danger\" href=\"'+escapeHtml(res.failed_report_url)+'\" download><i class=\"bi bi-file-earmark-excel\"></i> Rejected CSV</a>');
                    }
                    const mEl = document.getElementById('uploadCompleteModal');
                    if (mEl && window.bootstrap && bootstrap.Modal) { new bootstrap.Modal(mEl).show(); }
                  }
                } else {
                  const okFull = res.success && rejected === 0;
                  const toastWrap = document.getElementById('uploadToastContainer');
                  if (toastWrap) {
                    const t = document.createElement('div');
                    t.className = 'toast align-items-center '+(okFull ? 'text-bg-success' : 'text-bg-warning')+' border-0';
                    t.setAttribute('role','alert'); t.setAttribute('aria-live','assertive'); t.setAttribute('aria-atomic','true');
                    t.innerHTML = '<div class=\"d-flex\">'
                      + '<div class=\"toast-body\">'+(okFull ? '<i class=\"bi bi-check-circle me-2\"></i>' : '<i class=\"bi bi-exclamation-triangle me-2\"></i>')+(okFull ? 'Upload completed successfully.' : 'Upload completed with rejections.')+'</div>'
                      + '<button type=\"button\" class=\"btn-close btn-close-white me-2 m-auto\" data-bs-dismiss=\"toast\" aria-label=\"Close\"></button>'
                      + '</div>';
                    toastWrap.appendChild(t);
                    new bootstrap.Toast(t, { delay: 4000 }).show();
                  }
                }
            } catch (err) {
                statusDiv.innerHTML = '<div class="alert alert-danger border-0 shadow-sm">Upload failed.</div>';
            }
        };

        xhr.send(fd);
    });

})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
