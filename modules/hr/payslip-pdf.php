<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requirePermissionOrRole('hr.payslips.page', ['admin','agent','operations_agent','operations_manager','operations_director','form_filler','email_marketing_executive','email_marketing_agent','email_marketing_manager','email_marketing_director','qa','qa_agent','qa_manager','qa_director']);
$user = getCurrentUser();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];

$isAdmin = in_array((string)($user['role'] ?? ''), ['admin'], true);
$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$userId = ($isAdmin && $requestedUserId > 0) ? $requestedUserId : (int)($user['id'] ?? 0);
$isLocked = hrIsPayrollLocked($year, $month);
if (!$isAdmin && $isLocked) {
    http_response_code(403);
    echo 'Payslips are hidden while payroll is locked.';
    exit;
}

$row = getPayslip($userId, $year, $month);
if (!$row) {
    http_response_code(404);
    echo 'Payslip not found';
    exit;
}
$data = json_decode((string)($row['salary_data'] ?? ''), true);
if (!is_array($data)) $data = [];
$u = is_array($data['user'] ?? null) ? $data['user'] : [];
$attendance = is_array($data['attendance'] ?? null) ? $data['attendance'] : [];
$earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
$ded = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
$net = (float)($data['net_salary'] ?? 0);
$bank = is_array($data['bank'] ?? null) ? $data['bank'] : getUserBankDetails($userId);
$employeeId = (string)($u['employee_id'] ?? '');
$jobTitle = (string)($u['job_title'] ?? '');
if ($jobTitle === '') {
    $conn = getDbConnection();
    $st = $conn->prepare("SELECT job_title FROM users WHERE id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $userId);
        $st->execute();
        $jobTitle = (string)(($st->get_result()->fetch_assoc() ?: [])['job_title'] ?? '');
        $st->close();
    }
}
if ($employeeId === '') {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT employee_id FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $employeeId = (string)(($stmt->get_result()->fetch_assoc() ?: [])['employee_id'] ?? '');
        $stmt->close();
    }
}

function pdfEscape(string $s): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

function moneyPdf($v): string {
    return 'Rs. ' . number_format((float)$v, 2);
}

$numToWordsIndianInt = function(int $n) use (&$numToWordsIndianInt): string {
    $n = (int)$n;
    if ($n === 0) return 'Zero';
    $ones = [
        0 => '',
        1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
    ];
    $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
    ];
    $two = function(int $x) use ($ones, $tens): string {
        if ($x === 0) return '';
        if ($x < 20) return $ones[$x];
        $t = (int)floor($x / 10);
        $o = $x % 10;
        return trim(($tens[$t] ?? '') . ' ' . ($ones[$o] ?? ''));
    };
    $parts = [];
    $crore = (int)floor($n / 10000000);
    if ($crore > 0) { $parts[] = $numToWordsIndianInt($crore) . ' Crore'; $n %= 10000000; }
    $lakh = (int)floor($n / 100000);
    if ($lakh > 0) { $parts[] = $numToWordsIndianInt($lakh) . ' Lakh'; $n %= 100000; }
    $thousand = (int)floor($n / 1000);
    if ($thousand > 0) { $parts[] = $numToWordsIndianInt($thousand) . ' Thousand'; $n %= 1000; }
    $hundred = (int)floor($n / 100);
    if ($hundred > 0) { $parts[] = $ones[$hundred] . ' Hundred'; $n %= 100; }
    if ($n > 0) {
        $tail = $two($n);
        if (!empty($parts) && strpos(end($parts), 'Hundred') !== false) $tail = 'and ' . $tail;
        $parts[] = $tail;
    }
    return trim(implode(' ', array_filter($parts)));
};

$moneyToWordsIndian = function(float $amount) use ($numToWordsIndianInt): string {
    $amount = round($amount, 2);
    $rupees = (int)floor($amount);
    $paise = (int)round(($amount - $rupees) * 100);
    $w = 'Rupees ' . $numToWordsIndianInt($rupees);
    if ($paise > 0) $w .= ' and Paise ' . $numToWordsIndianInt($paise);
    return $w . ' Only';
};

$wrapWords = function(string $text, int $maxChars): array {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return [];
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $cur = '';
    foreach ($words as $w) {
        $test = $cur === '' ? $w : ($cur . ' ' . $w);
        if (strlen($test) <= $maxChars) {
            $cur = $test;
        } else {
            if ($cur !== '') $lines[] = $cur;
            $cur = $w;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
};

$approxTextWidth = function(string $text, float $fontSize, float $factor = 0.52): float {
    $text = (string)$text;
    return strlen($text) * $fontSize * $factor;
};
$fitText = function(string $text, float $maxWidth, float $fontSize, float $factor = 0.52) use ($approxTextWidth): string {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return '';
    if ($approxTextWidth($text, $fontSize, $factor) <= $maxWidth) return $text;
    $maxChars = (int)floor($maxWidth / max(0.1, ($fontSize * $factor)));
    if ($maxChars <= 0) return '';
    if ($maxChars <= 4) return substr($text, 0, $maxChars);
    return substr($text, 0, $maxChars - 3) . '...';
};

$headerLogoPath = __DIR__ . '/../../assets/images/logos/New Taraj Logo.png';
$headerLogo = null;
if (file_exists($headerLogoPath) && function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
    $im = @imagecreatefrompng($headerLogoPath);
    if ($im) {
        $w = imagesx($im);
        $h = imagesy($im);
        ob_start();
        imagejpeg($im, null, 85);
        $jpeg = (string)ob_get_clean();
        imagedestroy($im);
        if ($jpeg !== '' && $w > 0 && $h > 0) $headerLogo = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
    }
}

$watermarkLogoPath = __DIR__ . '/../../assets/images/logos/Only-Logo.png';
$watermarkLogo = null;
if (file_exists($watermarkLogoPath) && function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
    $im = @imagecreatefrompng($watermarkLogoPath);
    if ($im) {
        $w = imagesx($im);
        $h = imagesy($im);
        $wm = imagecreatetruecolor($w, $h);
        if ($wm) {
            $white = imagecolorallocate($wm, 255, 255, 255);
            imagefilledrectangle($wm, 0, 0, $w, $h, $white);
            imagecopymerge($wm, $im, 0, 0, 0, 0, $w, $h, 12);
            ob_start();
            imagejpeg($wm, null, 70);
            $jpeg = (string)ob_get_clean();
            imagedestroy($wm);
            if ($jpeg !== '' && $w > 0 && $h > 0) $watermarkLogo = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
        }
        imagedestroy($im);
    }
}

$salaryPr = (float)($earn['salary_prorated'] ?? ($earn['base_prorated'] ?? 0));
$pt = (float)($ded['professional_tax'] ?? 0);
$tds = (float)($ded['tds'] ?? ($ded['tax'] ?? 0));

$drawText = function(float $x, float $y, string $font, int $size, string $text): string {
    $t = pdfEscape($text);
    return "BT\n/$font $size Tf\n" . $x . " " . $y . " Td\n(" . $t . ") Tj\nET\n";
};
$drawLine = function(float $x1, float $y1, float $x2, float $y2): string {
    return $x1 . " " . $y1 . " m\n" . $x2 . " " . $y2 . " l\nS\n";
};
$drawRect = function(float $x, float $y, float $w, float $h): string {
    return $x . " " . $y . " " . $w . " " . $h . " re\nS\n";
};
$fillRect = function(float $x, float $y, float $w, float $h): string {
    return $x . " " . $y . " " . $w . " " . $h . " re\nf\n";
};

$LEFT = 40.0;
$RIGHT = 555.0;
$WIDTH = $RIGHT - $LEFT;
$TOP = 834.0;
$BOTTOM = 40.0;
$PAD_X = 8.0;
$PAD_Y = 4.0;
$GAP_Y = 12.0;
$COL_GAP = 10.0;

$setStroke = function(float $r, float $g, float $b): string { return $r . " " . $g . " " . $b . " RG\n"; };
$setFill = function(float $r, float $g, float $b): string { return $r . " " . $g . " " . $b . " rg\n"; };
$setLineWidth = function(float $w): string { return $w . " w\n"; };

$textBaseline = function(float $rowTop, float $rowH, int $fontSize): float {
    return $rowTop - ($rowH / 2.0) - ($fontSize / 2.0) + 2.0;
};

$drawTextRight = function(float $xRight, float $y, string $font, int $size, string $text) use ($approxTextWidth, $drawText): string {
    $x = $xRight - $approxTextWidth($text, $size);
    return $drawText($x, $y, $font, $size, $text);
};

$wrapByWidth = function(string $text, float $maxWidth, int $fontSize) use ($approxTextWidth): array {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return [];
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $cur = '';
    foreach ($words as $w) {
        $test = $cur === '' ? $w : ($cur . ' ' . $w);
        if ($approxTextWidth($test, $fontSize) <= $maxWidth) {
            $cur = $test;
        } else {
            if ($cur !== '') $lines[] = $cur;
            $cur = $w;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
};

$content = "q\n";
if ($watermarkLogo && isset($watermarkLogo['w'], $watermarkLogo['h'])) {
    $lw = (float)$watermarkLogo['w'];
    $lh = (float)$watermarkLogo['h'];
    $targetW = 420.0;
    $scale = $targetW / max(1.0, $lw);
    $dw = $lw * $scale;
    $dh = $lh * $scale;
    if ($dh > 520.0) {
        $scale2 = 520.0 / max(1.0, $dh);
        $dw *= $scale2;
        $dh *= $scale2;
    }
    $dx = ((595.0 - $dw) / 2.0);
    $dy = ((842.0 - $dh) / 2.0);
    $content .= "q\n{$dw} 0 0 {$dh} {$dx} {$dy} cm\n/Im1 Do\nQ\n";
}
$content .= "q\n";
$content .= $setLineWidth(0.7);
$content .= $setStroke(0.85, 0.87, 0.89);
$content .= $setFill(0, 0, 0);

$y = $TOP;

$headerH = 96.0;
$headerY = $y - $headerH;
$centerX = ($LEFT + $RIGHT) / 2.0;
$drawTextCenter = function(float $yPos, string $font, int $size, string $text) use ($drawText, $approxTextWidth, $centerX): string {
    $w = $approxTextWidth($text, $size);
    $x = $centerX - ($w / 2.0);
    return $drawText($x, $yPos, $font, $size, $text);
};

if ($headerLogo) {
    $logoW = 110.0;
    $scale = $logoW / (float)$headerLogo['w'];
    $logoH = (float)$headerLogo['h'] * $scale;
    $maxLogoH = $headerH - 10.0;
    if ($logoH > $maxLogoH) {
        $scale2 = $maxLogoH / max(1.0, $logoH);
        $logoW = $logoW * $scale2;
        $logoH = $logoH * $scale2;
    }
    $logoX = $LEFT + $PAD_X;
    $logoY = $headerY + (($headerH - $logoH) / 2.0);
    $content .= "q\n" . $logoW . " 0 0 " . $logoH . " " . $logoX . " " . $logoY . " cm\n/Im2 Do\nQ\n";
}

$addressLines = [
    'The Space Business Complex Office No 512, 513, 514,',
    'Grant Rd, Kharadi, Pune, Maharashtra 411014',
];
$monthYearWords = $monthStr;
if (preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $mm)) {
    $yy = (int)$mm[1];
    $mn = (int)$mm[2];
    $monthName = date('F', mktime(0, 0, 0, max(1, min(12, $mn)), 1, $yy));
    $monthYearWords = $monthName . ' ' . $yy;
}

$content .= $drawTextCenter($headerY + 74, 'F2', 16, 'Taraj Global Solutions PVT. LTD.');
$addrMaxW = $WIDTH - (110.0 + ($PAD_X * 2.0) + 16.0);
if ($addrMaxW < 260.0) $addrMaxW = 260.0;
$addrL1 = $fitText((string)($addressLines[0] ?? ''), $addrMaxW, 9);
$addrL2 = $fitText((string)($addressLines[1] ?? ''), $addrMaxW, 9);
if ($addrL1 !== '') $content .= $drawTextCenter($headerY + 58, 'F1', 9, $addrL1);
if ($addrL2 !== '') $content .= $drawTextCenter($headerY + 46, 'F1', 9, $addrL2);
$content .= $drawTextCenter($headerY + 30, 'F2', 11, 'Payslip');
$content .= $drawTextCenter($headerY + 16, 'F2', 12, $monthYearWords);
$content .= $setStroke(0.85, 0.87, 0.89);
$content .= $drawLine($LEFT, $headerY, $RIGHT, $headerY);

$y = $headerY - $GAP_Y;

$cardW = ($WIDTH - $COL_GAP) / 2.0;
$cardHeaderH = 18.0;
$infoRowH = 16.0;
$infoFont = 9;
$labelW = 78.0;

$empRows = [
    ['Name', (string)($u['name'] ?? '')],
    ['Employee ID', $employeeId],
    ['Job Title', $jobTitle !== '' ? $jobTitle : (string)($u['role'] ?? '')],
    ['Department', (string)($u['department'] ?? '')],
];
$bankRows = [
    ['Bank', (string)($bank['bank_name'] ?? '')],
    ['A/C', maskAccountNumber((string)($bank['account_number'] ?? ''))],
    ['IFSC', (string)($bank['ifsc_code'] ?? '')],
    ['PAN', (string)($bank['pan_number'] ?? '')],
];
$maxInfoRows = max(count($empRows), count($bankRows));
$infoH = $cardHeaderH + ($PAD_Y * 2.0) + ($maxInfoRows * $infoRowH);

$empX = $LEFT;
$bankX = $LEFT + $cardW + $COL_GAP;
$infoTop = $y;
$infoBottom = $infoTop - $infoH;

$content .= $setFill(0, 0, 0);
$content .= $drawRect($empX, $infoBottom, $cardW, $infoH);
$content .= $drawRect($bankX, $infoBottom, $cardW, $infoH);
$content .= $drawText($empX + $PAD_X, $infoTop - 13, 'F2', 10, 'Employee Details');
$content .= $drawText($bankX + $PAD_X, $infoTop - 13, 'F2', 10, 'Bank & Tax');

$rowTop = $infoTop - $cardHeaderH - $PAD_Y;
for ($i = 0; $i < $maxInfoRows; $i++) {
    $rt = $rowTop - ($i * $infoRowH);
    $by = $textBaseline($rt, $infoRowH, $infoFont);
    if (isset($empRows[$i])) {
        $lab = (string)$empRows[$i][0];
        $val = (string)$empRows[$i][1];
        $val = $fitText($val, $cardW - ($PAD_X * 2.0) - $labelW, $infoFont);
        $content .= $drawText($empX + $PAD_X, $by, 'F2', $infoFont, $lab);
        $content .= $drawText($empX + $PAD_X + $labelW, $by, 'F1', $infoFont, $val);
    }
    if (isset($bankRows[$i])) {
        $lab = (string)$bankRows[$i][0];
        $val = (string)$bankRows[$i][1];
        $val = $fitText($val, $cardW - ($PAD_X * 2.0) - $labelW, $infoFont);
        $content .= $drawText($bankX + $PAD_X, $by, 'F2', $infoFont, $lab);
        $content .= $drawText($bankX + $PAD_X + $labelW, $by, 'F1', $infoFont, $val);
    }
}

$y = $infoBottom - $GAP_Y;

$attHeaderH = 18.0;
$attRowH = 30.0;
$attH = $attHeaderH + ($PAD_Y * 2.0) + $attRowH;
$attTop = $y;
$attBottom = $attTop - $attH;

$content .= $setFill(0, 0, 0);
$content .= $drawRect($LEFT, $attBottom, $WIDTH, $attH);
$content .= $drawText($LEFT + $PAD_X, $attTop - 13, 'F2', 10, 'Attendance Summary');

$ncnsDays = (string)($attendance['ncns_days'] ?? 0);
$attText1 = 'Working: ' . (string)($attendance['working_days'] ?? 0) .
    '   Present: ' . (string)($attendance['present_days'] ?? 0) .
    '   Half Day: ' . (string)($attendance['half_days'] ?? 0) .
    '   Absent: ' . (string)($attendance['absent_days'] ?? 0);
$attText2 = 'PL: ' . (string)($attendance['paid_leave_days'] ?? 0) .
    '   UL: ' . (string)($attendance['unpaid_leave_days'] ?? 0) .
    '   NCNS: ' . $ncnsDays .
    '   Paid: ' . (string)($attendance['paid_days'] ?? 0) .
    '   Salary Base: ' . (string)($attendance['salary_working_days'] ?? ($attendance['working_days'] ?? 0)) .
    '   Salary Days: ' . (string)($attendance['salary_payable_days'] ?? ($attendance['paid_days'] ?? 0));
 
$attText1 = $fitText($attText1, $WIDTH - ($PAD_X * 2.0), 9);
$attText2 = $fitText($attText2, $WIDTH - ($PAD_X * 2.0), 9);
$segH = $attRowH / 2.0;
$rowTop = $attTop - $attHeaderH - $PAD_Y;
$attBy1 = $textBaseline($rowTop, $segH, 9);
$attBy2 = $textBaseline($rowTop - $segH, $segH, 9);
$content .= $drawText($LEFT + $PAD_X, $attBy1, 'F1', 9, $attText1);
$content .= $drawText($LEFT + $PAD_X, $attBy2, 'F1', 9, $attText2);

$y = $attBottom - $GAP_Y;

$earnBase = (float)($earn['salary_prorated'] ?? ($earn['base_prorated'] ?? 0));
$gross = (float)($earn['gross'] ?? 0);
$dedTotal = (float)($ded['total'] ?? 0);

$otherAllow = (float)($earn['special_allowance'] ?? 0) + (float)($earn['other_allowance'] ?? 0);
$bonus = (float)($earn['bonus'] ?? 0);
$incent = (float)($earn['incentives'] ?? 0);

$earnItems = [];
$earnItems[] = ['Salary (Prorated)', (float)$salaryPr];
if (abs((float)($earn['basic'] ?? 0)) > 0.009) $earnItems[] = ['Basic', (float)($earn['basic'] ?? 0)];
if (abs((float)($earn['hra'] ?? 0)) > 0.009) $earnItems[] = ['HRA', (float)($earn['hra'] ?? 0)];
if (abs((float)($earn['conveyance'] ?? 0)) > 0.009) $earnItems[] = ['Conveyance', (float)($earn['conveyance'] ?? 0)];
if (abs((float)($earn['medical'] ?? 0)) > 0.009) $earnItems[] = ['Medical', (float)($earn['medical'] ?? 0)];
if (abs($otherAllow) > 0.009) $earnItems[] = ['Other Allowance', (float)$otherAllow];
if (abs($incent) > 0.009) $earnItems[] = ['Incentives', (float)$incent];
if (abs($bonus) > 0.009) $earnItems[] = ['Bonus', (float)$bonus];

$dedItems = [];
if (abs((float)($ded['pf'] ?? 0)) > 0.009) $dedItems[] = ['PF', (float)($ded['pf'] ?? 0)];
if (abs($pt) > 0.009) $dedItems[] = ['Professional Tax', (float)$pt];
if (abs($tds) > 0.009) $dedItems[] = ['TDS', (float)$tds];
if (abs((float)($ded['loan_emi'] ?? 0)) > 0.009) $dedItems[] = ['Loan EMI', (float)($ded['loan_emi'] ?? 0)];
if (abs((float)($ded['other'] ?? 0)) > 0.009) $dedItems[] = ['Other', (float)($ded['other'] ?? 0)];
$ncnsPenaltyAmount = (float)($ded['ncns_penalty_amount'] ?? 0);
if (abs($ncnsPenaltyAmount) > 0.009) {
    $ncnsDaysForPenalty = (float)($attendance['ncns_days'] ?? 0);
    $ncnsPenaltyDays = (float)($ded['ncns_penalty_days'] ?? 0);
    $dedItems[] = ['NCNS Penalty', $ncnsPenaltyAmount];
}

$footerFont = 8;
$footerLineH = 11.0;
$footerH = ($footerLineH * 2.0) + 6.0;

$netPayHeaderH = 18.0;
$netPayPadTop = 8.0;
$netPayLineH = 12.0;
$netPayFontWords = 9;
$netWords = $moneyToWordsIndian((float)$net);
$netWordLines = $wrapByWidth($netWords, $WIDTH - ($PAD_X * 2.0), $netPayFontWords);
if (count($netWordLines) > 3) {
    $netPayFontWords = 8;
    $netWordLines = $wrapByWidth($netWords, $WIDTH - ($PAD_X * 2.0), $netPayFontWords);
}
if (count($netWordLines) > 4) $netWordLines = array_slice($netWordLines, 0, 4);
$netPayH = $netPayHeaderH + $netPayPadTop + 18.0 + ($PAD_Y * 2.0) + (count($netWordLines) * $netPayLineH) + 6.0;

$availableForTables = ($y - $GAP_Y) - ($BOTTOM + $footerH + $GAP_Y + $netPayH);
$tableHeaderH = 18.0;
$minRowH = 12.0;
$rowFont = 9;

$totalEarnRows = count($earnItems) + 1;
$totalDedRows = count($dedItems) + 1;
$maxRows = max($totalEarnRows, $totalDedRows);
$gridHMax = $availableForTables - $tableHeaderH - ($PAD_Y * 2.0);
if ($gridHMax < 80) $gridHMax = 80;
$rowH = $maxRows > 0 ? min(16.0, max($minRowH, floor($gridHMax / (float)$maxRows))) : 14.0;
$rowFont = $rowH < 14.0 ? 8 : 9;
$maxRowsAllowed = (int)floor($gridHMax / $minRowH);

$aggregateTail = function(array $rows, int $maxRows, string $label, float $sumRounding = 2.0): array {
    if ($maxRows <= 0) return $rows;
    if (count($rows) <= $maxRows) return $rows;
    $keep = max(1, $maxRows - 1);
    $head = array_slice($rows, 0, $keep);
    $tail = array_slice($rows, $keep);
    $sum = 0.0;
    foreach ($tail as $r) $sum += (float)($r[1] ?? 0);
    $head[] = [$label, round($sum, (int)$sumRounding)];
    return $head;
};

if ($maxRowsAllowed > 0) {
    $maxItemsAllowed = max(1, $maxRowsAllowed - 1);
    if (count($earnItems) > $maxItemsAllowed) $earnItems = $aggregateTail($earnItems, $maxItemsAllowed, 'Other Earnings');
    if (count($dedItems) > $maxItemsAllowed) $dedItems = $aggregateTail($dedItems, $maxItemsAllowed, 'Other Deductions');
}

$totalEarnRows = count($earnItems) + 1;
$totalDedRows = count($dedItems) + 1;
$maxRows = max($totalEarnRows, $totalDedRows);
$rowH = $maxRows > 0 ? min(16.0, max($minRowH, floor($gridHMax / (float)$maxRows))) : 14.0;
$rowFont = $rowH < 14.0 ? 8 : 9;

$padTable = function(array $items, int $target): array {
    while (count($items) < $target) $items[] = ['', null];
    return $items;
};
$earnItems = $padTable($earnItems, $maxRows - 1);
$dedItems = $padTable($dedItems, $maxRows - 1);

$tableH = $tableHeaderH + ($PAD_Y * 2.0) + ($maxRows * $rowH);
$tableTop = $y;
$tableBottom = $tableTop - $tableH;

$content .= $setFill(0, 0, 0);
$content .= $drawRect($empX, $tableBottom, $cardW, $tableH);
$content .= $drawRect($bankX, $tableBottom, $cardW, $tableH);
$content .= $drawText($empX + $PAD_X, $tableTop - 13, 'F2', 10, 'Earnings');
$content .= $drawText($bankX + $PAD_X, $tableTop - 13, 'F2', 10, 'Deductions');

$gridTop = $tableTop - $tableHeaderH - $PAD_Y;
$gridLeftX = $empX;
$gridRightX = $bankX;
$dividerFrac = 0.65;
$earnDivX = $gridLeftX + ($cardW * $dividerFrac);
$dedDivX = $gridRightX + ($cardW * $dividerFrac);

$content .= $drawLine($earnDivX, $tableBottom + $PAD_Y, $earnDivX, $gridTop);
$content .= $drawLine($dedDivX, $tableBottom + $PAD_Y, $dedDivX, $gridTop);

for ($i = 0; $i <= $maxRows; $i++) {
    $yy = $gridTop - ($i * $rowH);
    $content .= $drawLine($gridLeftX, $yy, $gridLeftX + $cardW, $yy);
    $content .= $drawLine($gridRightX, $yy, $gridRightX + $cardW, $yy);
}

$rowTop = $gridTop;
for ($i = 0; $i < $maxRows; $i++) {
    $rt = $rowTop - ($i * $rowH);
    $by = $textBaseline($rt, $rowH, $rowFont);
    if ($i < ($maxRows - 1)) {
        $eLab = (string)($earnItems[$i][0] ?? '');
        $eAmt = $earnItems[$i][1];
        $dLab = (string)($dedItems[$i][0] ?? '');
        $dAmt = $dedItems[$i][1];

        $eLab = $fitText($eLab, ($earnDivX - ($gridLeftX + $PAD_X) - $PAD_X), $rowFont);
        $dLab = $fitText($dLab, ($dedDivX - ($gridRightX + $PAD_X) - $PAD_X), $rowFont);
        if ($eLab !== '') $content .= $drawText($gridLeftX + $PAD_X, $by, 'F2', $rowFont, $eLab);
        if ($dLab !== '') $content .= $drawText($gridRightX + $PAD_X, $by, 'F2', $rowFont, $dLab);

        if ($eAmt !== null) {
            $amt = moneyPdf((float)$eAmt);
            $content .= $drawTextRight($gridLeftX + $cardW - $PAD_X, $by, 'F1', $rowFont, $amt);
        }
        if ($dAmt !== null) {
            $amt = moneyPdf((float)$dAmt);
            $content .= $drawTextRight($gridRightX + $cardW - $PAD_X, $by, 'F1', $rowFont, $amt);
        }
    } else {
        $content .= $setFill(0, 0, 0);
        $content .= $drawText($gridLeftX + $PAD_X, $by, 'F2', $rowFont, 'Gross');
        $content .= $drawTextRight($gridLeftX + $cardW - $PAD_X, $by, 'F2', $rowFont, moneyPdf((float)$gross));
        $content .= $drawText($gridRightX + $PAD_X, $by, 'F2', $rowFont, 'Total');
        $content .= $drawTextRight($gridRightX + $cardW - $PAD_X, $by, 'F2', $rowFont, moneyPdf((float)$dedTotal));
    }
}

$y = $tableBottom - $GAP_Y;

$netTop = $y;
$netBottom = $netTop - $netPayH;
$content .= $setFill(0, 0, 0);
$content .= $drawRect($LEFT, $netBottom, $WIDTH, $netPayH);
$content .= $drawText($LEFT + $PAD_X, $netTop - 13, 'F2', 10, 'Net Pay');
$content .= $drawTextRight($RIGHT - $PAD_X, $netTop - 13, 'F2', 12, moneyPdf((float)$net));

$wordsYTop = $netTop - $netPayHeaderH - $netPayPadTop;
$wordsBy = $wordsYTop - 2.0;
$content .= $drawText($LEFT + $PAD_X, $wordsBy, 'F2', 9, 'In Words');
$wordsStartY = $wordsBy - 2.0 - $netPayLineH;
for ($i = 0; $i < count($netWordLines); $i++) {
    $content .= $drawText($LEFT + $PAD_X, $wordsStartY - ($i * $netPayLineH), 'F1', $netPayFontWords, (string)$netWordLines[$i]);
}

$y = $netBottom - $GAP_Y;

$footer1 = 'This is a system generated payslip. Incentives are fetched from Productivity module and included in payout.';
$footer2 = 'For any discrepancy, please contact HR: hr@tarajglobal.com';
$footer1 = $fitText($footer1, $WIDTH, 8);
$footer2 = $fitText($footer2, $WIDTH, 8);
$content .= $drawText($LEFT, max($BOTTOM + 10, $y - 6), 'F1', 8, $footer1);
$content .= $drawText($LEFT, max($BOTTOM, $y - 17), 'F1', 8, $footer2);

$content .= "Q\n";

$objects = [];
$addObj = function(string $body) use (&$objects): int {
    $objects[] = $body;
    return count($objects);
};

$fontObj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
$fontBoldObj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");
$wmObj = 0;
if ($watermarkLogo && isset($watermarkLogo['jpeg'], $watermarkLogo['w'], $watermarkLogo['h'])) {
    $imgData = (string)$watermarkLogo['jpeg'];
    $wmObj = $addObj("<< /Type /XObject /Subtype /Image /Width " . (int)$watermarkLogo['w'] . " /Height " . (int)$watermarkLogo['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imgData) . " >>\nstream\n" . $imgData . "\nendstream");
}
$headerObj = 0;
if ($headerLogo && isset($headerLogo['jpeg'], $headerLogo['w'], $headerLogo['h'])) {
    $imgData = (string)$headerLogo['jpeg'];
    $headerObj = $addObj("<< /Type /XObject /Subtype /Image /Width " . (int)$headerLogo['w'] . " /Height " . (int)$headerLogo['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imgData) . " >>\nstream\n" . $imgData . "\nendstream");
}
$contentStream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
$contentObj = $addObj($contentStream);
$pagesObj = $addObj("<< /Type /Pages /Kids [] /Count 0 >>");
$xobjParts = [];
if ($wmObj > 0) $xobjParts[] = "/Im1 {$wmObj} 0 R";
if ($headerObj > 0) $xobjParts[] = "/Im2 {$headerObj} 0 R";
$xobj = !empty($xobjParts) ? " /XObject << " . implode(' ', $xobjParts) . " >>" : "";
$pageObj = $addObj("<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontObj} 0 R /F2 {$fontBoldObj} 0 R >>{$xobj} >> /Contents {$contentObj} 0 R >>");
$objects[$pagesObj - 1] = "<< /Type /Pages /Kids [{$pageObj} 0 R] /Count 1 >>";
$catalogObj = $addObj("<< /Type /Catalog /Pages {$pagesObj} 0 R >>");

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $idx => $obj) {
    $offsets[] = strlen($pdf);
    $n = $idx + 1;
    $pdf .= $n . " 0 obj\n" . $obj . "\nendobj\n";
}
$xrefPos = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";
for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
}
$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogObj} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

header('Content-Type: application/pdf');
$fileName = 'payslip_' . $monthStr . '_user_' . $userId . '.pdf';
header('Content-Disposition: attachment; filename="' . $fileName . '"');
echo $pdf;
exit;
