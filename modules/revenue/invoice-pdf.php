<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$conn = getDbConnection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid invoice';
    exit;
}
$invoiceNoParam = isset($_GET['invoice_no']) ? trim((string)$_GET['invoice_no']) : '';
$invoiceNoParam = $invoiceNoParam !== '' ? substr($invoiceNoParam, 0, 50) : '';

$stmt = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo 'Database error';
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if (!$inv && $invoiceNoParam !== '') {
    $stmt = $conn->prepare("SELECT * FROM revenue_invoices WHERE invoice_no = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $invoiceNoParam);
        $stmt->execute();
        $inv = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($inv) $id = (int)($inv['id'] ?? $id);
    }
}
if (!$inv) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

$items = [];
$stmt = $conn->prepare("SELECT * FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
if ($stmt) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

function pdfEscape(string $s): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

$currencySymbol = function(string $cur): string {
    $c = strtoupper(trim($cur));
    if ($c === 'USD') return '$';
    if ($c === 'INR') return '₹';
    if ($c === 'EUR') return '€';
    if ($c === 'GBP') return '£';
    return $c !== '' ? ($c . ' ') : '';
};

$numToWords = function(int $n) use (&$numToWords): string {
    $ones = ['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    if ($n < 20) return $ones[$n];
    if ($n < 100) {
        $t = intdiv($n, 10);
        $r = $n % 10;
        return $tens[$t] . ($r ? '-' . $ones[$r] : '');
    }
    if ($n < 1000) {
        $h = intdiv($n, 100);
        $r = $n % 100;
        return $ones[$h] . ' hundred' . ($r ? ' ' . $numToWords($r) : '');
    }
    if ($n < 1_000_000) {
        $k = intdiv($n, 1000);
        $r = $n % 1000;
        return $numToWords($k) . ' thousand' . ($r ? ' ' . $numToWords($r) : '');
    }
    if ($n < 1_000_000_000) {
        $m = intdiv($n, 1_000_000);
        $r = $n % 1_000_000;
        return $numToWords($m) . ' million' . ($r ? ' ' . $numToWords($r) : '');
    }
    $b = intdiv($n, 1_000_000_000);
    $r = $n % 1_000_000_000;
    return $numToWords($b) . ' billion' . ($r ? ' ' . $numToWords($r) : '');
};

$amountToWords = function(float $amount, string $currency) use ($numToWords): string {
    $cur = strtoupper(trim($currency));
    $whole = (int)floor($amount + 0.00001);
    $cents = (int)round(($amount - $whole) * 100);
    if ($cents === 100) {
        $whole += 1;
        $cents = 0;
    }
    $major = $cur === 'INR' ? 'RUPEES' : ($cur === 'USD' ? 'DOLLARS' : $cur);
    $minor = $cur === 'INR' ? 'PAISE' : ($cur === 'USD' ? 'CENTS' : 'CENTS');
    $words = strtoupper($numToWords(max(0, $whole))) . " {$major}";
    if ($cents > 0) $words .= ' AND ' . strtoupper($numToWords($cents)) . " {$minor}";
    return $words . ' ONLY';
};

$approxTextWidth = function(string $text, float $fontSize, float $factor = 0.52): float {
    return strlen((string)$text) * $fontSize * $factor;
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
$wrapWords = function(string $text, int $maxChars): array {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
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

$currency = trim((string)($inv['currency'] ?? 'USD'));
if ($currency === '') $currency = 'USD';

$subtotal = 0.0;
foreach ($items as $it) {
    $qty = (float)($it['qty'] ?? 0);
    $rate = (float)($it['unit_price'] ?? 0);
    $amt = (float)($it['amount'] ?? ($qty * $rate));
    $subtotal += $amt;
}
$taxRate = (float)($inv['tax_rate'] ?? 0);
if ($taxRate < 0) $taxRate = 0;
if ($taxRate > 100) $taxRate = 100;
$taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
$total = $subtotal + $taxAmount;
$totalWords = $amountToWords($total, $currency);

$invoiceNo = (string)($inv['invoice_no'] ?? '');
$issueDate = (string)($inv['issue_date'] ?? '');
$dueDate = (string)($inv['due_date'] ?? '');
$billToName = (string)($inv['bill_to_name'] ?? ($inv['client_name'] ?? ($inv['client_code'] ?? '')));
$billToAddress = (string)($inv['bill_to_address'] ?? '');
$billToContacts = (string)($inv['bill_to_contacts'] ?? '');
$billToContactName = (string)($inv['bill_to_contact_name'] ?? '');
$billToContactEmail = (string)($inv['bill_to_contact_email'] ?? '');
$billToContactPhone = (string)($inv['bill_to_contact_phone'] ?? '');

$billFromName = trim((string)($inv['bill_from_name'] ?? ''));
if ($billFromName === '') $billFromName = 'Taraj Global Solutions PVT. LTD.';
$billFromAddress = (string)($inv['bill_from_address'] ?? '');
$billFromCityState = (string)($inv['bill_from_city_state'] ?? '');
$billFromCountry = (string)($inv['bill_from_country'] ?? '');
$billFromEmail = (string)($inv['bill_from_email'] ?? '');
$billFromPhone = (string)($inv['bill_from_phone'] ?? '');

$bankName = (string)($inv['bank_name'] ?? '');
$accountName = (string)($inv['account_name'] ?? '');
$accountNumber = (string)($inv['account_number'] ?? '');
$ifscCode = (string)($inv['ifsc_code'] ?? '');
$swiftCode = (string)($inv['swift_code'] ?? '');
$beneficiaryAddress = (string)($inv['beneficiary_address'] ?? '');
$beneficiaryCityState = (string)($inv['beneficiary_city_state'] ?? '');
$signaturePath = (string)($inv['signature_path'] ?? '');
$notes = (string)($inv['notes'] ?? '');
$status = (string)($inv['status'] ?? 'Draft');

$logoPath = __DIR__ . '/../../assets/images/logos/New Taraj Logo.png';
$logo = null;
if (file_exists($logoPath)) {
    $raw = @file_get_contents($logoPath);
    if ($raw !== false) {
        $img = @imagecreatefromstring($raw);
        if ($img) {
            $w = imagesx($img);
            $h = imagesy($img);
            $tmp = tempnam(sys_get_temp_dir(), 'inv_logo_');
            if ($tmp) {
                imagejpeg($img, $tmp, 60);
                $jpeg = @file_get_contents($tmp);
                @unlink($tmp);
                if ($jpeg !== false) {
                    $logo = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
                }
            }
            imagedestroy($img);
        }
    }
}

$watermarkPath = __DIR__ . '/../../assets/images/logos/Only-Logo.png';
    if (file_exists($watermarkPath)) {
    $raw = @file_get_contents($watermarkPath);
    if ($raw !== false) {
        $img = @imagecreatefromstring($raw);
        if ($img) {
            $w = imagesx($img);
            $h = imagesy($img);
            $wm = imagecreatetruecolor($w, $h);
            if ($wm) {
                $white = imagecolorallocate($wm, 255, 255, 255);
                imagefilledrectangle($wm, 0, 0, $w, $h, $white);
                imagecopymerge($wm, $img, 0, 0, 0, 0, $w, $h, 12);
                $tmp = tempnam(sys_get_temp_dir(), 'inv_wm_');
                if ($tmp) {
                    imagejpeg($wm, $tmp, 70);
                    $jpeg = @file_get_contents($tmp);
                    @unlink($tmp);
                    if ($jpeg !== false) {
                        $watermark = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
                    }
                }
                imagedestroy($wm);
            }
            imagedestroy($img);
        }
    }
}

$stampPath = __DIR__ . '/../../assets/images/logos/stamp.png';
    if (file_exists($stampPath)) {
    $raw = @file_get_contents($stampPath);
    if ($raw !== false) {
        $img = @imagecreatefromstring($raw);
        if ($img) {
            $w = imagesx($img);
            $h = imagesy($img);
            $st = imagecreatetruecolor($w, $h);
            if ($st) {
                $white = imagecolorallocate($st, 255, 255, 255);
                imagefilledrectangle($st, 0, 0, $w, $h, $white);
                imagecopymerge($st, $img, 0, 0, 0, 0, $w, $h, 55);
                $tmp = tempnam(sys_get_temp_dir(), 'inv_st_');
                if ($tmp) {
                    imagejpeg($st, $tmp, 80);
                    $jpeg = @file_get_contents($tmp);
                    @unlink($tmp);
                    if ($jpeg !== false) {
                        $stamp = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
                    }
                }
                imagedestroy($st);
            }
            imagedestroy($img);
        }
    }
}

$signature = null;
if ($signaturePath !== '') {
    $sigAbs = __DIR__ . '/../../' . ltrim($signaturePath, '/\\');
    if (file_exists($sigAbs)) {
        $raw = @file_get_contents($sigAbs);
        if ($raw !== false) {
            $img = @imagecreatefromstring($raw);
            if ($img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $tmp = tempnam(sys_get_temp_dir(), 'inv_sig_');
                if ($tmp) {
                    imagejpeg($img, $tmp, 88);
                    $jpeg = @file_get_contents($tmp);
                    @unlink($tmp);
                    if ($jpeg !== false) {
                        $signature = ['jpeg' => $jpeg, 'w' => $w, 'h' => $h];
                    }
                }
                imagedestroy($img);
            }
        }
    }
}

$pageW = 595.0;
$pageH = 842.0;
$left = 40.0;
$right = 555.0;
$tableW = $right - $left;

$setFill = function(int $r, int $g, int $b): string {
    return (round($r / 255, 4)) . ' ' . (round($g / 255, 4)) . ' ' . (round($b / 255, 4)) . " rg\n";
};
$setStroke = function(int $r, int $g, int $b): string {
    return (round($r / 255, 4)) . ' ' . (round($g / 255, 4)) . ' ' . (round($b / 255, 4)) . " RG\n";
};
$drawText = function(float $x, float $y, string $font, float $size, string $text): string {
    return "BT /{$font} {$size} Tf {$x} {$y} Td (" . pdfEscape($text) . ") Tj ET\n";
};
$drawLine = function(float $x1, float $y1, float $x2, float $y2): string {
    return "{$x1} {$y1} m {$x2} {$y2} l S\n";
};
$strokeRect = function(float $x, float $y, float $w, float $h): string {
    return "{$x} {$y} {$w} {$h} re S\n";
};
$fillRect = function(float $x, float $y, float $w, float $h): string {
    return "{$x} {$y} {$w} {$h} re f\n";
};
$strokeRoundRect = function(float $x, float $y, float $w, float $h, float $r = 6.0): string {
    $r = max(0.0, min($r, min($w, $h) / 2.0));
    if ($r <= 0.0) return "{$x} {$y} {$w} {$h} re S\n";
    $k = 0.5522847498;
    $c = $r * $k;
    $x0 = $x;
    $y0 = $y;
    $x1 = $x + $w;
    $y1 = $y + $h;
    $p = '';
    $p .= ($x0 + $r) . " " . $y0 . " m\n";
    $p .= ($x1 - $r) . " " . $y0 . " l\n";
    $p .= ($x1 - $r + $c) . " " . $y0 . " " . $x1 . " " . ($y0 + $r - $c) . " " . $x1 . " " . ($y0 + $r) . " c\n";
    $p .= $x1 . " " . ($y1 - $r) . " l\n";
    $p .= $x1 . " " . ($y1 - $r + $c) . " " . ($x1 - $r + $c) . " " . $y1 . " " . ($x1 - $r) . " " . $y1 . " c\n";
    $p .= ($x0 + $r) . " " . $y1 . " l\n";
    $p .= ($x0 + $r - $c) . " " . $y1 . " " . $x0 . " " . ($y1 - $r + $c) . " " . $x0 . " " . ($y1 - $r) . " c\n";
    $p .= $x0 . " " . ($y0 + $r) . " l\n";
    $p .= $x0 . " " . ($y0 + $r - $c) . " " . ($x0 + $r - $c) . " " . $y0 . " " . ($x0 + $r) . " " . $y0 . " c\nS\n";
    return $p;
};

$headerY = 752.0;
$headerH = 80.0;
$headerTop = $headerY + $headerH;
$logoBox = ['x' => $left, 'y' => $headerY + 4.0, 'w' => 340.0, 'h' => $headerH - 8.0];

$billLinesBy = [];
if (trim($billFromAddress) !== '') $billLinesBy = array_merge($billLinesBy, $wrapWords($billFromAddress, 44));
$tail = trim($billFromCityState . ($billFromCountry !== '' ? (', ' . $billFromCountry) : ''));
if ($tail !== '') $billLinesBy[] = $tail;
if (trim($billFromEmail) !== '') $billLinesBy[] = 'Email: ' . $billFromEmail;
if (trim($billFromPhone) !== '') $billLinesBy[] = 'Phone: ' . $billFromPhone;
if (empty($billLinesBy)) $billLinesBy[] = '—';

$billLinesTo = [];
if (trim($billToAddress) !== '') $billLinesTo = array_merge($billLinesTo, $wrapWords($billToAddress, 44));
if (trim($billToContacts) !== '') {
    $rawLines = preg_split('/\r\n|\r|\n/', trim($billToContacts)) ?: [];
    foreach ($rawLines as $ln) {
        $ln = trim((string)$ln);
        if ($ln === '') continue;
        $billLinesTo[] = $ln;
    }
} else {
    if (trim($billToContactEmail) !== '') $billLinesTo[] = 'Email: ' . $billToContactEmail;
    if (trim($billToContactPhone) !== '') $billLinesTo[] = 'Phone: ' . $billToContactPhone;
    if (trim($billToContactName) !== '') $billLinesTo[] = 'Name: ' . $billToContactName;
}
if (empty($billLinesTo)) $billLinesTo[] = '—';

$billLinesMax = max(count($billLinesBy), count($billLinesTo));
$billH = max(96.0, 56.0 + ($billLinesMax * 12.0) + 6.0);
$billTop = $headerY - 28.0;
$billY = $billTop - $billH;

$tableTopFirst = $billY - 18.0;
$tableTopOther = $headerY - 40.0;
$tableBottomLast = 230.0;
$tableBottomOther = 70.0;
$footerY = 40.0;

$cols = [
    'desc' => 280.0,
    'qty' => 55.0,
    'rate' => 80.0,
    'amt' => 100.0,
];
$xDesc = $left + 8.0;
$xQtyR = $left + $cols['desc'] + $cols['qty'] - 8.0;
$xRateR = $left + $cols['desc'] + $cols['qty'] + $cols['rate'] - 8.0;
$xAmtR = $right - 8.0;
$xQtyC = $left + $cols['desc'] + ($cols['qty'] / 2.0);
$xRateC = $left + $cols['desc'] + $cols['qty'] + ($cols['rate'] / 2.0);
$xAmtC = $left + $cols['desc'] + $cols['qty'] + $cols['rate'] + ($cols['amt'] / 2.0);

$totalLeads = 0.0;
foreach ($items as $it) {
    $totalLeads += (float)($it['qty'] ?? 0);
}

$prepared = [];
foreach ($items as $it) {
    $desc = (string)($it['description'] ?? '');
    $qty = (float)($it['qty'] ?? 0);
    $rate = (float)($it['unit_price'] ?? 0);
    $amt = (float)($it['amount'] ?? ($qty * $rate));
    $lines = $wrapWords($desc, 62);
    if (empty($lines)) $lines = [''];
    $prepared[] = [
        'lines' => $lines,
        'qty' => $qty,
        'rate' => $rate,
        'amt' => $amt,
    ];
}

$headerRowH = 24.0;
$lineH = 13.0;
$rowPad = 10.0;
$totalRowH = 24.0;
$minRowH = ($lineH + $rowPad) + 2.0;
$availableFirst = ($tableTopFirst - $tableBottomLast) - $headerRowH - $totalRowH - 8.0;
$availableOther = ($tableTopOther - $tableBottomOther) - $headerRowH - $totalRowH - 8.0;

$pages = [];
$cur = [];
$used = 0.0;
$available = $availableFirst;
foreach ($prepared as $row) {
    $rowH = (max(1, count($row['lines'])) * $lineH) + $rowPad;
    if ($available < $minRowH) {
        if (empty($pages)) $pages[] = [];
        $available = $availableOther;
    }
    if (($used + $rowH) > $available) {
        if (!empty($cur)) $pages[] = $cur;
        $cur = [];
        $used = 0.0;
        $available = $availableOther;
        if ($rowH > $available && empty($cur)) {
            $cur[] = $row;
            $used += $rowH;
            continue;
        }
    }
    $cur[] = $row;
    $used += $rowH;
}
if (!empty($cur)) {
    $pages[] = $cur;
}
if (empty($pages)) {
    $pages[] = [];
}

$buildPage = function(array $rows, int $pageNum, int $totalPages) use (
    $setFill,
    $setStroke,
    $fillRect,
    $strokeRect,
    $strokeRoundRect,
    $drawLine,
    $drawText,
    $fitText,
    $approxTextWidth,
    $watermark,
    $stamp,
    $logo,
    $logoBox,
    $headerY,
    $headerH,
    $headerTop,
    $billY,
    $billH,
    $tableTopFirst,
    $tableTopOther,
    $tableBottomLast,
    $tableBottomOther,
    $footerY,
    $left,
    $right,
    $tableW,
    $cols,
    $xDesc,
    $xQtyR,
    $xRateR,
    $xAmtR,
    $xQtyC,
    $xRateC,
    $xAmtC,
    $headerRowH,
    $lineH,
    $rowPad,
    $invoiceNo,
    $issueDate,
    $dueDate,
    $status,
    $billToName,
    $billToAddress,
    $billToContactName,
    $billToContactEmail,
    $billToContactPhone,
    $billFromName,
    $billLinesBy,
    $billLinesTo,
    $bankName,
    $accountName,
    $accountNumber,
    $ifscCode,
    $swiftCode,
    $beneficiaryAddress,
    $beneficiaryCityState,
    $signature,
    $wrapWords,
    $currency,
    $currencySymbol,
    $totalLeads,
    $totalRowH,
    $subtotal,
    $taxRate,
    $taxAmount,
    $total,
    $totalWords,
    $notes
): string {
    $c = "q\n";
    $c .= "0.6 w\n";
    $c .= $setStroke(210, 214, 220);
    $c .= $setFill(255, 255, 255);

    if ($watermark && isset($watermark['w'], $watermark['h'])) {
        $lw = (float)$watermark['w'];
        $lh = (float)$watermark['h'];
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
        $c .= "q\n{$dw} 0 0 {$dh} {$dx} {$dy} cm\n/Im3 Do\nQ\n";
    }
    if ($stamp && isset($stamp['w'], $stamp['h'])) {
        $lw = (float)$stamp['w'];
        $lh = (float)$stamp['h'];
        $targetW = 95.0;
        $scale = $targetW / max(1.0, $lw);
        $dw = $lw * $scale;
        $dh = $lh * $scale;
        $dx = $left;
        $dy = $footerY + 12.0;
        $c .= "q\n{$dw} 0 0 {$dh} {$dx} {$dy} cm\n/Im4 Do\nQ\n";
    }

    $tableTopPage = $pageNum === 1 ? $tableTopFirst : $tableTopOther;
    $tableBottomPage = $pageNum === $totalPages ? $tableBottomLast : $tableBottomOther;

    $c .= $setStroke(37, 99, 235);
    $c .= "3 w\n";
    $c .= $drawLine($left, $headerY, $right, $headerY);
    $c .= "0.6 w\n";
    $c .= $setStroke(210, 214, 220);

    if ($logo && isset($logo['w'], $logo['h'])) {
        $lw = (float)$logo['w'];
        $lh = (float)$logo['h'];
        $pad = 6.0;
        $boxW = (float)$logoBox['w'] - (2 * $pad);
        $boxH = (float)$logoBox['h'] - (2 * $pad);
        $scale = min($boxW / max(1.0, $lw), $boxH / max(1.0, $lh));
        $dw = $lw * $scale;
        $dh = $lh * $scale;
        $dx = (float)$logoBox['x'] + $pad + (($boxW - $dw) / 2);
        $dy = (float)$logoBox['y'] + $pad + (($boxH - $dh) / 2);
        $c .= "q\n";
        $c .= "{$dw} 0 0 {$dh} {$dx} {$dy} cm\n";
        $c .= "/Im1 Do\n";
        $c .= "Q\n";
    }

    $c .= $setFill(17, 24, 39);
    $titleW = 165.0;
    $titleX = $right - $titleW;
    $c .= $drawText($titleX, $headerTop - 18, 'F2', 22, 'Invoice');
    $c .= $setFill(55, 65, 81);
    $c .= $drawText($titleX, $headerTop - 42, 'F1', 10, 'Invoice #: ' . $fitText($invoiceNo, $titleW, 10));
    $c .= $drawText($titleX, $headerTop - 55, 'F1', 10, 'Date: ' . $fitText($issueDate, $titleW, 10));
    $c .= $drawText($titleX, $headerTop - 68, 'F1', 10, 'Due: ' . $fitText($dueDate !== '' ? $dueDate : '—', $titleW, 10));

    if ($pageNum === 1) {
        $boxGap = 10.0;
        $halfW = ($tableW - $boxGap) / 2.0;
        $byX = $left;
        $toX = $left + $halfW + $boxGap;

        $c .= $setStroke(210, 214, 220);
        $c .= $strokeRect($byX, $billY, $halfW, $billH);
        $c .= $strokeRect($toX, $billY, $halfW, $billH);

        $c .= $setFill(33, 37, 41);
        $c .= $drawText($byX + 10, $billY + $billH - 18, 'F2', 11, 'Billed By');
        $c .= $drawText($byX + 10, $billY + $billH - 36, 'F2', 11, $fitText($billFromName, $halfW - 20, 11));
        $yy = $billY + $billH - 52;
        $kv = function(float $x, float $y, float $w, string $line) use ($drawText, $fitText, $approxTextWidth): string {
            $s = trim((string)$line);
            if (preg_match('/^(Name|Email|Phone|Address)\s*:\s*(.+)$/i', $s, $m)) {
                $label = ucfirst(strtolower((string)$m[1])) . ':';
                $val = trim((string)$m[2]);
                $labelW = $approxTextWidth($label, 10);
                $gap = 4.0;
                $valX = $x + $labelW + $gap;
                $valW = max(10.0, $w - ($labelW + $gap));
                $out = '';
                $out .= $drawText($x, $y, 'F2', 10, $label);
                $out .= $drawText($valX, $y, 'F1', 10, $fitText($val, $valW, 10));
                return $out;
            }
            return $drawText($x, $y, 'F1', 10, $fitText($s, $w, 10));
        };
        foreach ($billLinesBy as $ln) {
            if ($yy < ($billY + 10)) break;
            $c .= $kv($byX + 10, $yy, $halfW - 20, (string)$ln);
            $yy -= 13;
        }

        $c .= $drawText($toX + 10, $billY + $billH - 18, 'F2', 11, 'Billed To');
        $c .= $drawText($toX + 10, $billY + $billH - 36, 'F2', 11, $fitText($billToName, $halfW - 20, 11));
        $yy = $billY + $billH - 52;
        foreach ($billLinesTo as $ln) {
            if ($yy < ($billY + 10)) break;
            $c .= $kv($toX + 10, $yy, $halfW - 20, (string)$ln);
            $yy -= 13;
        }
    }

    $c .= $setStroke(210, 214, 220);
    $c .= $drawLine($left, $tableTopPage - $headerRowH, $right, $tableTopPage - $headerRowH);

    $c .= $setFill(33, 37, 41);
    $c .= $drawText($xDesc, $tableTopPage - 17, 'F2', 11, 'Campaign');
    $c .= $drawText($xQtyC - ($approxTextWidth('Leads', 11) / 2.0), $tableTopPage - 17, 'F2', 11, 'Leads');
    $c .= $drawText($xRateC - ($approxTextWidth('CPL', 11) / 2.0), $tableTopPage - 17, 'F2', 11, 'CPL');
    $c .= $drawText($xAmtC - ($approxTextWidth('Amount', 11) / 2.0), $tableTopPage - 17, 'F2', 11, 'Amount');

    $y = $tableTopPage - $headerRowH;
    $c .= $setFill(33, 37, 41);
    $i = 0;
    foreach ($rows as $row) {
        $rowH = (max(1, count($row['lines'])) * $lineH) + $rowPad;
        $yTop = $y;
        $yBottom = $y - $rowH;
        if ($yBottom < $tableBottomPage) break;

        $c .= $setStroke(210, 214, 220);
        $c .= $drawLine($left, $yBottom, $right, $yBottom);

        $c .= $setFill(33, 37, 41);
        $textY = $yTop - 14;
        foreach (array_slice($row['lines'], 0, 3) as $ln) {
            $c .= $drawText($xDesc, $textY, 'F1', 10, $fitText((string)$ln, $cols['desc'] - 16, 10));
            $textY -= $lineH;
        }

        $qtyStr = rtrim(rtrim(number_format((float)$row['qty'], 2, '.', ''), '0'), '.');
        if ($qtyStr === '') $qtyStr = '0';
        $sym = $currencySymbol($currency);
        $rateStr = $sym . number_format((float)$row['rate'], 2);
        $amtStr = $sym . number_format((float)$row['amt'], 2);

        $cellY = $yTop - 15;
        $c .= $drawText($xQtyC - ($approxTextWidth($qtyStr, 10) / 2.0), $cellY, 'F1', 10, $qtyStr);
        $c .= $drawText($xRateC - ($approxTextWidth($rateStr, 10) / 2.0), $cellY, 'F1', 10, $rateStr);
        $c .= $drawText($xAmtC - ($approxTextWidth($amtStr, 10) / 2.0), $cellY, 'F1', 10, $amtStr);

        $y = $yBottom;
        $i++;
    }

    if ($pageNum === $totalPages) {
        $yTop = $y;
        $yBottom = $y - $totalRowH;
        if ($yBottom >= $tableBottomPage) {
            $c .= $setStroke(210, 214, 220);
            $c .= $drawLine($left, $yBottom, $right, $yBottom);
            $c .= $setFill(17, 24, 39);
            $c .= $drawText($xDesc, $yTop - 16, 'F2', 11, 'Total');

            $totalLeadsStr = rtrim(rtrim(number_format((float)$totalLeads, 2, '.', ''), '0'), '.');
            if ($totalLeadsStr === '') $totalLeadsStr = '0';
            $sym = $currencySymbol($currency);
            $subtotalStr = $sym . number_format((float)$subtotal, 2);
            $c .= $drawText($xQtyC - ($approxTextWidth($totalLeadsStr, 11) / 2.0), $yTop - 16, 'F2', 11, $totalLeadsStr);
            $c .= $drawText($xAmtC - ($approxTextWidth($subtotalStr, 11) / 2.0), $yTop - 16, 'F2', 11, $subtotalStr);
            $y = $yBottom;
        }
    }

    $minBodyH = ($lineH * 2) + $rowPad + 6.0;
    $tableBottomDraw = $tableTopPage - $headerRowH - $minBodyH;
    if (!empty($rows) || $pageNum === $totalPages) {
        $tableBottomDraw = $y;
    }
    if ($tableBottomDraw < $tableBottomPage) $tableBottomDraw = $tableBottomPage;

    $c .= $setStroke(210, 214, 220);
    $c .= $strokeRoundRect($left, $tableBottomDraw, $tableW, $tableTopPage - $tableBottomDraw, 6.0);
    $c .= $drawLine($left + $cols['desc'], $tableBottomDraw, $left + $cols['desc'], $tableTopPage);
    $c .= $drawLine($left + $cols['desc'] + $cols['qty'], $tableBottomDraw, $left + $cols['desc'] + $cols['qty'], $tableTopPage);
    $c .= $drawLine($left + $cols['desc'] + $cols['qty'] + $cols['rate'], $tableBottomDraw, $left + $cols['desc'] + $cols['qty'] + $cols['rate'], $tableTopPage);
    if (empty($rows)) {
        $c .= $drawLine($left, $tableBottomDraw, $right, $tableBottomDraw);
    }

    if ($pageNum === $totalPages) {
        $pad = 10.0;
        $cursor = $tableBottomDraw - 14.0;
        $minY = $footerY + 22.0;

        $wrapByWidth = function(string $text, float $maxW, float $fontSize) use ($approxTextWidth): array {
            $text = trim(preg_replace('/\s+/', ' ', (string)$text));
            if ($text === '') return [];
            $words = preg_split('/\s+/', $text) ?: [];
            $lines = [];
            $cur = '';
            foreach ($words as $w) {
                $test = $cur === '' ? $w : ($cur . ' ' . $w);
                if ($approxTextWidth($test, $fontSize) <= $maxW) {
                    $cur = $test;
                } else {
                    if ($cur !== '') $lines[] = $cur;
                    $cur = $w;
                }
            }
            if ($cur !== '') $lines[] = $cur;
            return $lines;
        };

        $kvLine = function(float $x, float $y, float $w, string $label, string $value, float $labelFont, float $valFont, int $maxLines = 3, float $lineHeight = 12.0) use ($drawText, $fitText, $approxTextWidth, $wrapByWidth): array {
            $label = rtrim($label, ':') . ':';
            $value = trim($value) !== '' ? trim($value) : '—';
            $labelW = max(74.0, $approxTextWidth($label, $labelFont));
            $gap = 8.0;
            $valX = $x + $labelW + $gap;
            $valW = max(10.0, $w - ($labelW + $gap));
            $out = '';
            $lines = $wrapByWidth($value, $valW, $valFont);
            if (empty($lines)) $lines = ['—'];
            $out .= $drawText($x, $y, 'F2', $labelFont, $label);
            $out .= $drawText($valX, $y, 'F1', $valFont, $fitText((string)$lines[0], $valW, $valFont));
            $used = 1;
            $nextY = $y - $lineHeight;
            for ($i = 1; $i < count($lines); $i++) {
                $out .= $drawText($valX, $nextY, 'F1', $valFont, $fitText((string)$lines[$i], $valW, $valFont));
                $used++;
                $nextY -= $lineHeight;
                if ($used >= $maxLines) break;
            }
            return [$out, $y - ($lineHeight * $used)];
        };

        $wordsX = $left + $pad;
        $wordsY = $cursor;
        $c .= $setFill(17, 24, 39);
        $c .= $drawText($wordsX, $wordsY, 'F2', 10.8, 'AMOUNT IN WORDS:');
        $wordLines = $wrapByWidth($totalWords, $right - $left - (2 * $pad), 10.2);
        $maxWordLines = (($cursor - $minY) < 220.0) ? 2 : 4;
        if (count($wordLines) > $maxWordLines) $wordLines = array_slice($wordLines, 0, $maxWordLines);
        $wy = $wordsY - 14.0;
        foreach ($wordLines as $ln) {
            if ($wy < $minY) break;
            $c .= $drawText($wordsX, $wy, 'F1', 10.2, $fitText((string)$ln, $right - $left - (2 * $pad), 10.2));
            $wy -= 12.0;
        }

        $colGap = 26.0;
        $colW = (($right - $left) - $colGap) / 2.0;
        $colLX = $left;
        $colRX = $left + $colW + $colGap;
        $colTop = $wy - 10.0;

        $bankX = $colLX;
        $bankW = $colW;
        $bankInX = $bankX + $pad;
        $bankInW = $bankW - (2 * $pad);
        $bankTitleY = $colTop;
        $c .= $setFill(33, 37, 41);
        $c .= $drawText($bankInX, $bankTitleY - 2.0, 'F2', 11.5, 'Bank Details');
        $yy = $bankTitleY - 18.0;
        $pairs = [
            ['Bank', $bankName],
            ['A/c Name', $accountName],
            ['A/c No', $accountNumber],
            ['IFSC', $ifscCode],
            ['SWIFT Code', $swiftCode],
            ['Address', $beneficiaryAddress],
            ['City & State', $beneficiaryCityState],
        ];
        $bankContent = '';
        $bankBottom = $yy;
        foreach ($pairs as $p) {
            if ($yy < ($minY + 12.0)) break;
            $isBeneficiaryLine = in_array((string)$p[0], ['Beneficiary Address', 'Beneficiary <br> City & State'], true);
            [$out, $next] = $kvLine(
                $bankInX,
                $yy,
                $bankInW,
                (string)$p[0],
                (string)$p[1],
                10.2,
                10.2,
                $isBeneficiaryLine ? 5 : 3,
                $isBeneficiaryLine ? 13.0 : 12.0
            );
            $bankContent .= $out;
            $yy = $next - ($isBeneficiaryLine ? 4.0 : 2.0);
            $bankBottom = $yy;
        }
        $bankRectTop = $bankTitleY + 12.0;
        $bankRectBottom = max($minY, $bankBottom - 14.0);
        $c .= $setStroke(210, 214, 220);
        $c .= $strokeRoundRect($bankX, $bankRectBottom, $bankW, $bankRectTop - $bankRectBottom, 8.0);
        $c .= $drawLine($bankInX, $bankTitleY - 8.0, $bankX + $bankW - $pad, $bankTitleY - 8.0);
        $c .= $bankContent;

        $notesTop = $bankRectBottom - 14.0;
        if ($notesTop > ($minY + 34.0)) {
            $notesX = $colLX;
            $notesW = $colW;
            $notesInX = $notesX + $pad;
            $notesInW = $notesW - (2 * $pad);
            $notesTitleY = $notesTop;
            $notesLines = trim($notes) !== '' ? $wrapByWidth($notes, $notesInW, 10.0) : ['—'];
            if (count($notesLines) > 2) $notesLines = array_slice($notesLines, 0, 2);
            $ny = $notesTitleY - 18.0;
            foreach ($notesLines as $ln) {
                $ny -= 12.0;
            }
            $notesRectTop = $notesTitleY + 10.0;
            $notesRectBottom = max($minY, $ny - 8.0);
            if (($notesRectTop - $notesRectBottom) >= 34.0) {
                $c .= $setStroke(210, 214, 220);
                $c .= $strokeRoundRect($notesX, $notesRectBottom, $notesW, $notesRectTop - $notesRectBottom, 8.0);
                $c .= $drawLine($notesInX, $notesTitleY - 8.0, $notesX + $notesW - $pad, $notesTitleY - 8.0);
                $c .= $setFill(33, 37, 41);
                $c .= $drawText($notesInX, $notesTitleY - 2.0, 'F2', 11.0, 'Notes');
                $ty = $notesTitleY - 18.0;
                foreach ($notesLines as $ln) {
                    if ($ty < ($minY + 10.0)) break;
                    $c .= $drawText($notesInX, $ty, 'F1', 10.0, $fitText((string)$ln, $notesInW, 10.0));
                    $ty -= 12.0;
                }
            }
        }

        $sumX = $colRX;
        $sumW = $colW;
        $sumInX = $sumX + $pad;
        $sumInW = $sumW - (2 * $pad);
        $sumTitleY = $colTop;
        $sumRectTop = $sumTitleY + 12.0;
        $sumRectBottom = max($minY, $sumTitleY - 84.0);
        $c .= $setStroke(210, 214, 220);
        $c .= $strokeRoundRect($sumX, $sumRectBottom, $sumW, $sumRectTop - $sumRectBottom, 8.0);
        $c .= $drawLine($sumInX, $sumTitleY - 8.0, $sumX + $sumW - $pad, $sumTitleY - 8.0);
        $c .= $setFill(33, 37, 41);
        $c .= $drawText($sumInX, $sumTitleY - 2.0, 'F2', 12, 'Summary');
        $labX = $sumInX;
        $valR = $sumX + $sumW - $pad;
        $yy = $sumTitleY - 22.0;
        $subStr = $currency . ' ' . number_format((float)$subtotal, 2);
        $taxStr = $currency . ' ' . number_format((float)$taxAmount, 2);
        $totStr = $currency . ' ' . number_format((float)$total, 2);
        $c .= $drawText($labX, $yy, 'F2', 11, 'Subtotal');
        $c .= $drawText($valR - $approxTextWidth($subStr, 11), $yy, 'F1', 11, $subStr);
        $yy -= 14.0;
        $c .= $drawText($labX, $yy, 'F2', 11, 'Tax (' . number_format((float)$taxRate, 2) . '%)');
        $c .= $drawText($valR - $approxTextWidth($taxStr, 11), $yy, 'F1', 11, $taxStr);
        $yy -= 18.0;
        $c .= $drawLine($labX, $yy + 10.0, $valR, $yy + 10.0);
        $c .= $drawText($labX, $yy - 4.0, 'F2', 13, 'Total');
        $c .= $drawText($valR - $approxTextWidth($totStr, 13), $yy - 4.0, 'F2', 13, $totStr);

        if ($signature && isset($signature['w'], $signature['h'])) {
            $sigGap = 14.0;
            $sigTop = $sumRectBottom - $sigGap;
            $sigBottom = max($minY, $sigTop - 92.0);
            if ($sigTop > ($minY + 26.0)) {
                $c .= $setStroke(210, 214, 220);
                $c .= $strokeRoundRect($sumX, $sigBottom, $sumW, $sigTop - $sigBottom, 8.0);
                $c .= $drawLine($sumInX, $sigTop - 20.0, $sumX + $sumW - $pad, $sigTop - 20.0);
                $c .= $setFill(33, 37, 41);
                $c .= $drawText($sumInX, $sigTop - 14.0, 'F2', 10.2, 'Authorised Signatory');
                $lw = (float)$signature['w'];
                $lh = (float)$signature['h'];
                $imgMaxW = $sumInW;
                $imgMaxH = max(30.0, ($sigTop - $sigBottom) - 28.0);
                $scale = min($imgMaxW / max(1.0, $lw), $imgMaxH / max(1.0, $lh));
                $dw = $lw * $scale;
                $dh = $lh * $scale;
                $dx = $sumInX + (($imgMaxW - $dw) / 2.0);
                $dy = $sigBottom + 8.0 + (($imgMaxH - $dh) / 2.0);
                $c .= "q\n";
                $c .= "{$dw} 0 0 {$dh} {$dx} {$dy} cm\n";
                $c .= "/Im2 Do\n";
                $c .= "Q\n";
            }
        }
    } else {
        $c .= $setFill(108, 117, 125);
        $c .= $drawText($left, 208, 'F1', 9, 'Continued on next page...');
    }

    $c .= $setFill(108, 117, 125);
    $c .= $drawText($left, $footerY, 'F1', 8, 'Generated from DeamandFlow Bridge CRM behlaf of Taraj Global Solutions PVT. LTD.');
    $pageLabel = 'Page ' . $pageNum . ' of ' . $totalPages;
    $c .= $drawText($right - $approxTextWidth($pageLabel, 8), $footerY, 'F1', 8, $pageLabel);

    $c .= "Q\n";
    return $c;
};

$pageContents = [];
$totalPages = count($pages);
for ($i = 0; $i < $totalPages; $i++) {
    $pageContents[] = $buildPage($pages[$i], $i + 1, $totalPages);
}

$objects = [];
$addObj = function(string $body) use (&$objects): int {
    $objects[] = $body;
    return count($objects);
};

$fontObj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
$fontBoldObj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");
$imageObj = 0;
if ($logo && isset($logo['jpeg'], $logo['w'], $logo['h'])) {
    $imgData = (string)$logo['jpeg'];
    $imageObj = $addObj("<< /Type /XObject /Subtype /Image /Width " . (int)$logo['w'] . " /Height " . (int)$logo['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imgData) . " >>\nstream\n" . $imgData . "\nendstream");
}
$wmObj = 0;
if ($watermark && isset($watermark['jpeg'], $watermark['w'], $watermark['h'])) {
    $imgData = (string)$watermark['jpeg'];
    $wmObj = $addObj("<< /Type /XObject /Subtype /Image /Width " . (int)$watermark['w'] . " /Height " . (int)$watermark['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imgData) . " >>\nstream\n" . $imgData . "\nendstream");
}
$stampObj = 0;
if ($stamp && isset($stamp['jpeg'], $stamp['w'], $stamp['h'])) {
    $imgData = (string)$stamp['jpeg'];
    $stampObj = $addObj("<< /Type /XObject /Subtype /Image /Width " . (int)$stamp['w'] . " /Height " . (int)$stamp['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imgData) . " >>\nstream\n" . $imgData . "\nendstream");
}
$sigObj = 0;
if ($signature && isset($signature['jpeg'], $signature['w'], $signature['h'])) {
    $imgData = (string)$signature['jpeg'];
    $sigObj = $addObj("<< /Type /XObject /Subtype /Image /Width " . (int)$signature['w'] . " /Height " . (int)$signature['h'] . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imgData) . " >>\nstream\n" . $imgData . "\nendstream");
}
$pagesObj = $addObj("<< /Type /Pages /Kids [] /Count 0 >>");
$xobjParts = [];
if ($imageObj > 0) $xobjParts[] = "/Im1 {$imageObj} 0 R";
if ($sigObj > 0) $xobjParts[] = "/Im2 {$sigObj} 0 R";
if ($wmObj > 0) $xobjParts[] = "/Im3 {$wmObj} 0 R";
if ($stampObj > 0) $xobjParts[] = "/Im4 {$stampObj} 0 R";
$xobj = !empty($xobjParts) ? " /XObject << " . implode(' ', $xobjParts) . " >>" : "";

$pageObjs = [];
foreach ($pageContents as $pc) {
    $contentStream = "<< /Length " . strlen($pc) . " >>\nstream\n" . $pc . "endstream";
    $contentObj = $addObj($contentStream);
    $pageObjs[] = $addObj("<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontObj} 0 R /F2 {$fontBoldObj} 0 R >>{$xobj} >> /Contents {$contentObj} 0 R >>");
}
$kids = '';
foreach ($pageObjs as $p) $kids .= ($kids === '' ? '' : ' ') . "{$p} 0 R";
$objects[$pagesObj - 1] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageObjs) . " >>";
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
header('X-Content-Type-Options: nosniff');
$len = strlen($pdf);
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
$disp = $download ? 'attachment' : 'inline';
$fileName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $invoiceNo);
$fileName = $fileName !== '' ? $fileName : ('invoice_' . $id);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $len);
header('Content-Disposition: ' . $disp . '; filename="' . $fileName . '.pdf"');
echo $pdf;
exit;
