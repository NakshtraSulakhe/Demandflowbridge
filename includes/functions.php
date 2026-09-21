<?php
/**
 * DemandFlow Bridge CRM - Core Functions
 * Version: 1.0.1 (Stable)
 */
/**
 * Helper Functions
 * 
 * Contains utility functions used throughout the application
 */

// Include database connection and constants/helpers
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/constants.php';
// Optional Google Sheets webhook config
// If present, used to forward leads per-campaign
if (file_exists(__DIR__ . '/../config/google_sheets.php')) {
    require_once __DIR__ . '/../config/google_sheets.php';
}

/**
 * Sanitize output for HTML
 */
function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize input (XSS protection)
 */
function sanitizeInput(mixed $data): mixed {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
    } else {
        $data = trim((string)$data);
        $data = strip_tags($data);
    }
    return $data;
}

function mysqliBindParams(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = $v;
    }
    $args = [$types];
    foreach ($refs as $k => &$v) {
        $args[] = &$v;
    }
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function appAddFlashToast(string $type, string $message, string $title = ''): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (!isset($_SESSION['app_flash_toasts']) || !is_array($_SESSION['app_flash_toasts'])) $_SESSION['app_flash_toasts'] = [];
    $type = strtolower(trim($type));
    if (!in_array($type, ['success', 'warning', 'danger', 'info'], true)) $type = 'info';
    $message = trim((string)$message);
    if ($message === '') return;
    $_SESSION['app_flash_toasts'][] = ['type' => $type, 'title' => (string)$title, 'message' => $message];
}

function appFlashSuccess(string $message, string $title = ''): void { appAddFlashToast('success', $message, $title); }
function appFlashError(string $message, string $title = ''): void { appAddFlashToast('danger', $message, $title); }
function appFlashWarning(string $message, string $title = ''): void { appAddFlashToast('warning', $message, $title); }
function appFlashInfo(string $message, string $title = ''): void { appAddFlashToast('info', $message, $title); }

/**
 * Compute working days (Mon-Fri) for a given month and year
 */
function getWorkingDays(int $year, int $month): int {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    $holidaySet = [];
    foreach (getHolidaysForMonth((int)$year, (int)$month, 'US') as $h) {
        $d = (string)($h['holiday_date'] ?? '');
        if ($d !== '') $holidaySet[$d] = true;
    }
    $working = 0;
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $ts = mktime(0, 0, 0, (int)$month, $day, (int)$year);
        $weekday = (int)date('N', $ts);
        if ($weekday > 5) { continue; }
        $date = date('Y-m-d', $ts);
        if (isset($holidaySet[$date])) { continue; }
        $working++;
    }
    return $working;
}

function holidaysTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    if (!$exists) {
        ensureDatabaseSchema();
        $rs2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' LIMIT 1");
        $exists = (bool)($rs2 && $rs2->fetch_assoc());
    }
    return $exists;
}

function ensureUsFederalHolidaysForYear(int $year): void {
    if ($year < 1970 || $year > 2100) return;
    if (!holidaysTableExists()) return;
    $conn = getDbConnection();
    $country = 'US';

    $fixed = [
        ['name' => "New Year's Day", 'm' => 1, 'd' => 1],
        ['name' => "Juneteenth National Independence Day", 'm' => 6, 'd' => 19],
        ['name' => "Independence Day", 'm' => 7, 'd' => 4],
        ['name' => "Veterans Day", 'm' => 11, 'd' => 11],
        ['name' => "Christmas Day", 'm' => 12, 'd' => 25],
    ];

    $dates = [];
    foreach ($fixed as $h) {
        $actual = sprintf('%04d-%02d-%02d', $year, $h['m'], $h['d']);
        $ts = strtotime($actual);
        if ($ts === false) continue;
        $weekday = (int)date('N', $ts);
        $observed = $actual;
        $suffix = '';
        if ($weekday === 6) { $observed = date('Y-m-d', strtotime($actual . ' -1 day')); $suffix = ' (Observed)'; }
        elseif ($weekday === 7) { $observed = date('Y-m-d', strtotime($actual . ' +1 day')); $suffix = ' (Observed)'; }
        $dates[] = ['date' => $observed, 'name' => $h['name'] . $suffix];
    }

    $dates[] = ['date' => usHolidayNthWeekday($year, 1, 1, 3), 'name' => 'Martin Luther King Jr. Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 2, 1, 3), 'name' => "Washington's Birthday"];
    $dates[] = ['date' => usHolidayLastWeekday($year, 5, 1), 'name' => 'Memorial Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 9, 1, 1), 'name' => 'Labor Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 10, 1, 2), 'name' => 'Columbus Day'];
    $dates[] = ['date' => usHolidayNthWeekday($year, 11, 4, 4), 'name' => 'Thanksgiving Day'];

    $sql = "INSERT INTO holidays (country_code, holiday_date, name) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    foreach ($dates as $d) {
        $date = (string)($d['date'] ?? '');
        $name = (string)($d['name'] ?? '');
        if ($date === '' || $name === '') continue;
        $stmt->bind_param('sss', $country, $date, $name);
        $stmt->execute();
    }
    $stmt->close();
}

function usHolidayNthWeekday(int $year, int $month, int $weekdayMonIs1, int $n): string {
    $first = strtotime(sprintf('%04d-%02d-01', $year, $month));
    if ($first === false) return '';
    $firstWeekday = (int)date('N', $first);
    $delta = ($weekdayMonIs1 - $firstWeekday + 7) % 7;
    $day = 1 + $delta + (7 * ($n - 1));
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function usHolidayLastWeekday(int $year, int $month, int $weekdayMonIs1): string {
    $last = strtotime(sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year)));
    if ($last === false) return '';
    $lastWeekday = (int)date('N', $last);
    $delta = ($lastWeekday - $weekdayMonIs1 + 7) % 7;
    $day = (int)date('j', $last) - $delta;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function appBasePath(): string {
    $uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $uriPath = preg_replace('#/+#', '/', str_replace('\\', '/', $uriPath));

    $projectRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $projectRoot = preg_replace('#/+#', '/', str_replace('\\', '/', (string)$projectRoot));
    $projectSlug = trim((string)basename($projectRoot), '/');
    $needle = $projectSlug !== '' ? ('/' . $projectSlug) : '';

    $pickBaseFromPath = function (string $path) use ($needle): string {
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', $path));
        if ($needle === '') return '';

        $pos = stripos($path, $needle);
        if ($pos === false) return '';

        $beforeOk = $pos === 0 || ($path[$pos - 1] ?? '') === '/';
        $afterChar = $path[$pos + strlen($needle)] ?? '';
        $afterOk = $afterChar === '' || $afterChar === '/';
        if (!$beforeOk || !$afterOk) return '';

        $candidate = substr($path, 0, $pos + strlen($needle));
        if (preg_match('#^/[A-Za-z]:/#', $candidate) || strpos($candidate, ':/') !== false) return $needle;
        return $candidate;
    };

    if ($needle !== '') {
        $b = $pickBaseFromPath($uriPath);
        if ($b !== '') return $b;
    }

    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $script = preg_replace('#/+#', '/', str_replace('\\', '/', $script));
    if ($needle !== '') {
        $b = $pickBaseFromPath($script);
        if ($b !== '') return $b;
    }

    if ($script === '' || $script[0] !== '/') return '';

    $pos = strpos($script, '/modules/');
    if ($pos !== false) {
        $base = substr($script, 0, $pos);
        if ($needle !== '') {
            $b = $pickBaseFromPath($base);
            if ($b !== '') return $b;
        }
        if (preg_match('#^/[A-Za-z]:/#', $base) || strpos($base, ':/') !== false) return $needle !== '' ? $needle : '';
        return $base !== '' ? $base : '';
    }
    $pos2 = strpos($script, '/includes/');
    if ($pos2 !== false) {
        $base = substr($script, 0, $pos2);
        if ($needle !== '') {
            $b = $pickBaseFromPath($base);
            if ($b !== '') return $b;
        }
        if (preg_match('#^/[A-Za-z]:/#', $base) || strpos($base, ':/') !== false) return $needle !== '' ? $needle : '';
        return $base !== '' ? $base : '';
    }

    if (preg_match('#^/[A-Za-z]:/#', $script) || strpos($script, ':/') !== false) return $needle !== '' ? $needle : '';
    $parts = explode('/', trim($script, '/'));
    if (count($parts) <= 0) return '';
    $first = (string)($parts[0] ?? '');
    if (preg_match('#^[A-Za-z]:$#', $first)) return $needle !== '' ? $needle : '';
    return '/' . $first;
}

function appEnsureFileProxySecret(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (!isset($_SESSION['file_proxy_secret']) || !is_string($_SESSION['file_proxy_secret']) || strlen((string)$_SESSION['file_proxy_secret']) < 16) {
        $_SESSION['file_proxy_secret'] = bin2hex(random_bytes(18));
    }
    return (string)$_SESSION['file_proxy_secret'];
}

function appNormalizeUploadsRelPath(string $path): string {
    $p = trim((string)$path);
    if ($p === '' || strtolower($p) === 'null') return '';
    $p = str_replace(["\0", '\\'], ['', '/'], $p);
    if (preg_match('/^https?:\\/\\//i', $p)) {
        $uPath = (string)(parse_url($p, PHP_URL_PATH) ?? '');
        $uPath = str_replace('\\', '/', $uPath);
        $p = $uPath;
    }
    if ($p === '') return '';
    $p = '/' . ltrim($p, '/');
    $base = appBasePath();
    if ($base !== '' && $base !== '/' && str_starts_with($p, $base . '/')) {
        $p = substr($p, strlen($base));
        if ($p === false) $p = '';
    }
    $p = ltrim($p, '/');
    if (!str_starts_with($p, 'uploads/')) return '';
    if (str_contains($p, '..')) return '';
    if (preg_match('/^[a-zA-Z]:/', $p)) return '';
    return $p;
}

function appBuildLeadFileDownloadUrl(int $leadFileId, bool $inline = true): string {
    $leadFileId = (int)$leadFileId;
    if ($leadFileId <= 0) return '';
    $base = appBasePath();
    return $base . '/modules/files/download?kind=lead_file&id=' . $leadFileId . ($inline ? '&inline=1' : '');
}

function appBuildLeadPathDownloadUrl(int $leadId, string $path, bool $inline = true): string {
    $leadId = (int)$leadId;
    if ($leadId <= 0) return '';
    $rel = appNormalizeUploadsRelPath($path);
    if ($rel === '') return '';
    $exp = time() + 900;
    $secret = appEnsureFileProxySecret();
    $baseStr = $leadId . '|' . $rel . '|' . $exp;
    $sig = hash_hmac('sha256', $baseStr, $secret);
    $base = appBasePath();
    return $base . '/modules/files/download?kind=lead_path&lead_id=' . $leadId . '&path=' . rawurlencode($rel) . '&exp=' . $exp . '&sig=' . $sig . ($inline ? '&inline=1' : '');
}

function appSafeInternalUrl(string $url): string {
    $u = trim($url);
    if ($u === '') return '';
    if (stripos($u, 'javascript:') === 0) return '';
    $parts = parse_url($u);
    if ($parts === false) return '';

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (isset($parts['host']) && $host !== '' && strcasecmp((string)$parts['host'], $host) !== 0) return '';

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/') return '';
    if (strpos($path, '..') !== false) return '';

    $base = appBasePath();
    if ($base !== '/' && strpos($path, $base . '/') !== 0) return '';

    $qs = isset($parts['query']) && (string)$parts['query'] !== '' ? ('?' . (string)$parts['query']) : '';
    return $path . $qs;
}

function appBackUrl(string $fallback = ''): string {
    $back = isset($_GET['back']) ? (string)$_GET['back'] : '';
    $safe = appSafeInternalUrl($back);
    if ($safe !== '') return $safe;

    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $safe2 = appSafeInternalUrl($ref);
    if ($safe2 !== '') return $safe2;

    return $fallback;
}

function getPerformerPodiumMeta(int $index, string $context = 'top'): array {
    $position = max(0, (int)$index);
    $context = strtolower(trim($context));
    $map = $context === 'bottom'
        ? [
            ['label' => 'Bottom 1', 'place' => 'Lowest 1', 'class' => 'performer-podium-bottom-1'],
            ['label' => 'Bottom 2', 'place' => 'Lowest 2', 'class' => 'performer-podium-bottom-2'],
            ['label' => 'Bottom 3', 'place' => 'Lowest 3', 'class' => 'performer-podium-bottom-3'],
        ]
        : [
            ['label' => 'Gold', 'place' => '1st Place', 'class' => 'performer-podium-gold'],
            ['label' => 'Silver', 'place' => '2nd Place', 'class' => 'performer-podium-silver'],
            ['label' => 'Bronze', 'place' => '3rd Place', 'class' => 'performer-podium-bronze'],
        ];

    if (isset($map[$position])) {
        return $map[$position];
    }

    return [
        'label' => $context === 'bottom' ? ('Bottom ' . ($position + 1)) : ('Rank ' . ($position + 1)),
        'place' => $context === 'bottom' ? ('Lowest ' . ($position + 1)) : ('#' . ($position + 1)),
        'class' => 'performer-podium-neutral',
    ];
}

function getHolidaysForMonth(int $year, int $month, string $countryCode = 'US'): array {
    if ($year < 1970 || $year > 2100) return [];
    if (!holidaysTableExists()) return [];
    ensureUsFederalHolidaysForYear($year - 1);
    ensureUsFederalHolidaysForYear($year);
    ensureUsFederalHolidaysForYear($year + 1);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT holiday_date, name FROM holidays WHERE country_code = ? AND holiday_date >= ? AND holiday_date <= ? ORDER BY holiday_date ASC");
    if (!$stmt) return [];
    $stmt->bind_param('sss', $countryCode, $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getUpcomingHolidays(string $fromDate, int $limit = 5, string $countryCode = 'US'): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) return [];
    $limit = max(1, min(50, (int)$limit));
    $year = (int)substr($fromDate, 0, 4);
    if ($year < 1970 || $year > 2100) return [];
    if (!holidaysTableExists()) return [];
    ensureUsFederalHolidaysForYear($year - 1);
    ensureUsFederalHolidaysForYear($year);
    ensureUsFederalHolidaysForYear($year + 1);
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT holiday_date, name FROM holidays WHERE country_code = ? AND holiday_date >= ? ORDER BY holiday_date ASC LIMIT $limit");
    if (!$stmt) return [];
    $stmt->bind_param('ss', $countryCode, $fromDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getInternalPayrollUsers(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, role, job_title, department, employee_id FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0) ORDER BY full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function getUserBankDetails(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT bank_name, account_number, account_type, ifsc_code, pan_number FROM user_bank_details WHERE user_id = ? LIMIT 1");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function getUserPersonalDetails(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT personal_email, emergency_contact_number, date_of_birth FROM user_personal_details WHERE user_id = ? LIMIT 1");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function maskAccountNumber(string $acct): string {
    $acct = preg_replace('/\s+/', '', $acct);
    $len = strlen($acct);
    if ($len <= 4) return $acct;
    return str_repeat('X', max(0, $len - 4)) . substr($acct, -4);
}

function ensureDefaultShiftExists(): void {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id FROM hr_shifts WHERE active = 1 ORDER BY id ASC LIMIT 1");
    $row = $rs ? ($rs->fetch_assoc() ?: null) : null;
    if ($row) return;
    $stmt = $conn->prepare("INSERT INTO hr_shifts (name, start_time, end_time, grace_minutes, active) VALUES ('General', '09:30:00', '18:30:00', 15, 1)");
    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }
}

function getDefaultShift(): ?array {
    ensureDefaultShiftExists();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_shifts WHERE active = 1 AND LOWER(TRIM(name)) = 'general' ORDER BY id ASC LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) return $row;
    }
    $rs = $conn->query("SELECT * FROM hr_shifts WHERE active = 1 ORDER BY id ASC LIMIT 1");
    return $rs ? ($rs->fetch_assoc() ?: null) : null;
}

function getUserShiftForDate(int $userId, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    ensureDefaultShiftExists();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT s.*
        FROM hr_user_shift_assignments a
        JOIN hr_shifts s ON s.id = a.shift_id
        WHERE a.user_id = ? AND a.effective_date <= ? AND s.active = 1
        ORDER BY a.effective_date DESC, a.id DESC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $workDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) return $row;
    }
    return getDefaultShift();
}

function setAttendanceDayShiftMeta(int $attendanceDayId, int $userId, string $workDate): void {
    $attendanceDayId = (int)$attendanceDayId;
    $userId = (int)$userId;
    if ($attendanceDayId <= 0 || $userId <= 0) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    $shift = getUserShiftForDate($userId, $workDate);
    if (!$shift) return;
    $conn = getDbConnection();
    $shiftId = (int)($shift['id'] ?? 0);
    $start = (string)($shift['start_time'] ?? '');
    $end = (string)($shift['end_time'] ?? '');
    $grace = (int)($shift['grace_minutes'] ?? 15);
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET shift_id = COALESCE(shift_id, ?), shift_start_time = COALESCE(shift_start_time, ?), shift_end_time = COALESCE(shift_end_time, ?), grace_minutes = CASE WHEN grace_minutes IS NULL THEN ? ELSE grace_minutes END WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('issii', $shiftId, $start, $end, $grace, $attendanceDayId);
        $stmt->execute();
        $stmt->close();
    }
}

function getAttendanceDay(int $userId, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_attendance_days WHERE user_id = ? AND work_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $workDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getAttendanceDayWithShift(int $userId, string $workDate): ?array {
    $day = getAttendanceDay($userId, $workDate);
    if (!$day) return null;
    $shift = null;
    $shiftId = (int)($day['shift_id'] ?? 0);
    if ($shiftId > 0) {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM hr_shifts WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            $shift = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }
    if (!$shift) $shift = getUserShiftForDate($userId, $workDate);
    if ($shift) {
        $day['shift_name'] = (string)($shift['name'] ?? '');
    }
    $day['shift_start_time_full'] = (string)($day['shift_start_time'] ?? ($shift['start_time'] ?? ''));
    $day['shift_end_time_full'] = (string)($day['shift_end_time'] ?? ($shift['end_time'] ?? ''));
    return $day;
}

function hrAttendanceDisplayBreakMinutes(array $row, array $states = [], ?DateTimeImmutable $now = null): int {
    $storedMinutes = max(0, (int)($row['break_minutes'] ?? 0));
    $punchIn = trim((string)($row['punch_in'] ?? ''));
    if ($punchIn === '') return $storedMinutes;
    if (empty($states)) return $storedMinutes;

    $calculatedMinutes = array_sum(hrAttendanceBreakMinutesByState($row, $states, $now));
    return max($storedMinutes, $calculatedMinutes);
}

function hrAttendanceBreakMinutesByState(array $row, array $states = [], ?DateTimeImmutable $now = null): array {
    $totals = [
        'Break1' => 0,
        'Break2' => 0,
        'Lunch' => 0,
    ];
    $punchIn = trim((string)($row['punch_in'] ?? ''));
    if ($punchIn === '' || empty($states)) return $totals;

    $punchOut = trim((string)($row['punch_out'] ?? ''));
    $nowDt = $now ? $now->setTimezone(hrDisplayTz()) : new DateTimeImmutable('now', hrDisplayTz());
    foreach ($states as $stateRow) {
        $state = trim((string)($stateRow['state'] ?? ''));
        if (!array_key_exists($state, $totals)) continue;
        $stateMinutes = max(0, (int)($stateRow['minutes'] ?? 0));
        $startAt = trim((string)($stateRow['start_at'] ?? ''));
        $endAt = trim((string)($stateRow['end_at'] ?? ''));
        $startDt = hrResolveAttendanceDateTime($startAt, $row);
        $endDt = $endAt !== '' ? hrResolveAttendanceDateTime($endAt, $row) : null;

        if ($startDt) {
            $effectiveEnd = $endDt;
            if (!$effectiveEnd && $punchOut === '' && $nowDt > $startDt) {
                $effectiveEnd = $nowDt;
            }
            if ($effectiveEnd && $effectiveEnd > $startDt) {
                $stateMinutes = max($stateMinutes, (int)floor(($effectiveEnd->getTimestamp() - $startDt->getTimestamp()) / 60));
            }
        }

        $totals[$state] += max(0, $stateMinutes);
    }

    return $totals;
}

function hrAttendanceDisplayWorkMinutes(array $row, array $states = [], ?DateTimeImmutable $now = null): int {
    $storedMinutes = max(0, (int)($row['working_minutes'] ?? 0));
    $punchIn = trim((string)($row['punch_in'] ?? ''));
    $punchOut = trim((string)($row['punch_out'] ?? ''));
    if ($punchIn === '' || $punchOut !== '') return $storedMinutes;

    $inDt = hrResolveAttendanceDateTime($punchIn, $row);
    $nowDt = $now ? $now->setTimezone(hrDisplayTz()) : new DateTimeImmutable('now', hrDisplayTz());
    if (!$inDt || $nowDt <= $inDt) return $storedMinutes;

    $elapsedMinutes = (int)floor(($nowDt->getTimestamp() - $inDt->getTimestamp()) / 60);
    $breakMinutes = hrAttendanceDisplayBreakMinutes($row, $states, $nowDt);
    return max($storedMinutes, max(0, $elapsedMinutes - $breakMinutes));
}

function hrFormatMinutesCompact(int $minutes): string {
    $minutes = max(0, $minutes);
    $hours = (int)floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%dh %02dm', $hours, $mins);
}

function hrAttendanceStateSummary(array $states, int $fallbackBreakMinutes = 0, array $context = [], ?DateTimeImmutable $now = null): string {
    $parts = [];
    $displayNow = $now ? $now->setTimezone(hrDisplayTz()) : new DateTimeImmutable('now', hrDisplayTz());
    foreach ($states as $stateRow) {
        $state = trim((string)($stateRow['state'] ?? ''));
        if ($state === '' || strcasecmp($state, 'Working') === 0) continue;

        $label = hrAttendanceStateDisplayLabel($state);
        $startDt = hrResolveAttendanceDateTime((string)($stateRow['start_at'] ?? ''), $context);
        $endDt = hrResolveAttendanceDateTime((string)($stateRow['end_at'] ?? ''), $context);
        $rangeLabel = 'Open';
        if ($startDt || $endDt) {
            $startText = $startDt ? $startDt->format('H:i') : '—';
            $endText = $endDt ? $endDt->format('H:i') : ($startDt ? 'Now' : '—');
            $rangeLabel = $startText . ' - ' . $endText;
        }

        $durationLabel = '';
        if ($startDt) {
            $effectiveEnd = $endDt;
            if (!$effectiveEnd && $displayNow >= $startDt) {
                $effectiveEnd = $displayNow;
            }
            if ($effectiveEnd && $effectiveEnd >= $startDt) {
                $minutes = (int)floor(($effectiveEnd->getTimestamp() - $startDt->getTimestamp()) / 60);
                $durationLabel = ' (' . hrFormatMinutesCompact($minutes) . ')';
            }
        }

        $parts[] = $label . ': ' . $rangeLabel . $durationLabel;
    }

    if (!empty($parts)) return implode(' | ', $parts);
    if ($fallbackBreakMinutes > 0) return hrFormatMinutesCompact($fallbackBreakMinutes);
    return '—';
}

function hrAttendanceStatusMeta(array $row, string $workDate, ?string $today = null, string $countryCode = 'US', bool $pendingForToday = true): array {
    $status = trim((string)($row['status'] ?? ''));
    $pIn = trim((string)($row['punch_in'] ?? ''));
    $pOut = trim((string)($row['punch_out'] ?? ''));
    $workMinutes = max(0, (int)($row['working_minutes'] ?? 0));
    $breakMinutes = max(0, (int)($row['break_minutes'] ?? 0));
    $lateMinutes = max(0, (int)($row['late_minutes'] ?? 0));
    $holidayName = hrHolidayNameForDate($workDate, $countryCode);
    $isHoliday = $holidayName !== '';
    $isWeekend = !$isHoliday && hrIsWeekend($workDate);
    $blankAbsent = $pIn === '' && $pOut === '' && $workMinutes <= 0 && $breakMinutes <= 0 && ($status === '' || $status === 'Absent');
    $today = (is_string($today) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today))
        ? $today
        : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $missingPunch = (($pIn !== '' && $pOut === '') || ($pIn === '' && $pOut !== '')) && $workDate < $today;
    $label = $status !== '' ? $status : 'Absent';
    $filterKey = 'absent';
    $isPresentLike = false;

    if ($isHoliday && $blankAbsent) {
        $label = 'Holiday';
        $filterKey = 'holiday';
    } elseif ($isWeekend && $blankAbsent) {
        $label = 'Weekly Off';
        $filterKey = 'weekoff';
    } elseif ($pendingForToday && $workDate >= $today && $blankAbsent) {
        $label = 'Pending';
        $filterKey = 'pending';
    } elseif (in_array($status, ['Paid Leave', 'paid_leave', 'Unpaid Leave', 'unpaid_leave'], true)) {
        $label = 'Leave Approved';
        $filterKey = 'leave';
    } elseif (in_array($status, ['NCNS', 'ncns'], true)) {
        $label = 'NCNS';
        $filterKey = 'ncns';
    } elseif ($missingPunch) {
        $label = 'Missing Punch';
        $filterKey = 'missing';
    } elseif ($status === 'Half Day') {
        $label = 'Half Day';
        $filterKey = 'half';
        $isPresentLike = true;
    } elseif ($status === 'In Progress') {
        $label = 'In Progress';
        $filterKey = 'inprogress';
        $isPresentLike = true;
    } elseif ($status === 'Full Day') {
        $label = 'Full Day';
        $filterKey = 'present';
        $isPresentLike = true;
    } elseif (($status === '' || $status === 'Absent') && ($pIn !== '' || $pOut !== '' || $workMinutes > 0 || $breakMinutes > 0)) {
        $label = $pOut === '' ? 'In Progress' : 'Present';
        $filterKey = $pOut === '' ? 'inprogress' : 'present';
        $isPresentLike = true;
    } elseif ($pIn !== '' || $pOut !== '' || $workMinutes > 0) {
        $label = 'Present';
        $filterKey = 'present';
        $isPresentLike = true;
    }

    $isLate = $lateMinutes > 0 && $isPresentLike;
    $primaryStatus = 'Absent';
    if ($filterKey === 'holiday') $primaryStatus = 'Holiday';
    elseif ($filterKey === 'weekoff') $primaryStatus = 'Weekly Off';
    elseif ($filterKey === 'pending') $primaryStatus = 'Pending';
    elseif ($filterKey === 'leave') $primaryStatus = 'Leave';
    elseif ($filterKey === 'ncns') $primaryStatus = 'NCNS';
    elseif ($filterKey === 'missing') $primaryStatus = 'Missing Punch';
    elseif ($filterKey === 'half') $primaryStatus = 'Half Day';
    elseif ($filterKey === 'inprogress') $primaryStatus = 'In Progress';
    elseif ($isLate) $primaryStatus = 'Late';
    elseif ($isPresentLike) $primaryStatus = 'Present';

    return [
        'label' => $label,
        'filter_key' => $filterKey,
        'is_present_like' => $isPresentLike,
        'is_late' => $isLate,
        'is_holiday' => $isHoliday && $blankAbsent,
        'is_weekoff' => $isWeekend && $blankAbsent,
        'is_working_day' => !$isHoliday && !$isWeekend && $filterKey !== 'pending',
        'primary_status' => $primaryStatus,
        'missing_punch' => $missingPunch,
        'holiday_name' => $holidayName,
        'raw_status' => $status !== '' ? $status : 'Absent',
    ];
}

function hrAttendanceDisplayStatus(array $row, string $workDate, bool $pendingForToday = true, string $countryCode = 'US'): string {
    $meta = hrAttendanceStatusMeta($row, $workDate, null, $countryCode, $pendingForToday);
    return (string)($meta['label'] ?? 'Absent');
}

function hrNormalizeAttendanceSummaryForOpenDay(array $summary, string $startDate, string $endDate, string $today, string $todayStatus): array {
    $todayStatus = trim($todayStatus);
    if (!in_array($todayStatus, ['Pending', 'In Progress'], true)) return $summary;
    if ($startDate > $today || $endDate < $today) return $summary;
    $summary['absent_days'] = max(0, (int)($summary['absent_days'] ?? 0) - 1);
    $summary['pending_days'] = (int)($summary['pending_days'] ?? 0) + 1;
    $summary['working_days'] = max(0, (int)($summary['working_days'] ?? 0) - 1);
    $workingDays = (int)($summary['working_days'] ?? 0);
    $paidDays = (float)($summary['paid_days'] ?? 0.0);
    $summary['attendance_percent'] = $workingDays > 0 ? round((($paidDays / $workingDays) * 100.0), 1) : 0.0;
    return $summary;
}

function hrNormalizeAttendanceSummaryDisplayMinutesForSnapshot(array $summary, array $snapshot, string $startDate, string $endDate): array {
    $workDate = (string)($snapshot['work_date'] ?? '');
    if ($workDate === '' || $workDate < $startDate || $workDate > $endDate) return $summary;

    $storedWork = max(0, (int)($snapshot['work_minutes'] ?? 0));
    $storedBreak = max(0, (int)($snapshot['break_minutes'] ?? 0));
    $displayWork = max(0, (int)($snapshot['display_work_minutes'] ?? $storedWork));
    $displayBreak = max(0, (int)($snapshot['display_break_minutes'] ?? $storedBreak));

    $summary['work_minutes'] = max(0, (int)($summary['work_minutes'] ?? 0) + ($displayWork - $storedWork));
    $summary['break_minutes'] = max(0, (int)($summary['break_minutes'] ?? 0) + ($displayBreak - $storedBreak));
    return $summary;
}

function hrGetAttendanceSnapshot(int $userId, ?string $preferredWorkDate = null, bool $preferOpenDay = true): array {
    $userId = (int)$userId;
    $today = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $workDate = (is_string($preferredWorkDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredWorkDate)) ? $preferredWorkDate : $today;
    $day = null;

    if ($userId > 0 && $preferOpenDay) {
        $openDay = getOpenAttendanceDayForUser($userId);
        if ($openDay) {
            $workDate = (string)($openDay['work_date'] ?? $workDate);
            $day = $openDay;
        }
    }
    if ($userId > 0 && !$day) {
        $day = getAttendanceDay($userId, $workDate);
    }
    if ($userId > 0) {
        $dayWithShift = getAttendanceDayWithShift($userId, $workDate);
        if ($dayWithShift) {
            $day = is_array($day) ? array_merge($dayWithShift, $day) : $dayWithShift;
        }
    }
    if (!is_array($day)) {
        $day = ['user_id' => $userId, 'work_date' => $workDate];
    }

    $dayId = (int)($day['id'] ?? 0);
    $states = $dayId > 0 ? getAttendanceStatesForDay($dayId) : [];
    $openState = $dayId > 0 ? getOpenAttendanceState($dayId) : null;
    $currentState = trim((string)($day['current_state'] ?? ''));
    if ($openState) {
        $openStateKey = trim((string)($openState['state'] ?? ''));
        if ($openStateKey !== '') $currentState = $openStateKey;
    }
    if ($currentState === '') {
        $currentState = trim((string)($day['punch_in'] ?? '')) !== '' && trim((string)($day['punch_out'] ?? '')) === '' ? 'Working' : 'Off';
    }

    $displayBreakMinutes = hrAttendanceDisplayBreakMinutes($day, $states);
    $displayWorkMinutes = hrAttendanceDisplayWorkMinutes($day, $states);
    $status = hrAttendanceDisplayStatus($day, $workDate, true, 'US');
    $holidayName = hrHolidayNameForDate($workDate, 'US');

    return [
        'work_date' => $workDate,
        'row' => $day,
        'states' => $states,
        'open_state' => $openState,
        'status' => $status,
        'current_state' => $currentState,
        'punch_in' => (string)($day['punch_in'] ?? ''),
        'punch_out' => (string)($day['punch_out'] ?? ''),
        'work_minutes' => max(0, (int)($day['working_minutes'] ?? 0)),
        'break_minutes' => max(0, (int)($day['break_minutes'] ?? 0)),
        'late_minutes' => max(0, (int)($day['late_minutes'] ?? 0)),
        'display_work_minutes' => $displayWorkMinutes,
        'display_break_minutes' => $displayBreakMinutes,
        'holiday_name' => $holidayName,
        'is_holiday' => $holidayName !== '',
        'is_weekoff' => $holidayName === '' && hrIsWeekend($workDate),
    ];
}

function hrGetAttendanceAggregate(array $userIds, ?string $todayDate = null, ?string $rangeStartDate = null, ?string $rangeEndDate = null): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_values(array_filter($userIds, static fn(int $id): bool => $id > 0));
    $todayDate = (is_string($todayDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayDate))
        ? $todayDate
        : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');

    $out = [
        'today' => [
            'logged_in' => 0,
            'late' => 0,
            'on_time' => 0,
        ],
        'range' => [
            'present_days' => 0.0,
            'late_days' => 0,
            'avg_work_minutes' => 0,
            'attendance_days' => 0,
            'total_work_minutes' => 0,
        ],
    ];
    if (empty($userIds)) return $out;

    $rangeValid = is_string($rangeStartDate)
        && is_string($rangeEndDate)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStartDate)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEndDate)
        && $rangeStartDate <= $rangeEndDate;

    foreach ($userIds as $userId) {
        $snapshot = hrGetAttendanceSnapshot($userId, $todayDate, true);
        $hasPunchIn = trim((string)($snapshot['punch_in'] ?? '')) !== '';
        if ($hasPunchIn) {
            $out['today']['logged_in']++;
            if ((int)($snapshot['late_minutes'] ?? 0) > 0) {
                $out['today']['late']++;
            } else {
                $out['today']['on_time']++;
            }
        }

        if (!$rangeValid) continue;

        $summary = getAttendanceRangeSummary($userId, $rangeStartDate, $rangeEndDate);
        $out['range']['present_days'] += (float)(
            (int)($summary['present_days'] ?? 0) + (((int)($summary['half_days'] ?? 0)) * 0.5)
        );
        $out['range']['late_days'] += (int)($summary['late_days'] ?? 0);

        $rows = getAttendanceDaysForUserRange($userId, $rangeStartDate, $rangeEndDate);
        foreach ($rows as $row) {
            if (trim((string)($row['punch_in'] ?? '')) === '') continue;
            $states = [];
            if (trim((string)($row['punch_out'] ?? '')) === '' && (int)($row['id'] ?? 0) > 0) {
                $states = getAttendanceStatesForDay((int)$row['id']);
            }
            $displayWorkMinutes = hrAttendanceDisplayWorkMinutes($row, $states);
            if ($displayWorkMinutes <= 0) continue;
            $out['range']['attendance_days']++;
            $out['range']['total_work_minutes'] += $displayWorkMinutes;
        }
    }

    $attendanceDays = (int)($out['range']['attendance_days'] ?? 0);
    $totalWorkMinutes = (int)($out['range']['total_work_minutes'] ?? 0);
    $out['range']['avg_work_minutes'] = $attendanceDays > 0 ? (int)round($totalWorkMinutes / $attendanceDays) : 0;
    return $out;
}

function hrGetTodayAttendanceCapacity(array $userIds, int $year, int $month, ?string $workDate = null): int {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_values(array_filter($userIds, static fn(int $id): bool => $id > 0));
    if (empty($userIds)) return 0;
    $workDate = (is_string($workDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate))
        ? $workDate
        : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $year = (int)$year;
    $month = (int)$month;
    if ($year <= 0 || $month <= 0 || $month > 12) return 0;

    $conn = getDbConnection();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = 'ii' . str_repeat('i', count($userIds));
    $params = array_merge([$year, $month], $userIds);
    $targetMap = [];

    if (function_exists('productivityTargetsTableExists') && productivityTargetsTableExists()) {
        $stmt = $conn->prepare("
            SELECT agent_id, COALESCE(daily_target, 0) AS daily_target
            FROM productivity_targets
            WHERE year = ? AND month = ? AND agent_id IN ($placeholders)
        ");
        if ($stmt) {
            mysqliBindParams($stmt, $types, $params);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($row = $rs->fetch_assoc()) {
                $targetMap[(int)($row['agent_id'] ?? 0)] = (int)($row['daily_target'] ?? 0);
            }
            $stmt->close();
        }
    }

    $capacity = 0;
    foreach ($userIds as $userId) {
        $target = (int)($targetMap[$userId] ?? 0);
        if ($target <= 0) continue;
        $snapshot = hrGetAttendanceSnapshot($userId, $workDate, false);
        $status = trim((string)($snapshot['status'] ?? ''));
        if (in_array($status, ['Full Day', 'Present', 'present', 'In Progress'], true)) {
            $capacity += $target;
        } elseif (in_array($status, ['Half Day', 'half_day'], true)) {
            $capacity += (int)floor($target / 2);
        }
    }

    return $capacity;
}

function getAttendanceDaysForUserRange(int $userId, string $startDate, string $endDate): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    $join = hrAttendanceStartDateForUser($userId);
    if ($join !== null) {
        if ($endDate < $join) return [];
        if ($startDate < $join) $startDate = $join;
    }
    ensureDatabaseSchema();
    hrRepairOvernightOrphanPunchOutForUserRange($userId, $startDate, $endDate);
    hrAutoCloseOpenAttendanceForUserRange($userId, $startDate, $endDate);
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT d.*, s.name AS shift_name, COALESCE(d.shift_start_time, s.start_time) AS shift_start_time_full, COALESCE(d.shift_end_time, s.end_time) AS shift_end_time_full
        FROM hr_attendance_days d
        LEFT JOIN hr_shifts s ON s.id = d.shift_id
        WHERE d.user_id = ? AND d.work_date >= ? AND d.work_date <= ?
        ORDER BY d.work_date ASC, d.id ASC
    ");
    if (!$stmt) return [];
    $stmt->bind_param('iss', $userId, $startDate, $endDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getAttendanceStatesForDay(int $attendanceDayId): array {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_attendance_states WHERE attendance_day_id = ? ORDER BY start_at ASC, id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $attendanceDayId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getAttendanceAuditForDay(int $attendanceDayId): array {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT a.*, u.full_name AS created_by_name
        FROM hr_attendance_audit_logs a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.attendance_day_id = ?
        ORDER BY a.created_at DESC, a.id DESC
    ");
    if (!$stmt) return [];
    $stmt->bind_param('i', $attendanceDayId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function logAttendanceAudit(int $attendanceDayId, int $userId, string $workDate, string $action, ?string $fieldName, mixed $oldValue, mixed $newValue, ?string $remarks, ?int $createdBy): void {
    $attendanceDayId = (int)$attendanceDayId;
    $userId = (int)$userId;
    $createdBy = $createdBy !== null ? (int)$createdBy : null;
    $action = trim($action);
    $fieldName = $fieldName !== null ? trim($fieldName) : null;
    $remarks = $remarks !== null ? trim($remarks) : null;
    if ($attendanceDayId <= 0 || $userId <= 0) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    if ($action === '') return;
    ensureDatabaseSchema();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $old = $oldValue === null ? null : (is_scalar($oldValue) ? (string)$oldValue : json_encode($oldValue, JSON_UNESCAPED_SLASHES));
    $new = $newValue === null ? null : (is_scalar($newValue) ? (string)$newValue : json_encode($newValue, JSON_UNESCAPED_SLASHES));
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        INSERT INTO hr_attendance_audit_logs
          (attendance_day_id, user_id, work_date, action, field_name, old_value, new_value, remarks, created_by, ip_address, user_agent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) return;
    $stmt->bind_param(
        'iissssssiss',
        $attendanceDayId,
        $userId,
        $workDate,
        $action,
        $fieldName,
        $old,
        $new,
        $remarks,
        $createdBy,
        $ip,
        $ua
    );
    $stmt->execute();
    $stmt->close();
}

function hrIsWeekend(string $workDate): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return false;
    try {
        $d = new DateTimeImmutable($workDate . ' 00:00:00', hrBaseTz());
    } catch (Throwable $e) {
        return false;
    }
    $n = (int)$d->format('N');
    return $n === 6 || $n === 7;
}

function hrGetHolidayMapForRange(string $startDate, string $endDate, string $countryCode = 'US'): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    $countryCode = strtoupper(trim($countryCode));
    if ($countryCode === '') $countryCode = 'US';
    $cur = $startDate;
    $out = [];
    while ($cur <= $endDate) {
        $yy = (int)substr($cur, 0, 4);
        $mo = (int)substr($cur, 5, 2);
        $holidays = getHolidaysForMonth($yy, $mo, $countryCode);
        foreach ($holidays as $h) {
            $dt = (string)($h['holiday_date'] ?? '');
            if ($dt !== '' && $dt >= $startDate && $dt <= $endDate) {
                $out[$dt] = (string)($h['name'] ?? '');
            }
        }
        $cur = date('Y-m-01', strtotime($cur . ' +1 month'));
    }
    return $out;
}

function hrOvertimeMinutesFromWorking(int $workingMinutes): int {
    $workingMinutes = (int)$workingMinutes;
    return max(0, $workingMinutes - 465);
}

function hrGetUserJoinDate(int $userId): ?string {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT date_of_joining, created_at FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $join = (string)($row['date_of_joining'] ?? '');
    if ($join === '') {
        $ca = (string)($row['created_at'] ?? '');
        $join = $ca !== '' ? substr($ca, 0, 10) : '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $join)) return null;
    return $join;
}

function hrMaxDate(string $a, string $b): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) return $b;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) return $a;
    return $a >= $b ? $a : $b;
}

function hrWorkingDaysInRange(string $startDate, string $endDate, string $countryCode = 'US'): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return 0;
    if ($startDate > $endDate) return 0;
    $holidayMap = hrGetHolidayMapForRange($startDate, $endDate, $countryCode);
    $working = 0;
    $cur = $startDate;
    while ($cur <= $endDate) {
        if (!hrIsWeekend($cur) && !isset($holidayMap[$cur])) $working++;
        $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }
    return $working;
}

function hrAttendanceStartDateForUser(int $userId): ?string {
    return hrGetUserJoinDate($userId);
}

function hrPaidLeaveEligibilityMeta(int $userId, string $asOfDate): array {
    $join = hrGetUserJoinDate($userId);
    if ($join === null) {
        return [
            'join_date' => null,
            'period_start' => null,
            'working_days_from_join_in_month' => 0,
            'required_working_days' => 10,
            'eligible_in_joining_month' => false,
            'rule_text' => 'A joining-month paid leave credit is granted only when more than 10 working days remain from the employee joining date.',
        ];
    }

    $joinDt = new DateTimeImmutable($join . ' 00:00:00', hrBaseTz());
    $joinMonthEnd = $joinDt->modify('last day of this month')->format('Y-m-d');
    $workingDaysFromJoin = hrWorkingDaysInRange($join, $joinMonthEnd, 'US');
    $eligibleInJoiningMonth = $workingDaysFromJoin > 10;
    $periodStart = $eligibleInJoiningMonth
        ? $join
        : $joinDt->modify('first day of next month')->format('Y-m-d');

    $cutoff = '2026-06-01';
    $periodStart = hrMaxDate($periodStart, $cutoff);
    return [
        'join_date' => $join,
        'period_start' => $periodStart,
        'working_days_from_join_in_month' => $workingDaysFromJoin,
        'required_working_days' => 10,
        'eligible_in_joining_month' => $eligibleInJoiningMonth,
        'rule_text' => 'A joining-month paid leave credit is granted only when more than 10 working days remain from the employee joining date.',
    ];
}

function hrLeaveStartDateForUser(int $userId, string $asOfDate): ?string {
    $meta = hrPaidLeaveEligibilityMeta($userId, $asOfDate);
    $start = (string)($meta['period_start'] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : null;
}

function ensureAttendanceDay(int $userId, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    $join = hrAttendanceStartDateForUser($userId);
    if ($join !== null && $workDate < $join) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT IGNORE INTO hr_attendance_days (user_id, work_date, status) VALUES (?, ?, 'Absent')");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $workDate);
        $stmt->execute();
        $stmt->close();
    }
    $day = getAttendanceDay($userId, $workDate);
    if ($day) setAttendanceDayShiftMeta((int)$day['id'], $userId, $workDate);
    return getAttendanceDay($userId, $workDate);
}

function getOpenAttendanceDayForUser(int $userId, ?string $at = null, int $lookbackDays = 2): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    if (!$atDt) return null;
    $lookbackDays = max(1, (int)$lookbackDays);
    $minDate = $atDt->modify('-' . $lookbackDays . ' day')->format('Y-m-d');
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT *
        FROM hr_attendance_days
        WHERE user_id = ?
          AND work_date >= ?
          AND punch_in IS NOT NULL
          AND punch_in <> ''
          AND (punch_out IS NULL OR punch_out = '')
        ORDER BY work_date DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $minDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getOpenAttendanceState(int $attendanceDayId): ?array {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_attendance_states WHERE attendance_day_id = ? AND end_at IS NULL ORDER BY start_at DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $attendanceDayId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function appUtcTz(): DateTimeZone {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $id = defined('APP_STORAGE_TIMEZONE') ? (string)APP_STORAGE_TIMEZONE : 'UTC';
    try {
        $tz = new DateTimeZone($id !== '' ? $id : 'UTC');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    return $tz;
}

function appParseUtc(?string $dt): ?DateTimeImmutable {
    $dt = trim((string)$dt);
    if ($dt === '') return null;
    $tz = appUtcTz();
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
    if ($d instanceof DateTimeImmutable) return $d;
    try {
        return new DateTimeImmutable($dt, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function appFormatUtcForTimezone(?string $dt, DateTimeZone $tz, string $fmt): string {
    $d = appParseUtc($dt);
    if (!$d) return '';
    return $d->setTimezone($tz)->format($fmt);
}

function appDateInTimezone(?string $dt, DateTimeZone $tz): string {
    return appFormatUtcForTimezone($dt, $tz, 'Y-m-d');
}

function appNowUtc(): string {
    return (new DateTimeImmutable('now', appUtcTz()))->format('Y-m-d H:i:s');
}

function opsBusinessTz(): DateTimeZone {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $id = defined('APP_OPERATIONS_TIMEZONE') ? (string)APP_OPERATIONS_TIMEZONE : 'America/New_York';
    if (function_exists('getAppSetting')) {
        $id = (string)(getAppSetting('ops.timezone_business', $id) ?? $id);
    }
    try {
        $tz = new DateTimeZone($id !== '' ? $id : 'America/New_York');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('America/New_York');
    }
    return $tz;
}

function opsNow(): string {
    return (new DateTimeImmutable('now', opsBusinessTz()))->format('Y-m-d H:i:s');
}

function opsFormatUtc(?string $dt, string $fmt): string {
    return appFormatUtcForTimezone($dt, opsBusinessTz(), $fmt);
}

function opsDateFromUtc(?string $dt): string {
    return appDateInTimezone($dt, opsBusinessTz());
}

function opsNowDt(): DateTimeImmutable {
    return new DateTimeImmutable('now', opsBusinessTz());
}

function opsRange(array $query = [], string $defaultPreset = 'today'): array {
    return crmBuildDashboardRangeForTimezone($query, $defaultPreset, opsBusinessTz());
}

function opsTodayRange(): array {
    return opsRange([], 'today');
}

function opsMonthRange(): array {
    return opsRange([], 'this_month');
}

function opsTodayDate(): string {
    return (string)(opsTodayRange()['start_date'] ?? opsNowDt()->format('Y-m-d'));
}

function appDateRangeUtc(string $startDate, string $endDate, DateTimeZone $businessTz): array {
    $startDate = trim($startDate);
    $endDate = trim($endDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return [];
    }
    if ($endDate < $startDate) {
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }
    $startLocal = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $businessTz);
    $endLocal = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $businessTz);
    if (!($startLocal instanceof DateTimeImmutable) || !($endLocal instanceof DateTimeImmutable)) {
        return [];
    }
    $startLocal = $startLocal->setTime(0, 0, 0);
    $endLocal = $endLocal->setTime(23, 59, 59);
    return [
        'start' => $startLocal->setTimezone(appUtcTz())->format('Y-m-d H:i:s'),
        'end' => $endLocal->setTimezone(appUtcTz())->format('Y-m-d H:i:s'),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'start_local' => $startLocal->format('Y-m-d H:i:s'),
        'end_local' => $endLocal->format('Y-m-d H:i:s'),
        'timezone' => $businessTz->getName(),
        'storage_timezone' => appUtcTz()->getName(),
    ];
}

function opsDateRangeUtc(string $startDate, string $endDate): array {
    return appDateRangeUtc($startDate, $endDate, opsBusinessTz());
}

function leadsLegacyTz(): DateTimeZone {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $id = defined('APP_LEADS_LEGACY_TIMEZONE') ? (string)APP_LEADS_LEGACY_TIMEZONE : 'Asia/Kolkata';
    try {
        $tz = new DateTimeZone($id !== '' ? $id : 'Asia/Kolkata');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Kolkata');
    }
    return $tz;
}

function leadsUtcCutover(): ?DateTimeImmutable {
    static $cutover = false;
    if ($cutover instanceof DateTimeImmutable || $cutover === null) return $cutover;
    $raw = defined('APP_LEADS_UTC_CUTOVER') ? trim((string)APP_LEADS_UTC_CUTOVER) : '';
    if ($raw === '') {
        $cutover = null;
        return null;
    }
    $parsed = appParseUtc($raw);
    $cutover = $parsed instanceof DateTimeImmutable ? $parsed : null;
    return $cutover;
}

function leadsLegacyOffsetMinutes(): int {
    static $minutes = null;
    if ($minutes !== null) return $minutes;
    try {
        $probe = new DateTimeImmutable('2000-01-01 00:00:00', appUtcTz());
        $minutes = (int)round(leadsLegacyTz()->getOffset($probe) / 60);
    } catch (Throwable $e) {
        $minutes = 330;
    }
    return $minutes;
}

function leadLegacyUtcSqlExpr(string $qualifiedColumn): string {
    $qualifiedColumn = trim($qualifiedColumn);
    if ($qualifiedColumn === '') return 'NULL';
    $offsetMinutes = leadsLegacyOffsetMinutes();
    if ($offsetMinutes === 0) return $qualifiedColumn;
    $absMinutes = abs($offsetMinutes);
    return $offsetMinutes > 0
        ? "DATE_SUB($qualifiedColumn, INTERVAL $absMinutes MINUTE)"
        : "DATE_ADD($qualifiedColumn, INTERVAL $absMinutes MINUTE)";
}

function leadNormalizeRawTimestampToUtc(?string $raw, ?string $submittedAt = null, string $legacySource = 'legacy_local_assumed'): ?array {
    $raw = trim((string)$raw);
    $submittedAt = trim((string)$submittedAt);

    if ($raw === '') {
        $submitted = $submittedAt !== '' ? appParseUtc($submittedAt) : null;
        if ($submitted instanceof DateTimeImmutable) {
            return [
                'utc' => $submitted->format('Y-m-d H:i:s'),
                'source' => 'form_submission_utc',
            ];
        }
        return null;
    }

    $cutover = leadsUtcCutover();
    $rawAsUtc = appParseUtc($raw);
    if ($cutover instanceof DateTimeImmutable && $rawAsUtc instanceof DateTimeImmutable && $rawAsUtc >= $cutover) {
        return [
            'utc' => $rawAsUtc->format('Y-m-d H:i:s'),
            'source' => 'raw_utc_cutover',
        ];
    }

    try {
        $legacyLocal = new DateTimeImmutable($raw, leadsLegacyTz());
        return [
            'utc' => $legacyLocal->setTimezone(appUtcTz())->format('Y-m-d H:i:s'),
            'source' => $legacySource,
        ];
    } catch (Throwable $e) {
        if ($rawAsUtc instanceof DateTimeImmutable) {
            return [
                'utc' => $rawAsUtc->format('Y-m-d H:i:s'),
                'source' => 'raw_utc_fallback',
            ];
        }
    }

    return null;
}

function leadCanonicalTimestampExpr(string $column, string $alias = 'l'): string {
    $column = trim($column);
    if ($column === '') $column = 'created_at';
    $prefix = trim($alias) !== '' ? rtrim($alias, '.') . '.' : '';
    $rawColumn = "{$prefix}{$column}";
    $fallbackExpr = leadLegacyUtcSqlExpr($rawColumn);
    $cutover = leadsUtcCutover();
    if ($cutover instanceof DateTimeImmutable) {
        $cutoverSql = $cutover->format('Y-m-d H:i:s');
        $fallbackExpr = "(CASE WHEN {$rawColumn} >= '{$cutoverSql}' THEN {$rawColumn} ELSE {$fallbackExpr} END)";
    }
    if ($column === 'created_at' || $column === 'updated_at') {
        $canonical = $column . '_utc';
        return "COALESCE({$prefix}{$canonical}, {$fallbackExpr})";
    }
    return $fallbackExpr;
}

function leadCreatedAtExpr(string $alias = 'l'): string {
    return leadCanonicalTimestampExpr('created_at', $alias);
}

function leadUpdatedAtExpr(string $alias = 'l'): string {
    return leadCanonicalTimestampExpr('updated_at', $alias);
}

function leadCanonicalNowSeed(string $source = 'app_utc'): array {
    $now = appNowUtc();
    $source = trim($source) !== '' ? trim($source) : 'app_utc';
    return [
        'created_at_utc' => $now,
        'updated_at_utc' => $now,
        'created_at_source' => $source,
        'updated_at_source' => $source,
        'timezone_migrated' => 1,
    ];
}

function backfillLeadCanonicalTimestamps(int $limit = 25): int {
    static $running = false;
    if ($running) return 0;
    $running = true;

    try {
        $limit = max(1, min(250, (int)$limit));
        $conn = getDbConnection();
        $hasFormSubmissions = false;
        $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'form_submissions' LIMIT 1");
        if ($rs && $rs->fetch_assoc()) {
            $hasFormSubmissions = true;
        }
        $submittedAtSql = $hasFormSubmissions
            ? "(
                    SELECT fs.submitted_at
                    FROM form_submissions fs
                    WHERE fs.lead_id = l.id
                    ORDER BY fs.submitted_at DESC, fs.id DESC
                    LIMIT 1
                )"
            : "NULL";
        $sql = "
            SELECT
                l.id,
                l.created_at,
                l.updated_at,
                l.created_at_utc,
                l.updated_at_utc,
                l.created_at_source,
                l.updated_at_source,
                $submittedAtSql AS submitted_at
            FROM leads l
            WHERE l.created_at_utc IS NULL OR l.updated_at_utc IS NULL
            ORDER BY l.id ASC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        $updated = 0;
        foreach ($rows as $row) {
            $leadId = (int)($row['id'] ?? 0);
            if ($leadId <= 0) continue;

            $set = [];
            $params = [];
            $types = '';

            if (empty($row['created_at_utc'])) {
                $created = leadNormalizeRawTimestampToUtc(
                    $row['created_at'] ?? null,
                    $row['submitted_at'] ?? null,
                    'created_legacy_local'
                );
                if ($created) {
                    $set[] = 'created_at_utc = ?';
                    $params[] = $created['utc'];
                    $types .= 's';
                    if (trim((string)($row['created_at_source'] ?? '')) === '') {
                        $set[] = 'created_at_source = ?';
                        $params[] = (string)$created['source'];
                        $types .= 's';
                    }
                }
            }

            if (empty($row['updated_at_utc'])) {
                $updatedAt = leadNormalizeRawTimestampToUtc(
                    $row['updated_at'] ?? null,
                    null,
                    'updated_legacy_local'
                );
                if ($updatedAt) {
                    $set[] = 'updated_at_utc = ?';
                    $params[] = $updatedAt['utc'];
                    $types .= 's';
                    if (trim((string)($row['updated_at_source'] ?? '')) === '') {
                        $set[] = 'updated_at_source = ?';
                        $params[] = (string)$updatedAt['source'];
                        $types .= 's';
                    }
                }
            }

            if (empty($set)) continue;
            $set[] = 'timezone_migrated = 1';
            $params[] = $leadId;
            $types .= 'i';

            $up = $conn->prepare("UPDATE leads SET " . implode(', ', $set) . " WHERE id = ? LIMIT 1");
            if (!$up) continue;
            mysqliBindParams($up, $types, $params);
            if ($up->execute()) {
                $updated += (int)$up->affected_rows;
            }
            $up->close();
        }

        return $updated;
    } finally {
        $running = false;
    }
}

function maybeBackfillLeadCanonicalTimestamps(int $limit = 25): int {
    static $done = false;
    if ($done) return 0;
    $done = true;
    if (!(defined('DBMS_SCHEMA_AUTO_MIGRATE') && DBMS_SCHEMA_AUTO_MIGRATE)) return 0;
    return backfillLeadCanonicalTimestamps($limit);
}

function hrBaseTz(): DateTimeZone {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $fallbackId = function_exists('opsBusinessTz') ? opsBusinessTz()->getName() : 'America/New_York';
    $id = (string)(getAppSetting('hr.timezone_base', $fallbackId) ?? $fallbackId);
    if ($id === '') $id = $fallbackId;
    try {
        $tz = new DateTimeZone($id);
    } catch (Throwable $e) {
        $tz = new DateTimeZone($fallbackId);
    }
    return $tz;
}

function hrDisplayTz(): DateTimeZone {
    static $tz = null;
    if ($tz instanceof DateTimeZone) return $tz;
    $fallbackId = defined('APP_LEADS_LEGACY_TIMEZONE') ? (string)APP_LEADS_LEGACY_TIMEZONE : 'Asia/Kolkata';
    $id = (string)(getAppSetting('hr.timezone_display', $fallbackId) ?? $fallbackId);
    if ($id === '') $id = $fallbackId;
    try {
        $tz = new DateTimeZone($id);
    } catch (Throwable $e) {
        $tz = new DateTimeZone($fallbackId);
    }
    return $tz;
}

function hrParseBase(string $dt): ?DateTimeImmutable {
    $dt = trim($dt);
    if ($dt === '') return null;
    $tz = hrBaseTz();
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
    if ($d instanceof DateTimeImmutable) return $d;
    try {
        return new DateTimeImmutable($dt, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function hrParseDisplayTime(string $dt): ?DateTimeImmutable {
    $dt = trim($dt);
    if ($dt === '') return null;
    $tz = hrDisplayTz();
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
    if ($d instanceof DateTimeImmutable) return $d;
    try {
        return new DateTimeImmutable($dt, $tz);
    } catch (Throwable $e) {
        return null;
    }
}

function hrFormatForDisplay(?string $dt, string $fmt): string {
    $d = $dt !== null ? hrParseBase($dt) : null;
    if (!$d) return '';
    return $d->setTimezone(hrDisplayTz())->format($fmt);
}

function hrAttendanceShiftWindow(string $workDate, string $shiftStart, string $shiftEnd): ?array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    $shiftStart = trim($shiftStart);
    $shiftEnd = trim($shiftEnd);
    if ($shiftStart === '' || $shiftEnd === '') return null;
    if (strlen($shiftStart) === 5) $shiftStart .= ':00';
    if (strlen($shiftEnd) === 5) $shiftEnd .= ':00';
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftStart) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftEnd)) return null;
    try {
        $start = new DateTimeImmutable($workDate . ' ' . $shiftStart, hrDisplayTz());
        $end = new DateTimeImmutable($workDate . ' ' . $shiftEnd, hrDisplayTz());
    } catch (Throwable $e) {
        return null;
    }
    if ($end <= $start) $end = $end->modify('+1 day');
    return ['start' => $start, 'end' => $end];
}

function hrAttendanceShiftDistanceMinutes(DateTimeImmutable $candidate, array $window): int {
    $start = $window['start'] ?? null;
    $end = $window['end'] ?? null;
    if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) return PHP_INT_MAX;
    $candidateTs = $candidate->getTimestamp();
    $startTs = $start->getTimestamp();
    $endTs = $end->getTimestamp();
    if ($candidateTs >= $startTs && $candidateTs <= $endTs) return 0;
    return (int)floor(min(abs($candidateTs - $startTs), abs($candidateTs - $endTs)) / 60);
}

function hrResolveAttendanceDateTime(?string $dt, array $context = []): ?DateTimeImmutable {
    $raw = trim((string)$dt);
    if ($raw === '') return null;

    $converted = hrParseBase($raw);
    $converted = $converted ? $converted->setTimezone(hrDisplayTz()) : null;

    $local = hrParseDisplayTime($raw);
    $workDate = trim((string)($context['work_date'] ?? ''));
    $userId = (int)($context['user_id'] ?? 0);
    $shiftStart = (string)($context['shift_start_time_full'] ?? ($context['shift_start_time'] ?? ''));
    $shiftEnd = (string)($context['shift_end_time_full'] ?? ($context['shift_end_time'] ?? ''));
    if ($workDate !== '' && ($shiftStart === '' || $shiftEnd === '') && $userId > 0) {
        $shift = getUserShiftForDate($userId, $workDate);
        if ($shift) {
            if ($shiftStart === '') $shiftStart = (string)($shift['start_time'] ?? '');
            if ($shiftEnd === '') $shiftEnd = (string)($shift['end_time'] ?? '');
        }
    }
    $window = hrAttendanceShiftWindow($workDate, $shiftStart, $shiftEnd);

    if ($window && $local && $converted) {
        $localDistance = hrAttendanceShiftDistanceMinutes($local, $window);
        $convertedDistance = hrAttendanceShiftDistanceMinutes($converted, $window);
        return $localDistance <= $convertedDistance ? $local : $converted;
    }

    if ($local && !$converted) return $local;
    if ($converted) return $converted;
    return null;
}

function hrFormatAttendanceForDisplay(?string $dt, string $fmt, array $context = []): string {
    $resolved = hrResolveAttendanceDateTime($dt, $context);
    return $resolved ? $resolved->format($fmt) : '';
}

function hrNow(): string {
    return (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d H:i:s');
}

function punchIn(int $userId, ?string $at = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    if (!$atDt) return false;
    $at = $atDt->format('Y-m-d H:i:s');
    $workDateToday = $atDt->format('Y-m-d');
    $workDate = $workDateToday;
    $tNow = $atDt->format('H:i:s');
    $yesterday = $atDt->modify('-1 day')->format('Y-m-d');

    $shiftToday = getUserShiftForDate($userId, $workDateToday);
    $shiftYesterday = getUserShiftForDate($userId, $yesterday);
    $isOvernightEarly = function(?array $shift) use ($tNow): bool {
        if (!$shift) return false;
        $start = substr((string)($shift['start_time'] ?? ''), 0, 8);
        $end = substr((string)($shift['end_time'] ?? ''), 0, 8);
        return ($start !== '' && $end !== '' && $end <= $start && $tNow < $end);
    };
    if ($isOvernightEarly($shiftToday) || $isOvernightEarly($shiftYesterday)) {
        $workDate = $yesterday;
    }
    $day = ensureAttendanceDay($userId, $workDate);
    if (!$day) return false;
    setAttendanceDayShiftMeta((int)$day['id'], $userId, $workDate);
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_in = COALESCE(punch_in, ?), status = CASE WHEN punch_in IS NULL THEN 'In Progress' ELSE status END, updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $dayId = (int)$day['id'];
    $stmt->bind_param('si', $at, $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        recomputeAttendanceDay($dayId);
        $after = getAttendanceDay($userId, $workDate);
        if ($after) {
            hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? 'In Progress'), $userId, 'Leave request auto-cancelled because attendance was punched in.');
        }
    }
    return $ok;
}

require_once __DIR__ . '/recognition.php';

function punchOut(int $userId, ?string $at = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    if (!$atDt) return false;
    $at = $atDt->format('Y-m-d H:i:s');
    $openDay = getOpenAttendanceDayForUser($userId, $at);
    if (!$openDay || empty($openDay['punch_in'])) return false;
    $workDate = (string)($openDay['work_date'] ?? '');
    $day = $openDay;
    $dayId = (int)($day['id'] ?? 0);
    if ($dayId <= 0) return false;
    setAttendanceDayShiftMeta($dayId, $userId, $workDate);
    $open = getOpenAttendanceState($dayId);
    if ($open) {
        endAttendanceStateByDayId($dayId, $at);
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_out = ?, current_state = 'Off', updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('si', $at, $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    recomputeAttendanceDay($dayId);
    if ($ok) {
        $after = getAttendanceDay($userId, $workDate);
        if ($after) {
            hrSyncLeaveRequestForAttendanceStatus($userId, $workDate, (string)($after['status'] ?? 'Absent'), $userId, 'Leave request reconciled after punch out.');
        }
    }
    return $ok;
}

function hrNormalizeAttendanceStateKey(string $state): string {
    $raw = trim($state);
    if ($raw === '') return '';
    $normalized = strtolower(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $raw)));
    return match ($normalized) {
        'working' => 'Working',
        'in progress' => 'Working',
        'break1', 'break 1', 'tea break 1', 'tea1', 'tea 1' => 'Break1',
        'break2', 'break 2', 'tea break 2', 'tea2', 'tea 2' => 'Break2',
        'lunch', 'dinner' => 'Lunch',
        'meeting' => 'Meeting',
        'off' => 'Off',
        default => $raw,
    };
}

function hrAttendanceStateDisplayLabel(string $state): string {
    $canonical = hrNormalizeAttendanceStateKey($state);
    return match ($canonical) {
        'Break1' => 'Tea Break 1',
        'Break2' => 'Tea Break 2',
        'Lunch' => 'Dinner',
        'Working' => 'Working',
        'In Progress' => 'Working',
        'Meeting' => 'Meeting',
        'Off' => 'Off',
        default => trim($state) !== '' ? trim($state) : 'Off',
    };
}

function hrAttendanceBreakStates(): array {
    return ['Break1', 'Break2', 'Lunch'];
}

function startAttendanceState(int $userId, string $state, ?string $at = null): array {
    $allowed = ['Working','Break1','Break2','Lunch','Meeting'];
    $state = hrNormalizeAttendanceStateKey($state);
    if (!in_array($state, $allowed, true)) return ['ok' => false, 'error' => 'Invalid state'];
    $userId = (int)$userId;
    if ($userId <= 0) return ['ok' => false, 'error' => 'Invalid user'];
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    if (!$atDt) return ['ok' => false, 'error' => 'Invalid time'];
    $at = $atDt->format('Y-m-d H:i:s');
    $openDay = getOpenAttendanceDayForUser($userId, $at);
    if (!$openDay) return ['ok' => false, 'error' => 'Punch in first'];
    $workDate = (string)($openDay['work_date'] ?? '');
    $day = $openDay;
    if (empty($day['punch_in'])) return ['ok' => false, 'error' => 'Punch in first'];
    if (!empty($day['punch_in']) && !empty($day['punch_out']) && (string)($day['status'] ?? '') === 'Absent') {
        return ['ok' => false, 'error' => 'Marked Absent due to late policy'];
    }
    $dayId = (int)($day['id'] ?? 0);
    if ($dayId <= 0) return ['ok' => false, 'error' => 'Attendance record missing'];
    setAttendanceDayShiftMeta($dayId, $userId, $workDate);

    $open = getOpenAttendanceState($dayId);
    if ($open) {
        if ((string)($open['state'] ?? '') === $state) return ['ok' => true];
        endAttendanceStateByDayId($dayId, $at);
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO hr_attendance_states (attendance_day_id, state, start_at) VALUES (?, ?, ?)");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error'];
    $stmt->bind_param('iss', $dayId, $state, $at);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $stmt2 = $conn->prepare("UPDATE hr_attendance_days SET current_state = ?, status = CASE WHEN status IN ('Full Day','Half Day','Absent','Paid Leave','Unpaid Leave') THEN status ELSE status END, updated_at = NOW() WHERE id = ?");
        if ($stmt2) {
            $stmt2->bind_param('si', $state, $dayId);
            $stmt2->execute();
            $stmt2->close();
        }
    }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Database error'];
}

function endAttendanceState(int $userId, ?string $at = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    if (!$atDt) return false;
    $at = $atDt->format('Y-m-d H:i:s');
    $openDay = getOpenAttendanceDayForUser($userId, $at);
    if ($openDay) {
        $dayId = (int)($openDay['id'] ?? 0);
        return $dayId > 0 ? endAttendanceStateByDayId($dayId, $at) : false;
    }
    $workDate = $atDt->format('Y-m-d');
    $day = getAttendanceDay($userId, $workDate);
    if (!$day) return false;
    return endAttendanceStateByDayId((int)$day['id'], $at);
}

function endAttendanceStateByDayId(int $attendanceDayId, string $at): bool {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return false;
    $conn = getDbConnection();
    $open = getOpenAttendanceState($attendanceDayId);
    if (!$open) return true;
    $start = (string)($open['start_at'] ?? '');
    $startDt = hrParseBase($start);
    $endDt = hrParseBase($at);
    if (!$startDt || !$endDt) return false;
    if ($endDt <= $startDt) $endDt = $startDt;
    $startTs = $startDt->getTimestamp();
    $endTs = $endDt->getTimestamp();
    $minutes = (int)floor(($endTs - $startTs) / 60);

    $stmt = $conn->prepare("UPDATE hr_attendance_states SET end_at = ?, minutes = ? WHERE id = ?");
    if (!$stmt) return false;
    $id = (int)$open['id'];
    $stmt->bind_param('sii', $at, $minutes, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $stmt2 = $conn->prepare("UPDATE hr_attendance_days SET current_state = 'Working', updated_at = NOW() WHERE id = ?");
    if ($stmt2) {
        $stmt2->bind_param('i', $attendanceDayId);
        $stmt2->execute();
        $stmt2->close();
    }
    recomputeAttendanceDay($attendanceDayId);
    return $ok;
}

function hrAttendanceResetDateTimeForWorkDate(string $workDate): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return null;
    try {
        return (new DateTimeImmutable($workDate . ' 09:30:00', hrBaseTz()))->modify('+1 day');
    } catch (Throwable $e) {
        return null;
    }
}

function hrHolidayNameForDate(string $workDate, string $countryCode = 'US'): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return '';
    $holidayMap = hrGetHolidayMapForRange($workDate, $workDate, $countryCode);
    return (string)($holidayMap[$workDate] ?? '');
}

function hrAttendanceAutoPunchOutDateTimeForDay(array $day): ?DateTimeImmutable {
    $userId = (int)($day['user_id'] ?? 0);
    $workDate = (string)($day['work_date'] ?? '');
    $pIn = (string)($day['punch_in'] ?? '');
    if ($userId <= 0 || $workDate === '' || $pIn === '') return null;

    if (!array_key_exists('shift_start_time', $day) || !array_key_exists('shift_end_time', $day) || !array_key_exists('shift_id', $day)) {
        $storedDay = getAttendanceDay($userId, $workDate);
        if ($storedDay) {
            $day = array_merge($storedDay, $day);
        }
    }
    $shift = null;
    $shiftStart = substr((string)($day['shift_start_time'] ?? ''), 0, 8);
    $shiftEnd = substr((string)($day['shift_end_time'] ?? ''), 0, 8);
    $shiftId = (int)($day['shift_id'] ?? 0);
    if (($shiftStart === '' || $shiftEnd === '') && $shiftId > 0) {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM hr_shifts WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            $shift = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }
    if (($shiftStart === '' || $shiftEnd === '') && !$shift) {
        $shift = getUserShiftForDate($userId, $workDate);
    }
    $shiftStart = substr($shiftStart !== '' ? $shiftStart : (string)($shift['start_time'] ?? '09:30:00'), 0, 8);
    $shiftEnd = substr($shiftEnd !== '' ? $shiftEnd : (string)($shift['end_time'] ?? '18:30:00'), 0, 8);
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftStart)) $shiftStart = '09:30:00';
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $shiftEnd)) $shiftEnd = '18:30:00';

    $pInDt = hrParseBase($pIn);
    if (!$pInDt) return null;

    $candidates = [];
    $resetDt = hrAttendanceResetDateTimeForWorkDate($workDate);
    if ($resetDt && $resetDt > $pInDt) {
        $candidates[] = $resetDt;
    }

    try {
        $shiftEndDt = new DateTimeImmutable($workDate . ' ' . $shiftEnd, hrBaseTz());
    } catch (Throwable $e) {
        $shiftEndDt = null;
    }
    if ($shiftEndDt) {
        if ($shiftEnd <= $shiftStart) {
            $shiftEndDt = $shiftEndDt->modify('+1 day');
        }
        if ($shiftEndDt > $pInDt) {
            $candidates[] = $shiftEndDt;
        }
    }

    if (!empty($candidates)) {
        usort($candidates, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        return $candidates[0];
    }

    return $pInDt->modify('+1 minute');
}

function hrAutoCloseAttendanceDayAtResetCutoff(array $day, ?string $at = null): bool {
    $dayId = (int)($day['id'] ?? 0);
    $userId = (int)($day['user_id'] ?? 0);
    $workDate = (string)($day['work_date'] ?? '');
    $pIn = (string)($day['punch_in'] ?? '');
    $pOut = (string)($day['punch_out'] ?? '');
    if ($dayId <= 0 || $userId <= 0 || $workDate === '' || $pIn === '' || $pOut !== '') return false;
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    $pInDt = hrParseBase($pIn);
    $autoOutDt = hrAttendanceAutoPunchOutDateTimeForDay($day);
    if (!$autoOutDt) $autoOutDt = hrAttendanceResetDateTimeForWorkDate($workDate);
    if (!$atDt || !$pInDt || !$autoOutDt) return false;
    if ($atDt < $autoOutDt || $autoOutDt <= $pInDt) return false;
    $autoOut = $autoOutDt->format('Y-m-d H:i:s');
    $before = getAttendanceDay($userId, $workDate) ?: $day;
    $open = getOpenAttendanceState($dayId);
    if ($open) {
        endAttendanceStateByDayId($dayId, $autoOut);
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_out = ?, current_state = 'Off', updated_at = NOW() WHERE id = ? AND (punch_out IS NULL OR punch_out = '')");
    if (!$stmt) return false;
    $stmt->bind_param('si', $autoOut, $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        recomputeAttendanceDay($dayId);
        $after = getAttendanceDay($userId, $workDate);
        if ($after) {
            $remarks = 'Automatic punch-out applied at attendance reset cutoff.';
            foreach (['punch_out', 'current_state', 'status', 'working_minutes', 'break_minutes'] as $field) {
                $oldValue = $before[$field] ?? null;
                $newValue = $after[$field] ?? null;
                if ((string)$oldValue !== (string)$newValue) {
                    logAttendanceAudit($dayId, $userId, $workDate, 'Auto Punch Out', $field, $oldValue, $newValue, $remarks, null);
                }
            }
        }
    }
    return $ok;
}

function hrAutoCloseOpenAttendanceForUserRange(int $userId, string $startDate, string $endDate, ?string $at = null): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, user_id, work_date, punch_in, punch_out
        FROM hr_attendance_days
        WHERE user_id = ?
          AND work_date >= ?
          AND work_date <= ?
          AND punch_in IS NOT NULL
          AND punch_in <> ''
          AND (punch_out IS NULL OR punch_out = '')
        ORDER BY work_date ASC, id ASC
    ");
    if (!$stmt) return;
    $stmt->bind_param('iss', $userId, $startDate, $endDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $row) {
        hrAutoCloseAttendanceDayAtResetCutoff($row, $at);
    }
}

function hrAutoCloseOpenAttendanceForDate(string $workDate, ?string $at = null): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, user_id, work_date, punch_in, punch_out
        FROM hr_attendance_days
        WHERE work_date = ?
          AND punch_in IS NOT NULL
          AND punch_in <> ''
          AND (punch_out IS NULL OR punch_out = '')
        ORDER BY id ASC
    ");
    if (!$stmt) return;
    $stmt->bind_param('s', $workDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as $row) {
        hrAutoCloseAttendanceDayAtResetCutoff($row, $at);
    }
}

function hrRunAttendanceAutoCloseScheduler(?string $at = null): array {
    ensureDatabaseSchema();
    $at = $at ?: hrNow();
    $atDt = hrParseBase($at);
    if (!$atDt) {
        return ['ok' => false, 'message' => 'Invalid run time.', 'run_at' => $at, 'open_days' => 0, 'closed_days' => 0];
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, user_id, work_date, punch_in, punch_out
        FROM hr_attendance_days
        WHERE punch_in IS NOT NULL
          AND punch_in <> ''
          AND (punch_out IS NULL OR punch_out = '')
        ORDER BY work_date ASC, id ASC
    ");
    if (!$stmt) {
        return ['ok' => false, 'message' => 'Database error.', 'run_at' => $atDt->format('Y-m-d H:i:s'), 'open_days' => 0, 'closed_days' => 0];
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $closed = 0;
    foreach ($rows as $row) {
        if (hrAutoCloseAttendanceDayAtResetCutoff($row, $atDt->format('Y-m-d H:i:s'))) {
            $closed++;
        }
    }

    return [
        'ok' => true,
        'message' => $closed > 0 ? 'Attendance auto-close completed.' : 'No eligible open attendance found.',
        'run_at' => $atDt->format('Y-m-d H:i:s'),
        'open_days' => count($rows),
        'closed_days' => $closed,
        'skipped_days' => max(0, count($rows) - $closed),
    ];
}

function getBreakMinutesUsed(int $attendanceDayId, string $breakState): int {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return 0;
    $breakState = trim($breakState);
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(minutes),0) AS m FROM hr_attendance_states WHERE attendance_day_id = ? AND state = ? AND minutes IS NOT NULL");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $attendanceDayId, $breakState);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['m' => 0];
    $stmt->close();
    return (int)($row['m'] ?? 0);
}

function recomputeAttendanceDay(int $attendanceDayId): bool {
    $attendanceDayId = (int)$attendanceDayId;
    if ($attendanceDayId <= 0) return false;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT user_id, work_date, punch_in, punch_out, shift_start_time, grace_minutes, status FROM hr_attendance_days WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $attendanceDayId);
    $stmt->execute();
    $day = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$day) return false;

    $userId = (int)($day['user_id'] ?? 0);
    $workDate = (string)($day['work_date'] ?? '');
    if ($userId > 0 && $workDate !== '') {
        setAttendanceDayShiftMeta($attendanceDayId, $userId, $workDate);
        $stmt = $conn->prepare("SELECT punch_in, punch_out, shift_start_time, grace_minutes FROM hr_attendance_days WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $attendanceDayId);
            $stmt->execute();
            $day2 = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($day2) $day = array_merge($day, $day2);
        }
    }

    $pIn = (string)($day['punch_in'] ?? '');
    $pOut = (string)($day['punch_out'] ?? '');
    $currentStatus = (string)($day['status'] ?? '');
    $lateMinutes = 0;
    $shiftStart = (string)($day['shift_start_time'] ?? '');
    $policy = getAttendancePolicySettings();
    $grace = isset($day['grace_minutes']) && $day['grace_minutes'] !== null ? (int)$day['grace_minutes'] : (int)($policy['grace_minutes'] ?? 10);
    if ($grace < 0) $grace = 0;
    if ($grace > 120) $grace = 120;
    $inDt = null;
    $shiftDt = null;
    if ($pIn !== '' && $shiftStart !== '' && $workDate !== '') {
        $inDt = hrParseBase($pIn);
        if (strlen($shiftStart) === 5) $shiftStart .= ':00';
        try { $shiftDt = new DateTimeImmutable($workDate . ' ' . $shiftStart, hrBaseTz()); } catch (Throwable $e) { $shiftDt = null; }
        if ($inDt && $shiftDt) {
            $allowedDt = $shiftDt->modify('+' . max(0, $grace) . ' minutes');
            if ($inDt > $allowedDt) {
                $lateMinutes = (int)floor(($inDt->getTimestamp() - $allowedDt->getTimestamp()) / 60);
            }
        }
    }
    if ($pIn === '' && $pOut !== '' && $userId > 0 && $workDate !== '') {
        $prevDate = date('Y-m-d', strtotime($workDate . ' -1 day'));
        hrRepairOvernightOrphanPunchOutForUserRange($userId, $prevDate, $prevDate);
        $reloaded = getAttendanceDay($userId, $workDate);
        if ($reloaded) {
            $day = array_merge($day, $reloaded);
            $pIn = (string)($day['punch_in'] ?? '');
            $pOut = (string)($day['punch_out'] ?? '');
            $currentStatus = (string)($day['status'] ?? $currentStatus);
        }
    }

    if ($pIn === '') {
        $isLeave = in_array($currentStatus, ['Paid Leave','Unpaid Leave','paid_leave','unpaid_leave','NCNS','ncns'], true);
        $keepStatus = $isLeave ? ($currentStatus === 'paid_leave' ? 'Paid Leave' : ($currentStatus === 'unpaid_leave' ? 'Unpaid Leave' : ($currentStatus === 'ncns' ? 'NCNS' : $currentStatus))) : 'Absent';
        $stmt = $conn->prepare("UPDATE hr_attendance_days SET punch_out = NULL, current_state = 'Off', break_minutes = 0, working_minutes = 0, late_minutes = 0, status = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('si', $keepStatus, $attendanceDayId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $breakMinutes = 0;
    foreach (hrAttendanceBreakStates() as $k) {
        $m = getBreakMinutesUsed($attendanceDayId, $k);
        $breakMinutes += max(0, (int)$m);
    }

    $workMinutes = 0;
    if ($pOut !== '' && $inDt) {
        $outDt = hrParseBase($pOut);
        if ($outDt && $outDt <= $inDt) $outDt = $outDt->modify('+1 day');
        if ($outDt && $outDt > $inDt) {
            $workMinutes = (int)floor(($outDt->getTimestamp() - $inDt->getTimestamp()) / 60) - $breakMinutes;
            if ($workMinutes < 0) $workMinutes = 0;
        }
    }

    $isLeave = in_array($currentStatus, ['Paid Leave','Unpaid Leave','paid_leave','unpaid_leave','NCNS','ncns'], true);
    $status = 'In Progress';
    if ($isLeave) {
        $status = $currentStatus === 'paid_leave' ? 'Paid Leave' : ($currentStatus === 'unpaid_leave' ? 'Unpaid Leave' : ($currentStatus === 'ncns' ? 'NCNS' : $currentStatus));
    } elseif ($pOut !== '') {
        if ($workMinutes < (4 * 60)) {
            $status = 'Absent';
        } elseif ($workMinutes < 465) {
            $status = 'Half Day';
        } else {
            $status = 'Full Day';
        }
    }

    $stmt = $conn->prepare("UPDATE hr_attendance_days SET break_minutes = ?, working_minutes = ?, late_minutes = ?, status = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('iiisi', $breakMinutes, $workMinutes, $lateMinutes, $status, $attendanceDayId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function hrRepairOvernightOrphanPunchOut(string $workDate): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    ensureDatabaseSchema();
    $nextDate = date('Y-m-d', strtotime($workDate . ' +1 day'));
    $conn = getDbConnection();
    $pairs = [];
    $stmt = $conn->prepare("
        SELECT d1.id AS d1_id, d2.id AS d2_id
        FROM hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = ?
        WHERE d1.work_date = ?
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $nextDate, $workDate);
        $stmt->execute();
        $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    if (empty($pairs)) return;

    $conn->query("
        UPDATE hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = '{$nextDate}'
        SET
            d1.punch_out = d2.punch_out,
            d1.current_state = 'Off',
            d2.punch_out = NULL,
            d2.current_state = 'Off',
            d2.break_minutes = 0,
            d2.working_minutes = 0,
            d2.late_minutes = 0,
            d2.status = 'Absent',
            d2.updated_at = NOW()
        WHERE d1.work_date = '{$workDate}'
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");

    $seen = [];
    foreach ($pairs as $p) {
        $d1 = (int)($p['d1_id'] ?? 0);
        $d2 = (int)($p['d2_id'] ?? 0);
        if ($d1 > 0 && !isset($seen[$d1])) { $seen[$d1] = true; recomputeAttendanceDay($d1); }
        if ($d2 > 0 && !isset($seen[$d2])) { $seen[$d2] = true; recomputeAttendanceDay($d2); }
    }
}

function getAttendanceDashboard(string $workDate): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return [];
    ensureDatabaseSchema();
    hrRepairOvernightOrphanPunchOut($workDate);
    hrAutoCloseOpenAttendanceForDate($workDate);
    $holidayName = hrHolidayNameForDate($workDate, 'US');
    $isHoliday = $holidayName !== '';
    $today = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT u.id AS user_id, u.full_name, u.employee_id, u.job_title, u.role,
               u.date_of_joining, u.created_at,
               d.punch_in, d.punch_out, d.current_state, d.break_minutes, d.working_minutes, d.late_minutes, d.shift_start_time, d.shift_end_time,
               COALESCE(d.shift_start_time, s.start_time) AS shift_start_time_full, COALESCE(d.shift_end_time, s.end_time) AS shift_end_time_full, d.grace_minutes, d.status
        FROM users u
        LEFT JOIN hr_attendance_days d ON d.user_id = u.id AND d.work_date = ?
        LEFT JOIN hr_shifts s ON s.id = d.shift_id
        WHERE (u.client_id IS NULL OR u.client_id = 0)
          AND (u.vendor_id IS NULL OR u.vendor_id = 0)
          AND u.is_active = 1
          AND COALESCE(u.date_of_joining, DATE(u.created_at)) <= ?
        ORDER BY u.full_name
    ");
    if (!$stmt) return [];
    $stmt->bind_param('ss', $workDate, $workDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as &$row) {
        $meta = hrAttendanceStatusMeta($row, $workDate, $today, 'US', true);
        $row['holiday_name'] = (string)($meta['holiday_name'] ?? $holidayName);
        $row['is_holiday'] = !empty($meta['is_holiday']);
        $row['is_weekoff'] = !empty($meta['is_weekoff']);
        $row['display_status'] = (string)($meta['label'] ?? ($row['status'] ?? 'Absent'));
        $row['status_filter_key'] = (string)($meta['filter_key'] ?? 'absent');
        $row['primary_status'] = (string)($meta['primary_status'] ?? 'Absent');
        $row['is_present_like'] = !empty($meta['is_present_like']) ? 1 : 0;
        $row['is_late'] = !empty($meta['is_late']) ? 1 : 0;
    }
    unset($row);
    return $rows;
}

function getAttendanceRangeSummary(int $userId, string $startDate, string $endDate): array {
    $empty = [
        'present_days' => 0,
        'half_days' => 0,
        'absent_days' => 0,
        'pending_days' => 0,
        'ncns_days' => 0,
        'paid_leave_days' => 0,
        'unpaid_leave_days' => 0,
        'working_days' => 0,
        'late_days' => 0,
        'late_minutes' => 0,
        'work_minutes' => 0,
        'break_minutes' => 0,
        'late_policy_half_days' => 0,
        'late_policy_absent_days' => 0,
        'late_policy_deduction_days' => 0.0,
        'paid_days' => 0.0,
        'attendance_percent' => 0.0,
    ];
    $userId = (int)$userId;
    if ($userId <= 0) return $empty;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return $empty;
    if ($endDate < $startDate) return $empty;

    $join = hrAttendanceStartDateForUser($userId);
    if ($join !== null) {
        if ($endDate < $join) return $empty;
        if ($startDate < $join) $startDate = $join;
    }

    $today = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $nextDate = date('Y-m-d', strtotime($today . ' +1 day'));
    $effectiveEnd = $endDate > $today ? $today : $endDate;

    $workingDays = 0;
    if ($effectiveEnd >= $startDate) {
        $workingDays = hrWorkingDaysInRange($startDate, $effectiveEnd, 'US');
        hrRepairOvernightOrphanPunchOutForUserRange($userId, $startDate, $effectiveEnd);
        hrAutoCloseOpenAttendanceForUserRange($userId, $startDate, $effectiveEnd);
    }

    $pendingDays = 0;
    if ($endDate >= $nextDate) {
        $pendingStart = $startDate > $nextDate ? $startDate : $nextDate;
        if ($pendingStart <= $endDate) {
            $pendingDays = hrWorkingDaysInRange($pendingStart, $endDate, 'US');
        }
    }

    $r = [];
    if ($effectiveEnd >= $startDate) {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN status IN ('Full Day','Present','present') THEN 1 ELSE 0 END) AS full_days,
                SUM(CASE WHEN status IN ('Half Day','half_day') THEN 1 ELSE 0 END) AS half_days,
                SUM(CASE WHEN status IN ('Paid Leave','paid_leave') THEN 1 ELSE 0 END) AS paid_leave_days,
                SUM(CASE WHEN status IN ('Unpaid Leave','unpaid_leave') THEN 1 ELSE 0 END) AS unpaid_leave_days,
                SUM(CASE WHEN status IN ('NCNS','ncns') THEN 1 ELSE 0 END) AS ncns_days,
                SUM(CASE WHEN COALESCE(late_minutes, 0) > 0 THEN 1 ELSE 0 END) AS late_days,
                SUM(COALESCE(late_minutes, 0)) AS late_minutes,
                SUM(COALESCE(working_minutes, 0)) AS work_minutes,
                SUM(COALESCE(break_minutes, 0)) AS break_minutes
            FROM hr_attendance_days
            WHERE user_id = ? AND work_date >= ? AND work_date <= ?
        ");
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $startDate, $effectiveEnd);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }

    $presentDays = (int)($r['full_days'] ?? 0);
    $halfDays = (int)($r['half_days'] ?? 0);
    $paidLeaveDays = (int)($r['paid_leave_days'] ?? 0);
    $unpaidLeaveDays = (int)($r['unpaid_leave_days'] ?? 0);
    $ncnsDays = (int)($r['ncns_days'] ?? 0);
    $lateDays = (int)($r['late_days'] ?? 0);
    $lateMinutes = (int)($r['late_minutes'] ?? 0);
    $workMinutes = (int)($r['work_minutes'] ?? 0);
    $breakMinutes = (int)($r['break_minutes'] ?? 0);
    $absent = max(0, $workingDays - ($presentDays + $halfDays + $paidLeaveDays + $unpaidLeaveDays + $ncnsDays));
    $policy = getAttendancePolicySettings();
    $lateHalfAt = max(1, (int)($policy['late_halfday_at'] ?? 3));
    $lateAbsentAt = max($lateHalfAt, (int)($policy['late_absent_at'] ?? 4));
    $remainingLateDays = max(0, $lateDays);
    $latePolicyAbsentDays = $lateAbsentAt > 0 ? intdiv($remainingLateDays, $lateAbsentAt) : 0;
    $remainingLateDays -= $latePolicyAbsentDays * $lateAbsentAt;
    $latePolicyHalfDays = $lateHalfAt > 0 ? intdiv($remainingLateDays, $lateHalfAt) : 0;
    $latePolicyDeductionDays = ($latePolicyAbsentDays * 1.0) + ($latePolicyHalfDays * 0.5);
    $paidDays = max(0.0, ($presentDays + $paidLeaveDays + ($halfDays * 0.5)) - $latePolicyDeductionDays);
    $attendancePercent = $workingDays > 0 ? round((($paidDays / $workingDays) * 100.0), 1) : 0.0;

    return [
        'present_days' => $presentDays,
        'half_days' => $halfDays,
        'absent_days' => $absent,
        'pending_days' => $pendingDays,
        'ncns_days' => $ncnsDays,
        'paid_leave_days' => $paidLeaveDays,
        'unpaid_leave_days' => $unpaidLeaveDays,
        'working_days' => $workingDays,
        'late_days' => $lateDays,
        'late_minutes' => $lateMinutes,
        'work_minutes' => $workMinutes,
        'break_minutes' => $breakMinutes,
        'late_policy_half_days' => $latePolicyHalfDays,
        'late_policy_absent_days' => $latePolicyAbsentDays,
        'late_policy_deduction_days' => $latePolicyDeductionDays,
        'paid_days' => $paidDays,
        'attendance_percent' => $attendancePercent,
    ];
}

function getAttendanceMonthSummary(int $userId, int $year, int $month): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['present_days' => 0, 'half_days' => 0, 'absent_days' => 0, 'pending_days' => 0, 'ncns_days' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'working_days' => 0, 'late_days' => 0, 'late_minutes' => 0, 'work_minutes' => 0, 'break_minutes' => 0, 'paid_days' => 0.0, 'attendance_percent' => 0.0];
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    return getAttendanceRangeSummary($userId, $start, $end);
}

function getAttendanceRangePrimaryStatus(array $summary): string {
    $presentDays = (int)($summary['present_days'] ?? 0);
    $halfDays = (int)($summary['half_days'] ?? 0);
    $absentDays = (int)($summary['absent_days'] ?? 0);
    $pendingDays = (int)($summary['pending_days'] ?? 0);
    $ncnsDays = (int)($summary['ncns_days'] ?? 0);
    $paidLeaveDays = (int)($summary['paid_leave_days'] ?? 0);
    $unpaidLeaveDays = (int)($summary['unpaid_leave_days'] ?? 0);
    $lateDays = (int)($summary['late_days'] ?? 0);

    if ($ncnsDays > 0) return 'NCNS';
    if ($absentDays > 0) return 'Absent';
    if (($paidLeaveDays + $unpaidLeaveDays) > 0) return 'Leave';
    if ($lateDays > 0) return 'Late';
    if (($presentDays + $halfDays) > 0) return 'Present';
    if ($pendingDays > 0) return 'Pending';
    return 'No Record';
}

function getAttendanceRangeReport(string $startDate, string $endDate, array $filters = []): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    if ($endDate < $startDate) return [];

    $search = strtolower(trim((string)($filters['search'] ?? '')));
    $roleFilter = strtolower(trim((string)($filters['role'] ?? '')));
    $departmentFilter = strtolower(trim((string)($filters['department'] ?? '')));
    $statusFilter = strtolower(trim((string)($filters['status'] ?? '')));

    $rows = [];
    foreach (getInternalPayrollUsers() as $u) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) continue;

        $name = (string)($u['full_name'] ?? '');
        $employeeId = (string)($u['employee_id'] ?? '');
        $jobTitle = (string)($u['job_title'] ?? '');
        $role = (string)($u['role'] ?? '');
        $department = (string)($u['department'] ?? '');

        if ($search !== '') {
            $haystack = strtolower(trim($name . ' ' . $employeeId . ' ' . $jobTitle . ' ' . $role . ' ' . $department));
            if (strpos($haystack, $search) === false) continue;
        }
        if ($roleFilter !== '' && strtolower(trim($role)) !== $roleFilter) continue;
        if ($departmentFilter !== '' && strtolower(trim($department)) !== $departmentFilter) continue;

        $summary = getAttendanceRangeSummary($uid, $startDate, $endDate);
        $primaryStatus = getAttendanceRangePrimaryStatus($summary);
        if ($statusFilter !== '' && $statusFilter !== 'all' && strtolower($primaryStatus) !== $statusFilter) continue;

        $rows[] = [
            'user_id' => $uid,
            'name' => $name,
            'employee_id' => $employeeId,
            'job_title' => $jobTitle,
            'role' => $role,
            'department' => $department,
            'primary_status' => $primaryStatus,
        ] + $summary;
    }

    usort($rows, static function (array $a, array $b): int {
        $aName = strtolower(trim((string)($a['name'] ?? '')));
        $bName = strtolower(trim((string)($b['name'] ?? '')));
        return $aName <=> $bName;
    });

    return $rows;
}

function getPaidLeaveKittyForUser(int $userId, ?string $asOfDate = null): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['entitled' => 0, 'taken' => 0, 'available' => 0, 'pending_requests' => 0, 'join_date' => null, 'period_start' => null, 'taken_dates' => [], 'upcoming_holidays' => []];
    ensureDatabaseSchema();
    $asOf = $asOfDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) ? $asOfDate : (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
    $conn = getDbConnection();
    $eligibility = hrPaidLeaveEligibilityMeta($userId, $asOf);
    $join = (string)($eligibility['join_date'] ?? '') ?: $asOf;
    $periodStart = (string)($eligibility['period_start'] ?? '') ?: $asOf;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) $periodStart = $asOf;

    $joinDt = new DateTimeImmutable($join . ' 00:00:00', hrBaseTz());
    $startDt = new DateTimeImmutable($periodStart . ' 00:00:00', hrBaseTz());
    $asOfDt = new DateTimeImmutable($asOf . ' 00:00:00', hrBaseTz());
    if ($joinDt > $asOfDt) $joinDt = $asOfDt;
    $months = 0;
    if ($startDt <= $asOfDt) {
        $months = (((int)$asOfDt->format('Y') - (int)$startDt->format('Y')) * 12) + ((int)$asOfDt->format('n') - (int)$startDt->format('n')) + 1;
    }
    if ($months < 0) $months = 0;

    $takenMap = [];
    $stmt2 = $conn->prepare("SELECT leave_date FROM hr_leave_requests WHERE user_id = ? AND leave_type = 'Paid Leave' AND status = 'Approved' AND leave_date >= ? AND leave_date <= ? ORDER BY leave_date DESC");
    if ($stmt2) {
        $stmt2->bind_param('iss', $userId, $periodStart, $asOf);
        $stmt2->execute();
        $rs = $stmt2->get_result();
        while ($r = $rs->fetch_assoc()) {
            $d = (string)($r['leave_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $takenMap[$d] = true;
        }
        $stmt2->close();
    }

    $stmtLegacy = $conn->prepare("SELECT work_date FROM hr_attendance_days WHERE user_id = ? AND status = 'Paid Leave' AND work_date >= ? AND work_date <= ? ORDER BY work_date DESC");
    if ($stmtLegacy) {
        $stmtLegacy->bind_param('iss', $userId, $periodStart, $asOf);
        $stmtLegacy->execute();
        $rs = $stmtLegacy->get_result();
        while ($r = $rs->fetch_assoc()) {
            $d = (string)($r['work_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $takenMap[$d] = true;
        }
        $stmtLegacy->close();
    }

    $pendingRequests = 0;
    $stmtPending = $conn->prepare("SELECT COUNT(*) AS c FROM hr_leave_requests WHERE user_id = ? AND leave_type = 'Paid Leave' AND status = 'Pending' AND leave_date >= ? AND leave_date >= ?");
    if ($stmtPending) {
        $today = (new DateTimeImmutable('now', hrBaseTz()))->format('Y-m-d');
        $stmtPending->bind_param('iss', $userId, $periodStart, $today);
        $stmtPending->execute();
        $pendingRequests = (int)(($stmtPending->get_result()->fetch_assoc() ?: [])['c'] ?? 0);
        $stmtPending->close();
    }

    $takenDates = array_keys($takenMap);
    rsort($takenDates);
    $taken = count($takenDates);
    $available = $months - $taken - $pendingRequests;
    if ($available < 0) $available = 0;
    $upcoming = getUpcomingHolidays($asOf, 10, 'US');
    return [
        'entitled' => (int)$months,
        'taken' => (int)$taken,
        'available' => (int)$available,
        'pending_requests' => (int)$pendingRequests,
        'join_date' => $joinDt->format('Y-m-d'),
        'period_start' => $startDt->format('Y-m-d'),
        'working_days_from_join_in_month' => (int)($eligibility['working_days_from_join_in_month'] ?? 0),
        'required_working_days' => (int)($eligibility['required_working_days'] ?? 10),
        'eligible_in_joining_month' => !empty($eligibility['eligible_in_joining_month']),
        'rule_text' => (string)($eligibility['rule_text'] ?? ''),
        'taken_dates' => $takenDates,
        'upcoming_holidays' => is_array($upcoming) ? $upcoming : [],
    ];
}

function hrGetLeaveRequests(?int $userId = null, ?string $status = null, ?string $startDate = null, ?string $endDate = null): array {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $where = [];
    $types = '';
    $params = [];
    if ($userId !== null && $userId > 0) {
        $where[] = 'r.user_id = ?';
        $types .= 'i';
        $params[] = (int)$userId;
    }
    if (is_string($status) && $status !== '') {
        $where[] = 'r.status = ?';
        $types .= 's';
        $params[] = trim($status);
    }
    if (is_string($startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $where[] = 'r.leave_date >= ?';
        $types .= 's';
        $params[] = $startDate;
    }
    if (is_string($endDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $where[] = 'r.leave_date <= ?';
        $types .= 's';
        $params[] = $endDate;
    }
    $sql = "
        SELECT r.*, u.full_name, u.role, reviewer.full_name AS reviewed_by_name
        FROM hr_leave_requests r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
    ";
    if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY CASE WHEN r.status = \'Pending\' THEN 0 ELSE 1 END, r.leave_date ASC, r.id DESC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function hrGetLeaveRequestForDate(int $userId, string $leaveDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $leaveDate)) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_leave_requests WHERE user_id = ? AND leave_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $leaveDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function hrValidateLeaveRequestDate(int $userId, string $leaveDate): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leaveDate)) return 'Invalid leave date.';
    $joinDate = hrGetUserJoinDate($userId);
    if ($joinDate !== null && $leaveDate < $joinDate) return 'Leave date is before the employee join date.';
    if (hrHolidayNameForDate($leaveDate, 'US') !== '') return 'Leave cannot be requested on a holiday.';
    if (hrIsWeekend($leaveDate)) return 'Leave cannot be requested on a weekly off.';
    return null;
}

function hrCanRequestPaidLeave(int $userId, string $leaveDate, int $ignorePendingRequests = 0): array {
    $validationError = hrValidateLeaveRequestDate($userId, $leaveDate);
    if ($validationError !== null) return ['ok' => false, 'error' => $validationError];
    $period = getPaidLeaveKittyForUser($userId, $leaveDate);
    $leaveStart = (string)($period['period_start'] ?? '');
    if ($leaveStart !== '' && $leaveDate < $leaveStart) {
        return ['ok' => false, 'error' => 'Paid leave is not available before the eligible start date.'];
    }
    $available = (int)($period['available'] ?? 0) + max(0, $ignorePendingRequests);
    if ($available <= 0) {
        return ['ok' => false, 'error' => 'No paid leave balance is available for the selected date.'];
    }
    return ['ok' => true];
}

function hrApplyLeaveStatusToAttendance(int $userId, string $workDate, string $leaveType): array {
    $userId = (int)$userId;
    $leaveType = trim($leaveType);
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return ['ok' => false, 'error' => 'Invalid leave request.'];
    if (!in_array($leaveType, ['Paid Leave', 'Unpaid Leave'], true)) return ['ok' => false, 'error' => 'Invalid leave type.'];
    $day = ensureAttendanceDay($userId, $workDate);
    if (!$day) return ['ok' => false, 'error' => 'Attendance day could not be created.'];
    $dayId = (int)($day['id'] ?? 0);
    if ($dayId <= 0) return ['ok' => false, 'error' => 'Attendance day could not be loaded.'];
    $hasWork = trim((string)($day['punch_in'] ?? '')) !== ''
        || trim((string)($day['punch_out'] ?? '')) !== ''
        || (int)($day['working_minutes'] ?? 0) > 0
        || (int)($day['break_minutes'] ?? 0) > 0;
    if ($hasWork) {
        return ['ok' => false, 'error' => 'Leave cannot be approved for a day that already contains attendance activity.'];
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        UPDATE hr_attendance_days
        SET punch_in = NULL, punch_out = NULL, current_state = 'Off', break_minutes = 0, working_minutes = 0, late_minutes = 0, status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error while applying leave.'];
    $stmt->bind_param('si', $leaveType, $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    return ['ok' => $ok, 'error' => $ok ? null : 'Failed to apply leave to attendance.'];
}

function hrClearLeaveAttendanceStatus(int $userId, string $workDate): bool {
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return false;
    $day = getAttendanceDay($userId, $workDate);
    if (!$day) return true;
    $status = trim((string)($day['status'] ?? ''));
    if (!in_array($status, ['Paid Leave', 'Unpaid Leave'], true)) return true;
    $hasWork = trim((string)($day['punch_in'] ?? '')) !== ''
        || trim((string)($day['punch_out'] ?? '')) !== ''
        || (int)($day['working_minutes'] ?? 0) > 0
        || (int)($day['break_minutes'] ?? 0) > 0;
    if ($hasWork) return false;
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE hr_attendance_days SET status = 'Absent', updated_at = NOW() WHERE id = ?");
    if (!$stmt) return false;
    $dayId = (int)($day['id'] ?? 0);
    $stmt->bind_param('i', $dayId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function hrCreateLeaveRequest(int $userId, string $leaveDate, string $leaveType, string $reason, int $requestedBy): array {
    $userId = (int)$userId;
    $requestedBy = (int)$requestedBy;
    $leaveType = trim($leaveType);
    $reason = trim($reason);
    if ($userId <= 0 || $requestedBy <= 0) return ['ok' => false, 'error' => 'Invalid leave request.'];
    if (!in_array($leaveType, ['Paid Leave', 'Unpaid Leave'], true)) return ['ok' => false, 'error' => 'Invalid leave type.'];
    $validationError = hrValidateLeaveRequestDate($userId, $leaveDate);
    if ($validationError !== null) return ['ok' => false, 'error' => $validationError];
    if ($leaveType === 'Paid Leave') {
        $check = hrCanRequestPaidLeave($userId, $leaveDate);
        if (empty($check['ok'])) return $check;
    }
    $existing = hrGetLeaveRequestForDate($userId, $leaveDate);
    if ($existing && !in_array((string)($existing['status'] ?? ''), ['Rejected', 'Cancelled'], true)) {
        return ['ok' => false, 'error' => 'A leave request already exists for this date.'];
    }
    $day = getAttendanceDay($userId, $leaveDate);
    if ($day) {
        $hasWork = trim((string)($day['punch_in'] ?? '')) !== ''
            || trim((string)($day['punch_out'] ?? '')) !== ''
            || (int)($day['working_minutes'] ?? 0) > 0;
        if ($hasWork) return ['ok' => false, 'error' => 'Leave cannot be requested for a date with attendance activity.'];
    }
    ensureDatabaseSchema();
    $conn = getDbConnection();
    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE hr_leave_requests
            SET leave_type = ?, status = 'Pending', reason = ?, requested_by = ?, requested_at = NOW(), reviewed_by = NULL, reviewed_at = NULL, review_notes = NULL, updated_at = NOW()
            WHERE id = ?
        ");
        if (!$stmt) return ['ok' => false, 'error' => 'Database error while updating leave request.'];
        $id = (int)($existing['id'] ?? 0);
        $stmt->bind_param('ssii', $leaveType, $reason, $requestedBy, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return ['ok' => $ok, 'error' => $ok ? null : 'Failed to update leave request.'];
    }
    $stmt = $conn->prepare("
        INSERT INTO hr_leave_requests (user_id, leave_date, leave_type, status, reason, requested_by, requested_at, created_at, updated_at)
        VALUES (?, ?, ?, 'Pending', ?, ?, NOW(), NOW(), NOW())
    ");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error while creating leave request.'];
    $stmt->bind_param('isssi', $userId, $leaveDate, $leaveType, $reason, $requestedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return ['ok' => $ok, 'error' => $ok ? null : 'Failed to create leave request.'];
}

function hrReviewLeaveRequest(int $requestId, string $decision, int $actorUserId, string $notes = ''): array {
    $requestId = (int)$requestId;
    $actorUserId = (int)$actorUserId;
    $decision = strtolower(trim($decision));
    $notes = trim($notes);
    if ($requestId <= 0 || $actorUserId <= 0) return ['ok' => false, 'error' => 'Invalid leave review request.'];
    if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'Invalid leave review decision.'];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_leave_requests WHERE id = ? LIMIT 1");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error while loading leave request.'];
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return ['ok' => false, 'error' => 'Leave request not found.'];
    if ((string)($row['status'] ?? '') !== 'Pending') return ['ok' => false, 'error' => 'Only pending leave requests can be reviewed.'];
    $leaveType = (string)($row['leave_type'] ?? 'Paid Leave');
    $leaveDate = (string)($row['leave_date'] ?? '');
    $userId = (int)($row['user_id'] ?? 0);
    if ($decision === 'approve') {
        if ($leaveType === 'Paid Leave') {
            $check = hrCanRequestPaidLeave($userId, $leaveDate, 1);
            if (empty($check['ok'])) return $check;
        }
        $applied = hrApplyLeaveStatusToAttendance($userId, $leaveDate, $leaveType);
        if (empty($applied['ok'])) return $applied;
    }
    $newStatus = $decision === 'approve' ? 'Approved' : 'Rejected';
    $stmt = $conn->prepare("UPDATE hr_leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error while saving leave review.'];
    $stmt->bind_param('sisi', $newStatus, $actorUserId, $notes, $requestId);
    $ok = $stmt->execute();
    $stmt->close();
    return ['ok' => $ok, 'error' => $ok ? null : 'Failed to save leave review.'];
}

function hrCancelLeaveRequest(int $requestId, int $actorUserId, bool $isAdmin = false): array {
    $requestId = (int)$requestId;
    $actorUserId = (int)$actorUserId;
    if ($requestId <= 0 || $actorUserId <= 0) return ['ok' => false, 'error' => 'Invalid leave cancellation request.'];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_leave_requests WHERE id = ? LIMIT 1");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error while loading leave request.'];
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return ['ok' => false, 'error' => 'Leave request not found.'];
    $requestedBy = (int)($row['requested_by'] ?? 0);
    if (!$isAdmin && $requestedBy !== $actorUserId) return ['ok' => false, 'error' => 'You are not allowed to cancel this leave request.'];
    if ((string)($row['status'] ?? '') !== 'Pending') return ['ok' => false, 'error' => 'Only pending leave requests can be cancelled.'];
    $stmt = $conn->prepare("UPDATE hr_leave_requests SET status = 'Cancelled', reviewed_by = ?, reviewed_at = NOW(), review_notes = 'Cancelled by requester', updated_at = NOW() WHERE id = ?");
    if (!$stmt) return ['ok' => false, 'error' => 'Database error while cancelling leave request.'];
    $stmt->bind_param('ii', $actorUserId, $requestId);
    $ok = $stmt->execute();
    $stmt->close();
    return ['ok' => $ok, 'error' => $ok ? null : 'Failed to cancel leave request.'];
}

function hrSyncLeaveRequestForAttendanceStatus(int $userId, string $workDate, string $status, int $actorUserId, ?string $remarks = null): void {
    $userId = (int)$userId;
    $actorUserId = (int)$actorUserId;
    $status = trim($status);
    if ($userId <= 0 || $actorUserId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) return;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $existing = hrGetLeaveRequestForDate($userId, $workDate);
    if (in_array($status, ['Paid Leave', 'Unpaid Leave'], true)) {
        $reason = $remarks !== null && trim($remarks) !== '' ? trim($remarks) : 'Created from attendance workflow';
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE hr_leave_requests
                SET leave_type = ?, status = 'Approved', reason = COALESCE(NULLIF(reason, ''), ?), requested_by = COALESCE(NULLIF(requested_by, 0), ?), requested_at = COALESCE(requested_at, NOW()), reviewed_by = ?, reviewed_at = NOW(), review_notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            if ($stmt) {
                $id = (int)($existing['id'] ?? 0);
                $stmt->bind_param('ssiisi', $status, $reason, $actorUserId, $actorUserId, $reason, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO hr_leave_requests (user_id, leave_date, leave_type, status, reason, requested_by, requested_at, reviewed_by, reviewed_at, review_notes, created_at, updated_at)
                VALUES (?, ?, ?, 'Approved', ?, ?, NOW(), ?, NOW(), ?, NOW(), NOW())
            ");
            if ($stmt) {
                $stmt->bind_param('isssiis', $userId, $workDate, $status, $reason, $actorUserId, $actorUserId, $reason);
                $stmt->execute();
                $stmt->close();
            }
        }
        return;
    }
    if (!$existing) return;
    if (!in_array((string)($existing['status'] ?? ''), ['Pending', 'Approved'], true)) return;
    $notes = $remarks !== null && trim($remarks) !== '' ? trim($remarks) : 'Cancelled because attendance status changed.';
    $stmt = $conn->prepare("UPDATE hr_leave_requests SET status = 'Cancelled', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?, updated_at = NOW() WHERE id = ?");
    if ($stmt) {
        $id = (int)($existing['id'] ?? 0);
        $stmt->bind_param('isi', $actorUserId, $notes, $id);
        $stmt->execute();
        $stmt->close();
    }
    hrClearLeaveAttendanceStatus($userId, $workDate);
}

function hrRepairOvernightOrphanPunchOutForUserRange(int $userId, string $startDate, string $endDate): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $pairs = [];
    $stmt = $conn->prepare("
        SELECT d1.id AS d1_id, d2.id AS d2_id, d1.work_date AS d1_date
        FROM hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = DATE_ADD(d1.work_date, INTERVAL 1 DAY)
        WHERE d1.user_id = ?
          AND d1.work_date >= ?
          AND d1.work_date <= ?
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $startDate, $endDate);
        $stmt->execute();
        $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    if (empty($pairs)) return;

    $stmt2 = $conn->prepare("
        UPDATE hr_attendance_days d1
        JOIN hr_attendance_days d2
          ON d2.user_id = d1.user_id
         AND d2.work_date = DATE_ADD(d1.work_date, INTERVAL 1 DAY)
        SET
            d1.punch_out = d2.punch_out,
            d1.current_state = 'Off',
            d2.punch_out = NULL,
            d2.current_state = 'Off',
            d2.break_minutes = 0,
            d2.working_minutes = 0,
            d2.late_minutes = 0,
            d2.status = 'Absent',
            d2.updated_at = NOW()
        WHERE d1.user_id = ?
          AND d1.work_date >= ?
          AND d1.work_date <= ?
          AND d1.punch_in IS NOT NULL AND d1.punch_in <> ''
          AND (d1.punch_out IS NULL OR d1.punch_out = '')
          AND d2.punch_in IS NULL
          AND d2.punch_out IS NOT NULL AND d2.punch_out <> ''
          AND d2.punch_out > d1.punch_in
          AND d2.punch_out <= DATE_ADD(d1.punch_in, INTERVAL 20 HOUR)
    ");
    if ($stmt2) {
        $stmt2->bind_param('iss', $userId, $startDate, $endDate);
        $stmt2->execute();
        $stmt2->close();
    }

    $seen = [];
    foreach ($pairs as $p) {
        $d1 = (int)($p['d1_id'] ?? 0);
        $d2 = (int)($p['d2_id'] ?? 0);
        if ($d1 > 0 && !isset($seen[$d1])) { $seen[$d1] = true; recomputeAttendanceDay($d1); }
        if ($d2 > 0 && !isset($seen[$d2])) { $seen[$d2] = true; recomputeAttendanceDay($d2); }
    }
}

function getAttendanceMonthlyReport(int $year, int $month): array {
    $year = (int)$year;
    $month = (int)$month;
    if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) return [];
    $users = getInternalPayrollUsers();
    $rows = [];
    foreach ($users as $u) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) continue;
        $sum = getAttendanceMonthSummary($uid, $year, $month);
        $workingDays = (int)($sum['working_days'] ?? 0);
        $present = (int)($sum['present_days'] ?? 0);
        $half = (int)($sum['half_days'] ?? 0);
        $absent = (int)($sum['absent_days'] ?? 0);
        $ncns = (int)($sum['ncns_days'] ?? 0);
        $paidLeave = (int)($sum['paid_leave_days'] ?? 0);
        $unpaidLeave = (int)($sum['unpaid_leave_days'] ?? 0);
        $lateDays = (int)($sum['late_days'] ?? 0);
        $lateMinutes = (int)($sum['late_minutes'] ?? 0);
        $paidDays = $present + $paidLeave + ($half * 0.5);
        $pct = $workingDays > 0 ? round((($paidDays / $workingDays) * 100.0), 1) : 0.0;
        $rows[] = [
            'user_id' => $uid,
            'name' => (string)($u['full_name'] ?? ''),
            'job_title' => (string)($u['job_title'] ?? ''),
            'department' => (string)($u['department'] ?? ''),
            'working_days' => $workingDays,
            'present_days' => $present,
            'half_days' => $half,
            'absent_days' => $absent,
            'ncns_days' => $ncns,
            'paid_leave_days' => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'late_days' => $lateDays,
            'late_minutes' => $lateMinutes,
            'paid_days' => $paidDays,
            'attendance_percent' => $pct,
        ];
    }
    return $rows;
}

function getSalarySettingForMonth(int $userId, int $year, int $month): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    ensureDatabaseSchema();
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $start = sprintf('%04d-%02d-01', $year, $month);
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_salary_settings WHERE user_id = ? AND effective_date <= ? ORDER BY effective_date DESC, id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) return $row;
    $stmt2 = $conn->prepare("SELECT * FROM hr_salary_settings WHERE user_id = ? AND effective_date >= ? ORDER BY effective_date ASC, id ASC LIMIT 1");
    if (!$stmt2) return null;
    $stmt2->bind_param('is', $userId, $start);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_assoc() ?: null;
    $stmt2->close();
    if ($row2) return $row2;
    return $row;
}

function normalizeSalaryStructureType(?string $t): string {
    $t = trim((string)$t);
    $map = [
        'Standard (Balanced)' => 'Standard',
        'Standard' => 'Standard',
        'High Take-Home' => 'High Take-Home',
        'High Take Home' => 'High Take-Home',
        'Compliance Heavy' => 'Compliance Heavy',
        'Compliance-Heavy' => 'Compliance Heavy',
    ];
    return $map[$t] ?? 'Standard';
}

function computeMonthlyTdsEstimate(float $monthlySalary, float $pfMonthly, float $ptMonthly): float {
    $monthlySalary = max(0.0, $monthlySalary);
    $annual = $monthlySalary * 12.0;
    $stdDed = 50000.0;
    $taxable = max(0.0, $annual - $stdDed - ($pfMonthly * 12.0) - ($ptMonthly * 12.0));
    $tax = 0.0;
    $slabs = [
        [0.0, 300000.0, 0.0],
        [300000.0, 600000.0, 0.05],
        [600000.0, 900000.0, 0.10],
        [900000.0, 1200000.0, 0.15],
        [1200000.0, 1500000.0, 0.20],
        [1500000.0, 1e18, 0.30],
    ];
    foreach ($slabs as [$from, $to, $rate]) {
        if ($taxable <= $from) continue;
        $amt = min($taxable, $to) - $from;
        if ($amt > 0) $tax += $amt * $rate;
    }
    $tax *= 1.04;
    return round($tax / 12.0, 2);
}

function computeSalaryStructure(float $monthlySalary, string $type): array {
    $monthlySalary = round(max(0.0, $monthlySalary), 2);
    $type = normalizeSalaryStructureType($type);
    $convey = 1600.0;
    $medical = 1250.0;

    if ($type === 'High Take-Home') {
        $basic = round($monthlySalary * 0.36, 2);
        $hra = round($basic * 0.40, 2);
    } elseif ($type === 'Compliance Heavy') {
        $basic = round($monthlySalary * 0.50, 2);
        $hra = round($basic * 0.50, 2);
    } else {
        $basic = round($monthlySalary * 0.44, 2);
        $hra = round($basic * 0.50, 2);
    }

    $special = round($monthlySalary - ($basic + $hra + $convey + $medical), 2);
    if ($special < 0) {
        $special = 0.0;
        $convey = 0.0;
        $medical = 0.0;
        $hra = round(min($hra, max(0.0, $monthlySalary - $basic)), 2);
    }

    $other = 0.0;
    $pf = round($basic * 0.12, 2);
    $pt = 200.0;
    $tds = computeMonthlyTdsEstimate($monthlySalary, $pf, $pt);

    return [
        'structure_type' => $type,
        'total_salary' => $monthlySalary,
        'basic' => $basic,
        'hra' => $hra,
        'conveyance' => $convey,
        'medical' => $medical,
        'special_allowance' => $special,
        'other_allowance' => $other,
        'pf' => $pf,
        'professional_tax' => $pt,
        'tds' => $tds,
    ];
}

function getSalaryStructureForMonth(int $userId, int $year, int $month): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    ensureDatabaseSchema();
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $start = sprintf('%04d-%02d-01', $year, $month);
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_salary_structures WHERE user_id = ? AND effective_date <= ? ORDER BY effective_date DESC, id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getBonusesForMonth(int $userId, int $year, int $month): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_bonuses WHERE user_id = ? AND year = ? AND month = ? ORDER BY id DESC");
    if (!$stmt) return [];
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getActiveLoans(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_loans WHERE user_id = ? AND active = 1 ORDER BY start_date ASC, id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLoanDeductionForMonth(int $loanId, int $year, int $month): ?array {
    $loanId = (int)$loanId;
    if ($loanId <= 0) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_loan_deductions WHERE loan_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $loanId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function finalizeLoanDeductionsForMonth(int $userId, int $year, int $month): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    ensureDatabaseSchema();
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();

    foreach (getActiveLoans($userId) as $loan) {
        $loanId = (int)($loan['id'] ?? 0);
        if ($loanId <= 0) continue;
        $startDate = (string)($loan['start_date'] ?? '');
        if ($startDate !== '' && $startDate > $end) continue;
        $existing = getLoanDeductionForMonth($loanId, $year, $month);
        if ($existing) continue;
        $rem = (float)($loan['remaining_amount'] ?? 0);
        $emi = (float)($loan['emi_amount'] ?? 0);
        if ($rem <= 0 || $emi <= 0) continue;
        $amt = min($emi, $rem);
        $stmt = $conn->prepare("INSERT INTO hr_loan_deductions (loan_id, user_id, year, month, amount) VALUES (?,?,?,?,?)");
        if (!$stmt) continue;
        $stmt->bind_param('iiiid', $loanId, $userId, $year, $month, $amt);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) continue;
        $stmt2 = $conn->prepare("UPDATE hr_loans SET remaining_amount = GREATEST(0, remaining_amount - ?) WHERE id = ?");
        if ($stmt2) {
            $stmt2->bind_param('di', $amt, $loanId);
            $stmt2->execute();
            $stmt2->close();
        }
        $stmt3 = $conn->prepare("UPDATE hr_loans SET active = 0 WHERE id = ? AND remaining_amount <= 0");
        if ($stmt3) {
            $stmt3->bind_param('i', $loanId);
            $stmt3->execute();
            $stmt3->close();
        }
    }
}

function hrIsPayrollLocked(int $year, int $month): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT 1 FROM hr_payroll_month_locks WHERE year = ? AND month = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $ok = (bool)($stmt->get_result()->fetch_row());
    $stmt->close();
    return $ok;
}

function hrLockPayrollMonth(int $year, int $month, int $lockedBy): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO hr_payroll_month_locks (year, month, locked_by, locked_at) VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), locked_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iii', $year, $month, $lockedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function hrUnlockPayrollMonth(int $year, int $month): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM hr_payroll_month_locks WHERE year = ? AND month = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function computePayrollForUserMonth(int $userId, int $year, int $month): ?array {
    $structure = getSalaryStructureForMonth($userId, $year, $month);
    $salaryLegacy = $structure ? null : getSalarySettingForMonth($userId, $year, $month);
    if (!$structure && !$salaryLegacy) return null;
    $attendance = getAttendanceMonthSummary($userId, $year, $month);
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $joinDate = hrGetUserJoinDate($userId);
    $fullMonthWorkingDays = hrWorkingDaysInRange($monthStart, $monthEnd, 'US');
    $isNewJoinerMonth = $joinDate !== null && substr($joinDate, 0, 7) === sprintf('%04d-%02d', $year, $month);
    $workingDays = (int)($attendance['working_days'] ?? getWorkingDays($year, $month));
    $present = (int)($attendance['present_days'] ?? 0);
    $half = (int)($attendance['half_days'] ?? 0);
    $paidLeave = (int)($attendance['paid_leave_days'] ?? 0);
    $unpaidLeave = (int)($attendance['unpaid_leave_days'] ?? 0);
    $ncnsDays = (int)($attendance['ncns_days'] ?? 0);
    $paidDays = $present + $paidLeave + ($half * 0.5);
    $salaryWorkingDays = $workingDays > 0 ? $workingDays : $fullMonthWorkingDays;
    $salaryPayableDays = $paidDays;
    $salaryProrationRule = 'standard_paid_days';
    if ($isNewJoinerMonth) {
        $salaryWorkingDays = $fullMonthWorkingDays;
        $salaryPayableDays = $present + ($half * 0.5);
        $salaryProrationRule = 'new_joiner_present_days';
    }
    $attendanceFactor = $salaryWorkingDays > 0 ? ($salaryPayableDays / $salaryWorkingDays) : 0;

    if ($structure) {
        $basic = (float)($structure['basic'] ?? 0);
        $hra = (float)($structure['hra'] ?? 0);
        $convey = (float)($structure['conveyance'] ?? 0);
        $medical = (float)($structure['medical'] ?? 0);
        $special = (float)($structure['special_allowance'] ?? 0);
        $otherAllow = (float)($structure['other_allowance'] ?? 0);
        $monthlySalary = (float)($structure['total_salary'] ?? ($basic + $hra + $convey + $medical + $special + $otherAllow));
        $pfMonthly = (float)($structure['pf'] ?? round($basic * 0.12, 2));
        $ptMonthly = (float)($structure['professional_tax'] ?? 200);
        $tdsMonthly = (float)($structure['tds'] ?? 0);
        $structureType = (string)($structure['structure_type'] ?? 'Standard');
        $locked = (int)($structure['locked'] ?? 0) === 1;
    } else {
        $basic = (float)($salaryLegacy['basic_salary'] ?? 0);
        $hra = 0.0;
        $convey = 0.0;
        $medical = 0.0;
        $special = 0.0;
        $otherAllow = (float)($salaryLegacy['allowances'] ?? 0);
        $monthlySalary = $basic + $otherAllow;
        $pfMonthly = (float)($salaryLegacy['pf'] ?? 0);
        $ptMonthly = 0.0;
        $tdsMonthly = (float)($salaryLegacy['tax'] ?? 0);
        $structureType = 'Legacy';
        $locked = false;
    }

    $salaryProrated = $monthlySalary * $attendanceFactor;
    $basicPr = $basic * $attendanceFactor;
    $hraPr = $hra * $attendanceFactor;
    $conveyPr = $convey * $attendanceFactor;
    $medicalPr = $medical * $attendanceFactor;
    $specialPr = $special * $attendanceFactor;
    $otherAllowPr = $otherAllow * $attendanceFactor;

    $prod = getAgentMonthlyStats($userId, $year, $month);
    $incentives = (float)($prod['incentives']['total'] ?? 0);
    
    // Try to get FunnelTrack360 deduction details if available
    $ftSummary = funneltrackComputeAgentMonthSummary($userId, $year, $month);
    $incentiveDeductions = [];
    if (!empty($ftSummary['incentives']['deductions']) && is_array($ftSummary['incentives']['deductions'])) {
        $incentiveDeductions = $ftSummary['incentives']['deductions'];
    }

    $bonusesRows = getBonusesForMonth($userId, $year, $month);
    $bonus = 0.0;
    foreach ($bonusesRows as $b) $bonus += (float)($b['amount'] ?? 0);

    $loanDed = 0.0;
    $end = $monthEnd;
    foreach (getActiveLoans($userId) as $loan) {
        $startDate = (string)($loan['start_date'] ?? '');
        if ($startDate !== '' && $startDate > $end) continue;
        $rem = (float)($loan['remaining_amount'] ?? 0);
        $emi = (float)($loan['emi_amount'] ?? 0);
        if ($rem <= 0 || $emi <= 0) continue;
        $loanId = (int)($loan['id'] ?? 0);
        $existing = $loanId > 0 ? getLoanDeductionForMonth($loanId, $year, $month) : null;
        if ($existing) {
            $loanDed += (float)($existing['amount'] ?? 0);
        } else {
            $loanDed += min($emi, $rem);
        }
    }

    $dailySalaryRate = $salaryWorkingDays > 0 ? $monthlySalary / $salaryWorkingDays : 0;
    $pfPr = $pfMonthly * $attendanceFactor;
    $ptPr = $paidDays > 0 ? $ptMonthly : 0.0;
    $tdsPr = $tdsMonthly * $attendanceFactor;
    $otherDed = 0.0;
    $ncnsPenaltyDays = $ncnsDays * 2;
    $ncnsPenaltyAmount = $ncnsPenaltyDays * $dailySalaryRate;
    
    $gross = $salaryProrated + $incentives + $bonus;
    $deductions = $pfPr + $ptPr + $tdsPr + $loanDed + $otherDed + $ncnsPenaltyAmount;
    $net = $gross - $deductions;

    return [
        'attendance' => [
            'working_days' => $workingDays,
            'full_month_working_days' => $fullMonthWorkingDays,
            'salary_working_days' => $salaryWorkingDays,
            'present_days' => $present,
            'half_days' => $half,
            'absent_days' => (int)($attendance['absent_days'] ?? 0),
            'ncns_days' => $ncnsDays,
            'paid_leave_days' => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'paid_days' => $paidDays,
            'salary_payable_days' => $salaryPayableDays,
            'attendance_factor' => round($attendanceFactor, 4),
            'salary_proration_rule' => $salaryProrationRule,
            'is_new_joiner_month' => $isNewJoinerMonth,
            'join_date' => $joinDate,
        ],
        'salary_structure' => [
            'type' => $structureType,
            'locked' => $locked,
            'monthly_salary' => round($monthlySalary, 2),
            'components' => [
                'basic' => round($basic, 2),
                'hra' => round($hra, 2),
                'conveyance' => round($convey, 2),
                'medical' => round($medical, 2),
                'special_allowance' => round($special, 2),
                'other_allowance' => round($otherAllow, 2),
            ],
            'deductions' => [
                'pf' => round($pfMonthly, 2),
                'professional_tax' => round($ptMonthly, 2),
                'tds' => round($tdsMonthly, 2),
            ],
        ],
        'earnings' => [
            'monthly_salary' => round($monthlySalary, 2),
            'daily_salary_rate' => round($dailySalaryRate, 2),
            'salary_prorated' => round($salaryProrated, 2),
            'basic' => round($basicPr, 2),
            'hra' => round($hraPr, 2),
            'conveyance' => round($conveyPr, 2),
            'medical' => round($medicalPr, 2),
            'special_allowance' => round($specialPr, 2),
            'other_allowance' => round($otherAllowPr, 2),
            'incentives' => round($incentives, 2),
            'incentives_before_deductions' => !empty($ftSummary['incentives']['monthly_before_deductions']) ? round((float)$ftSummary['incentives']['monthly_before_deductions'], 2) : round($incentives, 2),
            'incentive_deductions' => $incentiveDeductions,
            'bonus' => round($bonus, 2),
            'gross' => round($gross, 2),
        ],
        'deductions' => [
            'pf' => round($pfPr, 2),
            'professional_tax' => round($ptPr, 2),
            'tds' => round($tdsPr, 2),
            'other' => round($otherDed, 2),
            'loan_emi' => round($loanDed, 2),
            'ncns_penalty_days' => $ncnsPenaltyDays,
            'ncns_penalty_amount' => round($ncnsPenaltyAmount, 2),
            'total' => round($deductions, 2),
        ],
        'net_salary' => round($net, 2),
    ];
}

function getPayrollSummaryRows(int $year, int $month): array {
    $year = (int)$year;
    $month = (int)$month;
    if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) return [];
    ensureDatabaseSchema();
    $users = getInternalPayrollUsers();
    $rows = [];
    foreach ($users as $u) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) continue;
        $p = getPayslip($uid, $year, $month);
        if (!$p) continue;
        $data = json_decode((string)($p['salary_data'] ?? ''), true);
        if (!is_array($data)) continue;
        $uu = is_array($data['user'] ?? null) ? $data['user'] : [];
        $att = is_array($data['attendance'] ?? null) ? $data['attendance'] : [];
        $earn = is_array($data['earnings'] ?? null) ? $data['earnings'] : [];
        $ded = is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
        $allowances = (float)($earn['allowances'] ?? 0);
        if ($allowances === 0.0) {
            $allowances = (float)($earn['hra'] ?? 0) + (float)($earn['conveyance'] ?? 0) + (float)($earn['medical'] ?? 0) + (float)($earn['special_allowance'] ?? 0) + (float)($earn['other_allowance'] ?? 0);
        }
        $basePr = (float)($earn['base_prorated'] ?? 0);
        if ($basePr === 0.0) $basePr = (float)($earn['salary_prorated'] ?? 0);
        $rows[] = [
            'user_id' => (int)($uu['id'] ?? $uid),
            'name' => (string)($uu['name'] ?? ($u['full_name'] ?? '')),
            'role' => (string)($uu['role'] ?? ($u['role'] ?? '')),
            'department' => (string)($uu['department'] ?? ($u['department'] ?? '')),
            'working_days' => (float)($att['working_days'] ?? 0),
            'full_month_working_days' => (float)($att['full_month_working_days'] ?? ($att['working_days'] ?? 0)),
            'salary_working_days' => (float)($att['salary_working_days'] ?? ($att['working_days'] ?? 0)),
            'present_days' => (float)($att['present_days'] ?? 0),
            'half_days' => (float)($att['half_days'] ?? 0),
            'absent_days' => (float)($att['absent_days'] ?? 0),
            'ncns_days' => (float)($att['ncns_days'] ?? 0),
            'paid_leave_days' => (float)($att['paid_leave_days'] ?? 0),
            'unpaid_leave_days' => (float)($att['unpaid_leave_days'] ?? 0),
            'paid_days' => (float)($att['paid_days'] ?? 0),
            'salary_payable_days' => (float)($att['salary_payable_days'] ?? ($att['paid_days'] ?? 0)),
            'salary_proration_rule' => (string)($att['salary_proration_rule'] ?? 'standard_paid_days'),
            'basic' => (float)($earn['basic'] ?? 0),
            'allowances' => $allowances,
            'daily_salary_rate' => (float)($earn['daily_salary_rate'] ?? 0),
            'base_prorated' => $basePr,
            'incentives' => (float)($earn['incentives'] ?? 0),
            'bonus' => (float)($earn['bonus'] ?? 0),
            'gross' => (float)($earn['gross'] ?? 0),
            'pf' => (float)($ded['pf'] ?? 0),
            'professional_tax' => (float)($ded['professional_tax'] ?? 0),
            'tds' => (float)($ded['tds'] ?? ($ded['tax'] ?? 0)),
            'loan_emi' => (float)($ded['loan_emi'] ?? 0),
            'other' => (float)($ded['other'] ?? 0),
            'ncns_penalty_days' => (float)($ded['ncns_penalty_days'] ?? 0),
            'ncns_penalty_amount' => (float)($ded['ncns_penalty_amount'] ?? 0),
            'deductions_total' => (float)($ded['total'] ?? 0),
            'net_salary' => (float)($data['net_salary'] ?? 0),
        ];
    }
    return $rows;
}

function upsertPayslip(int $userId, int $year, int $month, array $data, int $actorUserId): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("
        INSERT INTO hr_payslips (user_id, year, month, salary_data, generated_at, generated_by)
        VALUES (?,?,?,?,NOW(),?)
        ON DUPLICATE KEY UPDATE salary_data = VALUES(salary_data), generated_at = NOW(), generated_by = VALUES(generated_by)
    ");
    if (!$stmt) return false;
    $stmt->bind_param('iiisi', $userId, $year, $month, $json, $actorUserId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getPayslip(int $userId, int $year, int $month): ?array {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_payslips WHERE user_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function deletePayslip(int $userId, int $year, int $month): bool {
    $userId = (int)$userId;
    $year = (int)$year;
    $month = (int)$month;
    if ($userId <= 0 || $year <= 0 || $month < 1 || $month > 12) return false;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM hr_payslips WHERE user_id = ? AND year = ? AND month = ?");
    if (!$stmt) return false;
    $stmt->bind_param('iii', $userId, $year, $month);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function deletePayslipsForMonth(int $year, int $month): bool {
    $year = (int)$year;
    $month = (int)$month;
    if ($year <= 0 || $month < 1 || $month > 12) return false;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM hr_payslips WHERE year = ? AND month = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Upsert monthly productivity target for an agent
 */
function setAgentMonthlyTarget(int $agentId, int $year, int $month, int $dailyTarget, int $assignedBy): bool {
    if (!productivityTargetsTableExists()) return false;
    $conn = getDbConnection();
    $workingDays = 0;
    try {
        $cfg = funneltrackGetConfig();
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
        $workingDays = count(funneltrackWorkingDatesInRange($startDate, $endDate, $cfg));
    } catch (Throwable $e) {
        $workingDays = 0;
    }
    if ($workingDays <= 0) $workingDays = getWorkingDays($year, $month);
    $monthlyTarget = (int)$dailyTarget * (int)$workingDays;
    $minimumTarget = 0;

    $sql = "INSERT INTO productivity_targets (agent_id, year, month, working_days, daily_target, monthly_target, minimum_target, assigned_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE working_days = VALUES(working_days), daily_target = VALUES(daily_target), monthly_target = VALUES(monthly_target), minimum_target = VALUES(minimum_target), assigned_by = VALUES(assigned_by)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return false; }
    $stmt->bind_param('iiiiiiii', $agentId, $year, $month, $workingDays, $dailyTarget, $monthlyTarget, $minimumTarget, $assignedBy);
    $ok = (bool)$stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Get monthly target for an agent
 */
function getAgentMonthlyTarget(int $agentId, int $year, int $month): ?array {
    if (!productivityTargetsTableExists()) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM productivity_targets WHERE agent_id = ? AND year = ? AND month = ?");
    if (!$stmt) { return null; }
    $stmt->bind_param('iii', $agentId, $year, $month);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();
    return $row;
}

/**
 * Get all targets for a month (joined with agent names)
 */
function getMonthlyTargets(int $year, int $month): array {
    if (!productivityTargetsTableExists()) return [];
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT pt.*,
               u.full_name AS agent_name,
               creator.full_name AS assigned_by_name,
               updater.full_name AS updated_by_name
        FROM productivity_targets pt
        JOIN users u ON pt.agent_id = u.id
        LEFT JOIN users creator ON creator.id = pt.assigned_by
        LEFT JOIN users updater ON updater.id = pt.updated_by
        WHERE pt.year = ? AND pt.month = ?
        ORDER BY u.full_name
    ");
    if (!$stmt) { return []; }
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    }
    $stmt->close();
    return $rows;
}

/**
 * Count qualified leads for an agent on a specific date
 */
function countAgentLeadsByDate(int $agentId, string $date): int {
    $filters = [
        'agent_id' => (int)$agentId,
        'date_from' => $date,
        'date_to' => $date,
        'qa_status' => 'Qualified',
    ];
    return (int)getLeadsCount($filters);
}

/**
 * Count total generated leads (all QA statuses) for an agent on a specific date
 */
function countAgentLeadsByDateAll(int $agentId, string $date): int {
    $filters = [
        'agent_id' => (int)$agentId,
        'date_from' => $date,
        'date_to' => $date,
    ];
    return (int)getLeadsCount($filters);
}

function normalizeProductivityCampaignType(?string $campaignType): string {
    $t = strtolower(trim((string)$campaignType));
    if ($t === 'email marketing') return 'Email Marketing';
    if ($t === 'marketing qualified leads' || $t === 'mql' || $t === 'marketing qualified lead') return 'Marketing Qualified Leads';
    if ($t === 'bant') return 'BANT';
    if ($t === 'appointment generation') return 'Appointment Generation';
    return '';
}

function campaignTypeMqlFactor(string $normalizedCampaignType): float {
    if ($normalizedCampaignType === 'Marketing Qualified Leads') return 1.0;
    if ($normalizedCampaignType === 'BANT') return 2.5;
    if ($normalizedCampaignType === 'Appointment Generation') return 10.0;
    if ($normalizedCampaignType === 'Email Marketing') return (10.0 / 60.0);
    return 0.0;
}

function extraLeadBonusAmount(string $normalizedCampaignType): int {
    if ($normalizedCampaignType === 'Marketing Qualified Leads') return 100;
    if ($normalizedCampaignType === 'BANT') return 250;
    if ($normalizedCampaignType === 'Appointment Generation') return 1000;
    return 0;
}

function productivityLocksTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_locks' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    if (!$exists) {
        ensureDatabaseSchema();
        $rs2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_locks' LIMIT 1");
        $exists = (bool)($rs2 && $rs2->fetch_assoc());
    }
    return $exists;
}

function productivitySnapshotsTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_snapshots' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    if (!$exists) {
        ensureDatabaseSchema();
        $rs2 = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_month_snapshots' LIMIT 1");
        $exists = (bool)($rs2 && $rs2->fetch_assoc());
    }
    return $exists;
}

function productivityTargetsTableExists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productivity_targets' LIMIT 1");
    $exists = (bool)($rs && $rs->fetch_assoc());
    return $exists;
}

function isProductivityMonthLocked(int $year, int $month): bool {
    if (!productivityLocksTableExists()) return false;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT 1 FROM productivity_month_locks WHERE year = ? AND month = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $ok = (bool)($stmt->get_result()->fetch_row());
    $stmt->close();
    return $ok;
}

function getProductivitySnapshot(int $agentId, int $year, int $month): ?array {
    if (!productivitySnapshotsTableExists()) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM productivity_month_snapshots WHERE agent_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $agentId, $year, $month);
    $stmt->execute();
    $snap = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$snap) return null;

    $stmt = $conn->prepare("SELECT * FROM productivity_day_snapshots WHERE agent_id = ? AND year = ? AND month = ? ORDER BY work_date ASC");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $agentId, $year, $month);
    $stmt->execute();
    $days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $daily = [];
    foreach ($days as $d) {
        $counts = json_decode((string)($d['counts_json'] ?? ''), true);
        $extraCounts = json_decode((string)($d['extra_counts_json'] ?? ''), true);
        $daily[] = [
            'date' => (string)($d['work_date'] ?? ''),
            'counts' => is_array($counts) ? $counts : [],
            'total_leads' => (int)($d['total_leads'] ?? 0),
            'achieved_mql' => (float)($d['achieved_mql'] ?? 0),
            'daily_percent' => (float)($d['daily_percent'] ?? 0),
            'met_daily_target' => (int)($d['met_daily_target'] ?? 0) === 1,
            'daily_incentive' => (int)($d['daily_incentive'] ?? 0),
            'base_incentive' => (int)($d['base_incentive'] ?? 0),
            'extra_incentive' => (int)($d['extra_incentive'] ?? 0),
            'extra_counts' => is_array($extraCounts) ? $extraCounts : [],
        ];
    }

    return [
        'target' => [
            'daily_target' => (int)($snap['daily_target_mql'] ?? 0),
            'monthly_target' => (int)($snap['monthly_target_mql'] ?? 0),
            'minimum_target' => null,
            'working_days' => (int)($snap['working_days'] ?? 0),
        ],
        'period' => [
            'year' => (int)$year,
            'month' => (int)$month,
            'start' => (string)($snap['period_start'] ?? ''),
            'end' => (string)($snap['period_end'] ?? ''),
            'working_days' => (int)($snap['working_days'] ?? 0),
            'days_elapsed' => (int)($snap['days_elapsed'] ?? 0),
        ],
        'stats' => [
            'total_leads' => (int)($snap['total_leads'] ?? 0),
            'qualified_total' => (float)($snap['total_mql'] ?? 0),
            'total_calls' => (float)($snap['total_mql'] ?? 0),
            'days_met_daily' => (int)($snap['days_met_daily'] ?? 0),
            'met_monthly' => (int)($snap['met_monthly'] ?? 0) === 1,
            'status' => ((int)($snap['met_monthly'] ?? 0) === 1) ? 'achieved' : 'in_progress',
            'total_mql' => (float)($snap['total_mql'] ?? 0),
            'overall_percent' => (float)($snap['overall_percent'] ?? 0),
            'locked' => true,
        ],
        'daily' => $daily,
        'incentives' => [
            'daily_total' => (int)($snap['daily_incentives'] ?? 0),
            'monthly_bonus' => (int)($snap['monthly_incentive'] ?? 0),
            'total' => (int)($snap['total_incentives'] ?? 0),
        ],
    ];
}

function saveProductivitySnapshot(int $agentId, int $year, int $month, array $stats, int $lockedBy): bool {
    if (!productivitySnapshotsTableExists()) return false;
    $conn = getDbConnection();
    $target = is_array($stats['target'] ?? null) ? $stats['target'] : [];
    $period = is_array($stats['period'] ?? null) ? $stats['period'] : [];
    $s = is_array($stats['stats'] ?? null) ? $stats['stats'] : [];
    $inc = is_array($stats['incentives'] ?? null) ? $stats['incentives'] : [];

    $stmt = $conn->prepare("
        INSERT INTO productivity_month_snapshots (
            agent_id, year, month, daily_target_mql, monthly_target_mql, working_days, days_elapsed,
            period_start, period_end, total_leads, total_mql, overall_percent, days_met_daily, met_monthly,
            daily_incentives, monthly_incentive, total_incentives, locked_by, locked_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            daily_target_mql = VALUES(daily_target_mql),
            monthly_target_mql = VALUES(monthly_target_mql),
            working_days = VALUES(working_days),
            days_elapsed = VALUES(days_elapsed),
            period_start = VALUES(period_start),
            period_end = VALUES(period_end),
            total_leads = VALUES(total_leads),
            total_mql = VALUES(total_mql),
            overall_percent = VALUES(overall_percent),
            days_met_daily = VALUES(days_met_daily),
            met_monthly = VALUES(met_monthly),
            daily_incentives = VALUES(daily_incentives),
            monthly_incentive = VALUES(monthly_incentive),
            total_incentives = VALUES(total_incentives),
            locked_by = VALUES(locked_by),
            locked_at = NOW()
    ");
    if (!$stmt) return false;
    $dailyTarget = (int)($target['daily_target'] ?? 0);
    $monthlyTarget = (int)($target['monthly_target'] ?? 0);
    $workingDays = (int)($period['working_days'] ?? 0);
    $daysElapsed = (int)($period['days_elapsed'] ?? 0);
    $start = (string)($period['start'] ?? '');
    $end = (string)($period['end'] ?? '');
    $totalLeads = (int)($s['total_leads'] ?? 0);
    $totalMql = (float)($s['total_mql'] ?? ($s['qualified_total'] ?? 0));
    $overallPercent = (float)($s['overall_percent'] ?? 0);
    $daysMet = (int)($s['days_met_daily'] ?? 0);
    $metMonthly = !empty($s['met_monthly']) ? 1 : 0;
    $dailyInc = (int)($inc['daily_total'] ?? 0);
    $monthlyInc = (int)($inc['monthly_bonus'] ?? 0);
    $totalInc = (int)($inc['total'] ?? 0);
    $stmt->bind_param(
        'iiiiiiissiddiiiiii',
        $agentId, $year, $month,
        $dailyTarget, $monthlyTarget, $workingDays, $daysElapsed,
        $start, $end,
        $totalLeads, $totalMql, $overallPercent, $daysMet, $metMonthly,
        $dailyInc, $monthlyInc, $totalInc,
        $lockedBy
    );
    $ok = $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM productivity_day_snapshots WHERE agent_id = ? AND year = ? AND month = ?");
    if ($stmt) {
        $stmt->bind_param('iii', $agentId, $year, $month);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("
        INSERT INTO productivity_day_snapshots (
            agent_id, year, month, work_date, counts_json, total_leads, achieved_mql, daily_percent, met_daily_target,
            base_incentive, extra_incentive, extra_counts_json, daily_incentive
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) return $ok;
    foreach (($stats['daily'] ?? []) as $d) {
        $workDate = (string)($d['date'] ?? '');
        if ($workDate === '') continue;
        $countsJson = json_encode($d['counts'] ?? [], JSON_UNESCAPED_UNICODE);
        $extraCountsJson = json_encode($d['extra_counts'] ?? [], JSON_UNESCAPED_UNICODE);
        $totalLeadsDay = (int)($d['total_leads'] ?? 0);
        $achieved = (float)($d['achieved_mql'] ?? 0);
        $pct = (float)($d['daily_percent'] ?? 0);
        $met = !empty($d['met_daily_target']) ? 1 : 0;
        $base = (int)($d['base_incentive'] ?? 0);
        $extra = (int)($d['extra_incentive'] ?? 0);
        $dailyInc2 = (int)($d['daily_incentive'] ?? 0);
        $stmt->bind_param(
            'iiissiddiiisi',
            $agentId, $year, $month, $workDate,
            $countsJson, $totalLeadsDay, $achieved, $pct, $met,
            $base, $extra, $extraCountsJson, $dailyInc2
        );
        $stmt->execute();
    }
    $stmt->close();
    return $ok;
}

function lockProductivityMonth(int $year, int $month, int $lockedBy): bool {
    if (!productivityLocksTableExists() || !productivitySnapshotsTableExists() || !productivityTargetsTableExists()) return false;
    ensureUsFederalHolidaysForYear($year - 1);
    ensureUsFederalHolidaysForYear($year);
    ensureUsFederalHolidaysForYear($year + 1);
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO productivity_month_locks (year, month, locked_by, locked_at) VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), locked_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iii', $year, $month, $lockedBy);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return false;

    $targets = getMonthlyTargets($year, $month);
    foreach ($targets as $t) {
        $aid = (int)($t['agent_id'] ?? 0);
        if ($aid <= 0) continue;
        $stats = computeAgentMonthlyStatsLive($aid, $year, $month);
        saveProductivitySnapshot($aid, $year, $month, $stats, $lockedBy);
    }
    return true;
}

function unlockProductivityMonth(int $year, int $month): bool {
    if (!productivityLocksTableExists()) return true;
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM productivity_month_locks WHERE year = ? AND month = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $year, $month);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function computeAgentMonthlyStatsLive(int $agentId, int $year, int $month): array {
    $target = getAgentMonthlyTarget($agentId, (int)$year, (int)$month);
    $dailyTarget = (int)($target['daily_target'] ?? 0);
    $workingDays = getWorkingDays($year, $month);
    $att = getAttendanceMonthSummary($agentId, (int)$year, (int)$month);
    $effectiveWorkingDays = max(0, (int)($att['present_days'] ?? 0) + (int)($att['half_days'] ?? 0));
    if ($effectiveWorkingDays <= 0) $effectiveWorkingDays = (int)$workingDays;
    if ($effectiveWorkingDays > (int)$workingDays) $effectiveWorkingDays = (int)$workingDays;
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    $monthlyTarget = (int)($dailyTarget * (int)$effectiveWorkingDays);

    if (!$target || $dailyTarget <= 0) {
        return [
            'target' => [],
            'period' => [
                'year' => (int)$year,
                'month' => (int)$month,
                'start' => $startDate,
                'end' => $endDate,
                'working_days' => (int)$workingDays,
                'effective_working_days' => (int)$effectiveWorkingDays,
                'days_elapsed' => 0,
            ],
            'stats' => [
                'total_leads' => 0,
                'qualified_total' => 0,
                'total_calls' => 0,
                'days_met_daily' => 0,
                'met_monthly' => false,
                'status' => 'no_target',
                'total_mql' => 0,
                'overall_percent' => 0,
            ],
            'daily' => [],
            'incentives' => [
                'daily_total' => 0,
                'monthly_bonus' => 0,
                'total' => 0,
            ],
        ];
    }

    $holidaySet = [];
    foreach (getHolidaysForMonth((int)$year, (int)$month, 'US') as $h) {
        $d = (string)($h['holiday_date'] ?? '');
        if ($d !== '') $holidaySet[$d] = true;
    }

    ensureLeadsTrackingColumns();
    $conn = getDbConnection();
    $startTs = $startDate . ' 00:00:00';
    $endTs = $endDate . ' 23:59:59';
    $stmt = $conn->prepare("
        SELECT l.id, COALESCE(l.qa_updated_at, l.created_at) AS delivered_at, d.campaign_type
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.agent_id = ?
          AND l.client_delivery_status = 'Delivered'
          AND COALESCE(l.qa_updated_at, l.created_at) >= ?
          AND COALESCE(l.qa_updated_at, l.created_at) <= ?
        ORDER BY delivered_at ASC, l.id ASC
    ");
    $all = [];
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $startTs, $endTs);
        $stmt->execute();
        $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }

    $byDay = [];
    foreach ($all as $r) {
        $dt = (string)($r['delivered_at'] ?? '');
        if ($dt === '') continue;
        $day = date('Y-m-d', strtotime($dt));
        if (isset($holidaySet[$day])) continue;
        $ts = strtotime($day);
        $weekday = (int)date('N', $ts);
        if ($weekday > 5) continue;
        $ct = normalizeProductivityCampaignType((string)($r['campaign_type'] ?? ''));
        if ($ct === '') continue;
        if (!isset($byDay[$day])) $byDay[$day] = [];
        $byDay[$day][] = $ct;
    }

    $daily = [];
    $daysElapsed = 0;
    $daysMetDaily = 0;
    $totalLeadsMonth = 0;
    $totalMqlMonth = 0.0;
    $dailyIncentives = 0;

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $ts = mktime(0, 0, 0, (int)$month, $day, (int)$year);
        $weekday = (int)date('N', $ts);
        if ($weekday > 5) { continue; }
        $date = date('Y-m-d', $ts);
        if (isset($holidaySet[$date])) { continue; }
        $daysElapsed++;
        $leads = $byDay[$date] ?? [];

        $counts = [
            'Email Marketing' => 0,
            'Marketing Qualified Leads' => 0,
            'BANT' => 0,
            'Appointment Generation' => 0,
        ];
        $achievedMql = 0.0;
        foreach ($leads as $ct) {
            if (isset($counts[$ct])) $counts[$ct]++;
            $achievedMql += campaignTypeMqlFactor($ct);
        }

        $met = $dailyTarget > 0 && $achievedMql >= (float)$dailyTarget;
        if ($met) $daysMetDaily++;

        $extraCounts = [
            'Email Marketing' => 0,
            'Marketing Qualified Leads' => 0,
            'BANT' => 0,
            'Appointment Generation' => 0,
        ];
        $extraBonus = 0;
        if ($met) {
            $cum = 0.0;
            $reached = false;
            foreach ($leads as $ct) {
                $cum += campaignTypeMqlFactor($ct);
                if (!$reached && $cum >= (float)$dailyTarget) {
                    $reached = true;
                    continue;
                }
                if ($reached && isset($extraCounts[$ct])) $extraCounts[$ct]++;
            }
            foreach ($extraCounts as $ct => $c) {
                $extraBonus += (int)$c * extraLeadBonusAmount($ct);
            }
        }

        $baseBonus = $met ? (int)DAILY_INCENTIVE_AMOUNT : 0;
        $dailyIncentive = $baseBonus + $extraBonus;
        $dailyIncentives += $dailyIncentive;

        $dailyPercent = $dailyTarget > 0 ? round((($achievedMql / (float)$dailyTarget) * 100.0), 1) : 0.0;
        $totalLeadsDay = array_sum($counts);
        $totalLeadsMonth += $totalLeadsDay;
        $totalMqlMonth += $achievedMql;

        $daily[] = [
            'date' => $date,
            'counts' => $counts,
            'total_leads' => $totalLeadsDay,
            'achieved_mql' => $achievedMql,
            'daily_percent' => $dailyPercent,
            'met_daily_target' => $met,
            'daily_incentive' => $dailyIncentive,
            'base_incentive' => $baseBonus,
            'extra_incentive' => $extraBonus,
            'extra_counts' => $extraCounts,
        ];
    }

    $overallPercent = ($dailyTarget > 0 && $workingDays > 0) ? round((($totalMqlMonth / ((float)$dailyTarget * (float)$workingDays)) * 100.0), 1) : 0.0;
    if ($dailyTarget > 0 && $effectiveWorkingDays > 0) {
        $overallPercent = round((($totalMqlMonth / ((float)$dailyTarget * (float)$effectiveWorkingDays)) * 100.0), 1);
    }
    $metMonthly = $overallPercent >= 90.0;
    $monthlyBonusTotal = $metMonthly ? (int)MONTHLY_BONUS_AMOUNT : 0;
    $totalIncentives = $dailyIncentives + $monthlyBonusTotal;

    return [
        'target' => [
            'daily_target' => $dailyTarget,
            'monthly_target' => $monthlyTarget,
            'minimum_target' => null,
            'working_days' => (int)$workingDays,
            'effective_working_days' => (int)$effectiveWorkingDays,
        ],
        'period' => [
            'year' => (int)$year,
            'month' => (int)$month,
            'start' => $startDate,
            'end' => $endDate,
            'working_days' => (int)$workingDays,
            'effective_working_days' => (int)$effectiveWorkingDays,
            'days_elapsed' => $daysElapsed,
        ],
        'stats' => [
            'total_leads' => $totalLeadsMonth,
            'qualified_total' => $totalMqlMonth,
            'total_calls' => $totalMqlMonth,
            'days_met_daily' => $daysMetDaily,
            'met_monthly' => $metMonthly,
            'status' => $metMonthly ? 'achieved' : 'in_progress',
            'total_mql' => $totalMqlMonth,
            'overall_percent' => $overallPercent,
        ],
        'daily' => $daily,
        'incentives' => [
            'daily_total' => $dailyIncentives,
            'monthly_bonus' => $monthlyBonusTotal,
            'total' => $totalIncentives,
        ],
    ];
}

/**
 * Compute monthly stats and incentives for an agent
 */
function getAgentMonthlyStats(int $agentId, int $year, int $month): array {
    $y = (int)$year;
    $m = (int)$month;
    if (isProductivityMonthLocked($y, $m)) {
        $snap = getProductivitySnapshot((int)$agentId, $y, $m);
        if ($snap) return $snap;
    }
    return computeAgentMonthlyStatsLive((int)$agentId, $y, $m);
}


/**
 * Ensure CSRF token exists in session for forms
 */
function ensureCsrfToken(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Session is handled by auth.php; this is a safeguard
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function ensureCampaignDetailsColumns(): void {
    $conn = getDbConnection();
    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };

    if (!$hasColumn('campaign_details', 'status_updated_by')) {
        $conn->query("ALTER TABLE campaign_details ADD COLUMN status_updated_by INT NULL AFTER status");
    }
}

function ensureLeadsTrackingColumns(): void {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' LIMIT 1");
    if (!$rs || !$rs->fetch_assoc()) {
        ensureDatabaseSchema();
    }
    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $hasIndex = function(string $table, string $index) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };

    if (!$hasColumn('leads', 'created_by')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN created_by INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add created_by: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'updated_by')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN updated_by INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add updated_by: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status VARCHAR(30) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status_comment')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status_comment TEXT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status_comment: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status_updated_by')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status_updated_by INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status_updated_by: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'email_status_updated_at')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN email_status_updated_at DATETIME NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add email_status_updated_at: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'client_id')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN client_id INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add client_id: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'vendor_id')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN vendor_id INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add vendor_id: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'lead_source')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN lead_source VARCHAR(20) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add lead_source: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'assigned_to_user')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN assigned_to_user INT NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add assigned_to_user: ' . ($conn->error ?? 'unknown'));
        }
    }

    if (!$hasColumn('leads', 'qa_client_comment')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN qa_client_comment TEXT NULL AFTER qa_comment")) {
            error_log('ensureLeadsTrackingColumns: failed to add qa_client_comment: ' . ($conn->error ?? 'unknown'));
        }
    }

    if (!$hasColumn('leads', 'client_delivery_status')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending'")) {
            error_log('ensureLeadsTrackingColumns: failed to add client_delivery_status: ' . ($conn->error ?? 'unknown'));
        } else {
            if ($hasColumn('leads', 'qa_status')) {
                @$conn->query("UPDATE leads SET client_delivery_status = 'Delivered' WHERE qa_status = 'Delivered'");
            }
        }
    }

    if (!$hasColumn('leads', 'company_domain')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN company_domain VARCHAR(255) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add company_domain: ' . ($conn->error ?? 'unknown'));
        } else {
            @$conn->query("UPDATE leads SET company_domain = LOWER(SUBSTRING_INDEX(email,'@',-1)) WHERE (company_domain IS NULL OR company_domain = '') AND email LIKE '%@%'");
        }
    }

    // Update qa_status ENUM to include TBD
    $checkQaStatusEnum = $conn->query("SHOW COLUMNS FROM leads WHERE Field = 'qa_status'");
    if ($checkQaStatusEnum) {
        $row = $checkQaStatusEnum->fetch_assoc();
        $currentType = $row['Type'] ?? '';
        if (strpos($currentType, 'TBD') === false) {
            // Current ENUM doesn't include TBD, need to update it
            $conn->query("ALTER TABLE leads MODIFY COLUMN qa_status ENUM('Pending','TBD','In Progress','Qualified','Disqualified','Rework Needed','Reopened','Rectified','Duplicate/Supp') NULL DEFAULT 'Pending'");
        }
    }

    // Update client_delivery_status ENUM to include all required values
    $checkDeliveryStatusEnum = $conn->query("SHOW COLUMNS FROM leads WHERE Field = 'client_delivery_status'");
    if ($checkDeliveryStatusEnum) {
        $row = $checkDeliveryStatusEnum->fetch_assoc();
        $currentType = $row['Type'] ?? '';
        if (strpos($currentType, 'Accepted') === false) {
            // Current ENUM doesn't include all values, need to update it
            $conn->query("ALTER TABLE leads MODIFY COLUMN client_delivery_status ENUM('Pending','Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') NOT NULL DEFAULT 'Pending'");
        }
    }

    if (!$hasColumn('leads', 'company_website')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN company_website VARCHAR(255) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add company_website: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'company_size')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN company_size VARCHAR(80) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add company_size: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'software_implementation_timeline')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN software_implementation_timeline VARCHAR(255) NULL")) {
            error_log('ensureLeadsTrackingColumns: failed to add software_implementation_timeline: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'created_at_utc')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN created_at_utc DATETIME NULL AFTER created_at")) {
            error_log('ensureLeadsTrackingColumns: failed to add created_at_utc: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'updated_at_utc')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN updated_at_utc DATETIME NULL AFTER updated_at")) {
            error_log('ensureLeadsTrackingColumns: failed to add updated_at_utc: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'created_at_source')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN created_at_source VARCHAR(32) NULL AFTER created_at_utc")) {
            error_log('ensureLeadsTrackingColumns: failed to add created_at_source: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'updated_at_source')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN updated_at_source VARCHAR(32) NULL AFTER updated_at_utc")) {
            error_log('ensureLeadsTrackingColumns: failed to add updated_at_source: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasColumn('leads', 'timezone_migrated')) {
        if (!$conn->query("ALTER TABLE leads ADD COLUMN timezone_migrated TINYINT(1) NOT NULL DEFAULT 0 AFTER updated_at_source")) {
            error_log('ensureLeadsTrackingColumns: failed to add timezone_migrated: ' . ($conn->error ?? 'unknown'));
        }
    }
    if (!$hasIndex('leads', 'idx_created_at_utc')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_created_at_utc (created_at_utc)");
    }
    if (!$hasIndex('leads', 'idx_updated_at_utc')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_updated_at_utc (updated_at_utc)");
    }
    $qaType = '';
    $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'qa_status' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $qaType = (string)(($stmt->get_result()->fetch_assoc() ?: [])['COLUMN_TYPE'] ?? '');
        $stmt->close();
    }
    if ($qaType !== '' && str_contains(strtolower($qaType), 'enum(') && (!str_contains($qaType, "'In Progress'") || !str_contains($qaType, "'Reopened'") || !str_contains($qaType, "'Rectified'") || !str_contains($qaType, "'Duplicate/Supp'"))) {
        @$conn->query("ALTER TABLE leads MODIFY COLUMN qa_status ENUM('Pending','In Progress','Qualified','Disqualified','Rework Needed','Reopened','Rectified','Duplicate/Supp') NULL DEFAULT 'Pending'");
    }
    $deliveryType = '';
    $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'client_delivery_status' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $deliveryType = (string)(($stmt->get_result()->fetch_assoc() ?: [])['COLUMN_TYPE'] ?? '');
        $stmt->close();
    }
    if ($deliveryType !== '' && str_contains(strtolower($deliveryType), 'enum(') && (!str_contains($deliveryType, "'Accepted'") || !str_contains($deliveryType, "'Rejected'") || !str_contains($deliveryType, "'TBD(To be discussed)'") || !str_contains($deliveryType, "'In Progress'"))) {
        @$conn->query("ALTER TABLE leads MODIFY COLUMN client_delivery_status ENUM('Pending','Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') NOT NULL DEFAULT 'Pending'");
    }
    if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_audit_logs'")) {
        $stmt->execute();
        $hasAuditLogTable = (int)(($stmt->get_result()->fetch_assoc() ?: [])['cnt'] ?? 0) > 0;
        $stmt->close();
        if ($hasAuditLogTable) {
            @$conn->query("UPDATE leads l
                JOIN (
                    SELECT q1.lead_id, q1.qa_status, q1.client_delivery_status
                    FROM qa_audit_logs q1
                    JOIN (
                        SELECT lead_id, MAX(id) AS max_id
                        FROM qa_audit_logs
                        WHERE TRIM(COALESCE(qa_status, '')) <> '' OR TRIM(COALESCE(client_delivery_status, '')) <> ''
                        GROUP BY lead_id
                    ) q2 ON q2.max_id = q1.id
                ) qa_fix ON qa_fix.lead_id = l.id
                SET l.qa_status = CASE
                        WHEN TRIM(COALESCE(l.qa_status, '')) = '' AND TRIM(COALESCE(qa_fix.qa_status, '')) <> '' THEN qa_fix.qa_status
                        ELSE l.qa_status
                    END,
                    l.client_delivery_status = CASE
                        WHEN TRIM(COALESCE(l.client_delivery_status, '')) = '' AND TRIM(COALESCE(qa_fix.client_delivery_status, '')) <> '' THEN qa_fix.client_delivery_status
                        ELSE l.client_delivery_status
                    END
                WHERE TRIM(COALESCE(l.qa_status, '')) = '' OR TRIM(COALESCE(l.client_delivery_status, '')) = ''");
        }
    }
    maybeBackfillLeadCanonicalTimestamps(25);
}

/**
 * Get all campaigns
 * 
 * @return array Array of campaigns
 */
function getCampaigns() {
    $conn = getDbConnection();
    $result = $conn->query("
        SELECT c.*, COALESCE(MAX(d.code), '') AS code, COALESCE(MAX(d.client_code), '') AS client_code, COALESCE(MAX(d.status), '') AS status
        FROM campaigns c
        LEFT JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.active = 1
        GROUP BY c.id
        ORDER BY c.name
    ");
    
    $campaigns = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $campaigns[] = $row;
        }
    }
    
    return $campaigns;
}

function getClientCodes() {
    $conn = getDbConnection();
    $result = $conn->query("
        SELECT DISTINCT client_code
        FROM clients
        WHERE client_code IS NOT NULL AND TRIM(client_code) <> ''
        ORDER BY client_code ASC
    ");
    
    $clientCodes = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $clientCodes[] = $row['client_code'];
        }
    }
    
    return $clientCodes;
}

function getScopedVisibleCampaignIdsForUser(int $userId, string $role): ?array {
    $userId = (int)$userId;
    $role = (string)$role;
    if ($userId <= 0) return [];
    if (isAdmin() || isDirector()) return null;
    
    $visible = [];
    
    if (str_starts_with($role, 'qa') || $role === 'qa') {
        $qaVisible = getQaVisibleCampaignIdsForUser($userId, $role);
        if ($qaVisible === null) return null;
        $visible = $qaVisible ?: [];
    } elseif (str_starts_with($role, 'operations') || in_array($role, ['agent','email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler','vendor_admin','vendor_user'], true)) {
        $visible = getOpsVisibleCampaignIdsForUser($userId, $role) ?: [];
    } elseif (isSDR() || isSales()) {
        $visible = getUserAssignedCampaignIds($userId) ?: [];
    } else {
        $visible = getUserAssignedCampaignIds($userId) ?: [];
    }
    
    // Add team-based visibility (Union, not Intersection)
    $teamVisible = getTeamVisibleCampaignIdsForUser($userId) ?: [];
    if ($teamVisible === null) return null; // Should not happen for non-admin
    
    foreach ($teamVisible as $cid => $v) {
        $visible[(int)$cid] = true;
    }
    
    return empty($visible) ? [] : $visible;
}

/**
 * Get all campaigns (Basic info)
 */
function getCampaignsList(array $filters = [], int $perPage = 25, int $page = 1): array {
    $conn = getDbConnection();
    $where = ['c.active = 1'];
    $params = [];
    $types = '';

    if (!empty($filters['status'])) {
        $where[] = 'd.status = ?';
        $params[] = (string)$filters['status'];
        $types .= 's';
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'd.start_date >= ?';
        $params[] = (string)$filters['date_from'];
        $types .= 's';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'd.end_date <= ?';
        $params[] = (string)$filters['date_to'];
        $types .= 's';
    }
    if (!empty($filters['search'])) {
        $where[] = '(c.name LIKE ? OR d.code LIKE ?)';
        $q = '%'.(string)$filters['search'].'%';
        $params[] = $q;
        $params[] = $q;
        $types .= 'ss';
    }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return ['campaigns' => [], 'total' => 0, 'totalPages' => 1];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "c.id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }

    $whereSql = implode(' AND ', $where);
    $offset = max(0, ($page - 1) * $perPage);

    $cntSql = "SELECT COUNT(*) FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE $whereSql";
    $cntStmt = $conn->prepare($cntSql);
    if ($types) mysqliBindParams($cntStmt, $types, $params);
    $cntStmt->execute();
    $total = (int)($cntStmt->get_result()->fetch_row()[0] ?? 0);
    $cntStmt->close();

    $sql = "
        SELECT c.id, c.name, d.code, d.status, d.start_date, d.end_date, d.total_leads
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE $whereSql
        ORDER BY d.created_at DESC, c.name ASC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    mysqliBindParams($stmt, $types . 'ii', $params2);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    return ['campaigns' => $rows, 'total' => $total, 'totalPages' => max(1, (int)ceil($total / max(1, $perPage)))];
}

function getLiveCampaignsForLeadEntry(): array {
    $conn = getDbConnection();
    $sql = "SELECT c.id, c.name
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE d.status = 'Live'
            ORDER BY c.name";
    $rs = $conn->query($sql);
    $rows = [];
    if ($rs) {
        while ($r = $rs->fetch_assoc()) $rows[] = $r;
    }
    return $rows;
}

/**
 * Get all agents (users with agent role)
 * 
 * @return array Array of agents
 */
function getAgentRolesForFilters(): array {
    return [
        'agent',
        'operations_agent',
        'operations_manager',
        'operations_director',
        'email_marketing_agent',
        'email_marketing_executive',
        'form_filler',
    ];
}

function getAgents(array $roles = []): array {
    $conn = getDbConnection();
    $roles = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $roles), static fn($v) => $v !== ''));
    if (empty($roles)) {
        $roles = getAgentRolesForFilters();
    }
    if (empty($roles)) return [];

    $in = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT id, username, full_name, role
            FROM users
            WHERE is_active = 1
              AND (client_id IS NULL OR client_id = 0)
              AND (vendor_id IS NULL OR vendor_id = 0)
              AND role IN ($in)
            ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    mysqliBindParams($stmt, str_repeat('s', count($roles)), $roles);
    $stmt->execute();
    $agents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $agents;
}

function getCampaignById(int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function deleteCampaignHard(int $campaignId): bool {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return false;
    ensureDatabaseSchema();

    $conn = getDbConnection();
    $campaign = getCampaignById($campaignId);
    if (!$campaign) return false;

    $base = realpath(__DIR__ . '/..');
    if ($base === false) $base = __DIR__ . '/..';
    $base = rtrim(str_replace('\\', '/', (string)$base), '/');

    $filesToDelete = [];
    $collect = function (?string $path) use (&$filesToDelete, $base): void {
        $p = trim((string)$path);
        if ($p === '') return;
        $p = str_replace('\\', '/', $p);
        if (preg_match('#^(https?:)?//#i', $p)) return;
        $p = ltrim($p, '/');
        $abs = $base . '/' . $p;
        $real = realpath($abs);
        if ($real === false) return;
        $real = str_replace('\\', '/', $real);
        if (strpos($real, $base . '/') !== 0 && $real !== $base) return;
        $filesToDelete[] = $real;
    };

    $stmt = $conn->prepare("SELECT script_path, tal_path, suppression_path, recording_path FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $collect($row['script_path'] ?? null);
        $collect($row['tal_path'] ?? null);
        $collect($row['suppression_path'] ?? null);
        $collect($row['recording_path'] ?? null);
    }
    $stmt = $conn->prepare("SELECT file_path FROM campaign_delivery_files WHERE campaign_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $collect($r['file_path'] ?? null);
        $stmt->close();
    }
    $stmt = $conn->prepare("SELECT file_path FROM campaign_additional_files WHERE campaign_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $collect($r['file_path'] ?? null);
        $stmt->close();
    }
    $stmt = $conn->prepare("SELECT attachment_path FROM campaign_notes WHERE campaign_id = ? AND attachment_path IS NOT NULL AND attachment_path <> ''");
    if ($stmt) {
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $collect($r['attachment_path'] ?? null);
        $stmt->close();
    }
    $stmt = $conn->prepare("SELECT lf.file_path FROM lead_files lf INNER JOIN leads l ON l.id = lf.lead_id WHERE l.campaign_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) $collect($r['file_path'] ?? null);
        $stmt->close();
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE a FROM lead_activity a INNER JOIN leads l ON l.id = a.lead_id WHERE l.campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }

        $stmt = $conn->prepare("DELETE lf FROM lead_files lf INNER JOIN leads l ON l.id = lf.lead_id WHERE l.campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }

        $stmt = $conn->prepare("DELETE lt FROM lead_tags lt INNER JOIN leads l ON l.id = lt.lead_id WHERE l.campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }

        $stmt = $conn->prepare("DELETE FROM leads WHERE campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }

        $stmt = $conn->prepare("DELETE FROM notifications WHERE campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM notification_digest_queue WHERE campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }

        foreach ([
            'operations_campaign_assignments',
            'qa_campaign_assignments',
            'campaign_user_assignments',
            'team_campaigns',
            'vendor_campaign_map',
            'campaign_metrics',
            'campaign_revenue',
        ] as $table) {
            $sql = "DELETE FROM `$table` WHERE campaign_id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }
        }

        foreach ([
            'campaign_notes',
            'campaign_additional_files',
            'campaign_delivery_files',
        ] as $table) {
            $sql = "DELETE FROM `$table` WHERE campaign_id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }
        }

        $stmt = $conn->prepare("DELETE FROM campaign_forms WHERE campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM campaign_details WHERE campaign_id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM campaigns WHERE id = ?");
        if ($stmt) { $stmt->bind_param('i', $campaignId); $stmt->execute(); $stmt->close(); }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }

    $table = getCampaignLeadTableName($campaignId);
    if ($table !== null && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        $conn->query("DROP TABLE IF EXISTS `$table`");
    }

    $filesToDelete = array_values(array_unique($filesToDelete));
    foreach ($filesToDelete as $abs) {
        if (is_file($abs)) { @unlink($abs); }
    }

    return true;
}

function normalizeFieldKey(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, '_');
    if ($s === '') return '';
    if (preg_match('/^[0-9]/', $s)) $s = 'f_' . $s;
    if (strlen($s) > 64) $s = substr($s, 0, 64);
    return $s;
}

function getCampaignCode(int $campaignId): ?string {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT code FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $code = trim((string)($row['code'] ?? ''));
    return $code !== '' ? $code : null;
}

function getCampaignClientCode(int $campaignId): ?string {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT client_code FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $code = trim((string)($row['client_code'] ?? ''));
    return $code !== '' ? $code : null;
}

function getClientCodeById(int $clientId): ?string {
    $clientId = (int)$clientId;
    if ($clientId <= 0) return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT client_code FROM clients WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $code = trim((string)($row['client_code'] ?? ''));
    return $code !== '' ? $code : null;
}

function getClientIdByCode(string $clientCode): ?int {
    $clientCode = trim($clientCode);
    if ($clientCode === '') return null;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id FROM clients WHERE TRIM(client_code) = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $clientCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $clientId = (int)($row['id'] ?? 0);
    return $clientId > 0 ? $clientId : null;
}

function crmCampaignDisplayName(string $campaignName, ?string $clientCode = null, ?string $fallbackCode = null): string {
    $campaignName = trim($campaignName);
    $clientCode = trim((string)$clientCode);
    $fallbackCode = trim((string)$fallbackCode);
    $code = $clientCode !== '' ? $clientCode : $fallbackCode;
    if ($campaignName === '') return $code;
    if ($code === '') return $campaignName;
    return $campaignName . ' (' . $code . ')';
}

function crmCampaignDisplayNameFromRow(array $row): string {
    return crmCampaignDisplayName(
        (string)($row['name'] ?? $row['campaign_name'] ?? ''),
        (string)($row['client_code'] ?? ''),
        (string)($row['code'] ?? $row['campaign_code'] ?? '')
    );
}

function crmCampaignNameOnly(string $campaignName, string $clientCode = '', string $campaignCode = ''): string {
    $name = trim($campaignName);
    if ($name === '') {
        return '';
    }

    $codes = array_values(array_unique(array_filter([
        trim($clientCode),
        trim($campaignCode),
    ], static fn($value): bool => $value !== '')));

    foreach ($codes as $code) {
        $quoted = preg_quote($code, '/');
        $patterns = [
            '/\s*\(' . $quoted . '\)\s*$/i',
            '/\s*[-–—|:]\s*' . $quoted . '\s*$/i',
            '/\s*[·]\s*' . $quoted . '\s*$/i',
        ];
        foreach ($patterns as $pattern) {
            $name = trim((string)preg_replace($pattern, '', $name));
        }
    }

    return $name;
}

function crmCampaignNameOnlyFromRow(array $row): string {
    return crmCampaignNameOnly(
        (string)($row['name'] ?? $row['campaign_name'] ?? ''),
        (string)($row['client_code'] ?? ''),
        (string)($row['code'] ?? $row['campaign_code'] ?? '')
    );
}

function crmCampaignNameWithClientCode(string $campaignName, string $clientCode = '', string $campaignCode = ''): string {
    $name = crmCampaignNameOnly($campaignName, $clientCode, $campaignCode);
    $clientCode = trim($clientCode);
    if ($name === '') {
        return $clientCode;
    }
    if ($clientCode === '') {
        return $name;
    }

    return $name . ' - ' . $clientCode;
}

function crmCampaignNameWithClientCodeFromRow(array $row): string {
    return crmCampaignNameWithClientCode(
        (string)($row['name'] ?? $row['campaign_name'] ?? ''),
        (string)($row['client_code'] ?? ''),
        (string)($row['code'] ?? $row['campaign_code'] ?? '')
    );
}

function getCampaignClientId(int $campaignId): ?int {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT client_id, client_code FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cid = (int)($row['client_id'] ?? 0);
    if ($cid > 0) return $cid;
    $clientCode = trim((string)($row['client_code'] ?? ''));
    if ($clientCode === '') return null;
    return getClientIdByCode($clientCode);
}

function getCampaignLeadTableName(int $campaignId): ?string {
    $code = getCampaignCode($campaignId);
    if ($code === null) return null;
    $safe = preg_replace('/[^A-Za-z0-9]+/', '_', $code);
    $safe = preg_replace('/_+/', '_', $safe);
    $safe = trim($safe, '_');
    if ($safe === '') return null;
    $name = 'leads_' . $safe;
    if (strlen($name) > 64) $name = substr($name, 0, 64);
    return $name;
}

function campaignLeadTableExists(string $tableName): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function campaignLeadColumnExists(string $tableName, string $columnName): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function ensureCampaignLeadTable(int $campaignId): ?string {
    $table = getCampaignLeadTableName($campaignId);
    if ($table === null) return null;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;

    $conn = getDbConnection();
    if (!campaignLeadTableExists($table)) {
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id INT NOT NULL PRIMARY KEY,
            campaign_id INT NOT NULL,
            campaign_code VARCHAR(32) NOT NULL,
            client_id INT NULL,
            lead_code VARCHAR(40) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT NULL,
            updated_at DATETIME NULL,
            qa_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            qa_reviewed_by INT NULL,
            qa_reviewed_at DATETIME NULL,
            client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            lead_status VARCHAR(20) NOT NULL DEFAULT 'New',
            ip_address VARCHAR(45) NULL,
            recording_file_path VARCHAR(255) NULL,
            vendor_id INT NULL,
            lead_source VARCHAR(20) NULL,
            assigned_to_user INT NULL,
            agent_id INT NULL,
            agent_name VARCHAR(100) NULL,
            first_name VARCHAR(120) NULL,
            last_name VARCHAR(120) NULL,
            job_title VARCHAR(180) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(60) NULL,
            linkedin_profile VARCHAR(255) NULL,
            company_name VARCHAR(255) NULL,
            company_website VARCHAR(255) NULL,
            industry VARCHAR(180) NULL,
            employee_size VARCHAR(60) NULL,
            country VARCHAR(120) NULL,
            company_linkedin VARCHAR(255) NULL,
            lead_comment TEXT NULL,
            software_implementation_timeline VARCHAR(255) NULL,
            INDEX idx_campaign (campaign_id),
            INDEX idx_created_at (created_at),
            INDEX idx_qa (qa_status),
            INDEX idx_delivery (client_delivery_status),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($sql);
    }

    if (!campaignLeadColumnExists($table, 'client_delivery_status')) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER qa_reviewed_at");
        $conn->query("UPDATE `$table` SET client_delivery_status = 'Delivered' WHERE qa_status = 'Delivered'");
    }

    if (!campaignLeadColumnExists($table, 'vendor_id')) { $conn->query("ALTER TABLE `$table` ADD COLUMN vendor_id INT NULL"); }
    if (!campaignLeadColumnExists($table, 'lead_source')) { $conn->query("ALTER TABLE `$table` ADD COLUMN lead_source VARCHAR(20) NULL"); }
    if (!campaignLeadColumnExists($table, 'assigned_to_user')) { $conn->query("ALTER TABLE `$table` ADD COLUMN assigned_to_user INT NULL"); }
    if (!campaignLeadColumnExists($table, 'agent_id')) { $conn->query("ALTER TABLE `$table` ADD COLUMN agent_id INT NULL"); }
    if (!campaignLeadColumnExists($table, 'agent_name')) { $conn->query("ALTER TABLE `$table` ADD COLUMN agent_name VARCHAR(100) NULL"); }

    $form = getFormForCampaign($campaignId);
    $fields = (array)($form['schema']['fields'] ?? []);
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $key = normalizeFieldKey((string)($f['key'] ?? ''));
        if ($key === '') continue;
        if (in_array($key, [
            'id','campaign_id','campaign_code','client_id','lead_code',
            'created_by','created_at','updated_by','updated_at',
            'qa_status','qa_reviewed_by','qa_reviewed_at','client_delivery_status','lead_status','ip_address','recording_file_path',
            'vendor_id','lead_source','assigned_to_user','agent_id','agent_name',
            'first_name','last_name','job_title','email','phone','linkedin_profile',
            'company_name','company_website','industry','employee_size','country','company_linkedin'
        ], true)) {
            continue;
        }
        if (!campaignLeadColumnExists($table, $key)) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$key` TEXT NULL");
        }
    }

    return $table;
}

function upsertCampaignLeadRow(int $campaignId, int $leadId, array $rowData): bool {
    $table = ensureCampaignLeadTable($campaignId);
    if ($table === null) return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $conn = getDbConnection();

    $cols = [];
    $placeholders = [];
    $types = '';
    $vals = [];

    $rowData['id'] = $leadId;
    foreach ($rowData as $k => $v) {
        $col = normalizeFieldKey((string)$k);
        if ($col === '') continue;
        if (!campaignLeadColumnExists($table, $col)) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` TEXT NULL");
        }
        $cols[] = "`$col`";
        $placeholders[] = '?';
        if (is_int($v) || is_bool($v)) {
            $types .= 'i';
            $vals[] = (int)$v;
        } else {
            $types .= 's';
            $vals[] = ($v === null) ? null : (string)$v;
        }
    }
    if (empty($cols)) return false;

    $updates = [];
    foreach ($cols as $c) {
        if ($c === '`id`') continue;
        $updates[] = "$c = VALUES($c)";
    }
    $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")
            ON DUPLICATE KEY UPDATE " . implode(',', $updates);
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    mysqliBindParams($stmt, $types, $vals);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getCampaignLeadTableStats(int $campaignId, ?string $dateFrom = null, ?string $dateTo = null, bool $respectCampaignWindow = false): array {
    if ($campaignId <= 0) {
        return ['total' => 0, 'pending_qa' => 0, 'approved' => 0, 'rejected' => 0, 'client_delivered' => 0, 'client_accepted' => 0, 'last_submitted_at' => null];
    }
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('l');
    $where = ["l.campaign_id = ?"];
    $params = [$campaignId];
    $types = 'i';
    if ($dateFrom !== null && trim($dateFrom) !== '') {
        $where[] = "$createdExpr >= ?";
        $params[] = trim($dateFrom);
        $types .= 's';
    }
    if ($dateTo !== null && trim($dateTo) !== '') {
        $where[] = "$createdExpr <= ?";
        $params[] = trim($dateTo);
        $types .= 's';
    }
    if ($respectCampaignWindow) {
        $where[] = "(d.start_date IS NULL OR DATE($createdExpr) >= d.start_date)";
        $where[] = "(d.end_date IS NULL OR DATE($createdExpr) <= d.end_date)";
    }
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN l.qa_status IN ('Pending','Reopened') OR l.qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa,
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN l.client_delivery_status IN ('Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') THEN 1 ELSE 0 END) AS client_delivered,
            SUM(CASE WHEN l.client_delivery_status='Accepted' THEN 1 ELSE 0 END) AS client_accepted,
            MAX($createdExpr) AS last_submitted_at
        FROM leads l
        LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE " . implode(' AND ', $where) . "
    ");
    if (!$stmt) {
        return ['total' => 0, 'pending_qa' => 0, 'approved' => 0, 'rejected' => 0, 'client_delivered' => 0, 'client_accepted' => 0, 'last_submitted_at' => null];
    }
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'total' => (int)($r['total'] ?? 0),
        'pending_qa' => (int)($r['pending_qa'] ?? 0),
        'approved' => (int)($r['approved'] ?? 0),
        'rejected' => (int)($r['rejected'] ?? 0),
        'client_delivered' => (int)($r['client_delivered'] ?? 0),
        'client_accepted' => (int)($r['client_accepted'] ?? 0),
        'last_submitted_at' => $r['last_submitted_at'] ?? null,
    ];
}

function getCampaignLeadStatsBulk(array $campaignIds, ?string $dateFrom = null, ?string $dateTo = null, bool $respectCampaignWindow = false): array {
    $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $createdExpr = leadCreatedAtExpr('l');
    $types = str_repeat('i', count($ids));
    $params = $ids;
    $where = ["l.campaign_id IN ($in)"];
    if ($dateFrom !== null && trim($dateFrom) !== '') {
        $where[] = "$createdExpr >= ?";
        $params[] = trim($dateFrom);
        $types .= 's';
    }
    if ($dateTo !== null && trim($dateTo) !== '') {
        $where[] = "$createdExpr <= ?";
        $params[] = trim($dateTo);
        $types .= 's';
    }
    if ($respectCampaignWindow) {
        $where[] = "(d.start_date IS NULL OR DATE($createdExpr) >= d.start_date)";
        $where[] = "(d.end_date IS NULL OR DATE($createdExpr) <= d.end_date)";
    }
    $stmt = $conn->prepare("
        SELECT
            l.campaign_id,
            COUNT(*) AS total,
            SUM(CASE WHEN l.qa_status IN ('Pending','Reopened') OR l.qa_status IS NULL THEN 1 ELSE 0 END) AS pending_qa,
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN l.qa_status='Disqualified' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN l.client_delivery_status IN ('Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') THEN 1 ELSE 0 END) AS client_delivered,
            SUM(CASE WHEN l.client_delivery_status='Accepted' THEN 1 ELSE 0 END) AS client_accepted,
            MAX($createdExpr) AS last_submitted_at
        FROM leads l
        LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY l.campaign_id
    ");
    if (!$stmt) return [];
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $out = [];
    foreach ($rows as $r) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid <= 0) continue;
        $out[$cid] = [
            'total' => (int)($r['total'] ?? 0),
            'pending_qa' => (int)($r['pending_qa'] ?? 0),
            'approved' => (int)($r['approved'] ?? 0),
            'rejected' => (int)($r['rejected'] ?? 0),
            'client_delivered' => (int)($r['client_delivered'] ?? 0),
            'client_accepted' => (int)($r['client_accepted'] ?? 0),
            'last_submitted_at' => $r['last_submitted_at'] ?? null,
        ];
    }
    return $out;
}

function getAssignedCampaignIdsForUser(int $userId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getQaVisibleCampaignIdsForUser(int $userId, string $role): ?array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $role = function_exists('normalizeRole') ? normalizeRole($role) : $role;
    if ($role === 'admin') return null;
    if (in_array($role, ['qa_director', 'qa_manager'], true)) return null;
    $conn = getDbConnection();
    $out = [];
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM qa_campaign_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    $cache[$userId] = $out;
    return $out;
}

function getQaAssignableUsers(string $currentRole): array {
    $conn = getDbConnection();
    $roles = [];
    if ($currentRole === 'admin') {
        $roles = ['qa_director', 'qa_manager', 'qa_agent', 'qa'];
    } elseif ($currentRole === 'qa_director') {
        $roles = ['qa_manager', 'qa_agent', 'qa'];
    } elseif ($currentRole === 'qa_manager') {
        $roles = ['qa_agent', 'qa'];
    } else {
        return [];
    }
    $in = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT id, full_name, role, job_title FROM users WHERE is_active = 1 AND role IN ($in) ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($roles));
    mysqliBindParams($stmt, $types, $roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getQaAssignedCampaignIdsForUser(int $userId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM qa_campaign_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getOpsVisibleCampaignIdsForUser(int $userId, string $role): ?array {
    if ($role === 'admin') return null;
    $conn = getDbConnection();
    
    if ($role === 'vendor_admin' || $role === 'vendor_user') {
        $stmt = $conn->prepare("SELECT vendor_id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $vendorId = (int)($userRow['vendor_id'] ?? 0);
        if ($vendorId <= 0) return [];

        $allowedIds = [];
        $stmt = $conn->prepare("SELECT campaign_id FROM vendor_campaign_map WHERE vendor_id = ? AND uploads_enabled = 1");
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) { $allowedIds[(int)$r['campaign_id']] = true; }
        $stmt->close();

        if ($role === 'vendor_user') {
            $userAssigned = [];
            $stmt = $conn->prepare("SELECT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) { 
                $cid = (int)$r['campaign_id'];
                if (isset($allowedIds[$cid])) $userAssigned[$cid] = true; 
            }
            $stmt->close();
            return $userAssigned;
        }
        return $allowedIds;
    }

    $opsRoles = [
        'director','manager_director','operations_director','operations_manager','operations_agent','agent',
        'email_marketing_director','email_marketing_manager','email_marketing_agent','email_marketing_executive','form_filler'
    ];
    if (!in_array($role, $opsRoles, true)) return null;
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM operations_campaign_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getOpsAssignableUsers(string $currentRole): array {
    $conn = getDbConnection();
    $roles = [];
    if ($currentRole === 'admin' || $currentRole === 'director' || $currentRole === 'manager_director' || $currentRole === 'operations_director') {
        $roles = ['operations_manager', 'operations_agent', 'agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    } elseif ($currentRole === 'operations_manager') {
        $roles = ['operations_agent', 'agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler'];
    } else {
        return [];
    }
    $in = implode(',', array_fill(0, count($roles), '?'));
    $sql = "SELECT id, full_name, role, job_title FROM users WHERE is_active = 1 AND role IN ($in) ORDER BY full_name";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($roles));
    mysqliBindParams($stmt, $types, $roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getOpsCampaignsForUser(int $userId, string $role, string $status = ''): array {
    $conn = getDbConnection();
    $role = (string)$role;

    $direct = getOpsVisibleCampaignIdsForUser($userId, $role);
    if ($direct === null) {
        $visible = null;
    } else {
        $visible = $direct;

        if (!in_array($role, ['vendor_admin','vendor_user'], true)) {
            $teamIds = getUserTeamIds((int)$userId);
            if (!empty($teamIds)) {
                $teamCampaigns = getTeamCampaignIds($teamIds);
                foreach ($teamCampaigns as $cid => $v) {
                    $visible[(int)$cid] = true;
                }
            }

            $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM campaign_user_assignments WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_assoc()) {
                    $cid = (int)($r['campaign_id'] ?? 0);
                    if ($cid > 0) $visible[$cid] = true;
                }
                $stmt->close();
            }
        }
    }

    if ($visible !== null && empty($visible)) return [];

    $baseWhere = [];
    $params = [];
    $types = '';
    if ($status !== '') {
        $baseWhere[] = 'd.status = ?';
        $params[] = $status;
        $types .= 's';
    } else {
        $baseWhere[] = 'c.active = 1';
    }
    if ($visible !== null) {
        $ids = array_keys($visible);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $baseWhere[] = "c.id IN ($in)";
        foreach ($ids as $id) { $params[] = (int)$id; $types .= 'i'; }
    }
    $whereSql = implode(' AND ', $baseWhere);
    $sql = "SELECT c.id, c.name
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE $whereSql
            ORDER BY c.name";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLeadById(int $id): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getLeadByIdDynamic(int $id, int $campaignId): ?array {
    $table = getCampaignLeadTableName($campaignId);
    if (!$table || !campaignLeadTableExists($table)) {
        return getLeadById($id);
    }
    
    $conn = getDbConnection();
    $sql = "
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM `$table` l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return getLeadById($id);
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: getLeadById($id);
}

function getLeadByCode(string $leadCode): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT l.*,
               c.name AS campaign_name,
               u.full_name AS agent_name,
               r.full_name AS reviewer_name
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN users u ON l.agent_id = u.id
        LEFT JOIN users r ON l.qa_reviewed_by = r.id
        WHERE l.lead_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $leadCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function canUserAccessLead(array $user, array $lead, array $options = []): bool {
    $role = function_exists('normalizeRole') ? normalizeRole((string)($user['role'] ?? '')) : strtolower(trim((string)($user['role'] ?? '')));
    $userId = (int)($user['id'] ?? 0);
    $leadCampaignId = (int)($lead['campaign_id'] ?? 0);
    $leadAgentId = (int)($lead['agent_id'] ?? 0);

    if ($role === 'admin') {
        return true;
    }

    if (in_array($role, ['agent', 'operations_agent'], true) && $leadAgentId > 0 && $leadAgentId !== $userId) {
        return false;
    }

    if (in_array($role, ['qa', 'qa_agent', 'qa_manager', 'qa_director'], true)) {
        $visible = getQaVisibleCampaignIdsForUser($userId, $role);
        if (function_exists('getTeamVisibleCampaignIdsForUser')) {
            $visible = getTeamVisibleCampaignIdsForUser($userId, $visible);
        }
        if ($visible !== null && $leadCampaignId > 0 && !isset($visible[$leadCampaignId])) {
            return false;
        }
    }

    if (in_array($role, ['form_filler', 'email_marketing_executive', 'email_marketing_agent', 'email_marketing_manager', 'email_marketing_director'], true)) {
        $visible = getOpsVisibleCampaignIdsForUser($userId, $role);
        if ($visible !== null && $leadCampaignId > 0 && !isset($visible[$leadCampaignId])) {
            return false;
        }
    }

    $userVendorId = (int)($user['vendor_id'] ?? 0);
    if ($userVendorId > 0 || in_array($role, ['vendor_admin', 'vendor_user'], true)) {
        $leadVendorId = (int)($lead['vendor_id'] ?? 0);
        if ($userVendorId <= 0 || $leadVendorId !== $userVendorId) {
            return false;
        }
        if ($role === 'vendor_user' && $leadAgentId !== $userId) {
            return false;
        }
    }

    $userClientId = (int)($user['client_id'] ?? 0);
    if ($userClientId > 0 || in_array($role, ['client_admin', 'client_sdr'], true)) {
        if ($userClientId <= 0 || $leadCampaignId <= 0) {
            return false;
        }
        $campaignClientId = (int)(getCampaignClientId($leadCampaignId) ?? 0);
        if ($campaignClientId !== $userClientId) {
            return false;
        }
        $allowClientPending = !empty($options['allow_client_pending']);
        if (!$allowClientPending) {
            $delivery = normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending'));
            if ($delivery === 'Pending') {
                return false;
            }
        }
    }

    return true;
}

function getCampaignForm(int $campaignId): ?array {
    return getFormForCampaign($campaignId);
}

function syncLeadToCampaignTable(int $id): bool {
    $lead = getLeadById($id);
    if (!$lead) return false;
    
    $campaignId = (int)($lead['campaign_id'] ?? 0);
    if ($campaignId <= 0) return false;

    $clientId = (int)($lead['client_id'] ?? 0);
    if ($clientId <= 0) {
        $clientId = (int)(getCampaignClientId($campaignId) ?? 0);
        if ($clientId > 0) {
            $conn = getDbConnection();
            $stmtU = $conn->prepare("UPDATE leads SET client_id = ? WHERE id = ? AND (client_id IS NULL OR client_id = 0) LIMIT 1");
            if ($stmtU) {
                $stmtU->bind_param('ii', $clientId, $id);
                $stmtU->execute();
                $stmtU->close();
            }
        }
    }
    
    $formRow = getCampaignForm((int)$campaignId);
    $formId = (int)($formRow['form_id'] ?? 0);
    $schemaFields = [];
    if ($formId > 0) {
        $form = getFormById($formId);
        if (!$form) $form = getFormForCampaign($campaignId);
        $schemaFields = (array)($form['schema']['fields'] ?? []);
    }
    $customData = [];
    if ($formId > 0) {
        $submission = getLatestFormSubmissionForLead($id, $campaignId);
        if ($submission && isset($submission['data'])) {
            $customData = $submission['data'];
        }
    }
    $pickCustomScalar = function(array $aliases) use ($customData): string {
        foreach ($aliases as $k) {
            if (!array_key_exists($k, $customData)) continue;
            $v = $customData[$k];
            if (is_array($v)) continue;
            $s = trim((string)$v);
            if ($s !== '') return $s;
        }
        return '';
    };

    $pickByLabel = function(array $needles) use ($schemaFields, $customData): string {
        if (empty($schemaFields) || empty($customData)) return '';
        foreach ($schemaFields as $f) {
            if (!is_array($f)) continue;
            if (array_key_exists('visible', $f) && empty($f['visible'])) continue;
            $key = (string)($f['key'] ?? '');
            if ($key === '') continue;
            $label = strtolower(trim((string)($f['label'] ?? '')));
            if ($label === '') continue;
            foreach ($needles as $n) {
                $n = strtolower(trim((string)$n));
                if ($n !== '' && str_contains($label, $n)) {
                    if (!array_key_exists($key, $customData)) break;
                    $v = $customData[$key];
                    if (is_array($v)) continue 2;
                    $s = trim((string)$v);
                    if ($s !== '') return $s;
                    break;
                }
            }
        }
        return '';
    };

    $campaignTableData = [
        'id' => $id,
        'campaign_id' => $campaignId,
        'campaign_code' => getCampaignCode($campaignId),
        'client_id' => $clientId,
        'lead_code' => $lead['lead_id'],
        'created_by' => (int)($lead['created_by'] ?? ($lead['agent_id'] ?? 0)),
        'created_at' => $lead['created_at'],
        'created_at_utc' => $lead['created_at_utc'] ?? null,
        'created_at_source' => $lead['created_at_source'] ?? '',
        'updated_by' => (int)($lead['updated_by'] ?? 0),
        'updated_at' => $lead['updated_at'],
        'updated_at_utc' => $lead['updated_at_utc'] ?? null,
        'updated_at_source' => $lead['updated_at_source'] ?? '',
        'vendor_id' => (int)($lead['vendor_id'] ?? 0),
        'lead_source' => (string)($lead['lead_source'] ?? ''),
        'assigned_to_user' => (int)($lead['assigned_to_user'] ?? 0),
        'agent_id' => (int)($lead['agent_id'] ?? 0),
        'agent_name' => (string)($lead['agent_name'] ?? ''),
        'qa_status' => $lead['qa_status'] ?? 'Pending',
        'qa_reviewed_by' => (int)($lead['qa_reviewed_by'] ?? 0),
        'qa_reviewed_at' => $lead['qa_updated_at'] ?? $lead['qa_reviewed_at'] ?? null,
        'client_delivery_status' => $lead['client_delivery_status'] ?? 'Pending',
        'lead_status' => $lead['lead_status'] ?? 'New',
        'ip_address' => $lead['ip_address'] ?? '',
        'recording_file_path' => $lead['recording_path'] ?? '',
        'first_name' => $lead['first_name'] ?? '',
        'last_name' => $lead['last_name'] ?? '',
        'job_title' => (
            $pickCustomScalar(['job_title','jobtitle','title','designation','job_position','position','role','contact_title','contact_role'])
            ?: $pickByLabel(['job title','designation','job position'])
            ?: ($lead['job_title'] ?? '')
        ),
        'email' => $lead['email'] ?? '',
        'phone' => $lead['contact_phone'] ?? '',
        'linkedin_profile' => $pickCustomScalar(['linkedin_link','linkedin_url','linkedin_profile','prospect_linkedin_link','prospect_linkedin_url','prospect_linkedin_profile']) ?: ($lead['linkedin_link'] ?? ''),
        'company_name' => $lead['company_name'] ?? '',
        'company_website' => (string)($customData['company_website'] ?? $customData['website'] ?? $customData['domain'] ?? ''),
        'industry' => $lead['industry'] ?? '',
        'employee_size' => $lead['company_size'] ?? '',
        'country' => $lead['country'] ?? '',
        'company_linkedin' => $pickCustomScalar(['company_linkedin','company_linkedin_url','company_linkedin_link','companylinkedin','companylinkedinurl']) ?: ($lead['company_linkedin'] ?? ''),
        'lead_comment' => $lead['lead_comment'] ?? '',
        'software_implementation_timeline' => (
            $pickCustomScalar([
            'software_implementation_timeline',
            'implementation_timeline',
            'decision_timeline',
            'timeline',
            'when_is_your_company_planning_to_implement_new_software',
            'when_is_your_company_planning_to_implement_this_solution',
            'when_is_your_company_planning_to_implement_new_software_solution',
            ])
            ?: $pickByLabel(['implement','implementation','timeline'])
            ?: ($lead['software_implementation_timeline'] ?? '')
        ),
    ];
    
    if (!empty($customData)) {
        foreach ($customData as $k => $v) {
            $nk = normalizeFieldKey((string)$k);
            if (!isset($campaignTableData[$nk])) {
                $campaignTableData[$nk] = is_array($v) ? implode(', ', $v) : (string)$v;
            }
        }
    }
    
    return upsertCampaignLeadRow($campaignId, $id, $campaignTableData);
}

function updateLead(int $id, array $data): bool {
    ensureLeadsTrackingColumns();
    if (!array_key_exists('updated_at_utc', $data)) {
        $data['updated_at_utc'] = appNowUtc();
    }
    if (!array_key_exists('updated_at_source', $data)) {
        $data['updated_at_source'] = 'app_update_utc';
    }
    $allowed = [
        'created_by',
        'updated_by',
        'campaign_id',
        'campaign_name',
        'agent_id',
        'agent_name',
        'client_id',
        'vendor_id',
        'lead_source',
        'assigned_to_user',
        'first_name',
        'last_name',
        'job_title',
        'email',
        'linkedin_link',
        'contact_phone',
        'industry',
        'company_name',
        'company_website',
        'company_size',
        'company_linkedin',
        'country',
        'software_implementation_timeline',
        'recording_path',
        'ip_address',
        'qa_status',
        'qa_comment',
        'qa_client_comment',
        'client_delivery_status',
        'qa_reviewed_by',
        'qa_updated_at',
        'form_done',
        'form_filled_time',
        'created_at_utc',
        'updated_at_utc',
        'created_at_source',
        'updated_at_source',
        'timezone_migrated',
        'lead_comment',
        'email_status',
        'email_status_comment',
        'email_status_updated_at',
        'email_status_updated_by',
    ];
    $preserveIfBlank = [
        'campaign_name',
        'first_name',
        'last_name',
        'job_title',
        'email',
        'linkedin_link',
        'contact_phone',
        'industry',
        'company_name',
        'company_website',
        'company_size',
        'company_linkedin',
        'country',
        'software_implementation_timeline',
        'recording_path',
        'ip_address',
        'lead_comment',
    ];
    $existingLead = null;

    $set = [];
    $params = [];
    $types = '';
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $data)) continue;
        $set[] = "$col = ?";
        $val = $data[$col];
        if (in_array($col, $preserveIfBlank, true) && ($val === null || (is_string($val) && trim($val) === ''))) {
            if ($existingLead === null) {
                $existingLead = getLeadById($id) ?: [];
            }
            $existingVal = $existingLead[$col] ?? null;
            if ($existingVal !== null && (!is_string($existingVal) || trim($existingVal) !== '')) {
                $val = $existingVal;
            }
        }
        if ($col === 'campaign_id' || $col === 'agent_id' || $col === 'qa_reviewed_by' || $col === 'created_by' || $col === 'updated_by' || $col === 'email_status_updated_by' || $col === 'client_id' || $col === 'vendor_id' || $col === 'assigned_to_user' || $col === 'timezone_migrated') {
            $types .= 'i';
            $params[] = ($val === null || $val === '') ? null : (int)$val;
        } else {
            $types .= 's';
            $params[] = $val;
        }
    }
    if (empty($set)) return true;

    $conn = getDbConnection();
    $sql = "UPDATE leads SET " . implode(', ', $set) . " WHERE id = ?";
    $types .= 'i';
    $params[] = $id;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('updateLead prepare failed: ' . ($conn->error ?? 'unknown') . ' SQL=' . $sql);
        return false;
    }
    mysqliBindParams($stmt, $types, $params);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        syncLeadToCampaignTable($id);
        if (function_exists('notifyLeadUpdated')) {
            $actorId = (int)($data['updated_by'] ?? 0);
            $changed = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $data)) $changed[] = $col;
            }
            notifyLeadUpdated($id, $actorId, $changed);
        }
    }
    return $ok;
}

function logLeadActivity(int $leadId, ?int $actorId, string $action, array $meta = []): bool {
    $conn = getDbConnection();
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metaJson === false) $metaJson = null;
    $stmt = $conn->prepare("INSERT INTO lead_activity (lead_id, actor_id, action, meta_json) VALUES (?, ?, ?, ?)");
    $actor = $actorId ?? null;
    $stmt->bind_param('iiss', $leadId, $actor, $action, $metaJson);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function canDeleteLeadForUser(array $user): bool {
    $role = function_exists('normalizeRole') ? normalizeRole((string)($user['role'] ?? '')) : strtolower(trim((string)($user['role'] ?? '')));
    if ($role === '') return false;
    return in_array($role, ['admin', 'director', 'manager_director', 'operations_director'], true);
}

function canDeleteLeadForActor(?int $actorId = null): bool {
    if ($actorId === null) return true;
    $actorId = (int)$actorId;
    if ($actorId <= 0) return false;

    if (function_exists('getCurrentUser')) {
        $current = getCurrentUser();
        if (is_array($current) && (int)($current['id'] ?? 0) === $actorId) {
            return canDeleteLeadForUser($current);
        }
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $actorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return canDeleteLeadForUser($row);
}

function deleteSingleLead(int $leadId, bool $deleteFiles = false, ?int $actorId = null): bool {
    $leadId = (int)$leadId;
    if ($leadId <= 0) return false;
    if (!canDeleteLeadForActor($actorId)) return false;
    $conn = getDbConnection();
    $lead = getLeadById($leadId);
    if (!$lead) return false;

    $campaignId = (int)($lead['campaign_id'] ?? 0);
    $recordingPath = trim((string)($lead['recording_path'] ?? ''));
    $paths = [];
    if ($deleteFiles && $recordingPath !== '') $paths[] = $recordingPath;
    if ($deleteFiles) {
        $stmt = $conn->prepare("SELECT file_path FROM lead_files WHERE lead_id = ? AND file_path IS NOT NULL AND file_path <> ''");
        if ($stmt) {
            $stmt->bind_param('i', $leadId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) {
                $p = trim((string)($r['file_path'] ?? ''));
                if ($p !== '') $paths[] = $p;
            }
        }
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM lead_activity WHERE lead_id = ?");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM lead_files WHERE lead_id = ?");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }
        $stmt = $conn->prepare("DELETE FROM form_submissions WHERE lead_id = ?");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }

        if ($campaignId > 0) {
            $ct = getCampaignLeadTableName($campaignId);
            if ($ct && campaignLeadTableExists($ct) && preg_match('/^[A-Za-z0-9_]+$/', $ct)) {
                $conn->query("DELETE FROM `$ct` WHERE id = " . (int)$leadId . " LIMIT 1");
            }
        }

        $stmt = $conn->prepare("DELETE FROM leads WHERE id = ? LIMIT 1");
        if ($stmt) { $stmt->bind_param('i', $leadId); $stmt->execute(); $stmt->close(); }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }

    if ($deleteFiles && !empty($paths)) {
        $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        foreach ($paths as $rel) {
            $rel = str_replace(['\\', "\0"], ['/', ''], (string)$rel);
            if (str_starts_with($rel, '/')) $rel = ltrim($rel, '/');
            $full = realpath($root . '/' . $rel);
            if ($full && str_starts_with($full, $root) && is_file($full)) {
                @unlink($full);
            }
        }
    }
    return true;
}

function uploadRecording(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    $maxSize = 50 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        return false;
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['mp3', 'wav', 'm4a', 'aac', 'ogg'];
    if (!in_array($ext, $allowed, true)) {
        return false;
    }
    $dir = __DIR__ . '/../uploads/lead_files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $safeName = 'rec_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return false;
    }
    return 'uploads/lead_files/' . $safeName;
}

/**
 * Get all recordings for a lead
 */
function getLeadRecordings(int $leadId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, file_path, uploaded_at FROM lead_files WHERE lead_id = ? AND field_id = 'call_recording' ORDER BY uploaded_at ASC, id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $res;
}

/**
 * Register recording for asynchronous AI transcription & formatting
 */
function registerRecordingForTranscription(int $leadId, ?int $fileId, string $audioPath): int {
    if ($leadId <= 0 || trim($audioPath) === '') return 0;

    require_once __DIR__ . '/ai/AIConfig.php';
    require_once __DIR__ . '/ai/TranscriptRepository.php';

    $settings = AIConfig::getSettings();
    if (empty($settings['qa.ai.enabled']) || (string)$settings['qa.ai.enabled'] === '0') {
        return 0;
    }

    $repo = new TranscriptRepository();
    return $repo->createJob($leadId, $fileId, $audioPath);
}

/**
 * Add a recording to a lead
 */
function addLeadRecording(int $leadId, string $filePath, ?int $actorId = null): int {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO lead_files (lead_id, field_id, file_path, uploaded_at) VALUES (?, 'call_recording', ?, NOW())");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $leadId, $filePath);
    $ok = $stmt->execute();
    $id = $ok ? (int)$conn->insert_id : 0;
    $stmt->close();
    if ($id > 0) {
        logLeadActivity($leadId, $actorId, 'recording_uploaded', ['path' => $filePath, 'file_id' => $id]);
        registerRecordingForTranscription($leadId, $id, $filePath);
    }
    return $id;
}

/**
 * Delete a recording from a lead
 */
function deleteLeadRecording(int $leadId, int $fileId, ?int $actorId = null): bool {
    $conn = getDbConnection();
    
    // Get file path first for activity log and file deletion
    $stmt = $conn->prepare("SELECT file_path FROM lead_files WHERE id = ? AND lead_id = ? AND field_id = 'call_recording' LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $fileId, $leadId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    
    $filePath = (string)$row['file_path'];
    
    $stmt = $conn->prepare("DELETE FROM lead_files WHERE id = ? AND lead_id = ? AND field_id = 'call_recording' LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $fileId, $leadId);
    $ok = $stmt->execute();
    $stmt->close();
    
    if ($ok) {
        logLeadActivity($leadId, $actorId, 'recording_deleted', ['path' => $filePath, 'file_id' => $fileId]);
        // Note: We usually don't delete the physical file immediately to prevent accidental loss, 
        // but if required, we could add @unlink logic here.
    }
    return $ok;
}

/**
 * Ensure all legacy recordings are migrated to lead_files
 */
function ensureMultiRecordingMigration(): void {
    static $done = false;
    if ($done) return; 
    
    $conn = getDbConnection();
    // Use a simple check to see if we've already migrated recently
    // For production, this should ideally be in a migration tracker table
    $sql = "
        INSERT INTO lead_files (lead_id, field_id, file_path, uploaded_at)
        SELECT id, 'call_recording', recording_path, created_at
        FROM leads
        WHERE recording_path IS NOT NULL 
          AND recording_path <> '' 
          AND id NOT IN (
            SELECT lead_id FROM lead_files WHERE field_id = 'call_recording'
          )
    ";
    $conn->query($sql);
    $done = true;
}

// Initialize multi-recording migration if needed
ensureMultiRecordingMigration();

function findDuplicateLeads(string $firstName = '', string $lastName = '', string $email = '', string $companyName = '', int $limit = 10, int $campaignId = 0, string $search = ''): array {
    $conn = getDbConnection();
    $where = [];
    $params = [];
    $types = '';

    $search = trim($search);
    if ($search !== '') {
        $where[] = "(l.email LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.company_name LIKE ? OR l.lead_id LIKE ?)";
        $p = '%' . $search . '%';
        $params = array_merge($params, [$p, $p, $p, $p, $p]);
        $types .= 'sssss';
    }

    $email = trim($email);
    if ($email !== '') {
        $where[] = "l.email = ?";
        $params[] = $email;
        $types .= 's';
        
        $emailDomain = extractDomain($email);
        if ($emailDomain !== '') {
            $where[] = "LOWER(SUBSTRING_INDEX(l.email,'@',-1)) = ?";
            $params[] = strtolower($emailDomain);
            $types .= 's';
        }
    }

    $nameParts = [];
    if (trim($firstName) !== '') { $nameParts[] = "l.first_name LIKE ?"; $params[] = '%'.trim($firstName).'%'; $types .= 's'; }
    if (trim($lastName) !== '') { $nameParts[] = "l.last_name LIKE ?"; $params[] = '%'.trim($lastName).'%'; $types .= 's'; }
    if (!empty($nameParts)) {
        $where[] = '(' . implode(' AND ', $nameParts) . ')';
    }

    if (trim($companyName) !== '') {
        $where[] = "l.company_name LIKE ?";
        $params[] = '%'.trim($companyName).'%';
        $types .= 's';
    }

    if (empty($where)) {
        return [];
    }

    $clientId = $campaignId > 0 ? (int)(getCampaignClientId($campaignId) ?? 0) : 0;
    $scopeSql = '';
    if ($clientId > 0) {
        $scopeSql = 'd.client_id = ? AND ';
        $params = array_merge([$clientId], $params);
        $types = 'i' . $types;
    }

    $sql = "
        SELECT l.id, l.first_name, l.last_name, l.email, l.company_name, l.qa_status, l.created_at,
               d.client_id, d.code AS campaign_code, c.name AS campaign_name
        FROM leads l
        LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id
        LEFT JOIN campaigns c ON c.id = l.campaign_id
        WHERE " . $scopeSql . "(" . implode(' OR ', $where) . ")
        ORDER BY l.created_at DESC
        LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('findDuplicateLeads prepare failed: ' . ($conn->error ?? 'unknown'));
        return [];
    }
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rs = $stmt->get_result();
    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function dfNormalizeFieldKey(string $raw): string {
    $s = strtolower(trim($raw));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

function dfGetDuplicateRulesFromSchema(array $schema): array {
    $rules = [];
    $fields = $schema['fields'] ?? [];
    if (!is_array($fields)) return $rules;
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        if (empty($f['duplicate_check'])) continue;
        $key = dfNormalizeFieldKey((string)($f['key'] ?? ''));
        if ($key === '') continue;
        $label = trim((string)($f['label'] ?? $key));
        $limit = (int)($f['accepted_duplicates'] ?? 1);
        if ($limit < 1) $limit = 1;
        $rules[$key] = [
            'key' => $key,
            'label' => ($label !== '' ? $label : $key),
            'limit' => $limit,
        ];
    }
    return $rules;
}

function dfNormalizeDuplicateValue(string $key, mixed $value): string {
    if (is_array($value) || is_object($value)) return '';
    $v = trim((string)$value);
    if ($v === '' || strtolower($v) === 'null') return '';
    $k = dfNormalizeFieldKey($key);
    if ($k === 'email') return strtolower($v);
    if ($k === 'company_domain' || $k === 'company_website' || $k === 'domain') return extractDomain($v);
    if ($k === 'phone' || $k === 'contact_phone') {
        $v = preg_replace('/[^0-9]+/', '', $v);
        return trim((string)$v);
    }
    $v = preg_replace('/\s+/', ' ', $v);
    return strtolower(trim($v));
}

function dfDuplicateStorageColumnForKey(string $key): ?string {
    $k = dfNormalizeFieldKey($key);
    return match ($k) {
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'job_title' => 'job_title',
        'email' => 'email',
        'phone', 'contact_phone' => 'contact_phone',
        'industry' => 'industry',
        'company_name' => 'company_name',
        'employee_size', 'company_size' => 'company_size',
        'country' => 'country',
        'prospect_linkedin_link', 'linkedin_link' => 'linkedin_link',
        'company_linkedin_link', 'company_linkedin' => 'company_linkedin',
        'software_implementation_timeline' => 'software_implementation_timeline',
        'company_domain' => 'company_domain',
        default => null,
    };
}

function dfDuplicatePreloadCountsForCampaign(int $campaignId, array $rules): array {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0 || empty($rules)) return [];

    $conn = getDbConnection();
    $baseCounts = [];

    $colToKeys = [];
    $customKeys = [];
    foreach ($rules as $ruleKey => $rule) {
        $col = dfDuplicateStorageColumnForKey((string)$ruleKey);
        if ($col) {
            $colToKeys[$col] ??= [];
            $colToKeys[$col][] = (string)$ruleKey;
        } else {
            $customKeys[] = (string)$ruleKey;
        }
    }

    foreach ($colToKeys as $col => $keys) {
        if (!preg_match('/^[a-z_]+$/', (string)$col)) continue;
        $sql = "SELECT LOWER(TRIM(`$col`)) AS v, COUNT(*) AS c
                FROM leads
                WHERE campaign_id = ? AND `$col` IS NOT NULL AND TRIM(`$col`) <> ''
                GROUP BY LOWER(TRIM(`$col`))";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $v = (string)($r['v'] ?? '');
            if ($v === '') continue;
            $c = (int)($r['c'] ?? 0);
            foreach ($keys as $k) {
                $baseCounts[$k] ??= [];
                $baseCounts[$k][$v] = $c;
            }
        }
        $stmt->close();
    }

    if (!empty($customKeys)) {
        $sql = "
            SELECT fs.data_json
            FROM form_submissions fs
            JOIN (
                SELECT lead_id, MAX(id) AS max_id
                FROM form_submissions
                WHERE campaign_id = ? AND lead_id IS NOT NULL
                GROUP BY lead_id
            ) m ON m.max_id = fs.id
            WHERE fs.campaign_id = ?
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $campaignId, $campaignId);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $j = (string)($r['data_json'] ?? '');
                if ($j === '') continue;
                $data = json_decode($j, true);
                if (!is_array($data)) continue;
                foreach ($customKeys as $k) {
                    if (!array_key_exists($k, $data)) continue;
                    $vn = dfNormalizeDuplicateValue($k, $data[$k]);
                    if ($vn === '') continue;
                    $baseCounts[$k] ??= [];
                    $baseCounts[$k][$vn] = (int)($baseCounts[$k][$vn] ?? 0) + 1;
                }
            }
            $stmt->close();
        }
    }

    return $baseCounts;
}

function dfDuplicateValidateInput(int $campaignId, array $rules, array $input, array $baseCounts = [], array $batchCounts = []): array {
    $errors = [];
    if ($campaignId <= 0 || empty($rules)) return ['ok' => true];

    $normInput = [];
    foreach ($input as $k => $v) {
        $nk = dfNormalizeFieldKey((string)$k);
        if ($nk === '') continue;
        $normInput[$nk] = $v;
    }

    foreach ($rules as $ruleKey => $rule) {
        $rk = dfNormalizeFieldKey((string)$ruleKey);
        $rawVal = $normInput[$rk] ?? null;
        $vn = dfNormalizeDuplicateValue($rk, $rawVal);
        if ($vn === '') continue;

        $existing = (int)($baseCounts[$rk][$vn] ?? 0);
        $inFile = (int)($batchCounts[$rk][$vn] ?? 0);
        $limit = (int)($rule['limit'] ?? 1);
        if ($limit < 1) $limit = 1;

        $current = $existing + $inFile;
        if ($current >= $limit) {
            $label = (string)($rule['label'] ?? $rk);
            $msg = "Duplicate Rule Failed\n\nField: {$label}\nValue: {$vn}\nAllowed Count: {$limit}\nCurrent Count: {$current}";
            return [
                'ok' => false,
                'field_key' => $rk,
                'field_label' => $label,
                'value' => $vn,
                'allowed' => $limit,
                'existing' => $existing,
                'in_file' => $inFile,
                'message' => $msg,
            ];
        }
    }

    return ['ok' => true];
}

function dfDuplicateIncrementBatchCounts(array $rules, array $input, array &$batchCounts): void {
    $normInput = [];
    foreach ($input as $k => $v) {
        $nk = dfNormalizeFieldKey((string)$k);
        if ($nk === '') continue;
        $normInput[$nk] = $v;
    }
    foreach ($rules as $ruleKey => $rule) {
        $rk = dfNormalizeFieldKey((string)$ruleKey);
        $vn = dfNormalizeDuplicateValue($rk, $normInput[$rk] ?? null);
        if ($vn === '') continue;
        if (!isset($batchCounts[$rk])) $batchCounts[$rk] = [];
        $batchCounts[$rk][$vn] = (int)($batchCounts[$rk][$vn] ?? 0) + 1;
    }
}

function extractDomain(string $value): string {
    $v = trim($value);
    if ($v === '') return '';
    if (strpos($v, '@') !== false) {
        $parts = explode('@', $v);
        $v = trim((string)end($parts));
    }
    $v = preg_replace('/^https?:\/\//i', '', $v);
    $v = preg_replace('/^www\./i', '', $v);
    $v = preg_replace('/\/.*$/', '', $v);
    $v = preg_replace('/:.*/', '', $v);
    return strtolower(trim($v));
}

function findClientDomainSuppressionLead(int $campaignId, string $domain, int $days = 90): ?array {
    $domain = extractDomain($domain);
    if ($campaignId <= 0 || $domain === '') return null;
    $clientId = (int)(getCampaignClientId($campaignId) ?? 0);
    if ($clientId <= 0) return null;
    $conn = getDbConnection();
    $sql = "
        SELECT l.id, l.lead_id, l.company_name, l.email, l.qa_updated_at, l.created_at
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE d.client_id = ?
          AND l.client_delivery_status = 'Delivered'
          AND l.company_domain = ?
          AND COALESCE(l.qa_updated_at, l.created_at) >= (NOW() - INTERVAL ? DAY)
        ORDER BY COALESCE(l.qa_updated_at, l.created_at) DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('isi', $clientId, $domain, $days);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function findDuplicateSalesLeads(array $data, int $excludeId = 0, int $limit = 10): array {
    $conn = getDbConnection();
    $companyName = trim((string)($data['company_name'] ?? ''));
    $websiteDomain = extractDomain((string)($data['website'] ?? ($data['website_domain'] ?? '')));
    $emailDomain = extractDomain((string)($data['contact_email'] ?? ($data['contact_email_domain'] ?? '')));
    $linkedin = trim((string)($data['linkedin_url'] ?? ''));
    $country = trim((string)($data['country'] ?? ''));
    $ownerId = (int)($data['owner_id'] ?? 0);
    $scopeUserIds = $data['scope_user_ids'] ?? null;
    if (is_array($scopeUserIds)) {
        $scopeUserIds = array_values(array_filter(array_map('intval', $scopeUserIds), fn($v) => $v > 0));
        if (empty($scopeUserIds)) return [];
        if ($ownerId > 0 && !in_array($ownerId, $scopeUserIds, true)) return [];
    } else {
        $scopeUserIds = null;
    }

    $where = [];
    $params = [];
    $types = '';

    if ($excludeId > 0) {
        $where[] = "sl.id <> ?";
        $params[] = $excludeId;
        $types .= 'i';
    }

    $dupParts = [];
    if ($websiteDomain !== '') { $dupParts[] = "sl.website_domain = ?"; $params[] = $websiteDomain; $types .= 's'; }
    if ($companyName !== '') { $dupParts[] = "sl.company_name LIKE ?"; $params[] = '%'.$companyName.'%'; $types .= 's'; }
    if ($emailDomain !== '') { $dupParts[] = "sl.contact_email_domain = ?"; $params[] = $emailDomain; $types .= 's'; }
    if ($linkedin !== '') { $dupParts[] = "sl.linkedin_url = ?"; $params[] = $linkedin; $types .= 's'; }

    if (empty($dupParts)) return [];
    $where[] = '(' . implode(' OR ', $dupParts) . ')';

    if ($country !== '') {
        $where[] = "sl.country = ?";
        $params[] = $country;
        $types .= 's';
    }
    if ($ownerId > 0) {
        $where[] = "sl.owner_id = ?";
        $params[] = $ownerId;
        $types .= 'i';
    } elseif (is_array($scopeUserIds)) {
        $in = implode(',', array_fill(0, count($scopeUserIds), '?'));
        $where[] = "sl.owner_id IN ($in)";
        $params = array_merge($params, $scopeUserIds);
        $types .= str_repeat('i', count($scopeUserIds));
    }

    $sql = "SELECT sl.id, sl.company_name, sl.website, sl.contact_email, sl.linkedin_url, sl.status, sl.country, sl.owner_id,
                   o.full_name AS owner_name, o.role AS owner_role, o.job_title AS owner_job_title,
                   sl.created_at
            FROM sales_leads sl
            LEFT JOIN users o ON o.id = sl.owner_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sl.created_at DESC
            LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rs = $stmt->get_result();
    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function getSalesLeadById(int $id): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT sl.*,
        o.full_name AS owner_name, o.role AS owner_role, o.job_title AS owner_job_title,
        m.full_name AS manager_name, m.role AS manager_role, m.job_title AS manager_job_title,
        c.id AS client_id, c.client_code AS client_code, c.name AS client_name
        FROM sales_leads sl
        LEFT JOIN users o ON o.id = sl.owner_id
        LEFT JOIN users m ON m.id = sl.sales_manager_id
        LEFT JOIN clients c ON c.id = sl.client_id
        WHERE sl.id = ?
        LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getSalesLeadActivities(int $salesLeadId, int $limit = 200): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT a.*,
        u.full_name AS created_by_name, u.role AS created_by_role, u.job_title AS created_by_job_title,
        uu.full_name AS updated_by_name, uu.role AS updated_by_role, uu.job_title AS updated_by_job_title
        FROM sales_lead_activities a
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN users uu ON uu.id = a.updated_by
        WHERE a.sales_lead_id = ?
        ORDER BY a.created_at DESC
        LIMIT " . (int)$limit);
    $stmt->bind_param('i', $salesLeadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getSalesDirectorUserIds(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id FROM users WHERE role = 'sales_director' ORDER BY id");
    $ids = [];
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
    }
    $cache = array_keys($ids);
    return $cache;
}

function getSalesManagerForSdr(int $sdrUserId): ?int {
    $sdrUserId = (int)$sdrUserId;
    if ($sdrUserId <= 0) return null;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT sm.manager_user_id
        FROM sales_manager_sdr_map sm
        JOIN users u ON u.id = sm.manager_user_id
        WHERE sm.sdr_user_id = ?
          AND u.role IN ('sales_manager','sales_director')
        ORDER BY sm.id DESC
        LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $sdrUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $mid = (int)($row['manager_user_id'] ?? 0);
    return $mid > 0 ? $mid : null;
}

function getNormalizedSalesRoleByUserId(int $userId): string {
    static $cache = [];
    $userId = (int)$userId;
    if ($userId <= 0) return '';
    if (isset($cache[$userId])) return $cache[$userId];
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $role = function_exists('normalizeRole') ? normalizeRole((string)($row['role'] ?? '')) : (string)($row['role'] ?? '');
    $cache[$userId] = strtolower(trim($role));
    return $cache[$userId];
}

function isValidSalesManagerUserId(?int $managerUserId): bool {
    $managerUserId = (int)$managerUserId;
    if ($managerUserId <= 0) return false;
    $role = getNormalizedSalesRoleByUserId($managerUserId);
    return in_array($role, ['sales_manager','sales_director'], true);
}

function resolveSalesManagerAssignment(int $ownerId, mixed $requestedManagerId, int $actorUserId): ?int {
    $ownerId = (int)$ownerId;
    $actorUserId = (int)$actorUserId;
    $requestedManagerId = ($requestedManagerId !== null && $requestedManagerId !== '' && (int)$requestedManagerId > 0)
        ? (int)$requestedManagerId
        : null;

    $actorRole = getNormalizedSalesRoleByUserId($actorUserId);
    $ownerRole = getNormalizedSalesRoleByUserId($ownerId);

    if ($ownerRole === 'sdr') {
        // SDR-owned leads should inherit their current reporting manager from the mapping table.
        return getSalesManagerForSdr($ownerId);
    }

    if ($actorRole === 'sales_manager' && $actorUserId > 0) {
        return $actorUserId;
    }

    if ($requestedManagerId !== null && !isValidSalesManagerUserId($requestedManagerId)) {
        throw new RuntimeException('Invalid sales manager');
    }

    if ($ownerRole === 'sales_manager' && $requestedManagerId === null) {
        return $ownerId > 0 ? $ownerId : null;
    }

    return $requestedManagerId;
}

function getCurrentSalesSdrIdsForManager(int $managerUserId): array {
    $managerUserId = (int)$managerUserId;
    if ($managerUserId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT sm.sdr_user_id
        FROM sales_manager_sdr_map sm
        JOIN (
            SELECT sdr_user_id, MAX(id) AS max_id
            FROM sales_manager_sdr_map
            GROUP BY sdr_user_id
        ) latest ON latest.max_id = sm.id
        WHERE sm.manager_user_id = ?
        ORDER BY sm.sdr_user_id");
    if (!$stmt) return [];
    $stmt->bind_param('i', $managerUserId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $ids = [];
    foreach ($rows as $r) {
        $sid = (int)($r['sdr_user_id'] ?? 0);
        if ($sid > 0) $ids[$sid] = true;
    }
    return array_keys($ids);
}

function shouldIncludeAdminsInSalesNotifications(): bool {
    $v = (string)(function_exists('getAppSetting') ? (getAppSetting('notifications.sales_include_admins', '1') ?? '1') : '1');
    return trim($v) !== '0';
}

function getSalesNotificationRecipientsForLeadRow(array $lead, array $opts = []): array {
    $ids = [];
    $leadId = (int)($lead['id'] ?? 0);
    $ownerId = (int)($lead['owner_id'] ?? 0);
    $createdBy = (int)($lead['created_by'] ?? 0);
    $managerId = (int)($lead['sales_manager_id'] ?? 0);

    if ($createdBy > 0) $ids[$createdBy] = true;
    if ($ownerId > 0) $ids[$ownerId] = true;

    if ($managerId <= 0 && $ownerId > 0) {
        $m = getSalesManagerForSdr($ownerId);
        if ($m) $managerId = (int)$m;
    }
    if ($managerId > 0) $ids[$managerId] = true;

    foreach (getSalesDirectorUserIds() as $did) {
        $did = (int)$did;
        if ($did > 0) $ids[$did] = true;
    }

    $includeAdmins = array_key_exists('include_admins', $opts) ? !empty($opts['include_admins']) : shouldIncludeAdminsInSalesNotifications();
    if ($includeAdmins) {
        $conn = getDbConnection();
        $rs = $conn->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id");
        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                $aid = (int)($r['id'] ?? 0);
                if ($aid > 0) $ids[$aid] = true;
            }
        }
    }

    $extra = $opts['extra_user_ids'] ?? [];
    if (is_array($extra)) {
        foreach ($extra as $x) {
            $x = (int)$x;
            if ($x > 0) $ids[$x] = true;
        }
    }

    $exclude = $opts['exclude_user_id'] ?? 0;
    $exclude = (int)$exclude;
    if ($exclude > 0) unset($ids[$exclude]);

    if ($leadId > 0) {
        $company = trim((string)($lead['company_name'] ?? ''));
        $title = trim((string)($opts['title'] ?? ''));
        if ($company !== '' && $title !== '') {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT DISTINCT created_by FROM sales_lead_activities WHERE sales_lead_id = ? ORDER BY created_at DESC LIMIT 50");
            if ($stmt) {
                $stmt->bind_param('i', $leadId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt->close();
                foreach ($rows as $r) {
                    $uid = (int)($r['created_by'] ?? 0);
                    if ($uid > 0) $ids[$uid] = true;
                }
            }
        }
    }

    return array_keys($ids);
}

function notifySalesLeadUsers(int $salesLeadId, string $type, string $title, ?string $message, string $link, array $opts = []): void {
    $lead = getSalesLeadById($salesLeadId);
    if (!$lead) return;
    $opts['title'] = $title;
    $recipients = getSalesNotificationRecipientsForLeadRow($lead, $opts);
    if (empty($recipients)) return;
    foreach ($recipients as $uid) {
        createNotificationSmart((int)$uid, $type, $title, $message, $link, $opts);
    }
}

function updateSalesLeadActivity(int $activityId, string $status, string $comment, int $updatedBy): bool {
    $conn = getDbConnection();
    $status = trim($status);
    $comment = trim($comment);
    if ($comment === '') throw new RuntimeException('Comment is required');

    $leadId = 0;
    $stmt0 = $conn->prepare("SELECT sales_lead_id FROM sales_lead_activities WHERE id = ? LIMIT 1");
    if ($stmt0) {
        $stmt0->bind_param('i', $activityId);
        $stmt0->execute();
        $row0 = $stmt0->get_result()->fetch_assoc() ?: [];
        $stmt0->close();
        $leadId = (int)($row0['sales_lead_id'] ?? 0);
    }

    $stmt = $conn->prepare("UPDATE sales_lead_activities
        SET status = ?, comment = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?");
    $stmt->bind_param('ssii', $status, $comment, $updatedBy, $activityId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $leadId > 0) {
        $lead = getSalesLeadById($leadId);
        $company = $lead ? trim((string)($lead['company_name'] ?? '')) : '';
        $titleN = 'Follow-up Updated';
        $body = ($company !== '' ? ($company . ' · ') : '') . $comment;
        notifySalesLeadUsers($leadId, 'sales.followup.updated', $titleN, $body, '../sales/lead-view?id=' . (int)$leadId, [
            'triggered_by_user_id' => $updatedBy,
            'importance' => 'normal',
            'show_toast' => true,
            'dedup_key' => 'sales_followup_updated:' . $leadId . ':' . $activityId,
            'dedup_window_min' => 60,
            'exclude_user_id' => $updatedBy,
        ]);
    }
    return $ok;
}

function getUsdInrRate(?string $date = null): ?float {
    $conn = getDbConnection();
    $date = $date ?: date('Y-m-d');
    $stmt = $conn->prepare("SELECT usd_inr FROM fx_rates WHERE rate_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return null;
    $v = (float)($row['usd_inr'] ?? 0);
    return $v > 0 ? $v : null;
}

function setUsdInrRate(string $date, float $rate, int $updatedBy): bool {
    $conn = getDbConnection();
    $date = date('Y-m-d', strtotime($date));
    $rate = (float)$rate;
    if ($rate <= 0) throw new RuntimeException('Invalid USD→INR rate');
    $stmt = $conn->prepare("INSERT INTO fx_rates (rate_date, usd_inr, updated_by, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE usd_inr = VALUES(usd_inr), updated_by = VALUES(updated_by), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('sdi', $date, $rate, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getLatestUsdInrRateRecord(?string $date = null): ?array {
    $conn = getDbConnection();
    $date = $date ?: date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT rate_date, usd_inr, updated_by, updated_at
        FROM fx_rates
        WHERE rate_date <= ?
        ORDER BY rate_date DESC
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ?: null;
}

function fetchUsdInrRateFromGoogleFinance(): array {
    $urls = [
        'https://www.google.com/finance/quote/USD-INR',
        'https://www.google.com/finance/quote/USD-INR?hl=en',
        'https://www.google.com/finance/quote/USD-INR?window=1D',
    ];
    $url = $urls[0];
    $out = [
        'ok' => false,
        'rate' => null,
        'source' => 'google_finance',
        'http_status' => null,
        'message' => null,
        'effective_url' => $url,
    ];

    foreach ($urls as $candidateUrl) {
        if (function_exists('isSafeExternalUrl') && !isSafeExternalUrl($candidateUrl)) {
            $out['message'] = 'Google Finance URL blocked by safe URL policy.';
            continue;
        }

        $body = '';
        $http = null;
        $headers = [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($candidateUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'DemandFlowBridgeBot/1.0',
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = (string)curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            if ($effectiveUrl !== '') $out['effective_url'] = $effectiveUrl;
            if (curl_errno($ch)) $out['message'] = curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 8,
                    'header' => implode("\r\n", [
                        'User-Agent: DemandFlowBridgeBot/1.0',
                        'Accept: text/html,application/xhtml+xml',
                        'Accept-Language: en-US,en;q=0.9',
                        'Cache-Control: no-cache',
                    ]) . "\r\n",
                ],
            ]);
            $body = (string)@file_get_contents($candidateUrl, false, $ctx);
            $responseHeaders = $http_response_header ?? [];
            if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $m)) {
                $http = (int)$m[1];
            }
            if ($body === '') $out['message'] = 'Empty response from Google Finance.';
        }

        $out['http_status'] = $http;
        if ($body === '' || ($http !== null && ($http < 200 || $http >= 400))) {
            if ($out['message'] === null) $out['message'] = $http ? ('HTTP ' . $http) : 'Unable to fetch Google Finance quote.';
            continue;
        }

        $normalized = str_replace(['<br>', '<br/>', '<br />', '</div>', '</p>', '</li>', '</tr>'], "\n", $body);
        $text = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));

        $patterns = [
            ['subject' => $text, 'pattern' => '/USD\s*\/\s*INR\s+United States Dollar\s*\/\s*Indian Rupee\s+([0-9]{1,3}(?:\.[0-9]{2,6})?)/i'],
            ['subject' => $text, 'pattern' => '/United States Dollar\s*\/\s*Indian Rupee\s+([0-9]{1,3}(?:\.[0-9]{2,6})?)\s+[+\-]?[0-9.,%() ]+\s+1D/i'],
            ['subject' => $text, 'pattern' => '/USD\s*\/\s*INR.*?Prev\.\s*close\s+[0-9]{1,3}(?:\.[0-9]{2,6})?.*?([0-9]{1,3}(?:\.[0-9]{2,6})?)/i'],
            ['subject' => $body, 'pattern' => '/USD\s*\/\s*INR[\s\S]{0,2000}?([0-9]{1,3}(?:\.[0-9]{2,6})?)/i'],
            ['subject' => $body, 'pattern' => '/United States Dollar\s*\/\s*Indian Rupee[\s\S]{0,2000}?([0-9]{1,3}(?:\.[0-9]{2,6})?)/i'],
        ];

        foreach ($patterns as $spec) {
            if (preg_match((string)$spec['pattern'], (string)$spec['subject'], $m)) {
                $rate = (float)str_replace(',', '', (string)($m[1] ?? ''));
                if ($rate > 40 && $rate < 120) {
                    $out['ok'] = true;
                    $out['rate'] = $rate;
                    $out['message'] = null;
                    return $out;
                }
            }
        }
    }
    $out['message'] = 'Unable to parse USD/INR quote from Google Finance.';
    return $out;
}

function fetchUsdInrRateFromFrankfurter(?string $date = null): array {
    $dateTs = $date ? strtotime($date) : false;
    $date = $dateTs ? date('Y-m-d', $dateTs) : date('Y-m-d');
    $today = date('Y-m-d');
    $path = $date === $today ? 'latest' : $date;
    $url = 'https://api.frankfurter.app/' . $path . '?from=USD&to=INR';

    $out = [
        'ok' => false,
        'rate' => null,
        'source' => 'frankfurter',
        'http_status' => null,
        'message' => null,
        'effective_url' => $url,
    ];

    if (function_exists('isSafeExternalUrl') && !isSafeExternalUrl($url)) {
        $out['message'] = 'Frankfurter URL blocked by safe URL policy.';
        return $out;
    }

    $body = '';
    $http = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'DemandFlowBridgeBot/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Cache-Control: no-cache',
            ],
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($effectiveUrl !== '') $out['effective_url'] = $effectiveUrl;
        if (curl_errno($ch)) $out['message'] = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => implode("\r\n", [
                    'User-Agent: DemandFlowBridgeBot/1.0',
                    'Accept: application/json',
                    'Cache-Control: no-cache',
                ]) . "\r\n",
            ],
        ]);
        $body = (string)@file_get_contents($url, false, $ctx);
        $responseHeaders = $http_response_header ?? [];
        if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $m)) {
            $http = (int)$m[1];
        }
        if ($body === '') $out['message'] = 'Empty response from Frankfurter.';
    }

    $out['http_status'] = $http;
    if ($body === '' || ($http !== null && ($http < 200 || $http >= 400))) {
        if ($out['message'] === null) $out['message'] = $http ? ('HTTP ' . $http) : 'Unable to fetch fallback USD/INR quote.';
        return $out;
    }

    $json = json_decode($body, true);
    $rate = (float)($json['rates']['INR'] ?? 0);
    if ($rate <= 40 || $rate >= 120) {
        $out['message'] = 'Fallback USD/INR quote response did not contain a valid INR rate.';
        return $out;
    }

    $out['ok'] = true;
    $out['rate'] = $rate;
    $out['message'] = null;
    return $out;
}

function ensureUsdInrRateForDate(string $date, int $updatedBy = 0, bool $forceRefresh = false): array {
    $dateTs = strtotime($date);
    $date = $dateTs ? date('Y-m-d', $dateTs) : date('Y-m-d');
    $today = date('Y-m-d');
    $existing = getUsdInrRate($date);

    if ($existing !== null && !$forceRefresh) {
        return [
            'ok' => true,
            'rate' => $existing,
            'status' => 'cached',
            'rate_date' => $date,
            'source' => 'database',
            'message' => null,
        ];
    }

    $fetched = $date === $today
        ? fetchUsdInrRateFromGoogleFinance()
        : ['ok' => false, 'rate' => null, 'source' => 'google_finance', 'message' => 'Historical Google Finance sync not supported.'];

    if (empty($fetched['ok']) || empty($fetched['rate'])) {
        $fetched = fetchUsdInrRateFromFrankfurter($date);
        if (!empty($fetched['ok']) && !empty($fetched['rate'])) {
            $rate = (float)$fetched['rate'];
            if (!setUsdInrRate($date, $rate, max(0, $updatedBy))) {
                return [
                    'ok' => false,
                    'rate' => $existing,
                    'status' => 'error',
                    'rate_date' => $date,
                    'source' => (string)($fetched['source'] ?? 'frankfurter'),
                    'message' => 'Fallback USD/INR quote fetched but could not be stored in fx_rates.',
                ];
            }

            return [
                'ok' => true,
                'rate' => $rate,
                'status' => $existing === null ? 'fetched_fallback' : 'refreshed_fallback',
                'rate_date' => $date,
                'source' => (string)($fetched['source'] ?? 'frankfurter'),
                'message' => null,
            ];
        }
    }

    if (empty($fetched['ok']) || empty($fetched['rate'])) {
        return [
            'ok' => false,
            'rate' => $existing,
            'status' => 'error',
            'rate_date' => $date,
            'source' => (string)($fetched['source'] ?? 'google_finance'),
            'message' => (string)($fetched['message'] ?? 'Unable to refresh USD/INR from the configured sources.'),
        ];
    }

    $rate = (float)$fetched['rate'];
    if (!setUsdInrRate($date, $rate, max(0, $updatedBy))) {
        return [
            'ok' => false,
            'rate' => $existing,
            'status' => 'error',
            'rate_date' => $date,
            'source' => 'google_finance',
            'message' => 'USD/INR quote fetched but could not be stored in fx_rates.',
        ];
    }

    return [
        'ok' => true,
        'rate' => $rate,
        'status' => $existing === null ? 'fetched' : 'refreshed',
        'rate_date' => $date,
        'source' => 'google_finance',
        'message' => null,
    ];
}

function upsertSalesMonthlyTarget(int $userId, int $year, int $month, int $newAccountsTarget, float $revenueTargetUsd, int $assignedBy): bool {
    $conn = getDbConnection();
    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));
    $newAccountsTarget = max(0, $newAccountsTarget);
    $revenueTargetUsd = max(0.0, (float)$revenueTargetUsd);

    $stmt = $conn->prepare("INSERT INTO sales_targets (user_id, year, month, target_new_accounts, target_revenue_usd, assigned_by, assigned_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            target_new_accounts = VALUES(target_new_accounts),
            target_revenue_usd = VALUES(target_revenue_usd),
            assigned_by = VALUES(assigned_by),
            assigned_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iiiidi', $userId, $year, $month, $newAccountsTarget, $revenueTargetUsd, $assignedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getSalesMonthlyTarget(int $userId, int $year, int $month): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM sales_targets WHERE user_id = ? AND year = ? AND month = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getSalesCommissionPercentForUser(int $userId): float {
    $userId = (int)$userId;
    if ($userId <= 0) return 0.0;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT commission_percent FROM sales_commission_settings WHERE user_id = ? LIMIT 1");
    if (!$stmt) return 0.0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $pct = (float)($row['commission_percent'] ?? 0.0);
    if ($pct < 0) $pct = 0.0;
    if ($pct > 100) $pct = 100.0;
    return $pct;
}

function setSalesCommissionPercentForUser(int $userId, float $commissionPercent, int $updatedBy): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $pct = (float)$commissionPercent;
    if ($pct < 0) $pct = 0.0;
    if ($pct > 100) $pct = 100.0;
    $upd = $updatedBy > 0 ? (int)$updatedBy : null;
    $stmt = $conn->prepare("INSERT INTO sales_commission_settings (user_id, commission_percent, updated_by, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE commission_percent = VALUES(commission_percent), updated_by = VALUES(updated_by), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('idi', $userId, $pct, $upd);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getSalesCommissionPercentMap(array $userIds): array {
    $userIds = array_values(array_filter(array_map('intval', $userIds), fn($v) => $v > 0));
    if (empty($userIds)) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    $stmt = $conn->prepare("SELECT user_id, commission_percent FROM sales_commission_settings WHERE user_id IN ($in)");
    if (!$stmt) return [];
    mysqliBindParams($stmt, $types, $userIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $out = [];
    foreach ($rows as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        $pct = (float)($r['commission_percent'] ?? 0.0);
        if ($pct < 0) $pct = 0.0;
        if ($pct > 100) $pct = 100.0;
        if ($uid > 0) $out[$uid] = $pct;
    }
    return $out;
}

function getSalesOwnerForClient(int $clientId): array {
    $clientId = (int)$clientId;
    if ($clientId <= 0) return ['owner_id' => null, 'manager_id' => null];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT owner_id, manager_id FROM sales_client_ownership WHERE client_id = ? LIMIT 1");
    if (!$stmt) return ['owner_id' => null, 'manager_id' => null];
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $oid = (int)($row['owner_id'] ?? 0);
    $mid = (int)($row['manager_id'] ?? 0);
    return [
        'owner_id' => $oid > 0 ? $oid : null,
        'manager_id' => $mid > 0 ? $mid : null,
    ];
}

function syncSalesCommissionForInvoice(int $invoiceId, int $actorUserId = 0): void {
    $invoiceId = (int)$invoiceId;
    if ($invoiceId <= 0) return;
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, status, issue_date, paid_at, currency, client_id, campaign_id, total, sales_owner_id, sales_manager_id FROM revenue_invoices WHERE id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$inv) return;

    $status = (string)($inv['status'] ?? '');
    $issueDate = (string)($inv['issue_date'] ?? '');
    $paidAt = (string)($inv['paid_at'] ?? '');
    $currency = (string)($inv['currency'] ?? 'USD');
    $clientId = (int)($inv['client_id'] ?? 0);
    $total = (float)($inv['total'] ?? 0.0);
    $salesOwnerId = (int)($inv['sales_owner_id'] ?? 0);
    $salesMgrId = (int)($inv['sales_manager_id'] ?? 0);

    if ($salesOwnerId <= 0 && $clientId > 0) {
        $own = getSalesOwnerForClient($clientId);
        $salesOwnerId = (int)($own['owner_id'] ?? 0);
        $salesMgrId = (int)($own['manager_id'] ?? 0);
        if ($salesOwnerId > 0) {
            $stmt = $conn->prepare("UPDATE revenue_invoices SET sales_owner_id = ?, sales_manager_id = COALESCE(sales_manager_id, ?) WHERE id = ? LIMIT 1");
            if ($stmt) {
                $mgr = $salesMgrId > 0 ? $salesMgrId : null;
                $stmt->bind_param('iii', $salesOwnerId, $mgr, $invoiceId);
                @$stmt->execute();
                $stmt->close();
            }
        }
    }

    $salesOwnerId = (int)$salesOwnerId;
    if ($salesOwnerId <= 0 || $total <= 0) {
        return;
    }

    $pct = getSalesCommissionPercentForUser($salesOwnerId);
    if ($pct <= 0) return;
    $commission = round($total * ($pct / 100.0), 2);

    $cStatus = 'pending';
    if ($status === 'Paid') $cStatus = 'paid';
    if ($status === 'Cancelled') $cStatus = 'cancelled';

    $paidAtVal = ($status === 'Paid' && $paidAt !== '') ? $paidAt : null;
    $issueVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) ? $issueDate : null;

    $createdBy = $actorUserId > 0 ? (int)$actorUserId : null;
    $stmt = $conn->prepare("
        INSERT INTO sales_commissions
          (invoice_id, sales_user_id, commission_percent, billable_total, commission_amount, currency, status, invoice_status, issue_date, paid_at, created_by, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          commission_percent = VALUES(commission_percent),
          billable_total = VALUES(billable_total),
          commission_amount = VALUES(commission_amount),
          currency = VALUES(currency),
          status = VALUES(status),
          invoice_status = VALUES(invoice_status),
          issue_date = VALUES(issue_date),
          paid_at = VALUES(paid_at),
          updated_at = NOW()
    ");
    if (!$stmt) return;
    $stmt->bind_param(
        'iiddssssssi',
        $invoiceId,
        $salesOwnerId,
        $pct,
        $total,
        $commission,
        $currency,
        $cStatus,
        $status,
        $issueVal,
        $paidAtVal,
        $createdBy
    );
    @$stmt->execute();
    $stmt->close();
}

function getSalesTargetAssignableUsers(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, job_title, role FROM users WHERE role IN ('sales_director','sales_manager','sdr') ORDER BY FIELD(role, 'sales_director', 'sales_manager', 'sdr'), full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function getSalesScopeUserIdsForCurrentUser(array $user): ?array {
    $role = function_exists('normalizeRole') ? normalizeRole((string)($user['role'] ?? '')) : (string)($user['role'] ?? '');
    $role = strtolower(trim($role));
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'admin' || $role === 'sales_director') return null;
    if ($role === 'sdr') return [$userId];
    if ($role === 'sales_manager') {
        $ids = [$userId => true];
        foreach (getCurrentSalesSdrIdsForManager($userId) as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $ids[$sid] = true;
        }
        return array_keys($ids);
    }
    return [$userId];
}

function getSalesMonthlyTargetSum(?array $scopeUserIds, int $year, int $month): array {
    $conn = getDbConnection();
    $year = max(2000, min(2100, (int)$year));
    $month = max(1, min(12, (int)$month));

    if ($scopeUserIds === null) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(target_new_accounts),0) AS target_new_accounts, COALESCE(SUM(target_revenue_usd),0) AS target_revenue_usd, COUNT(*) AS rows_cnt
            FROM sales_targets WHERE year = ? AND month = ?");
        if (!$stmt) return ['target_new_accounts' => 0, 'target_revenue_usd' => 0.0, 'rows_cnt' => 0];
        $stmt->bind_param('ii', $year, $month);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return [
            'target_new_accounts' => (int)($row['target_new_accounts'] ?? 0),
            'target_revenue_usd' => (float)($row['target_revenue_usd'] ?? 0.0),
            'rows_cnt' => (int)($row['rows_cnt'] ?? 0),
        ];
    }

    $ids = array_values(array_filter(array_map('intval', $scopeUserIds), fn($v) => $v > 0));
    if (empty($ids)) return ['target_new_accounts' => 0, 'target_revenue_usd' => 0.0, 'rows_cnt' => 0];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = 'ii' . str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT COALESCE(SUM(target_new_accounts),0) AS target_new_accounts, COALESCE(SUM(target_revenue_usd),0) AS target_revenue_usd, COUNT(*) AS rows_cnt
        FROM sales_targets WHERE year = ? AND month = ? AND user_id IN ($in)");
    if (!$stmt) return ['target_new_accounts' => 0, 'target_revenue_usd' => 0.0, 'rows_cnt' => 0];
    $params = array_merge([$year, $month], $ids);
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'target_new_accounts' => (int)($row['target_new_accounts'] ?? 0),
        'target_revenue_usd' => (float)($row['target_revenue_usd'] ?? 0.0),
        'rows_cnt' => (int)($row['rows_cnt'] ?? 0),
    ];
}

function getSalesMonthlyTargetForUserScope(array $user, int $year, int $month): array {
    $scope = getSalesScopeUserIdsForCurrentUser($user);
    return getSalesMonthlyTargetSum($scope, $year, $month);
}

function getSalesNewAccountsProgress(array $user, int $year, int $month): array {
    $conn = getDbConnection();
    $scope = getSalesScopeUserIdsForCurrentUser($user);
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
    if ($scope === null) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM sales_client_ownership WHERE assigned_at >= ? AND assigned_at < ?");
        if ($stmt) {
            $stmt->bind_param('ss', $start, $end);
            $stmt->execute();
            $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();
            return ['achieved' => $cnt];
        }
        return ['achieved' => 0];
    }
    if (empty($scope)) return ['achieved' => 0];
    $in = implode(',', array_fill(0, count($scope), '?'));
    $sql = "SELECT COUNT(*) AS cnt
        FROM sales_client_ownership sco
        WHERE sco.assigned_at >= ? AND sco.assigned_at < ?
          AND (sco.owner_id IN ($in) OR sco.manager_id IN ($in))";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = 'ss' . str_repeat('i', count($scope)) . str_repeat('i', count($scope));
        $params = array_merge([$start, $end], $scope, $scope);
        mysqliBindParams($stmt, $types, $params);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        return ['achieved' => $cnt];
    }
    return ['achieved' => 0];
}

function getSalesRevenueProgress(array $user): array {
    $conn = getDbConnection();
    $scope = getSalesScopeUserIdsForCurrentUser($user);
    $clientIds = [];
    if ($scope === null) {
        $rs = $conn->query("SELECT client_id FROM sales_client_ownership");
        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                $cid = (int)($r['client_id'] ?? 0);
                if ($cid > 0) $clientIds[$cid] = true;
            }
        }
    } else {
        if (!empty($scope)) {
            $in = implode(',', array_fill(0, count($scope), '?'));
            $sql = "SELECT DISTINCT client_id
                FROM sales_client_ownership
                WHERE owner_id IN ($in) OR manager_id IN ($in)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($scope)) . str_repeat('i', count($scope));
                $params = array_merge($scope, $scope);
                mysqliBindParams($stmt, $types, $params);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($r = $rs->fetch_assoc()) {
                    $cid = (int)($r['client_id'] ?? 0);
                    if ($cid > 0) $clientIds[$cid] = true;
                }
                $stmt->close();
            }
        }
    }

    $allocated = 0.0;
    $generated = 0.0;
    $campaignCount = 0;
    $clientCount = count($clientIds);

    foreach (array_keys($clientIds) as $cid) {
        $stmt = $conn->prepare("SELECT c.id AS campaign_id, d.total_leads, d.cpl
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE d.client_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $r) {
                $campaignId = (int)($r['campaign_id'] ?? 0);
                $cpl = (float)($r['cpl'] ?? 0);
                $tl = (int)($r['total_leads'] ?? 0);
                if ($campaignId <= 0 || $cpl <= 0) continue;
                $campaignCount++;
                if ($tl > 0) $allocated += ($cpl * $tl);
                $stats = getCampaignLeadTableStats($campaignId);
                $delivered = (int)($stats['approved'] ?? 0) + (int)($stats['rejected'] ?? 0);
                if ($delivered > 0) $generated += ($cpl * $delivered);
            }
        }
    }

    $pending = max(0.0, $allocated - $generated);
    return [
        'allocated_usd' => $allocated,
        'generated_usd' => $generated,
        'pending_usd' => $pending,
        'clients' => $clientCount,
        'campaigns' => $campaignCount,
    ];
}

function createSalesLead(array $data, int $createdBy): int {
    $conn = getDbConnection();
    $companyName = trim((string)($data['company_name'] ?? ''));
    if ($companyName === '') throw new RuntimeException('Company Name is required');

    $website = trim((string)($data['website'] ?? ''));
    $websiteDomain = extractDomain($website);
    $contactEmail = trim((string)($data['contact_email'] ?? ''));
    $emailDomain = extractDomain($contactEmail);
    $linkedin = trim((string)($data['linkedin_url'] ?? ''));

    $ownerId = (int)($data['owner_id'] ?? $createdBy);
    $managerId = ($data['sales_manager_id'] ?? '') !== '' ? (int)$data['sales_manager_id'] : null;
    $managerId = resolveSalesManagerAssignment($ownerId, $managerId, $createdBy);

    $stmt = $conn->prepare("INSERT INTO sales_leads
        (company_name, website, website_domain, industry, company_size, country,
         contact_name, contact_job_title, contact_email, contact_email_domain, contact_phone, linkedin_url,
         lead_source, status, priority, expected_opportunity_size, notes,
         owner_id, sales_manager_id, created_by, created_at, updated_at, last_activity_at, next_follow_up_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW(),NULL)");

    $expected = ($data['expected_opportunity_size'] ?? '') !== '' ? (string)$data['expected_opportunity_size'] : null;
    $params = [
        $companyName,
        $website,
        $websiteDomain,
        $data['industry'],
        $data['company_size'],
        $data['country'],
        $data['contact_name'],
        $data['contact_job_title'],
        $contactEmail,
        $emailDomain,
        $data['contact_phone'],
        $linkedin,
        $data['lead_source'],
        $data['status'],
        $data['priority'],
        $expected,
        $data['notes'],
        $ownerId,
        $managerId,
        $createdBy
    ];
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Failed to create sales lead');
    $id = (int)$conn->insert_id;
    $stmt->close();
    notifySalesLeadUsers($id, 'sales.prospect.created', 'New Prospect Added', $companyName, '../sales/lead-view?id=' . $id, [
        'triggered_by_user_id' => $createdBy,
        'importance' => 'normal',
        'show_toast' => true,
        'dedup_key' => 'sales_prospect_created:' . $id,
        'dedup_window_min' => 1440,
        'exclude_user_id' => 0,
    ]);
    return $id;
}

function updateSalesLead(int $id, array $data, int $updatedBy): bool {
    $conn = getDbConnection();
    $before = getSalesLeadById($id);
    if (!$before) return false;
    if (array_key_exists('owner_id', $data)) {
        $data['owner_id'] = (int)$data['owner_id'];
    }
    if (array_key_exists('owner_id', $data) || array_key_exists('sales_manager_id', $data)) {
        $effectiveOwnerId = array_key_exists('owner_id', $data) ? (int)$data['owner_id'] : (int)($before['owner_id'] ?? 0);
        $requestedManagerId = array_key_exists('sales_manager_id', $data) ? $data['sales_manager_id'] : ($before['sales_manager_id'] ?? null);
        $data['sales_manager_id'] = resolveSalesManagerAssignment($effectiveOwnerId, $requestedManagerId, $updatedBy);
    }
    $allowed = [
        'company_name','website','industry','company_size','country',
        'contact_name','contact_job_title','contact_email','contact_phone','linkedin_url',
        'lead_source','status','priority','expected_opportunity_size','notes',
        'owner_id','sales_manager_id','next_follow_up_at'
    ];

    $set = [];
    $params = [];
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $data)) continue;
        if ($col === 'website') {
            $set[] = "website = ?";
            $params[] = trim((string)$data['website']);
            $set[] = "website_domain = ?";
            $params[] = extractDomain((string)$data['website']);
            continue;
        }
        if ($col === 'contact_email') {
            $set[] = "contact_email = ?";
            $params[] = trim((string)$data['contact_email']);
            $set[] = "contact_email_domain = ?";
            $params[] = extractDomain((string)$data['contact_email']);
            continue;
        }
        $set[] = "$col = ?";
        $params[] = $data[$col];
    }
    $set[] = "updated_by = ?";
    $params[] = $updatedBy;
    $set[] = "updated_at = NOW()";

    $sql = "UPDATE sales_leads SET ".implode(', ', $set)." WHERE id = ?";
    $params[] = $id;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $before) {
        $after = getSalesLeadById($id) ?: $before;
        $company = trim((string)($after['company_name'] ?? ($before['company_name'] ?? '')));
        $link = '../sales/lead-view?id=' . (int)$id;

        $oldStatus = (string)($before['status'] ?? '');
        $newStatus = (string)($after['status'] ?? $oldStatus);
        if ($oldStatus !== '' && $newStatus !== '' && $oldStatus !== $newStatus) {
            notifySalesLeadUsers($id, 'sales.prospect.status_changed', 'Prospect Status Changed', ($company !== '' ? ($company . ' · ') : '') . $oldStatus . ' → ' . $newStatus, $link, [
                'triggered_by_user_id' => $updatedBy,
                'importance' => 'normal',
                'show_toast' => true,
                'dedup_key' => 'sales_status:' . $id . ':' . sha1($oldStatus . '>' . $newStatus),
                'dedup_window_min' => 60,
                'exclude_user_id' => $updatedBy,
            ]);
        }

        $oldOwner = (int)($before['owner_id'] ?? 0);
        $newOwner = (int)($after['owner_id'] ?? $oldOwner);
        if ($oldOwner > 0 && $newOwner > 0 && $oldOwner !== $newOwner) {
            notifySalesLeadUsers($id, 'sales.prospect.assigned', 'Lead Assigned/Reassigned', ($company !== '' ? ($company . ' · ') : '') . 'Owner changed', $link, [
                'triggered_by_user_id' => $updatedBy,
                'importance' => 'high',
                'show_toast' => true,
                'dedup_key' => 'sales_assigned:' . $id . ':' . $oldOwner . '>' . $newOwner,
                'dedup_window_min' => 1440,
                'exclude_user_id' => $updatedBy,
                'extra_user_ids' => [$oldOwner, $newOwner],
            ]);
        }

        $oldMgr = (int)($before['sales_manager_id'] ?? 0);
        $newMgr = (int)($after['sales_manager_id'] ?? $oldMgr);
        if ($newMgr !== $oldMgr) {
            notifySalesLeadUsers($id, 'sales.prospect.updated', 'Prospect Updated', ($company !== '' ? ($company . ' · ') : '') . 'Sales manager updated', $link, [
                'triggered_by_user_id' => $updatedBy,
                'importance' => 'normal',
                'show_toast' => false,
                'dedup_key' => 'sales_mgr:' . $id . ':' . $oldMgr . '>' . $newMgr,
                'dedup_window_min' => 1440,
                'exclude_user_id' => $updatedBy,
            ]);
        }

        $oldNf = trim((string)($before['next_follow_up_at'] ?? ''));
        $newNf = trim((string)($after['next_follow_up_at'] ?? $oldNf));
        if ($newNf !== '' && $newNf !== $oldNf) {
            notifySalesLeadUsers($id, 'sales.followup.scheduled', 'Follow-up Scheduled', ($company !== '' ? ($company . ' · ') : '') . 'Follow-up at ' . date('Y-m-d H:i', strtotime($newNf)), $link, [
                'triggered_by_user_id' => $updatedBy,
                'importance' => 'normal',
                'show_toast' => true,
                'dedup_key' => 'sales_followup_set:' . $id . ':' . substr($newNf, 0, 16),
                'dedup_window_min' => 1440,
                'exclude_user_id' => $updatedBy,
            ]);
        }
    }
    return $ok;
}

function deleteSalesLead(int $id, int $deletedBy): bool {
    $id = (int)$id;
    if ($id <= 0) return false;

    $conn = getDbConnection();
    $lead = getSalesLeadById($id);
    if (!$lead) return false;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM sales_lead_activities WHERE sales_lead_id = ?");
        if (!$stmt) throw new RuntimeException('Failed to delete prospect activities');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM sales_leads WHERE id = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException('Failed to delete prospect');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = (int)$stmt->affected_rows > 0;
        $stmt->close();

        if (!$deleted) {
            throw new RuntimeException('Prospect not found');
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }

    $companyName = trim((string)($lead['company_name'] ?? ''));
    notifySalesLeadUsers($id, 'sales.prospect.deleted', 'Prospect Deleted', $companyName !== '' ? $companyName : ('Prospect #' . $id), '../sales/leads', [
        'triggered_by_user_id' => $deletedBy,
        'importance' => 'normal',
        'show_toast' => true,
        'dedup_key' => 'sales_prospect_deleted:' . $id,
        'dedup_window_min' => 60,
        'exclude_user_id' => $deletedBy,
    ]);

    return true;
}

function addSalesLeadActivity(int $salesLeadId, string $status, string $comment, int $createdBy, ?string $nextFollowUpAt = null): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO sales_lead_activities (sales_lead_id, status, comment, created_by, created_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param('issi', $salesLeadId, $status, $comment, $createdBy);
    $ok = $stmt->execute();
    $actId = (int)$conn->insert_id;
    $stmt->close();
    if ($ok) {
        $lead = getSalesLeadById($salesLeadId);
        $company = $lead ? trim((string)($lead['company_name'] ?? '')) : '';
        $titleN = 'Follow-up Added';
        $body = ($company !== '' ? ($company . ' · ') : '') . $comment;
        notifySalesLeadUsers($salesLeadId, 'sales.followup.added', $titleN, $body, '../sales/lead-view?id=' . (int)$salesLeadId, [
            'triggered_by_user_id' => $createdBy,
            'importance' => 'normal',
            'show_toast' => true,
            'dedup_key' => 'sales_followup_added:' . $salesLeadId . ':' . $actId,
            'dedup_window_min' => 60,
            'exclude_user_id' => $createdBy,
        ]);

        $closed = in_array($status, ['Closed Won','Closed Lost'], true);
        if ($closed) {
            $upd = $conn->prepare("UPDATE sales_leads SET last_activity_at = NOW(), next_follow_up_at = NULL, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('ii', $createdBy, $salesLeadId);
            $upd->execute();
            $upd->close();
        } elseif ($nextFollowUpAt !== null && trim($nextFollowUpAt) !== '') {
            $nf = trim($nextFollowUpAt);
            $upd = $conn->prepare("UPDATE sales_leads SET last_activity_at = NOW(), next_follow_up_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('sii', $nf, $createdBy, $salesLeadId);
            $upd->execute();
            $upd->close();
        } else {
            $upd = $conn->prepare("UPDATE sales_leads SET last_activity_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('ii', $createdBy, $salesLeadId);
            $upd->execute();
            $upd->close();
        }
    }
    return $ok;
}

function notifySalesFollowupRemindersForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $conn = getDbConnection();
    $stmtU = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $role = '';
    if ($stmtU) {
        $stmtU->bind_param('i', $userId);
        $stmtU->execute();
        $u = $stmtU->get_result()->fetch_assoc() ?: [];
        $stmtU->close();
        $role = strtolower(trim((string)($u['role'] ?? '')));
    }
    if (!in_array($role, ['admin','sales_director','sales_manager','sdr'], true)) return;

    $where = ["sl.next_follow_up_at IS NOT NULL", "sl.status NOT IN ('Closed Won','Closed Lost')", "sl.next_follow_up_at >= (NOW() - INTERVAL 6 HOUR)", "sl.next_follow_up_at <= (NOW() + INTERVAL 6 HOUR)"];
    $params = [];
    $types = '';
    if ($role === 'sdr') {
        $where[] = "sl.owner_id = ?";
        $params[] = $userId;
        $types .= 'i';
    } elseif ($role === 'sales_manager') {
        $teamIds = getCurrentSalesSdrIdsForManager($userId);
        if (!empty($teamIds)) {
            $in = implode(',', array_fill(0, count($teamIds), '?'));
            $where[] = "(sl.sales_manager_id = ? OR sl.owner_id IN ($in))";
            $params[] = $userId;
            $params = array_merge($params, $teamIds);
            $types .= 'i' . str_repeat('i', count($teamIds));
        } else {
            $where[] = "sl.sales_manager_id = ?";
            $params[] = $userId;
            $types .= 'i';
        }
    }

    $sql = "SELECT sl.id, sl.company_name, sl.next_follow_up_at FROM sales_leads sl WHERE " . implode(' AND ', $where) . " ORDER BY sl.next_follow_up_at ASC LIMIT 50";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $nowTs = time();
    foreach ($rows as $r) {
        $leadId = (int)($r['id'] ?? 0);
        if ($leadId <= 0) continue;
        $nfa = (string)($r['next_follow_up_at'] ?? '');
        if ($nfa === '') continue;
        $ts = strtotime($nfa);
        if ($ts === false) continue;
        $diffMin = (int)floor(($ts - $nowTs) / 60);
        $company = trim((string)($r['company_name'] ?? ''));
        $when = date('Y-m-d H:i', $ts);
        $link = '../sales/lead-view?id=' . $leadId;

        $bucket = null;
        $typeN = '';
        $title = '';
        $importance = 'normal';
        $showToast = true;
        $dedupWindow = 90;

        if ($diffMin >= 25 && $diffMin <= 30) {
            $bucket = '30';
            $typeN = 'sales.followup.alert_30';
            $title = 'Follow-up Due (30 min)';
        } elseif ($diffMin >= 8 && $diffMin <= 10) {
            $bucket = '10';
            $typeN = 'sales.followup.alert_10';
            $title = 'Follow-up Due (10 min)';
            $importance = 'high';
        } elseif ($diffMin >= -1 && $diffMin <= 1) {
            $bucket = '0';
            $typeN = 'sales.followup.due_now';
            $title = 'Follow-up Due Now';
            $importance = 'high';
        } elseif ($diffMin <= -5 && $diffMin >= -180) {
            $bucket = 'missed';
            $typeN = 'sales.followup.missed';
            $title = 'Follow-up Missed';
            $importance = 'high';
            $dedupWindow = 240;
        } else {
            continue;
        }

        $body = ($company !== '' ? ($company . ' · ') : '') . $when;
        createNotificationSmart($userId, $typeN, $title, $body, $link, [
            'triggered_by_user_id' => 0,
            'importance' => $importance,
            'show_toast' => $showToast,
            'dedup_key' => 'sales_followup:' . $bucket . ':' . $leadId . ':' . substr($nfa, 0, 16),
            'dedup_window_min' => $dedupWindow,
            'visibility_scope' => 'direct',
        ]);
    }
}

function findDuplicateClientsByNameOrDomain(string $name, string $website): array {
    $conn = getDbConnection();
    $name = trim($name);
    $domain = extractDomain($website);

    $where = [];
    $params = [];
    $types = '';

    if ($domain !== '') {
        $where[] = "website_domain = ?";
        $params[] = $domain;
        $types .= 's';
    }
    if ($name !== '') {
        $where[] = "name LIKE ?";
        $params[] = '%'.$name.'%';
        $types .= 's';
    }
    if (empty($where)) return [];

    $sql = "SELECT id, client_code, name, website FROM clients WHERE ".implode(' OR ', $where)." ORDER BY created_at DESC LIMIT 10";
    $stmt = $conn->prepare($sql);
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function generateClientCode(string $companyName = ''): string {
    $conn = getDbConnection();
    $base = strtoupper(preg_replace('/[^A-Z]/', '', strtoupper((string)$companyName)));
    $base = substr($base, 0, 3);
    if ($base === '') $base = 'CL';

    for ($i = 0; $i < 25; $i++) {
        $rand = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $code = 'DFB-'.$base.'-'.$rand;
        $stmt = $conn->prepare("SELECT 1 FROM clients WHERE client_code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) return $code;
    }

    return 'DFB-CL-'.bin2hex(random_bytes(2));
}

function convertSalesLeadToClient(int $salesLeadId, int $actorUserId): int {
    $conn = getDbConnection();
    $lead = getSalesLeadById($salesLeadId);
    if (!$lead) throw new RuntimeException('Prospect not found');
    if (($lead['status'] ?? '') !== 'Closed Won') throw new RuntimeException('Prospect must be Closed Won before conversion');
    if (!empty($lead['client_id'])) return (int)$lead['client_id'];

    $dups = findDuplicateClientsByNameOrDomain((string)($lead['company_name'] ?? ''), (string)($lead['website'] ?? ''));
    if (!empty($dups)) throw new RuntimeException('Duplicate client found. Open the existing client instead of converting.');

    $clientCode = generateClientCode((string)($lead['company_name'] ?? ''));
    $clientName = trim((string)($lead['company_name'] ?? ''));
    $website = trim((string)($lead['website'] ?? ''));
    $websiteDomain = extractDomain($website);
    $industry = trim((string)($lead['industry'] ?? ''));
    $notes = trim((string)($lead['notes'] ?? ''));
    $prefix = 'Converted from Sales Prospect #'.$salesLeadId;
    $clientNotes = $notes !== '' ? ($prefix."\n".$notes) : $prefix;

    $stmt = $conn->prepare("INSERT INTO clients (client_code, name, website, website_domain, industry, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('ssssssi', $clientCode, $clientName, $website, $websiteDomain, $industry, $clientNotes, $actorUserId);
    if (!$stmt->execute()) throw new RuntimeException('Failed to create client');
    $clientId = (int)$conn->insert_id;
    $stmt->close();

    $contactName = trim((string)($lead['contact_name'] ?? ''));
    $contactEmail = trim((string)($lead['contact_email'] ?? ''));
    $contactPhone = trim((string)($lead['contact_phone'] ?? ''));
    $contactTitle = trim((string)($lead['contact_job_title'] ?? ''));
    if ($contactName !== '' || $contactEmail !== '' || $contactPhone !== '' || $contactTitle !== '') {
        $cName = $contactName !== '' ? $contactName : ($clientName !== '' ? $clientName.' Contact' : 'Contact');
        $stmt = $conn->prepare("INSERT INTO client_contacts (client_id, name, email, phone, title, created_at) VALUES (?,?,?,?,?,NOW())");
        $stmt->bind_param('issss', $clientId, $cName, $contactEmail, $contactPhone, $contactTitle);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE sales_leads SET client_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('iii', $clientId, $actorUserId, $salesLeadId);
    $stmt->execute();
    $stmt->close();

    $ownerId = (int)($lead['owner_id'] ?? 0);
    $managerId = (int)($lead['sales_manager_id'] ?? 0);
    $managerId = $managerId > 0 ? $managerId : null;
    upsertSalesClientOwnership($clientId, $ownerId, $managerId, $actorUserId, $salesLeadId);

    addSalesLeadActivity($salesLeadId, 'Closed Won', 'Converted to client: '.$clientCode, $actorUserId);

    return $clientId;
}

function upsertSalesClientOwnership(int $clientId, int $ownerId, ?int $managerId, int $assignedBy, ?int $sourceSalesLeadId = null): bool {
    $conn = getDbConnection();
    if ($clientId <= 0 || $ownerId <= 0) throw new RuntimeException('Invalid client assignment');
    $managerId = $managerId && $managerId > 0 ? $managerId : null;
    $sourceSalesLeadId = $sourceSalesLeadId && $sourceSalesLeadId > 0 ? $sourceSalesLeadId : null;

    $stmt = $conn->prepare("INSERT INTO sales_client_ownership (client_id, owner_id, manager_id, source_sales_lead_id, assigned_at, assigned_by)
        VALUES (?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            owner_id = VALUES(owner_id),
            manager_id = VALUES(manager_id),
            source_sales_lead_id = VALUES(source_sales_lead_id),
            assigned_at = NOW(),
            assigned_by = VALUES(assigned_by)");
    if (!$stmt) return false;
    $stmt->bind_param('iiiii', $clientId, $ownerId, $managerId, $sourceSalesLeadId, $assignedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getSalesManagersBasic(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE role IN ('sales_manager','sales_director') ORDER BY role, full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function getSalesOwnersBasic(): array {
    $conn = getDbConnection();
    $rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE role IN ('sales_manager','sdr') ORDER BY role, full_name");
    return $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function pushLeadToGoogleSheet(string $campaignName, array $leadData): bool {
    return false;
}

/**
 * Ensure all required tables and columns exist in the database.
 * This should be run sparingly (e.g., once per session or on app update).
 */
function ensureDatabaseSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $hasIndex = function(string $table, string $indexName) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->bind_param('ss', $table, $indexName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $getColumnType = function(string $table, string $column) use ($conn): ?string {
        $stmt = $conn->prepare("SELECT COLUMN_TYPE AS ct FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row ? (string)($row['ct'] ?? '') : null;
    };
    $addColumn = function(string $table, string $column, string $definition) use ($conn, $hasColumn): void {
        if ($hasColumn($table, $column)) return;
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    };
    $addIndex = function(string $table, string $indexName, string $columns, bool $unique = false) use ($conn, $hasIndex): void {
        if ($hasIndex($table, $indexName)) return;
        $kw = $unique ? 'UNIQUE ' : '';
        $conn->query("ALTER TABLE `$table` ADD {$kw}INDEX `$indexName` ($columns)");
    };

    // Essential Core Tables
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'agent',
        is_active TINYINT(1) DEFAULT 1,
        is_locked TINYINT(1) DEFAULT 0,
        locked_until TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role (role),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaigns (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 0,
        owner_id INT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT NULL,
        login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_activity DATETIME NULL,
        logout_time DATETIME NULL,
        INDEX idx_user (user_id),
        INDEX idx_login (login_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id VARCHAR(20) NOT NULL,
        campaign_id INT NOT NULL,
        campaign_name VARCHAR(100) NOT NULL,
        agent_id INT NOT NULL,
        agent_name VARCHAR(100) NOT NULL,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        job_title VARCHAR(150) NULL,
        email VARCHAR(150) NULL,
        company_domain VARCHAR(255) NULL,
        linkedin_link TEXT NULL,
        contact_phone VARCHAR(20) NULL,
        industry VARCHAR(100) NULL,
        company_linkedin TEXT NULL,
        company_name VARCHAR(150) NULL,
        company_size VARCHAR(50) NULL,
        country VARCHAR(100) NULL,
        software_implementation_timeline VARCHAR(20) NULL,
        recording_path VARCHAR(255) NULL,
        qa_status ENUM('Pending','TBD','In Progress','Qualified','Disqualified','Rework Needed','Reopened','Rectified','Duplicate/Supp') NULL DEFAULT 'Pending',
        qa_comment TEXT NULL,
        qa_client_comment TEXT NULL,
        qa_updated_at TIMESTAMP NULL DEFAULT NULL,
        qa_reviewed_by INT NULL,
        client_delivery_status ENUM('Pending','Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') NOT NULL DEFAULT 'Pending',
        form_done ENUM('Yes','No') NULL DEFAULT 'No',
        ip_address VARCHAR(50) NULL,
        form_filled_time TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at_utc DATETIME NULL,
        created_at_source VARCHAR(32) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_at_utc DATETIME NULL,
        updated_at_source VARCHAR(32) NULL,
        timezone_migrated TINYINT(1) NOT NULL DEFAULT 0,
        lead_comment TEXT NULL,
        created_by INT NULL,
        updated_by INT NULL,
        INDEX idx_campaign_id (campaign_id),
        INDEX idx_agent_id (agent_id),
        INDEX idx_client_delivery (client_delivery_status),
        INDEX idx_company_domain (company_domain),
        INDEX idx_created_at (created_at),
        INDEX idx_created_at_utc (created_at_utc),
        INDEX idx_updated_at_utc (updated_at_utc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS lead_upload_runs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(40) NOT NULL UNIQUE,
        source VARCHAR(32) NOT NULL,
        campaign_id INT NOT NULL,
        uploaded_by INT NOT NULL,
        original_filename VARCHAR(255) NULL,
        total_rows INT NOT NULL DEFAULT 0,
        inserted_rows INT NOT NULL DEFAULT 0,
        rejected_rows INT NOT NULL DEFAULT 0,
        success_report_file VARCHAR(255) NULL,
        rejected_report_file VARCHAR(255) NULL,
        meta_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        INDEX idx_campaign (campaign_id),
        INDEX idx_uploaded_by (uploaded_by),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS lead_upload_rejections (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id INT NOT NULL,
        row_num INT NULL,
        row_json MEDIUMTEXT NOT NULL,
        reason_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_run (run_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Campaign details table
    $conn->query("CREATE TABLE IF NOT EXISTS campaign_details (
        campaign_id INT NOT NULL PRIMARY KEY,
        code VARCHAR(32) NOT NULL UNIQUE,
        client_code VARCHAR(50) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        start_date DATE NULL,
        end_date DATE NULL,
        total_leads INT NULL,
        pacing_type VARCHAR(20) NULL,
        pacing_count INT NULL,
        cpc DECIMAL(10,2) NULL,
        cpl DECIMAL(10,2) NULL,
        cpl_currency VARCHAR(8) NULL,
        campaign_type VARCHAR(40) NULL,
        delivery_format VARCHAR(40) NULL,
        targeted_country TEXT NULL,
        job_title VARCHAR(255) NULL,
        departments TEXT NULL,
        seniority_levels TEXT NULL,
        industries TEXT NULL,
        employee_sizes TEXT NULL,
        revenue_sizes TEXT NULL,
        instruction TEXT NULL,
        script_path VARCHAR(255) NULL,
        tal_path VARCHAR(255) NULL,
        suppression_path VARCHAR(255) NULL,
        recording_path VARCHAR(255) NULL,
        custom_fields_json TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_status(status),
        INDEX idx_campaign_type(campaign_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensureTeamSchema();
    ensureAppSettingsSchema();
    if (function_exists('dfRecognitionEnsureSchema')) {
        dfRecognitionEnsureSchema();
    }
    ensurePasswordResetSchema();
    ensureUserIpAccessSchema();
    ensureTaskManagementSchema();
    ensureProductivitySchema();
    ensureFunnelTrack360Schema();
    ensureDbmsSchema();

    $conn->query("CREATE TABLE IF NOT EXISTS holidays (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        country_code VARCHAR(2) NOT NULL,
        holiday_date DATE NOT NULL,
        name VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_country_date (country_code, holiday_date),
        INDEX idx_date (holiday_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_attendance_days (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        work_date DATE NOT NULL,
        punch_in DATETIME NULL,
        punch_out DATETIME NULL,
        current_state VARCHAR(20) NOT NULL DEFAULT 'Off',
        break_minutes INT NOT NULL DEFAULT 0,
        working_minutes INT NOT NULL DEFAULT 0,
        late_minutes INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Absent',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_date (user_id, work_date),
        INDEX idx_work_date (work_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $addColumn('hr_attendance_days', 'late_minutes', "INT NOT NULL DEFAULT 0 AFTER `working_minutes`");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_attendance_states (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_day_id INT NOT NULL,
        state VARCHAR(20) NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NULL,
        minutes INT NULL,
        INDEX idx_day (attendance_day_id),
        INDEX idx_state (state),
        INDEX idx_start (start_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_attendance_audit_logs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_day_id INT NOT NULL,
        user_id INT NOT NULL,
        work_date DATE NOT NULL,
        action VARCHAR(40) NOT NULL,
        field_name VARCHAR(60) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        remarks TEXT NULL,
        created_by INT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (attendance_day_id),
        INDEX idx_user_date (user_id, work_date),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_salary_settings (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
        pf DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
        effective_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (user_id, effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_salary_structures (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        effective_date DATE NOT NULL,
        structure_type VARCHAR(40) NOT NULL DEFAULT 'Standard',
        total_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        basic DECIMAL(12,2) NOT NULL DEFAULT 0,
        hra DECIMAL(12,2) NOT NULL DEFAULT 0,
        conveyance DECIMAL(12,2) NOT NULL DEFAULT 0,
        medical DECIMAL(12,2) NOT NULL DEFAULT 0,
        special_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        pf DECIMAL(12,2) NOT NULL DEFAULT 0,
        professional_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        tds DECIMAL(12,2) NOT NULL DEFAULT 0,
        locked TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_effective_date (user_id, effective_date),
        INDEX idx_user_date (user_id, effective_date),
        INDEX idx_effective (effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_bonuses (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_month (user_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_loans (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        emi_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        start_date DATE NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_active (user_id, active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_loan_deductions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_loan_month (loan_id, year, month),
        INDEX idx_user_month (user_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_payslips (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        salary_data LONGTEXT NOT NULL,
        generated_at DATETIME NOT NULL,
        generated_by INT NOT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_month (user_id, year, month),
        INDEX idx_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_payroll_month_locks (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        month INT NOT NULL,
        locked_by INT NOT NULL,
        locked_at DATETIME NOT NULL,
        UNIQUE KEY uniq_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_leave_requests (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        leave_date DATE NOT NULL,
        leave_type VARCHAR(20) NOT NULL DEFAULT 'Paid Leave',
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        reason TEXT NULL,
        requested_by INT NOT NULL,
        requested_at DATETIME NOT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        review_notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_user_leave_date (user_id, leave_date),
        INDEX idx_status_date (status, leave_date),
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_shifts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        grace_minutes INT NOT NULL DEFAULT 15,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS hr_user_shift_assignments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        shift_id INT NOT NULL,
        effective_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_date (user_id, effective_date),
        INDEX idx_user (user_id),
        INDEX idx_effective (effective_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_personal_details (
        user_id INT NOT NULL PRIMARY KEY,
        personal_email VARCHAR(190) NULL,
        emergency_contact_number VARCHAR(40) NULL,
        date_of_birth DATE NULL,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_bank_details (
        user_id INT NOT NULL PRIMARY KEY,
        bank_name VARCHAR(120) NULL,
        account_number VARCHAR(40) NULL,
        account_type VARCHAR(20) NULL,
        ifsc_code VARCHAR(20) NULL,
        pan_number VARCHAR(20) NULL,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_documents (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(60) NOT NULL,
        doc_type VARCHAR(80) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NULL,
        mime_type VARCHAR(120) NULL,
        file_size INT NULL,
        uploaded_by INT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_user_cat (user_id, category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('users', 'reporting_manager_id', 'INT NULL');
    $addColumn('users', 'onboarding_notes', 'TEXT NULL');

    $addColumn('hr_attendance_days', 'late_minutes', 'INT NOT NULL DEFAULT 0');
    $addColumn('hr_attendance_days', 'shift_id', 'INT NULL');
    $addColumn('hr_attendance_days', 'shift_start_time', 'TIME NULL');
    $addColumn('hr_attendance_days', 'shift_end_time', 'TIME NULL');
    $addColumn('hr_attendance_days', 'grace_minutes', 'INT NOT NULL DEFAULT 15');

    // Chat schema
    $conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        delivered_at DATETIME NULL,
        read_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sender(sender_id),
        INDEX idx_receiver(receiver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_presence (
        user_id INT PRIMARY KEY,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_online TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Campaign delivery and metrics
    $conn->query("CREATE TABLE IF NOT EXISTS campaign_delivery_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        file_type VARCHAR(50) NULL,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(255) NOT NULL,
        uploader_id INT NULL,
        format VARCHAR(40) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_campaign(campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_additional_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        file_title VARCHAR(180) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_type VARCHAR(80) NULL,
        description TEXT NULL,
        uploaded_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_campaign(campaign_id),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_user_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_campaign_user (campaign_id, user_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS operations_campaign_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ops_campaign_user (campaign_id, user_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_user(user_id),
        INDEX idx_assigned_by(assigned_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS qa_campaign_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_qa_campaign_user (campaign_id, user_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_user(user_id),
        INDEX idx_assigned_by(assigned_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS qa_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        prev_status VARCHAR(32) NULL,
        campaign_id INT NOT NULL,
        qa_status VARCHAR(32) NOT NULL,
        qa_comment TEXT NULL,
        qa_client_comment TEXT NULL,
        client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        qa_reviewed_by INT NOT NULL,
        reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead(lead_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_reviewer(qa_reviewed_by),
        INDEX idx_reviewed_at(reviewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$hasColumn('qa_audit_logs', 'prev_status')) {
        @$conn->query("ALTER TABLE qa_audit_logs ADD COLUMN prev_status VARCHAR(32) NULL AFTER lead_id");
    }
    if (!$hasColumn('qa_audit_logs', 'qa_client_comment')) {
        @$conn->query("ALTER TABLE qa_audit_logs ADD COLUMN qa_client_comment TEXT NULL AFTER qa_comment");
    }
    if (!$hasColumn('qa_audit_logs', 'client_delivery_status')) {
        @$conn->query("ALTER TABLE qa_audit_logs ADD COLUMN client_delivery_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER qa_client_comment");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS qa_assignment_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requested_by INT NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'Open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_by INT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_status(status),
        INDEX idx_created(created_at),
        INDEX idx_requested_by(requested_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_metrics (
        campaign_id INT PRIMARY KEY,
        delivered INT NULL,
        generated INT NULL,
        qualified INT NULL,
        disqualified INT NULL,
        pending INT NULL,
        rejected INT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS forms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        fingerprint CHAR(64) NOT NULL UNIQUE,
        schema_json MEDIUMTEXT NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS form_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(150) NOT NULL,
        schema_json MEDIUMTEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_forms (
        campaign_id INT NOT NULL UNIQUE,
        form_id INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (campaign_id),
        INDEX idx_form(form_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS form_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        form_id INT NOT NULL,
        campaign_id INT NOT NULL,
        lead_id INT NULL,
        submitted_by INT NOT NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        data_json MEDIUMTEXT NOT NULL,
        INDEX idx_form(form_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_lead(lead_id),
        INDEX idx_lead_campaign(lead_id, campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addIndex('form_submissions', 'idx_lead_campaign', 'lead_id, campaign_id');

    $conn->query("CREATE TABLE IF NOT EXISTS lead_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        field_id VARCHAR(80) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead(lead_id),
        INDEX idx_field(field_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS lead_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        actor_id INT NULL,
        action VARCHAR(50) NOT NULL,
        meta_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead(lead_id),
        INDEX idx_actor(actor_id),
        INDEX idx_action(action),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS url_previews (
        url_hash CHAR(64) PRIMARY KEY,
        url TEXT NOT NULL,
        final_url TEXT NULL,
        preview_title VARCHAR(255) NULL,
        preview_description TEXT NULL,
        preview_image VARCHAR(512) NULL,
        fetch_status VARCHAR(20) NOT NULL DEFAULT 'ok',
        http_status INT NULL,
        last_error VARCHAR(255) NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fetched(fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        campaign_id INT NULL,
        triggered_by_user_id INT NULL,
        type VARCHAR(40) NOT NULL,
        visibility_scope VARCHAR(24) NOT NULL DEFAULT 'direct',
        title VARCHAR(180) NOT NULL,
        body TEXT NULL,
        link_url VARCHAR(255) NULL,
        dedup_key CHAR(40) NULL,
        importance VARCHAR(12) NOT NULL DEFAULT 'normal',
        show_toast TINYINT(1) NOT NULL DEFAULT 0,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        INDEX idx_user_created(user_id, created_at),
        INDEX idx_user_read(user_id, is_read),
        INDEX idx_campaign_created(campaign_id, created_at),
        INDEX idx_user_campaign(user_id, campaign_id),
        INDEX idx_user_dedup(user_id, type, dedup_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('notifications', 'campaign_id', "INT NULL AFTER `user_id`");
    $addColumn('notifications', 'triggered_by_user_id', "INT NULL AFTER `campaign_id`");
    $addColumn('notifications', 'visibility_scope', "VARCHAR(24) NOT NULL DEFAULT 'direct' AFTER `type`");
    $addColumn('notifications', 'dedup_key', "CHAR(40) NULL AFTER `link_url`");
    $addColumn('notifications', 'importance', "VARCHAR(12) NOT NULL DEFAULT 'normal' AFTER `dedup_key`");
    $addColumn('notifications', 'show_toast', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `importance`");
    $addIndex('notifications', 'idx_user_dedup', 'user_id, type, dedup_key');
    $addIndex('notifications', 'idx_campaign_created', 'campaign_id, created_at');
    $addIndex('notifications', 'idx_user_campaign', 'user_id, campaign_id');

    $conn->query("CREATE TABLE IF NOT EXISTS notification_preferences (
        user_id INT NOT NULL,
        type VARCHAR(40) NOT NULL,
        delivery_mode VARCHAR(12) NOT NULL DEFAULT 'instant',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        show_toast TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, type),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS notification_digest_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        campaign_id INT NULL,
        triggered_by_user_id INT NULL,
        type VARCHAR(40) NOT NULL,
        visibility_scope VARCHAR(24) NOT NULL DEFAULT 'direct',
        title VARCHAR(180) NOT NULL,
        body TEXT NULL,
        link_url VARCHAR(255) NULL,
        dedup_key CHAR(40) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        INDEX idx_user_created(user_id, created_at),
        INDEX idx_user_processed(user_id, processed_at),
        INDEX idx_user_dedup(user_id, type, dedup_key),
        INDEX idx_campaign_created(campaign_id, created_at),
        INDEX idx_user_campaign(user_id, campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('notification_digest_queue', 'campaign_id', "INT NULL AFTER `user_id`");
    $addColumn('notification_digest_queue', 'triggered_by_user_id', "INT NULL AFTER `campaign_id`");
    $addColumn('notification_digest_queue', 'visibility_scope', "VARCHAR(24) NOT NULL DEFAULT 'direct' AFTER `type`");
    $addIndex('notification_digest_queue', 'idx_campaign_created', 'campaign_id, created_at');
    $addIndex('notification_digest_queue', 'idx_user_campaign', 'user_id, campaign_id');

    $conn->query("CREATE TABLE IF NOT EXISTS vendors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(160) NOT NULL,
        website VARCHAR(255) NULL,
        contact_name VARCHAR(160) NULL,
        contact_email VARCHAR(255) NULL,
        contact_phone VARCHAR(50) NULL,
        country VARCHAR(120) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        INDEX idx_active(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('vendors', 'contact_name', "VARCHAR(160) NULL");
    $addColumn('vendors', 'contact_email', "VARCHAR(255) NULL");
    $addColumn('vendors', 'contact_phone', "VARCHAR(50) NULL");
    $addColumn('vendors', 'country', "VARCHAR(120) NULL");
    $addColumn('vendors', 'notes', "TEXT NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS vendor_campaign_map (
        vendor_id INT NOT NULL,
        campaign_id INT NOT NULL,
        vendor_cpl DECIMAL(10,2) NULL,
        vendor_cpl_currency VARCHAR(8) NULL,
        uploads_enabled TINYINT(1) NOT NULL DEFAULT 1,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor_id, campaign_id),
        INDEX idx_campaign(campaign_id),
        INDEX idx_vendor(vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS client_sdr_map (
        client_id INT NOT NULL,
        sdr_user_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (client_id, sdr_user_id),
        INDEX idx_client(client_id),
        INDEX idx_sdr(sdr_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS vendor_user_map (
        vendor_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (vendor_id, user_id),
        INDEX idx_vendor(vendor_id),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure extra columns in core tables
    $addColumn('campaigns', 'active', "TINYINT(1) NOT NULL DEFAULT 0");
    $addColumn('campaigns', 'owner_id', "INT NULL");
    $addColumn('campaigns', 'created_by', "INT NULL");
    $addColumn('campaigns', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $addColumn('campaigns', 'updated_by', "INT NULL");
    $addColumn('campaigns', 'updated_at', "DATETIME NULL");
    if (!$hasColumn('campaign_details', 'client_code')) {
        $addColumn('campaign_details', 'client_code', "VARCHAR(50) NULL");
    }
    if (!$hasColumn('campaign_details', 'client_id')) {
        $addColumn('campaign_details', 'client_id', "INT NULL");
        $addIndex('campaign_details', 'idx_client_id', 'client_id');
    }
    if (!$hasColumn('campaign_forms', 'assigned_by')) {
        $addColumn('campaign_forms', 'assigned_by', "INT NULL");
    }
    if (!$hasColumn('campaign_forms', 'created_at')) {
        $addColumn('campaign_forms', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    if (!$hasColumn('campaign_delivery_files', 'file_type')) {
        $addColumn('campaign_delivery_files', 'file_type', "VARCHAR(40) NULL");
    }
    if (!$hasColumn('campaign_delivery_files', 'file_name')) {
        $addColumn('campaign_delivery_files', 'file_name', "VARCHAR(180) NULL");
    }
    if (!$hasColumn('campaign_delivery_files', 'uploaded_by')) {
        $addColumn('campaign_delivery_files', 'uploaded_by', "INT NULL");
    }
    if (!$hasColumn('campaign_delivery_files', 'uploaded_at')) {
        $addColumn('campaign_delivery_files', 'uploaded_at', "DATETIME NULL");
    }
    $addColumn('leads', 'qa_status', "VARCHAR(20) DEFAULT 'Pending'");
    $addColumn('leads', 'qa_comment', "TEXT NULL");
    if (!$hasColumn('leads', 'qa_client_comment')) {
        $addColumn('leads', 'qa_client_comment', "TEXT NULL AFTER `qa_comment`");
    }
    $addColumn('leads', 'qa_reviewed_by', "INT NULL");
    $addColumn('leads', 'qa_updated_at', "DATETIME NULL");
    $addColumn('leads', 'form_done', "VARCHAR(3) DEFAULT 'No'");
    $addColumn('leads', 'lead_comment', "TEXT NULL");
    $addColumn('leads', 'created_by', "INT NULL");
    $addColumn('leads', 'updated_by', "INT NULL");
    $addColumn('leads', 'email_status', "VARCHAR(30) NULL");
    $addColumn('leads', 'email_status_comment', "TEXT NULL");
    $addColumn('leads', 'email_status_updated_by', "INT NULL");
    $addColumn('leads', 'email_status_updated_at', "DATETIME NULL");
    $addColumn('leads', 'client_id', "INT NULL");
    $addColumn('leads', 'vendor_id', "INT NULL");
    $addColumn('leads', 'lead_source', "VARCHAR(20) NULL");
    $addColumn('leads', 'assigned_to_user', "INT NULL");
    $addColumn('leads', 'created_at_utc', "DATETIME NULL AFTER `created_at`");
    $addColumn('leads', 'created_at_source', "VARCHAR(32) NULL AFTER `created_at_utc`");
    $addColumn('leads', 'updated_at_utc', "DATETIME NULL AFTER `updated_at`");
    $addColumn('leads', 'updated_at_source', "VARCHAR(32) NULL AFTER `updated_at_utc`");
    $addColumn('leads', 'timezone_migrated', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at_source`");

    $ct = $getColumnType('leads', 'qa_status');
    if ($ct && str_contains(strtolower($ct), 'enum(') && (!str_contains($ct, "'In Progress'") || !str_contains($ct, "'Reopened'") || !str_contains($ct, "'Rectified'") || !str_contains($ct, "'Duplicate/Supp'"))) {
        @$conn->query("ALTER TABLE leads MODIFY COLUMN qa_status ENUM('Pending','In Progress','Qualified','Disqualified','Rework Needed','Reopened','Rectified','Duplicate/Supp') NULL DEFAULT 'Pending'");
    }
    $cdt = $getColumnType('leads', 'client_delivery_status');
    if ($cdt && str_contains(strtolower($cdt), 'enum(') && (!str_contains($cdt, "'Accepted'") || !str_contains($cdt, "'Rejected'") || !str_contains($cdt, "'TBD(To be discussed)'") || !str_contains($cdt, "'In Progress'"))) {
        @$conn->query("ALTER TABLE leads MODIFY COLUMN client_delivery_status ENUM('Pending','Delivered','Accepted','Rejected','TBD(To be discussed)','In Progress') NOT NULL DEFAULT 'Pending'");
    }
    if ($rs = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_audit_logs' LIMIT 1")) {
        $hasAuditLogTable = (bool)$rs->fetch_assoc();
        $rs->close();
        if ($hasAuditLogTable) {
            @$conn->query("UPDATE leads l
                JOIN (
                    SELECT q1.lead_id, q1.qa_status, q1.client_delivery_status
                    FROM qa_audit_logs q1
                    JOIN (
                        SELECT lead_id, MAX(id) AS max_id
                        FROM qa_audit_logs
                        WHERE TRIM(COALESCE(qa_status, '')) <> '' OR TRIM(COALESCE(client_delivery_status, '')) <> ''
                        GROUP BY lead_id
                    ) q2 ON q2.max_id = q1.id
                ) qa_fix ON qa_fix.lead_id = l.id
                SET l.qa_status = CASE
                        WHEN TRIM(COALESCE(l.qa_status, '')) = '' AND TRIM(COALESCE(qa_fix.qa_status, '')) <> '' THEN qa_fix.qa_status
                        ELSE l.qa_status
                    END,
                    l.client_delivery_status = CASE
                        WHEN TRIM(COALESCE(l.client_delivery_status, '')) = '' AND TRIM(COALESCE(qa_fix.client_delivery_status, '')) <> '' THEN qa_fix.client_delivery_status
                        ELSE l.client_delivery_status
                    END
                WHERE TRIM(COALESCE(l.qa_status, '')) = '' OR TRIM(COALESCE(l.client_delivery_status, '')) = ''");
        }
    }
    if (!$hasIndex('leads', 'idx_created_by')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_created_by (created_by)");
    }
    if (!$hasIndex('leads', 'idx_updated_by')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_updated_by (updated_by)");
    }
    if (!$hasIndex('leads', 'idx_email_status')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_email_status (email_status)");
    }
    if (!$hasIndex('leads', 'idx_email_status_updated')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_email_status_updated (email_status_updated_at)");
    }
    if (!$hasIndex('leads', 'idx_client_id')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_client_id (client_id)");
    }
    if (!$hasIndex('leads', 'idx_vendor_id')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_vendor_id (vendor_id)");
    }
    if (!$hasIndex('leads', 'idx_lead_source')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_lead_source (lead_source)");
    }
    if (!$hasIndex('leads', 'idx_created_at_utc')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_created_at_utc (created_at_utc)");
    }
    if (!$hasIndex('leads', 'idx_updated_at_utc')) {
        @$conn->query("ALTER TABLE leads ADD INDEX idx_updated_at_utc (updated_at_utc)");
    }

    // Users table enhancements
    $addColumn('users', 'profile_pic', "VARCHAR(255) NULL");
    $addColumn('users', 'client_id', "INT NULL");
    $addColumn('users', 'vendor_id', "INT NULL");
    $addColumn('users', 'employee_id', "VARCHAR(50) NULL");
    $addColumn('users', 'date_of_birth', "DATE NULL");
    $addColumn('users', 'date_of_joining', "DATE NULL");
    $addColumn('users', 'job_title', "VARCHAR(100) NULL");
    $addColumn('users', 'phone_number', "VARCHAR(20) NULL");
    $addColumn('users', 'department', "VARCHAR(100) NULL");
    $addColumn('users', 'address', "TEXT NULL");
    $addColumn('users', 'emergency_contact', "VARCHAR(100) NULL");
    $addIndex('users', 'employee_id', 'employee_id', true);
    $roleType = $getColumnType('users', 'role');
    if ($roleType && stripos($roleType, 'enum(') === 0) {
        @$conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'agent'");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(200) NOT NULL,
        website VARCHAR(255) NULL,
        website_domain VARCHAR(255) NULL,
        industry VARCHAR(120) NULL,
        country VARCHAR(120) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_website_domain(website_domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('clients', 'country', "VARCHAR(120) NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS client_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        name VARCHAR(180) NOT NULL,
        email VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        title VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client(client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS client_tags (
        client_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY (client_id, tag_id),
        INDEX idx_tag(tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS campaign_revenue (
        campaign_id INT PRIMARY KEY,
        revenue DECIMAL(12,2) NULL,
        currency VARCHAR(8) NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_manual_expenses (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(80) NOT NULL,
        description TEXT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'INR',
        campaign_id INT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        INDEX idx_date (expense_date),
        INDEX idx_campaign (campaign_id),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoices (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(40) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        issue_date DATE NOT NULL,
        due_date DATE NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        client_id INT NULL,
        client_code VARCHAR(50) NULL,
        client_name VARCHAR(200) NULL,
        bill_to_name VARCHAR(200) NULL,
        bill_to_address TEXT NULL,
        bill_to_contact_name VARCHAR(180) NULL,
        bill_to_contact_email VARCHAR(255) NULL,
        bill_to_contact_phone VARCHAR(50) NULL,
        bill_to_contacts TEXT NULL,
        bill_from_name VARCHAR(200) NULL,
        bill_from_address TEXT NULL,
        bill_from_city_state VARCHAR(200) NULL,
        bill_from_country VARCHAR(120) NULL,
        bill_from_email VARCHAR(255) NULL,
        bill_from_phone VARCHAR(50) NULL,
        bank_name VARCHAR(200) NULL,
        account_name VARCHAR(200) NULL,
        account_number VARCHAR(80) NULL,
        ifsc_code VARCHAR(40) NULL,
        swift_code VARCHAR(40) NULL,
        beneficiary_address TEXT NULL,
        beneficiary_city_state VARCHAR(200) NULL,
        signature_path VARCHAR(255) NULL,
        campaign_id INT NULL,
        month_str VARCHAR(7) NULL,
        notes TEXT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_invoice_no (invoice_no),
        INDEX idx_status (status),
        INDEX idx_issue (issue_date),
        INDEX idx_client (client_id),
        INDEX idx_campaign (campaign_id),
        INDEX idx_month (month_str)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('revenue_invoices', 'bill_to_contact_name', "VARCHAR(180) NULL");
    $addColumn('revenue_invoices', 'bill_to_contact_email', "VARCHAR(255) NULL");
    $addColumn('revenue_invoices', 'bill_to_contact_phone', "VARCHAR(50) NULL");
    $addColumn('revenue_invoices', 'bill_to_contacts', "TEXT NULL");
    $addColumn('revenue_invoices', 'bill_from_name', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'bill_from_address', "TEXT NULL");
    $addColumn('revenue_invoices', 'bill_from_city_state', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'bill_from_country', "VARCHAR(120) NULL");
    $addColumn('revenue_invoices', 'bill_from_email', "VARCHAR(255) NULL");
    $addColumn('revenue_invoices', 'bill_from_phone', "VARCHAR(50) NULL");
    $addColumn('revenue_invoices', 'bank_name', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'account_name', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'account_number', "VARCHAR(80) NULL");
    $addColumn('revenue_invoices', 'ifsc_code', "VARCHAR(40) NULL");
    $addColumn('revenue_invoices', 'swift_code', "VARCHAR(40) NULL");
    $addColumn('revenue_invoices', 'beneficiary_address', "TEXT NULL");
    $addColumn('revenue_invoices', 'beneficiary_city_state', "VARCHAR(200) NULL");
    $addColumn('revenue_invoices', 'signature_path', "VARCHAR(255) NULL");
    $addColumn('revenue_invoices', 'sales_owner_id', "INT NULL AFTER `month_str`");
    $addColumn('revenue_invoices', 'sales_manager_id', "INT NULL AFTER `sales_owner_id`");
    $addColumn('revenue_invoices', 'updated_by', "INT NULL AFTER `created_at`");
    $addColumn('revenue_invoices', 'paid_at', "DATETIME NULL AFTER `updated_at`");
    $addIndex('revenue_invoices', 'idx_sales_owner', 'sales_owner_id, issue_date');
    $addIndex('revenue_invoices', 'idx_paid_at', 'paid_at');
    $addColumn('revenue_manual_expenses', 'updated_by', "INT NULL AFTER `created_at`");
    $addColumn('revenue_manual_expenses', 'updated_at', "DATETIME NULL AFTER `updated_by`");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoice_settings (
        user_id INT NOT NULL PRIMARY KEY,
        bill_from_name VARCHAR(200) NULL,
        bill_from_address TEXT NULL,
        bill_from_city_state VARCHAR(200) NULL,
        bill_from_country VARCHAR(120) NULL,
        bill_from_email VARCHAR(255) NULL,
        bill_from_phone VARCHAR(50) NULL,
        bank_name VARCHAR(200) NULL,
        account_name VARCHAR(200) NULL,
        account_number VARCHAR(80) NULL,
        ifsc_code VARCHAR(40) NULL,
        swift_code VARCHAR(40) NULL,
        beneficiary_address TEXT NULL,
        beneficiary_city_state VARCHAR(200) NULL,
        signature_path VARCHAR(255) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoice_billto_profiles (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        label VARCHAR(120) NOT NULL,
        client_id INT NULL,
        client_code VARCHAR(50) NULL,
        bill_to_name VARCHAR(200) NULL,
        bill_to_address TEXT NULL,
        bill_to_contacts TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_label (user_id, label),
        INDEX idx_user (user_id),
        INDEX idx_client_code (client_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS revenue_invoice_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_invoice (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(200) NOT NULL,
        website VARCHAR(255) NULL,
        website_domain VARCHAR(255) NULL,
        industry VARCHAR(120) NULL,
        company_size VARCHAR(60) NULL,
        country VARCHAR(120) NULL,
        contact_name VARCHAR(180) NULL,
        contact_job_title VARCHAR(120) NULL,
        contact_email VARCHAR(255) NULL,
        contact_email_domain VARCHAR(255) NULL,
        contact_phone VARCHAR(50) NULL,
        linkedin_url VARCHAR(255) NULL,
        lead_source VARCHAR(40) NOT NULL DEFAULT 'Manual Outreach',
        status VARCHAR(40) NOT NULL DEFAULT 'New',
        priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
        expected_opportunity_size DECIMAL(12,2) NULL,
        notes TEXT NULL,
        client_id INT NULL,
        owner_id INT NOT NULL,
        sales_manager_id INT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        last_activity_at DATETIME NULL,
        next_follow_up_at DATETIME NULL,
        INDEX idx_status(status),
        INDEX idx_client(client_id),
        INDEX idx_owner(owner_id),
        INDEX idx_manager(sales_manager_id),
        INDEX idx_company(company_name),
        INDEX idx_website_domain(website_domain),
        INDEX idx_email_domain(contact_email_domain),
        INDEX idx_linkedin(linkedin_url),
        INDEX idx_last_activity(last_activity_at),
        INDEX idx_next_followup(next_follow_up_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_lead_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sales_lead_id INT NOT NULL,
        status VARCHAR(40) NULL,
        comment TEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        INDEX idx_lead(sales_lead_id),
        INDEX idx_created(created_at),
        INDEX idx_updated(updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_manager_sdr_map (
        id INT AUTO_INCREMENT PRIMARY KEY,
        manager_user_id INT NOT NULL,
        sdr_user_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_manager_sdr (manager_user_id, sdr_user_id),
        INDEX idx_manager(manager_user_id),
        INDEX idx_sdr(sdr_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS fx_rates (
        rate_date DATE PRIMARY KEY,
        usd_inr DECIMAL(10,4) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at(updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        target_new_accounts INT NOT NULL DEFAULT 0,
        target_revenue_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_month (user_id, year, month),
        INDEX idx_year_month (year, month),
        INDEX idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_commission_settings (
        user_id INT NOT NULL PRIMARY KEY,
        commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_commissions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        sales_user_id INT NOT NULL,
        commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        billable_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        invoice_status VARCHAR(20) NULL,
        issue_date DATE NULL,
        paid_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_invoice_user (invoice_id, sales_user_id),
        INDEX idx_user_status (sales_user_id, status),
        INDEX idx_paid_at (paid_at),
        INDEX idx_issue (issue_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS sales_client_ownership (
        client_id INT NOT NULL PRIMARY KEY,
        owner_id INT NOT NULL,
        manager_id INT NULL,
        source_sales_lead_id INT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        assigned_by INT NULL,
        INDEX idx_owner(owner_id),
        INDEX idx_manager(manager_id),
        INDEX idx_assigned_at(assigned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("INSERT INTO sales_client_ownership (client_id, owner_id, manager_id, source_sales_lead_id, assigned_at, assigned_by)
        SELECT sl.client_id, sl.owner_id, sl.sales_manager_id, sl.id, COALESCE(sl.updated_at, sl.created_at), sl.updated_by
        FROM sales_leads sl
        LEFT JOIN sales_client_ownership sco ON sco.client_id = sl.client_id
        WHERE sl.client_id IS NOT NULL AND sl.client_id > 0
          AND sco.client_id IS NULL
          AND sl.status = 'Closed Won'");

    // Login attempts table
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        success TINYINT(1) DEFAULT 0,
        user_agent TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$hasColumn('clients', 'website_domain')) {
        @$conn->query("ALTER TABLE clients ADD COLUMN website_domain VARCHAR(255) NULL");
        @$conn->query("ALTER TABLE clients ADD INDEX idx_website_domain (website_domain)");
    }

    if (!$hasColumn('sales_leads', 'client_id')) {
        @$conn->query("ALTER TABLE sales_leads ADD COLUMN client_id INT NULL");
        @$conn->query("ALTER TABLE sales_leads ADD INDEX idx_client (client_id)");
    }

    if (!$hasColumn('sales_leads', 'last_activity_at')) {
        @$conn->query("ALTER TABLE sales_leads ADD COLUMN last_activity_at DATETIME NULL AFTER updated_at");
        @$conn->query("ALTER TABLE sales_leads ADD INDEX idx_last_activity (last_activity_at)");
    }
    if (!$hasColumn('sales_leads', 'next_follow_up_at')) {
        @$conn->query("ALTER TABLE sales_leads ADD COLUMN next_follow_up_at DATETIME NULL AFTER last_activity_at");
        @$conn->query("ALTER TABLE sales_leads ADD INDEX idx_next_followup (next_follow_up_at)");
    }

    if (!$hasColumn('sales_lead_activities', 'updated_by')) {
        @$conn->query("ALTER TABLE sales_lead_activities ADD COLUMN updated_by INT NULL AFTER created_at");
    }
    if (!$hasColumn('sales_lead_activities', 'updated_at')) {
        @$conn->query("ALTER TABLE sales_lead_activities ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
        @$conn->query("ALTER TABLE sales_lead_activities ADD INDEX idx_updated (updated_at)");
    }

    if (!$hasColumn('sales_targets', 'target_new_accounts')) {
        @$conn->query("ALTER TABLE sales_targets ADD COLUMN target_new_accounts INT NOT NULL DEFAULT 0");
    }
    if (!$hasColumn('sales_targets', 'target_revenue_usd')) {
        @$conn->query("ALTER TABLE sales_targets ADD COLUMN target_revenue_usd DECIMAL(12,2) NOT NULL DEFAULT 0");
    }
    if (!$hasColumn('sales_client_ownership', 'assigned_by')) {
        @$conn->query("ALTER TABLE sales_client_ownership ADD COLUMN assigned_by INT NULL AFTER assigned_at");
    }

    if ($hasColumn('revenue_invoices', 'paid_at')) {
        @$conn->query("UPDATE revenue_invoices SET paid_at = COALESCE(paid_at, updated_at, created_at) WHERE status = 'Paid' AND paid_at IS NULL");
    }

    // Performance indexes
    $addIndex('users', 'idx_role', 'role');
    $addIndex('users', 'idx_active', 'is_active');
    @$conn->query("ALTER TABLE hr_salary_structures ADD UNIQUE KEY uniq_user_effective_date (user_id, effective_date)");
    $addIndex('leads', 'idx_lead_id_search', 'lead_id');
    $addIndex('leads', 'idx_email_search', 'email');
    $addIndex('leads', 'idx_company_search', 'company_name');
    $addIndex('leads', 'idx_name_search', 'first_name, last_name');
    $addIndex('leads', 'idx_qa_queue', 'qa_status, created_at');

    maybeBackfillLeadCanonicalTimestamps(25);
    $verified = true;
}

function createNotification(int $userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null, array $opts = []): bool {
    $conn = getDbConnection();
    if ($userId <= 0) return false;
    $type = trim($type);
    if ($type === '') $type = 'system';
    $title = trim($title);
    if ($title === '') return false;
    $title = substr($title, 0, 180);
    if ($linkUrl !== null) $linkUrl = substr(trim($linkUrl), 0, 255);

    $campaignId = (int)($opts['campaign_id'] ?? 0);
    $campaignId = $campaignId > 0 ? $campaignId : null;
    $triggeredBy = (int)($opts['triggered_by_user_id'] ?? 0);
    $triggeredBy = $triggeredBy > 0 ? $triggeredBy : null;
    $scope = trim((string)($opts['visibility_scope'] ?? 'direct'));
    if ($scope === '') $scope = 'direct';
    $scope = substr($scope, 0, 24);

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, campaign_id, triggered_by_user_id, type, visibility_scope, title, body, link_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('iiisssss', $userId, $campaignId, $triggeredBy, $type, $scope, $title, $body, $linkUrl);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getNotificationPreference(int $userId, string $type): array {
    $userId = (int)$userId;
    $type = trim($type);
    $defaultToastTypes = [
        'campaign.end_warning' => true,
        'campaign.pacing_risk' => true,
        'sales.followup_reminder' => true,
        'chat.message' => true,
        'chat.group_message' => true,
        'lead.created' => true,
        'lead.updated' => true,
    ];
    $defaultToast = !empty($defaultToastTypes[$type]);
    if ($userId <= 0 || $type === '') return ['enabled' => true, 'mode' => 'instant', 'toast' => $defaultToast];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT delivery_mode, is_enabled, show_toast FROM notification_preferences WHERE user_id = ? AND type = ? LIMIT 1");
    if (!$stmt) return ['enabled' => true, 'mode' => 'instant', 'toast' => $defaultToast];
    $stmt->bind_param('is', $userId, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) return ['enabled' => true, 'mode' => 'instant', 'toast' => $defaultToast];
    $enabled = (int)($row['is_enabled'] ?? 1) === 1;
    $mode = (string)($row['delivery_mode'] ?? 'instant');
    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
    $toast = (int)($row['show_toast'] ?? 0) === 1;
    return ['enabled' => $enabled, 'mode' => $mode, 'toast' => $toast];
}

function createNotificationSmart(int $userId, string $type, string $title, ?string $body = null, ?string $linkUrl = null, array $opts = []): bool {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    if ($userId <= 0) return false;
    $type = trim($type);
    if ($type === '') $type = 'system';
    $campaignId = (int)($opts['campaign_id'] ?? 0);
    $campaignId = $campaignId > 0 ? $campaignId : null;
    $triggeredBy = (int)($opts['triggered_by_user_id'] ?? 0);
    $triggeredBy = $triggeredBy > 0 ? $triggeredBy : null;
    $scope = trim((string)($opts['visibility_scope'] ?? ($campaignId ? 'campaign' : 'direct')));
    if ($scope === '') $scope = ($campaignId ? 'campaign' : 'direct');
    $scope = substr($scope, 0, 24);
    $title = trim($title);
    if ($title === '') return false;
    $title = substr($title, 0, 180);
    if ($linkUrl !== null) $linkUrl = substr(trim($linkUrl), 0, 255);

    $pref = getNotificationPreference($userId, $type);
    if (empty($pref['enabled'])) return false;
    $mode = (string)($pref['mode'] ?? 'instant');

    $importance = trim((string)($opts['importance'] ?? 'normal'));
    if (!in_array($importance, ['low','normal','high'], true)) $importance = 'normal';
    $showToast = !empty($opts['show_toast']) || (!empty($pref['toast']));

    $dedupKey = trim((string)($opts['dedup_key'] ?? ''));
    if ($dedupKey === '') {
        $dedupKey = sha1(($campaignId ? ('c:' . (string)$campaignId) : 'c:0') . '|' . $type . '|' . $title . '|' . (string)$linkUrl . '|' . (string)$body);
    } else {
        $dedupKey = sha1(($campaignId ? ('c:' . (string)$campaignId) : 'c:0') . '|' . $type . '|' . $dedupKey);
    }
    $dedupWindowMin = (int)($opts['dedup_window_min'] ?? 10);
    if ($dedupWindowMin < 0) $dedupWindowMin = 0;
    if ($dedupWindowMin > 1440) $dedupWindowMin = 1440;

    if ($dedupWindowMin > 0) {
        $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = ? AND dedup_key = ? AND created_at >= (NOW() - INTERVAL ? MINUTE) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('issi', $userId, $type, $dedupKey, $dedupWindowMin);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row()[0] ?? null;
            $stmt->close();
            if ($exists) return true;
        }
        $stmt = $conn->prepare("SELECT id FROM notification_digest_queue WHERE user_id = ? AND type = ? AND dedup_key = ? AND processed_at IS NULL AND created_at >= (NOW() - INTERVAL ? MINUTE) LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('issi', $userId, $type, $dedupKey, $dedupWindowMin);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row()[0] ?? null;
            $stmt->close();
            if ($exists) return true;
        }
    }

    if ($mode === 'digest') {
    $stmt = $conn->prepare("INSERT INTO notification_digest_queue (user_id, campaign_id, triggered_by_user_id, type, visibility_scope, title, body, link_url, dedup_key) VALUES (?,?,?,?,?,?,?,?,?)");
        if (!$stmt) return false;
    $types = 'iii' . str_repeat('s', 6);
    $stmt->bind_param($types, $userId, $campaignId, $triggeredBy, $type, $scope, $title, $body, $linkUrl, $dedupKey);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, campaign_id, triggered_by_user_id, type, visibility_scope, title, body, link_url, dedup_key, importance, show_toast) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) return false;
    $toastVal = $showToast ? 1 : 0;
    $types = 'iii' . str_repeat('s', 7) . 'i';
    $stmt->bind_param($types, $userId, $campaignId, $triggeredBy, $type, $scope, $title, $body, $linkUrl, $dedupKey, $importance, $toastVal);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getUserCampaignAccessMapForNotifications(int $userId): ?array {
    static $cache = [];
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    if (isset($cache[$userId])) return $cache[$userId];

    $conn = getDbConnection();
    $stmtU = $conn->prepare("SELECT role, client_id, vendor_id FROM users WHERE id = ? LIMIT 1");
    $role = '';
    $clientId = 0;
    $vendorId = 0;
    if ($stmtU) {
        $stmtU->bind_param('i', $userId);
        $stmtU->execute();
        $u = $stmtU->get_result()->fetch_assoc() ?: [];
        $stmtU->close();
        $role = strtolower(trim((string)($u['role'] ?? '')));
        $clientId = (int)($u['client_id'] ?? 0);
        $vendorId = (int)($u['vendor_id'] ?? 0);
    }
    if ($role === 'admin') {
        $cache[$userId] = null;
        return null;
    }

    $ids = [];
    $stmt = $conn->prepare("SELECT id FROM campaigns WHERE owner_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $cid = (int)($r['id'] ?? 0);
            if ($cid > 0) $ids[$cid] = true;
        }
    }
    foreach (getUserAssignedCampaignIds($userId, ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']) as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) $ids[$cid] = true;
    }

    $teamIds = [];
    ensureTeamSchema();
    $stmtT = $conn->prepare("SELECT DISTINCT team_id FROM team_members WHERE user_id = ?");
    if ($stmtT) {
        $stmtT->bind_param('i', $userId);
        $stmtT->execute();
        $rows = $stmtT->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmtT->close();
        foreach ($rows as $r) {
            $tid = (int)($r['team_id'] ?? 0);
            if ($tid > 0) $teamIds[$tid] = true;
        }
    }
    $stmtTm = $conn->prepare("SELECT id FROM teams WHERE manager_user_id = ?");
    if ($stmtTm) {
        $stmtTm->bind_param('i', $userId);
        $stmtTm->execute();
        $rows = $stmtTm->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmtTm->close();
        foreach ($rows as $r) {
            $tid = (int)($r['id'] ?? 0);
            if ($tid > 0) $teamIds[$tid] = true;
        }
    }
    if (!empty($teamIds)) {
        $in = implode(',', array_fill(0, count($teamIds), '?'));
        $types = str_repeat('i', count($teamIds));
        $stmtC = $conn->prepare("SELECT DISTINCT campaign_id FROM team_campaigns WHERE team_id IN ($in)");
        if ($stmtC) {
            $tids = array_keys($teamIds);
            mysqliBindParams($stmtC, $types, $tids);
            $stmtC->execute();
            $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtC->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $ids[$cid] = true;
            }
        }
    }

    if ($clientId > 0) {
        $stmtC = $conn->prepare("SELECT DISTINCT campaign_id FROM campaign_details WHERE client_id = ?");
        if ($stmtC) {
            $stmtC->bind_param('i', $clientId);
            $stmtC->execute();
            $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtC->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $ids[$cid] = true;
            }
        }
    }
    if ($vendorId > 0) {
        $stmtV = $conn->prepare("SELECT DISTINCT campaign_id FROM vendor_campaign_map WHERE vendor_id = ?");
        if ($stmtV) {
            $stmtV->bind_param('i', $vendorId);
            $stmtV->execute();
            $rows = $stmtV->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtV->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $ids[$cid] = true;
            }
        }
    }

    $cache[$userId] = $ids;
    return $ids;
}

function buildCampaignAccessSqlFilterForNotifications(int $userId, string $campaignColumn = 'campaign_id'): array {
    $map = getUserCampaignAccessMapForNotifications($userId);
    if ($map === null) return ['sql' => '1=1', 'types' => '', 'params' => []];
    $ids = array_keys($map);
    if (empty($ids)) {
        return ['sql' => "({$campaignColumn} IS NULL AND type NOT LIKE 'campaign.%')", 'types' => '', 'params' => []];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return ['sql' => "(({$campaignColumn} IS NULL AND type NOT LIKE 'campaign.%') OR {$campaignColumn} IN ($in))", 'types' => str_repeat('i', count($ids)), 'params' => array_map('intval', $ids)];
}

function getUnreadNotificationCount(int $userId): int {
    $conn = getDbConnection();
    $filter = buildCampaignAccessSqlFilterForNotifications($userId, 'campaign_id');
    $sql = "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0 AND " . $filter['sql'];
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $types = 'i' . (string)($filter['types'] ?? '');
    $params = array_merge([(int)$userId], (array)($filter['params'] ?? []));
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
    $stmt->close();
    return (int)($row['cnt'] ?? 0);
}

function getUnreadToastNotifications(int $userId, int $limit = 3): array {
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $limit = max(1, min(5, $limit));
    $filter = buildCampaignAccessSqlFilterForNotifications($userId, 'campaign_id');
    $sql = "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 AND show_toast = 1 AND " . $filter['sql'] . " ORDER BY created_at DESC LIMIT " . (int)$limit;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $types = 'i' . (string)($filter['types'] ?? '');
    $params = array_merge([(int)$userId], (array)($filter['params'] ?? []));
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getUserNotifications(int $userId, int $limit = 12): array {
    $conn = getDbConnection();
    $limit = max(1, min(50, $limit));
    $filter = buildCampaignAccessSqlFilterForNotifications($userId, 'campaign_id');
    $sql = "SELECT * FROM notifications WHERE user_id = ? AND " . $filter['sql'] . " ORDER BY created_at DESC LIMIT " . (int)$limit;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $types = 'i' . (string)($filter['types'] ?? '');
    $params = array_merge([(int)$userId], (array)($filter['params'] ?? []));
    mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function markNotificationRead(int $userId, int $notificationId): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $notificationId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function markAllNotificationsRead(int $userId): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function notifyUsers(array $userIds, string $type, string $title, string $message, string $link = '', array $opts = []): void {
    $ids = [];
    foreach ($userIds as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[$id] = true;
    }
    if (empty($ids)) return;
    foreach (array_keys($ids) as $uid) {
        createNotificationSmart($uid, $type, $title, $message, $link, $opts);
    }
}

function getCampaignNotificationRecipientIds(int $campaignId, array $opts = []): array {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return [];
    $conn = getDbConnection();

    $includeAdmins = array_key_exists('include_admins', $opts) ? (bool)$opts['include_admins'] : true;
    $includeClients = !empty($opts['include_clients']);
    $includeVendors = !empty($opts['include_vendors']);

    $ids = [];
    if ($includeAdmins) {
        $rs = $conn->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        $rows = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
        foreach ($rows as $r) {
            $uid = (int)($r['id'] ?? 0);
            if ($uid > 0) $ids[$uid] = true;
        }
    }

    $stmtO = $conn->prepare("SELECT owner_id FROM campaigns WHERE id = ? LIMIT 1");
    if ($stmtO) {
        $stmtO->bind_param('i', $campaignId);
        $stmtO->execute();
        $ownerId = (int)(($stmtO->get_result()->fetch_assoc() ?: [])['owner_id'] ?? 0);
        $stmtO->close();
        if ($ownerId > 0) $ids[$ownerId] = true;
    }

    foreach (getCampaignAssignedUserIds($campaignId, ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']) as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) $ids[$uid] = true;
    }

    ensureTeamSchema();
    $teamIds = [];
    $rsT = $conn->query("SELECT DISTINCT team_id FROM team_campaigns WHERE campaign_id = " . (int)$campaignId);
    $tRows = $rsT ? ($rsT->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    foreach ($tRows as $r) {
        $tid = (int)($r['team_id'] ?? 0);
        if ($tid > 0) $teamIds[$tid] = true;
    }
    foreach (array_keys($teamIds) as $tid) {
        $stmtM = $conn->prepare("SELECT user_id FROM team_members WHERE team_id = ?");
        if ($stmtM) {
            $stmtM->bind_param('i', $tid);
            $stmtM->execute();
            $rows = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtM->close();
            foreach ($rows as $r) {
                $uid = (int)($r['user_id'] ?? 0);
                if ($uid > 0) $ids[$uid] = true;
            }
        }
        $stmtMgr = $conn->prepare("SELECT manager_user_id FROM teams WHERE id = ? LIMIT 1");
        if ($stmtMgr) {
            $stmtMgr->bind_param('i', $tid);
            $stmtMgr->execute();
            $mgrId = (int)(($stmtMgr->get_result()->fetch_assoc() ?: [])['manager_user_id'] ?? 0);
            $stmtMgr->close();
            if ($mgrId > 0) $ids[$mgrId] = true;
        }
    }

    if ($includeClients) {
        $stmtC = $conn->prepare("SELECT client_id FROM campaign_details WHERE campaign_id = ? LIMIT 1");
        if ($stmtC) {
            $stmtC->bind_param('i', $campaignId);
            $stmtC->execute();
            $clientId = (int)(($stmtC->get_result()->fetch_assoc() ?: [])['client_id'] ?? 0);
            $stmtC->close();
            if ($clientId > 0) {
                $stmtCU = $conn->prepare("SELECT id FROM users WHERE client_id = ? AND is_active = 1 AND role IN ('client_admin','client_sdr')");
                if ($stmtCU) {
                    $stmtCU->bind_param('i', $clientId);
                    $stmtCU->execute();
                    $rows = $stmtCU->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $stmtCU->close();
                    foreach ($rows as $r) {
                        $uid = (int)($r['id'] ?? 0);
                        if ($uid > 0) $ids[$uid] = true;
                    }
                }
            }
        }
    }

    if ($includeVendors) {
        $rsV = $conn->query("SELECT DISTINCT vendor_id FROM vendor_campaign_map WHERE campaign_id = " . (int)$campaignId);
        $vRows = $rsV ? ($rsV->fetch_all(MYSQLI_ASSOC) ?: []) : [];
        $vendorIds = [];
        foreach ($vRows as $r) {
            $vid = (int)($r['vendor_id'] ?? 0);
            if ($vid > 0) $vendorIds[$vid] = true;
        }
        foreach (array_keys($vendorIds) as $vid) {
            $stmtVU = $conn->prepare("SELECT user_id FROM vendor_user_map WHERE vendor_id = ?");
            if ($stmtVU) {
                $stmtVU->bind_param('i', $vid);
                $stmtVU->execute();
                $rows = $stmtVU->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmtVU->close();
                foreach ($rows as $r) {
                    $uid = (int)($r['user_id'] ?? 0);
                    if ($uid > 0) $ids[$uid] = true;
                }
            }
        }
    }

    $exclude = (int)($opts['exclude_user_id'] ?? 0);
    if ($exclude > 0) unset($ids[$exclude]);

    return array_keys($ids);
}

function notifyCampaignUsers(int $campaignId, string $type, string $title, string $message, string $link, array $opts = []): void {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return;
    $actorId = (int)($opts['triggered_by_user_id'] ?? 0);
    $recipients = getCampaignNotificationRecipientIds($campaignId, [
        'include_admins' => array_key_exists('include_admins', $opts) ? (bool)$opts['include_admins'] : true,
        'include_clients' => !empty($opts['include_clients']),
        'include_vendors' => array_key_exists('include_vendors', $opts) ? (bool)$opts['include_vendors'] : true,
        'exclude_user_id' => $actorId > 0 ? $actorId : 0,
    ]);
    if (empty($recipients)) return;

    $opts['campaign_id'] = $campaignId;
    $opts['visibility_scope'] = $opts['visibility_scope'] ?? 'campaign';
    notifyUsers($recipients, $type, $title, $message, $link, $opts);
}

function getCampaignAssignedUserIds(int $campaignId, array $tables = ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']): array {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return [];
    $conn = getDbConnection();
    $ids = [];
    foreach ($tables as $t) {
        $t = (string)$t;
        if (!preg_match('/^[a-z_]+$/', $t)) continue;
        $sql = "SELECT DISTINCT user_id FROM {$t} WHERE campaign_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid > 0) $ids[$uid] = true;
        }
    }
    return array_keys($ids);
}

function getUserAssignedCampaignIds(int $userId, array $tables = ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']): array {
    static $cache = [];
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    $cacheKey = $userId . '_' . implode(',', $tables);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    
    $conn = getDbConnection();
    $ids = [];
    foreach ($tables as $t) {
        $t = (string)$t;
        if (!preg_match('/^[a-z_]+$/', $t)) continue;
        $sql = "SELECT DISTINCT campaign_id FROM {$t} WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $r) {
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($cid > 0) $ids[$cid] = true;
        }
    }
    $result = array_keys($ids);
    $cache[$cacheKey] = $result;
    return $result;
}

function notifyCampaignEndWarningsForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;

    $conn = getDbConnection();
    $role = '';
    $clientId = 0;
    $vendorId = 0;
    $stmtU = $conn->prepare('SELECT role, client_id, vendor_id FROM users WHERE id = ? LIMIT 1');
    if ($stmtU) {
        $stmtU->bind_param('i', $userId);
        $stmtU->execute();
        $u = $stmtU->get_result()->fetch_assoc() ?: [];
        $stmtU->close();
        $role = strtolower((string)($u['role'] ?? ''));
        $clientId = (int)($u['client_id'] ?? 0);
        $vendorId = (int)($u['vendor_id'] ?? 0);
    }

    $campaignIds = [];
    if (str_starts_with($role, 'client_') && $clientId > 0) {
        $stmtC = $conn->prepare('SELECT DISTINCT campaign_id FROM campaign_details WHERE client_id = ?');
        if ($stmtC) {
            $stmtC->bind_param('i', $clientId);
            $stmtC->execute();
            $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtC->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $campaignIds[$cid] = true;
            }
        }
    } elseif (str_starts_with($role, 'vendor_') && $vendorId > 0) {
        $stmtV = $conn->prepare('SELECT DISTINCT campaign_id FROM vendor_campaign_map WHERE vendor_id = ?');
        if ($stmtV) {
            $stmtV->bind_param('i', $vendorId);
            $stmtV->execute();
            $rows = $stmtV->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmtV->close();
            foreach ($rows as $r) {
                $cid = (int)($r['campaign_id'] ?? 0);
                if ($cid > 0) $campaignIds[$cid] = true;
            }
        }
    } else {
        foreach (getUserAssignedCampaignIds($userId) as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) $campaignIds[$cid] = true;
        }
    }

    $campaignIds = array_keys($campaignIds);
    if (empty($campaignIds)) return;
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = str_repeat('i', count($campaignIds));
    $stmt = $conn->prepare("
        SELECT c.id, c.name, d.end_date, d.status
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.id IN ($in)
    ");
    if (!$stmt) return;
    mysqliBindParams($stmt, $types, $campaignIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $cid = (int)($r['id'] ?? 0);
        $endDate = (string)($r['end_date'] ?? '');
        if ($cid <= 0 || $endDate === '') continue;
        $daysLeft = (int)floor((strtotime($endDate) - strtotime($today)) / 86400);
        $bucket = null;
        if ($daysLeft < 0) $bucket = 'overdue';
        elseif ($daysLeft <= 1) $bucket = 'd1';
        elseif ($daysLeft <= 3) $bucket = 'd3';
        elseif ($daysLeft <= 7) $bucket = 'd7';
        if ($bucket === null) continue;

        $campName = (string)($r['name'] ?? '');
        $title = $daysLeft < 0 ? 'Campaign end date passed' : 'Campaign ending soon';
        $msg = ($campName !== '' ? $campName : ('Campaign #' . $cid)) . ' · End: ' . $endDate;
        if ($daysLeft >= 0) $msg .= ' · ' . $daysLeft . ' day(s) left';
        $link = '../campaigns/view?id=' . $cid;
        createNotificationSmart($userId, 'campaign.end_warning', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'campaign_end:' . $cid . ':' . $bucket,
            'dedup_window_min' => 720,
            'campaign_id' => $cid,
            'visibility_scope' => 'campaign',
        ]);
    }
}

function getInternalActiveUserIds(): array {
    $conn = getDbConnection();
    $rows = [];
    $rs = $conn->query("SELECT id FROM users WHERE is_active = 1 AND (client_id IS NULL OR client_id = 0) AND (vendor_id IS NULL OR vendor_id = 0)");
    if ($rs) $rows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    return $ids;
}

function notifyCampaignCreated(int $campaignId, int $actorId = 0): void {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $name = trim((string)($row['name'] ?? ''));
    $code = trim((string)($row['code'] ?? ''));
    $status = trim((string)($row['status'] ?? ''));
    $title = 'New campaign created';
    $msg = ($name !== '' ? $name : ('Campaign #' . $campaignId));
    if ($code !== '') $msg .= ' · ' . $code;
    if ($status !== '') $msg .= ' · Status: ' . $status;
    $link = '../campaigns/view?id=' . $campaignId;

    notifyCampaignUsers($campaignId, 'campaign.created', $title, $msg, $link, [
        'importance' => 'high',
        'show_toast' => true,
        'dedup_key' => 'camp_created:' . $campaignId,
        'dedup_window_min' => 120,
        'triggered_by_user_id' => (int)$actorId,
        'visibility_scope' => 'campaign',
    ]);
}

function notifyCampaignUpdated(int $campaignId, int $actorId = 0, array $meta = []): void {
    $campaignId = (int)$campaignId;
    if ($campaignId <= 0) return;
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT c.name, d.code, d.status FROM campaigns c JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $name = trim((string)($row['name'] ?? ''));
    $code = trim((string)($row['code'] ?? ''));
    $status = trim((string)($row['status'] ?? ''));
    $title = 'Campaign updated';
    $msg = ($name !== '' ? $name : ('Campaign #' . $campaignId));
    if ($code !== '') $msg .= ' · ' . $code;
    if ($status !== '') $msg .= ' · Status: ' . $status;
    if (!empty($meta['by'])) $msg .= ' · By: ' . (string)$meta['by'];
    $link = '../campaigns/view?id=' . $campaignId;

    notifyCampaignUsers($campaignId, 'campaign.updated', $title, $msg, $link, [
        'importance' => 'high',
        'show_toast' => true,
        'dedup_key' => 'camp_updated:' . $campaignId,
        'dedup_window_min' => 10,
        'triggered_by_user_id' => (int)$actorId,
        'visibility_scope' => 'campaign',
    ]);
}

function notifyCampaignPacingRiskForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $campaignIds = getUserAssignedCampaignIds($userId, ['operations_campaign_assignments','qa_campaign_assignments','campaign_user_assignments']);
    if (empty($campaignIds)) return;

    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $types = str_repeat('i', count($campaignIds));
    $stmt = $conn->prepare("
        SELECT c.id, c.name, d.status, d.start_date, d.end_date, d.total_leads, d.pacing_type, d.pacing_count
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.id IN ($in)
    ");
    if (!$stmt) return;
    mysqliBindParams($stmt, $types, $campaignIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $today = date('Y-m-d');
    foreach ($rows as $r) {
        $cid = (int)($r['id'] ?? 0);
        if ($cid <= 0) continue;
        $status = (string)($r['status'] ?? '');
        if (!in_array($status, ['Active','Live'], true)) continue;

        $start = (string)($r['start_date'] ?? '');
        if ($start === '' || strtotime($start) === false) continue;
        if (strtotime($start) > strtotime($today)) continue;

        $end = (string)($r['end_date'] ?? '');
        if ($end !== '' && strtotime($end) !== false && strtotime($end) < strtotime($today)) continue;

        $totalLeads = (int)($r['total_leads'] ?? 0);
        $pacingType = trim((string)($r['pacing_type'] ?? ''));
        $pacingCount = (int)($r['pacing_count'] ?? 0);

        $daysElapsed = (int)floor((strtotime($today) - strtotime($start)) / 86400) + 1;
        if ($daysElapsed < 1) $daysElapsed = 1;

        $expected = 0;
        if ($pacingCount > 0 && $pacingType !== '') {
            if ($pacingType === 'Daily') $expected = $pacingCount * $daysElapsed;
            elseif ($pacingType === 'Weekly') $expected = $pacingCount * (int)max(1, ceil($daysElapsed / 7));
            elseif ($pacingType === 'Monthly') $expected = $pacingCount * (int)max(1, ceil($daysElapsed / 30));
        } elseif ($totalLeads > 0 && $end !== '' && strtotime($end) !== false) {
            $totalDays = (int)floor((strtotime($end) - strtotime($start)) / 86400) + 1;
            if ($totalDays < 1) $totalDays = 1;
            $expected = (int)ceil($totalLeads * min(1, max(0, $daysElapsed / $totalDays)));
        }
        if ($expected <= 0) continue;
        if ($totalLeads > 0) $expected = min($expected, $totalLeads);

        $stmt2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM leads WHERE campaign_id = ? AND client_delivery_status = 'Delivered'");
        if (!$stmt2) continue;
        $stmt2->bind_param('i', $cid);
        $stmt2->execute();
        $delivered = (int)(($stmt2->get_result()->fetch_assoc() ?: [])['cnt'] ?? 0);
        $stmt2->close();

        $threshold = (int)floor($expected * 0.8);
        if ($delivered >= $threshold) continue;

        $campName = (string)($r['name'] ?? '');
        $title = 'Low delivery pacing';
        $msg = ($campName !== '' ? $campName : ('Campaign #' . $cid)) . ' · Delivered: ' . $delivered . ' · Expected: ' . $expected;
        $link = '../campaigns/view?id=' . $cid;
        createNotificationSmart($userId, 'campaign.pacing_risk', $title, $msg, $link, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'pacing:' . $cid . ':' . $today,
            'dedup_window_min' => 1440,
            'campaign_id' => $cid,
            'visibility_scope' => 'campaign',
        ]);
    }
}

function notifyLeadUpdated(int $leadDbId, int $actorId = 0, array $changedFields = []): void {
    $leadDbId = (int)$leadDbId;
    if ($leadDbId <= 0) return;
    $lead = getLeadById($leadDbId);
    if (!$lead) return;
    $campaignId = (int)($lead['campaign_id'] ?? 0);

    $recipients = [];
    if ($campaignId > 0) {
        foreach (getCampaignNotificationRecipientIds($campaignId, ['include_admins' => true, 'include_vendors' => true]) as $uid) {
            if ((int)$uid > 0) $recipients[(int)$uid] = true;
        }
    }
    $agentId = (int)($lead['agent_id'] ?? 0);
    if ($agentId > 0) $recipients[$agentId] = true;
    $assignedTo = (int)($lead['assigned_to_user'] ?? 0);
    if ($assignedTo > 0) $recipients[$assignedTo] = true;
    $createdBy = (int)($lead['created_by'] ?? 0);
    if ($createdBy > 0) $recipients[$createdBy] = true;
    if ($actorId > 0) unset($recipients[(int)$actorId]);
    if (empty($recipients)) return;

    $leadLabel = (string)($lead['lead_id'] ?? ('#' . (string)$leadDbId));
    $campName = (string)($lead['campaign_name'] ?? '');
    $title = 'Lead updated';
    $msg = $leadLabel . ($campName !== '' ? (' · ' . $campName) : '');
    if (!empty($changedFields)) {
        $safe = array_slice(array_values(array_filter(array_map('strval', $changedFields))), 0, 6);
        if (!empty($safe)) $msg .= ' · Fields: ' . implode(', ', $safe);
    }
    $link = '../leads/lead-details.php?id=' . $leadDbId;

    foreach (array_keys($recipients) as $uid) {
        createNotificationSmart((int)$uid, 'lead.updated', $title, $msg, $link, [
            'importance' => 'normal',
            'show_toast' => false,
            'dedup_key' => 'lead_updated:' . $leadDbId . ':' . sha1(implode('|', $changedFields)),
            'dedup_window_min' => 2,
            'campaign_id' => $campaignId > 0 ? $campaignId : null,
            'triggered_by_user_id' => (int)$actorId,
            'visibility_scope' => $campaignId > 0 ? 'campaign' : 'direct',
        ]);
    }

    $cdChanged = in_array('client_delivery_status', array_map('strval', $changedFields), true);
    $cds = function_exists('normalizeClientDeliveryStatus') ? normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending')) : (string)($lead['client_delivery_status'] ?? 'Pending');
    if ($cdChanged && strtolower($cds) === 'delivered') {
        $clientId = (int)($lead['client_id'] ?? 0);
        if ($clientId <= 0 && $campaignId > 0 && function_exists('getCampaignClientId')) {
            $clientId = (int)(getCampaignClientId($campaignId) ?? 0);
        }
        if ($clientId > 0) {
            $conn = getDbConnection();
            $stmtC = $conn->prepare("SELECT id FROM users WHERE client_id = ? AND is_active = 1 ORDER BY id");
            $clientUserIds = [];
            if ($stmtC) {
                $stmtC->bind_param('i', $clientId);
                $stmtC->execute();
                $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmtC->close();
                foreach ($rows as $r) {
                    $uid = (int)($r['id'] ?? 0);
                    if ($uid > 0) $clientUserIds[$uid] = true;
                }
            }
            if (!empty($clientUserIds)) {
                $leadLabel2 = (string)($lead['lead_id'] ?? ('#' . (string)$leadDbId));
                $campName2 = (string)($lead['campaign_name'] ?? '');
                $title2 = 'New Lead Delivered';
                $msg2 = $leadLabel2 . ($campName2 !== '' ? (' · ' . $campName2) : '');
                $base = function_exists('appBasePath') ? rtrim((string)appBasePath(), '/') : '';
                $link2 = $base . '/modules/clients/client-lead-details?id=' . $leadDbId;
                foreach (array_keys($clientUserIds) as $uid) {
                    createNotificationSmart((int)$uid, 'client.lead.delivered', $title2, $msg2, $link2, [
                        'importance' => 'high',
                        'show_toast' => true,
                        'dedup_key' => 'client_lead_delivered:' . $leadDbId,
                        'dedup_window_min' => 1440,
                        'campaign_id' => $campaignId > 0 ? $campaignId : null,
                        'triggered_by_user_id' => (int)$actorId,
                        'visibility_scope' => $campaignId > 0 ? 'campaign' : 'direct',
                    ]);
                }
            }
        }
    }
}

function notifyLeadCreated(int $leadDbId, int $campaignId, int $actorId = 0): void {
    $leadDbId = (int)$leadDbId;
    $campaignId = (int)$campaignId;
    if ($leadDbId <= 0 || $campaignId <= 0) return;
    $lead = getLeadById($leadDbId);
    if (!$lead) return;

    $recipients = [];
    foreach (getCampaignNotificationRecipientIds($campaignId, ['include_admins' => true, 'include_vendors' => true]) as $uid) {
        if ((int)$uid > 0) $recipients[(int)$uid] = true;
    }
    $agentId = (int)($lead['agent_id'] ?? 0);
    if ($agentId > 0) $recipients[$agentId] = true;
    $assignedTo = (int)($lead['assigned_to_user'] ?? 0);
    if ($assignedTo > 0) $recipients[$assignedTo] = true;
    if ($actorId > 0) unset($recipients[(int)$actorId]);

    $leadLabel = (string)($lead['lead_id'] ?? ('#' . (string)$leadDbId));
    $campName = (string)($lead['campaign_name'] ?? '');
    $title = 'New lead uploaded';
    $msg = $leadLabel . ($campName !== '' ? (' · ' . $campName) : '');
    $link = '../leads/lead-details.php?id=' . $leadDbId;
    notifyUsers(array_keys($recipients), 'lead.created', $title, $msg, $link, [
        'campaign_id' => $campaignId,
        'triggered_by_user_id' => (int)$actorId,
        'visibility_scope' => 'campaign',
        'importance' => 'normal',
        'show_toast' => false,
        'dedup_key' => 'lead_created:' . $leadDbId,
        'dedup_window_min' => 2,
    ]);

    $cds = function_exists('normalizeClientDeliveryStatus') ? normalizeClientDeliveryStatus((string)($lead['client_delivery_status'] ?? 'Pending')) : (string)($lead['client_delivery_status'] ?? 'Pending');
    if (strtolower($cds) === 'delivered') {
        $clientId = (int)($lead['client_id'] ?? 0);
        if ($clientId <= 0 && function_exists('getCampaignClientId')) {
            $clientId = (int)(getCampaignClientId($campaignId) ?? 0);
        }
        if ($clientId > 0) {
            $conn = getDbConnection();
            $stmtC = $conn->prepare("SELECT id FROM users WHERE client_id = ? AND is_active = 1 ORDER BY id");
            $clientUserIds = [];
            if ($stmtC) {
                $stmtC->bind_param('i', $clientId);
                $stmtC->execute();
                $rows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmtC->close();
                foreach ($rows as $r) {
                    $uid = (int)($r['id'] ?? 0);
                    if ($uid > 0) $clientUserIds[$uid] = true;
                }
            }
            if (!empty($clientUserIds)) {
                $base = function_exists('appBasePath') ? rtrim((string)appBasePath(), '/') : '';
                $link2 = $base . '/modules/clients/client-lead-details?id=' . $leadDbId;
                foreach (array_keys($clientUserIds) as $uid) {
                    createNotificationSmart((int)$uid, 'client.lead.delivered', 'New Lead Delivered', $msg, $link2, [
                        'importance' => 'high',
                        'show_toast' => true,
                        'dedup_key' => 'client_lead_delivered:' . $leadDbId,
                        'dedup_window_min' => 1440,
                        'campaign_id' => $campaignId,
                        'triggered_by_user_id' => (int)$actorId,
                        'visibility_scope' => 'campaign',
                    ]);
                }
            }
        }
    }
}

function ensureCampaignDetailsSchema(): void {}
function ensureProductivityTargetsSchema(): void {}
function ensureChatSchema(): void {
    $conn = getDbConnection();
    $sql = "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        group_id INT NULL,
        message TEXT,
        attachment_path VARCHAR(255),
        attachment_name VARCHAR(255) NULL,
        attachment_size INT NULL,
        attachment_mime VARCHAR(120) NULL,
        message_type VARCHAR(16) DEFAULT 'text',
        reply_to_id INT NULL,
        forwarded_from_id INT NULL,
        edited_at DATETIME NULL,
        edited_by INT NULL,
        delivered_at DATETIME NULL,
        read_at DATETIME,
        is_deleted TINYINT(1) DEFAULT 0,
        deleted_by INT NULL,
        deleted_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (sender_id),
        INDEX (receiver_id),
        INDEX (group_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);

    @$conn->query("ALTER TABLE chat_messages ADD COLUMN delivered_at DATETIME NULL AFTER attachment_path");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN group_id INT NULL AFTER receiver_id");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN message_type VARCHAR(16) DEFAULT 'text' AFTER attachment_path");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN attachment_size INT NULL AFTER attachment_name");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN attachment_mime VARCHAR(120) NULL AFTER attachment_size");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN reply_to_id INT NULL AFTER message_type");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN forwarded_from_id INT NULL AFTER reply_to_id");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN edited_at DATETIME NULL AFTER forwarded_from_id");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN edited_by INT NULL AFTER edited_at");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 AFTER read_at");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN deleted_by INT NULL AFTER is_deleted");
    @$conn->query("ALTER TABLE chat_messages ADD COLUMN deleted_at DATETIME NULL AFTER deleted_by");
    @$conn->query("ALTER TABLE chat_messages ADD INDEX (group_id)");
    @$conn->query("ALTER TABLE chat_messages ADD INDEX (created_at)");
    @$conn->query("ALTER TABLE chat_messages ADD INDEX (reply_to_id)");

    $sqlGroups = "CREATE TABLE IF NOT EXISTS chat_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(120) NOT NULL,
        group_type VARCHAR(16) NOT NULL DEFAULT 'private',
        department VARCHAR(80) NULL,
        description TEXT NULL,
        group_photo VARCHAR(255) NULL,
        visibility VARCHAR(16) NOT NULL DEFAULT 'private',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX (created_by),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlGroups);
    @$conn->query("ALTER TABLE chat_groups ADD COLUMN group_type VARCHAR(16) NOT NULL DEFAULT 'private' AFTER group_name");
    @$conn->query("ALTER TABLE chat_groups ADD COLUMN department VARCHAR(80) NULL AFTER group_type");
    @$conn->query("ALTER TABLE chat_groups ADD COLUMN description TEXT NULL AFTER department");
    @$conn->query("ALTER TABLE chat_groups ADD COLUMN group_photo VARCHAR(255) NULL AFTER description");
    @$conn->query("ALTER TABLE chat_groups ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'private' AFTER group_photo");
    @$conn->query("ALTER TABLE chat_groups ADD COLUMN updated_at DATETIME NULL AFTER created_at");

    $sqlMembers = "CREATE TABLE IF NOT EXISTS chat_group_members (
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT 'member',
        added_by INT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_read_message_id INT NOT NULL DEFAULT 0,
        PRIMARY KEY (group_id, user_id),
        INDEX (user_id),
        INDEX (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlMembers);
    @$conn->query("ALTER TABLE chat_group_members ADD COLUMN last_read_message_id INT NOT NULL DEFAULT 0");
    
    $sqlPresence = "CREATE TABLE IF NOT EXISTS user_presence (
        user_id INT PRIMARY KEY,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_online TINYINT(1) DEFAULT 0,
        status VARCHAR(20) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlPresence);
    @$conn->query("ALTER TABLE user_presence ADD COLUMN status VARCHAR(20) NULL AFTER is_online");

    $sqlReactions = "CREATE TABLE IF NOT EXISTS chat_message_reactions (
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        reaction VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (message_id, user_id, reaction),
        INDEX idx_message (message_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sqlReactions);
}

function dfChatIsSalesRole(string $role): bool {
    $role = strtolower(trim($role));
    return in_array($role, ['sdr','sales_manager','sales_director'], true);
}

function dfChatConnectedSalesUserIdsForClient(int $clientId): array {
    $clientId = (int)$clientId;
    if ($clientId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $ids = [];
    $stmt = $conn->prepare("SELECT owner_id, manager_id FROM sales_client_ownership WHERE client_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        foreach (['owner_id','manager_id'] as $k) {
            $uid = (int)($r[$k] ?? 0);
            if ($uid > 0) $ids[$uid] = true;
        }
    }
    if (empty($ids)) return [];
    $uids = array_keys($ids);
    $in = implode(',', array_fill(0, count($uids), '?'));
    $types = str_repeat('i', count($uids));
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE is_active = 1 AND id IN ($in)");
    if (!$stmt) return [];
    mysqliBindParams($stmt, $types, $uids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $out = [];
    foreach ($rows as $u) {
        $id = (int)($u['id'] ?? 0);
        if ($id <= 0) continue;
        if (!dfChatIsSalesRole((string)($u['role'] ?? ''))) continue;
        $out[$id] = true;
    }
    return array_keys($out);
}

function dfChatConnectedSalesUserIdsForVendor(int $vendorId): array {
    $vendorId = (int)$vendorId;
    if ($vendorId <= 0) return [];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $ids = [];

    $stmt = $conn->prepare("SELECT DISTINCT campaign_id, assigned_by FROM vendor_campaign_map WHERE vendor_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $vendorId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        $campIds = [];
        foreach ($rows as $r) {
            $ab = (int)($r['assigned_by'] ?? 0);
            if ($ab > 0) $ids[$ab] = true;
            $cid = (int)($r['campaign_id'] ?? 0);
            if ($cid > 0) $campIds[$cid] = true;
        }
        $campIds = array_keys($campIds);
        if (!empty($campIds)) {
            $in = implode(',', array_fill(0, count($campIds), '?'));
            $types = str_repeat('i', count($campIds));
            $stmtC = $conn->prepare("SELECT DISTINCT client_id FROM campaign_details WHERE campaign_id IN ($in) AND client_id IS NOT NULL");
            if ($stmtC) {
                mysqliBindParams($stmtC, $types, $campIds);
                $stmtC->execute();
                $crows = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmtC->close();
                foreach ($crows as $cr) {
                    $clientId = (int)($cr['client_id'] ?? 0);
                    foreach (dfChatConnectedSalesUserIdsForClient($clientId) as $sid) {
                        $ids[(int)$sid] = true;
                    }
                }
            }
        }
    }

    if (empty($ids)) return [];
    $uids = array_keys($ids);
    $in = implode(',', array_fill(0, count($uids), '?'));
    $types = str_repeat('i', count($uids));
    $stmtU = $conn->prepare("SELECT id, role FROM users WHERE is_active = 1 AND id IN ($in)");
    if (!$stmtU) return [];
    mysqliBindParams($stmtU, $types, $uids);
    $stmtU->execute();
    $rows = $stmtU->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmtU->close();
    $out = [];
    foreach ($rows as $u) {
        $id = (int)($u['id'] ?? 0);
        if ($id <= 0) continue;
        if (!dfChatIsSalesRole((string)($u['role'] ?? ''))) continue;
        $out[$id] = true;
    }
    return array_keys($out);
}

function dfChatAllowedPeerIdsForUser(array $user): ?array {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) return null;
    $role = strtolower((string)($user['role'] ?? ''));
    $conn = getDbConnection();

    if (str_starts_with($role, 'client_')) {
        $clientId = (int)($user['client_id'] ?? 0);
        $allowed = [];
        if ($clientId > 0) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND client_id = ? AND role LIKE 'client_%' AND id <> ?");
            if ($stmt) {
                $stmt->bind_param('ii', $clientId, $uid);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt->close();
                foreach ($rows as $r) { $id = (int)($r['id'] ?? 0); if ($id > 0) $allowed[$id] = true; }
            }
            foreach (dfChatConnectedSalesUserIdsForClient($clientId) as $sid) {
                $sid = (int)$sid;
                if ($sid > 0 && $sid !== $uid) $allowed[$sid] = true;
            }
        }
        return array_keys($allowed);
    }

    if (str_starts_with($role, 'vendor_')) {
        $vendorId = (int)($user['vendor_id'] ?? 0);
        $allowed = [];
        if ($vendorId > 0) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND vendor_id = ? AND role LIKE 'vendor_%' AND id <> ?");
            if ($stmt) {
                $stmt->bind_param('ii', $vendorId, $uid);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt->close();
                foreach ($rows as $r) { $id = (int)($r['id'] ?? 0); if ($id > 0) $allowed[$id] = true; }
            }
            foreach (dfChatConnectedSalesUserIdsForVendor($vendorId) as $sid) {
                $sid = (int)$sid;
                if ($sid > 0 && $sid !== $uid) $allowed[$sid] = true;
            }
        }
        return array_keys($allowed);
    }

    return null;
}
function ensureCampaignDeliverySchema(): void {}
function ensureCampaignMetricsSchema(): void {
    $conn = getDbConnection();
    $sql = "CREATE TABLE IF NOT EXISTS campaign_metrics (
        campaign_id INT PRIMARY KEY,
        delivered INT DEFAULT 0,
        generated INT DEFAULT 0,
        qualified INT DEFAULT 0,
        disqualified INT DEFAULT 0,
        pending INT DEFAULT 0,
        rejected INT DEFAULT 0,
        updated_by INT,
        updated_at DATETIME,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
}

function createOrReuseForm(string $name, array $schema, ?int $createdBy): int {
    $conn = getDbConnection();
    $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($schemaJson === false) {
        throw new RuntimeException('Invalid form schema');
    }
    $fingerprint = hash('sha256', $schemaJson);

    $stmt = $conn->prepare("INSERT IGNORE INTO forms (name, fingerprint, schema_json, created_by) VALUES (?, ?, ?, ?)");
    $createdByInt = $createdBy ?? null;
    $stmt->bind_param("sssi", $name, $fingerprint, $schemaJson, $createdByInt);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT id FROM forms WHERE fingerprint = ? LIMIT 1");
    $stmt2->bind_param("s", $fingerprint);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if (!$row) {
        throw new RuntimeException('Failed to create or reuse form');
    }
    return (int)$row['id'];
}

function assignFormToCampaign(int $campaignId, int $formId, int $assignedBy = 0): bool {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO campaign_forms (campaign_id, form_id, assigned_by, assigned_at, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, NOW())
        ON DUPLICATE KEY UPDATE
            form_id = VALUES(form_id),
            assigned_by = VALUES(assigned_by),
            assigned_at = CURRENT_TIMESTAMP");
    $stmt->bind_param("iii", $campaignId, $formId, $assignedBy);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        ensureCampaignLeadTable($campaignId);
    }
    return $ok;
}

function getFormForCampaign(int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT f.id AS form_id, f.name, f.schema_json
        FROM campaign_forms cf
        JOIN forms f ON cf.form_id = f.id
        WHERE cf.campaign_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $schema = json_decode($row['schema_json'], true);
    if (!is_array($schema)) $schema = ['fields' => []];
    return [
        'form_id' => (int)$row['form_id'],
        'name' => $row['name'],
        'schema' => $schema,
    ];
}

function getSelectOptionsByFormSchema(array $schema, array $keys): array {
    $norm = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        return trim($s, '_');
    };
    $want = [];
    foreach ($keys as $k) {
        $nk = $norm((string)$k);
        if ($nk !== '') $want[$nk] = true;
    }
    if (empty($want)) return [];

    $fields = (array)($schema['fields'] ?? []);
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $key = $norm((string)($f['key'] ?? ''));
        if ($key === '' || !isset($want[$key])) continue;
        $type = strtolower(trim((string)($f['type'] ?? 'text')));
        if (!in_array($type, ['select','radio','checkbox'], true)) continue;
        $opts = $f['options'] ?? [];
        if (!is_array($opts) || empty($opts)) continue;
        $clean = [];
        $seen = [];
        foreach ($opts as $o) {
            $v = is_array($o) ? (string)($o['value'] ?? ($o['label'] ?? '')) : (string)$o;
            $v = trim($v);
            if ($v === '' || isset($seen[strtolower($v)])) continue;
            $seen[strtolower($v)] = true;
            $clean[] = $v;
        }
        if (!empty($clean)) $out[$key] = $clean;
    }
    return $out;
}

function valueInAllowedOptions(?string $value, array $options): bool {
    $normalize = function(string $s): string {
        $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\xA8", "\xE2\x80\xA9"], ' ', $s);
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return (string)$s;
    };
    $v = $normalize((string)$value);
    if ($v === '') return true;
    foreach ($options as $o) {
        $oo = $normalize((string)$o);
        if ($oo === '') continue;
        if (strcasecmp($v, $oo) === 0) return true;
    }
    return false;
}

function normalizeSubmissionKey(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

function extractSubmissionValue(array $submissionData, array $aliases) {
    if (empty($submissionData)) return null;
    $index = [];
    foreach ($submissionData as $k => $v) {
        $nk = normalizeSubmissionKey((string)$k);
        if ($nk === '') continue;
        $index[$nk] = $v;
    }
    foreach ($aliases as $a) {
        $a = (string)$a;
        if ($a === '') continue;
        if (array_key_exists($a, $submissionData)) return $submissionData[$a];
        $na = normalizeSubmissionKey($a);
        if ($na !== '' && array_key_exists($na, $index)) return $index[$na];
    }
    return null;
}

function deriveLeadDisplayFieldsFromSubmission(array $lead, ?string $submissionJson): array {
    $data = [];
    if (is_string($submissionJson) && trim($submissionJson) !== '') {
        $tmp = json_decode($submissionJson, true);
        if (is_array($tmp)) $data = $tmp;
    }

    $timeline = extractSubmissionValue($data, [
        'software_implementation_timeline',
        'implementation_timeline',
        'decision_timeline',
        'timeline',
        'cq1',
        'when_is_your_company_planning_to_implement_new_software',
        'when_is_your_company_planning_to_implement_this_solution',
        'when_is_your_company_planning_to_implement_new_software_solution',
    ]);
    if (is_array($timeline)) $timeline = implode(', ', array_map('strval', $timeline));
    $timeline = trim((string)($timeline ?? ''));
    if ($timeline !== '') $lead['software_implementation_timeline'] = $timeline;

    $website = extractSubmissionValue($data, ['company_website', 'website', 'domain', 'company_domain']);
    if (is_array($website)) $website = implode(', ', array_map('strval', $website));
    $website = trim((string)($website ?? ''));
    if ($website === '') $website = trim((string)($lead['company_domain'] ?? ''));
    if ($website !== '') $lead['company_website'] = $website;

    $li = extractSubmissionValue($data, [
        'linkedin_link',
        'linkedin_url',
        'linkedin_profile',
        'prospect_linkedin',
        'prospect_linkedin_link',
        'prospect_linkedin_url',
        'prospect_linkedin_profile',
    ]);
    if (is_array($li)) $li = implode(', ', array_map('strval', $li));
    $li = trim((string)($li ?? ''));
    if ($li !== '') $lead['linkedin_link'] = $li;

    $cli = extractSubmissionValue($data, [
        'company_linkedin',
        'company_linkedin_url',
        'company_linkedin_link',
        'companylinkedin',
        'companylinkedinurl',
    ]);
    if (is_array($cli)) $cli = implode(', ', array_map('strval', $cli));
    $cli = trim((string)($cli ?? ''));
    if ($cli !== '') $lead['company_linkedin'] = $cli;

    return $lead;
}

function getFormById(int $formId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id AS form_id, name, schema_json FROM forms WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $formId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $schema = json_decode($row['schema_json'], true);
    if (!is_array($schema)) $schema = ['fields' => []];
    return [
        'form_id' => (int)$row['form_id'],
        'name' => $row['name'],
        'schema' => $schema,
    ];
}

function getLatestFormSubmissionForLead(int $leadId, int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, form_id, submitted_by, submitted_at, data_json
        FROM form_submissions
        WHERE lead_id = ? AND campaign_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $leadId, $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $data = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($data)) $data = [];
    return [
        'id' => (int)($row['id'] ?? 0),
        'form_id' => (int)($row['form_id'] ?? 0),
        'submitted_by' => (int)($row['submitted_by'] ?? 0),
        'submitted_at' => $row['submitted_at'] ?? null,
        'data' => $data,
    ];
}

function getCampaignAssignedFormId(int $campaignId): ?int {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT form_id FROM campaign_forms WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fid = (int)($row['form_id'] ?? 0);
    return $fid > 0 ? $fid : null;
}

function setCampaignStatus(int $campaignId, string $status, int $updatedBy = 0): bool {
    $allowed = ['Draft','Active','Live','Pause','Complete'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid status.');
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT status FROM campaign_details WHERE campaign_id = ? LIMIT 1");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Campaign not found.');
    }
    $currentStatus = (string)($row['status'] ?? '');

    if ($status === 'Live' && $currentStatus !== 'Live') {
        $fid = getCampaignAssignedFormId($campaignId);
        if (!$fid) {
            throw new RuntimeException('Assign a lead form before switching status to Live.');
        }
    }

    $active = in_array($status, ['Active','Live'], true) ? 1 : 0;

    $stmt = $conn->prepare("UPDATE campaign_details SET status = ?, status_updated_by = ?, updated_at = NOW() WHERE campaign_id = ?");
    $stmt->bind_param('sii', $status, $updatedBy, $campaignId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Failed to update campaign status.');
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE campaigns SET active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('iii', $active, $updatedBy, $campaignId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $status === 'Pause') {
        $userName = 'System';
        if ($updatedBy > 0) {
            $stmtU = $conn->prepare("SELECT full_name, username FROM users WHERE id = ? LIMIT 1");
            if ($stmtU) {
                $stmtU->bind_param('i', $updatedBy);
                $stmtU->execute();
                $u = $stmtU->get_result()->fetch_assoc();
                $stmtU->close();
                if ($u) $userName = $u['full_name'] ?: $u['username'];
            }
        }
        $campName = 'Campaign';
        $stmtC = $conn->prepare("SELECT name FROM campaigns WHERE id = ? LIMIT 1");
        if ($stmtC) {
            $stmtC->bind_param('i', $campaignId);
            $stmtC->execute();
            $c = $stmtC->get_result()->fetch_assoc();
            $stmtC->close();
            if ($c) $campName = $c['name'];
        }

        $notifTitle = "Campaign Paused: $campName";
        $notifBody = "The campaign has been paused by $userName.";
        $linkUrl = "../campaigns/view?id=" . (int)$campaignId;

        notifyCampaignUsers($campaignId, 'campaign.status_changed', $notifTitle, $notifBody, $linkUrl, [
            'importance' => 'high',
            'show_toast' => true,
            'dedup_key' => 'camp_status_pause:' . (int)$campaignId,
            'dedup_window_min' => 30,
            'triggered_by_user_id' => (int)$updatedBy,
            'visibility_scope' => 'campaign',
            'include_clients' => true,
        ]);
    }

    return $ok;
}

function saveFormSubmission(int $formId, int $campaignId, ?int $leadId, int $submittedBy, array $data): bool {
    $conn = getDbConnection();
    $norm = function(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        return trim($s, '_');
    };
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
    foreach ($data as $k => $v) {
        if (!is_scalar($v)) continue;
        $nk = $norm((string)$k);
        if (in_array($nk, ['company_website','website','company_site','domain'], true)) {
            $data[$k] = $normalizeDomain((string)$v);
        }
    }
    $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($dataJson === false) $dataJson = '{}';
    $stmt = $conn->prepare("INSERT INTO form_submissions (form_id, campaign_id, lead_id, submitted_by, data_json) VALUES (?, ?, ?, ?, ?)");
    $leadIdInt = $leadId ?? null;
    $stmt->bind_param("iiiis", $formId, $campaignId, $leadIdInt, $submittedBy, $dataJson);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok && $leadIdInt) {
        $updates = [];
        foreach ($data as $k => $v) {
            $nk = $norm((string)$k);
            $val = is_array($v) ? implode(', ', array_map('strval', $v)) : (is_scalar($v) ? (string)$v : '');
            $val = trim($val);
            if ($val === '') continue;
            if ($nk === 'first_name') $updates['first_name'] = $val;
            elseif ($nk === 'last_name') $updates['last_name'] = $val;
            elseif ($nk === 'email') $updates['email'] = $val;
            elseif ($nk === 'phone' || $nk === 'contact_phone') $updates['contact_phone'] = $val;
            elseif (in_array($nk, ['linkedin','linkedin_link','linkedin_url','prospect_linkedin','prospect_linkedin_link','prospect_linkedin_url'], true)) $updates['linkedin_link'] = $val;
            elseif ($nk === 'company_name') $updates['company_name'] = $val;
            elseif (in_array($nk, ['company_linkedin','company_linkedin_link','company_linkedin_url'], true)) $updates['company_linkedin'] = $val;
            elseif ($nk === 'industry') $updates['industry'] = $val;
            elseif (in_array($nk, ['employee_size','employee_sizes','employees','headcount','company_size'], true)) $updates['company_size'] = $val;
            elseif ($nk === 'country') $updates['country'] = $val;
        }
        if (!empty($updates)) {
            $updates['updated_by'] = $submittedBy;
            updateLead((int)$leadIdInt, $updates);
        }
    }
    return $ok;
}

function saveLeadFieldFile(int $leadId, string $fieldId, array $file): ?string {
    if (empty($file['name']) || empty($file['tmp_name'])) return null;
    if (!is_dir(__DIR__ . '/../uploads/lead_files')) {
        @mkdir(__DIR__ . '/../uploads/lead_files', 0775, true);
    }
    $maxSize = 15 * 1024 * 1024;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ((int)($file['size'] ?? 0) > $maxSize) return null;

    $orig = (string)$file['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','txt','csv','xls','xlsx','png','jpg','jpeg','mp3','wav','m4a'];
    if ($ext !== '' && !in_array($ext, $allowed, true)) return null;

    $safeField = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fieldId);
    $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
    $safeBase = $safeBase !== '' ? $safeBase : 'file';
    $destName = 'L'.$leadId.'_'.$safeField.'_'.time().'_'.$safeBase.($ext ? '.'.$ext : '');
    $destAbs = __DIR__ . '/../uploads/lead_files/'.$destName;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) return null;
    $relPath = 'uploads/lead_files/'.$destName;

    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO lead_files (lead_id, field_id, file_path, uploaded_at) VALUES (?,?,?,NOW())");
    $stmt->bind_param('iss', $leadId, $fieldId, $relPath);
    $stmt->execute();
    $stmt->close();

    return $relPath;
}

function generateCampaignCode(string $clientCode): string {
    $clientCode = strtoupper(trim((string)$clientCode));
    $clientCode = preg_replace('/[^A-Z0-9]+/', '', $clientCode);
    if ($clientCode === '') {
        throw new RuntimeException('Client code is required to generate campaign code');
    }

    $conn = getDbConnection();
    $prefix = 'TG-' . $clientCode . '-';
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT code FROM campaign_details WHERE client_code = ? AND code LIKE ? ORDER BY code DESC LIMIT 2000");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare campaign code lookup');
    }
    $stmt->bind_param('ss', $clientCode, $like);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $max = 0;
    foreach ($rows as $r) {
        $c = (string)($r['code'] ?? '');
        if ($c === '' || !str_starts_with($c, $prefix)) continue;
        $suffix = substr($c, strlen($prefix));
        if ($suffix === '') continue;
        if (!ctype_digit($suffix)) continue;
        $n = (int)$suffix;
        if ($n > $max) $max = $n;
    }

    $next = $max + 1;
    for ($i = 0; $i < 20000; $i++) {
        $width = max(3, strlen((string)$next));
        $code = $prefix . str_pad((string)$next, $width, '0', STR_PAD_LEFT);
        $chk = $conn->prepare("SELECT 1 FROM campaign_details WHERE code = ? LIMIT 1");
        if (!$chk) return $code;
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = (bool)($chk->get_result()->fetch_row()[0] ?? false);
        $chk->close();
        if (!$exists) return $code;
        $next++;
    }
    throw new RuntimeException('Failed to generate unique campaign code');
}

/**
 * Save campaign files to uploads/campaign_files/* with validation
 */
function saveCampaignFiles(array $files, string $code): array {
    $baseDir = __DIR__ . '/../uploads/campaign_files';
    $dirs = [
        'script' => $baseDir . '/scripts',
        'tal' => $baseDir . '/tal',
        'suppression' => $baseDir . '/suppression',
        'recording' => $baseDir . '/recordings'
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
    }

    $saved = ['script_path' => null, 'tal_path' => null, 'suppression_path' => null, 'recording_path' => null];
    $maxSize = 10 * 1024 * 1024;

    // Helper for move_uploaded_file with type check
    $process = function($key, $dir, $allowed, $label) use ($files, $code, $maxSize) {
        if (empty($files[$key]['name'])) return null;
        $ext = strtolower(pathinfo($files[$key]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) throw new RuntimeException("Invalid file type for $label");
        if ($files[$key]['size'] > $maxSize) throw new RuntimeException("$label file too large");
        $target = $dir . "/{$code}_{$key}.$ext";
        if (!move_uploaded_file($files[$key]['tmp_name'], $target)) throw new RuntimeException("Failed to save $label");
        return basename($target);
    };

    try {
        if ($p = $process('script_file', $dirs['script'], ['doc','docx','pdf'], 'Script')) $saved['script_path'] = 'uploads/campaign_files/scripts/'.$p;
        if ($p = $process('tal_file', $dirs['tal'], ['csv','xls','xlsx'], 'TAL')) $saved['tal_path'] = 'uploads/campaign_files/tal/'.$p;
        if ($p = $process('suppression_file', $dirs['suppression'], ['csv','xls','xlsx'], 'Suppression')) $saved['suppression_path'] = 'uploads/campaign_files/suppression/'.$p;
        if ($p = $process('recording_file', $dirs['recording'], ['mp3','wav'], 'Recording')) $saved['recording_path'] = 'uploads/campaign_files/recordings/'.$p;
    } catch (RuntimeException $e) { throw $e; }

    return $saved;
}

function saveCampaignSetupFilesToDb(int $campaignId, array $filePaths, int $uploadedBy): void {
    $conn = getDbConnection();
    $map = [
        'script_path' => 'Script File',
        'tal_path' => 'TAL List',
        'suppression_path' => 'Suppression List',
        'recording_path' => 'Recording',
    ];
    foreach ($map as $k => $type) {
        $path = $filePaths[$k] ?? null;
        if (!$path) continue;
        $fileName = basename((string)$path);
        $uploaderId = $uploadedBy > 0 ? $uploadedBy : 0;
        $stmt = $conn->prepare("INSERT INTO campaign_delivery_files (campaign_id, uploader_id, format, notes, file_path, created_at, file_type, file_name, uploaded_by, uploaded_at)
            VALUES (?, ?, NULL, NULL, ?, NOW(), ?, ?, ?, NOW())");
        if (!$stmt) continue;
        $stmt->bind_param('iisssi', $campaignId, $uploaderId, $path, $type, $fileName, $uploaderId);
        $stmt->execute();
        $stmt->close();
    }
}

function getDeliveryFilesByCampaign(int $campaignId): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT f.*, u.full_name
        FROM campaign_delivery_files f
        LEFT JOIN users u ON u.id = f.uploader_id
        WHERE f.campaign_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $files[] = $row;
        }
    }
    $stmt->close();
    return $files;
}

function resolveAppUploadAbsPath(string $relPath): ?string {
    $relPath = str_replace('\\', '/', trim($relPath));
    $relPath = ltrim($relPath, '/');
    while (str_starts_with($relPath, './')) {
        $relPath = substr($relPath, 2);
    }
    if ($relPath === '' || !str_starts_with($relPath, 'uploads/') || str_contains($relPath, '..')) {
        return null;
    }
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        return null;
    }
    $abs = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath));
    if ($abs === false || !is_file($abs)) {
        return null;
    }
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $absNorm = str_replace('\\', '/', $abs);
    if (!str_starts_with($absNorm, $rootNorm)) {
        return null;
    }
    return $abs;
}

function campaignUploadExistsOnDisk(string $relPath): bool {
    return resolveAppUploadAbsPath($relPath) !== null;
}

function campaignOwnsUploadPath(int $campaignId, string $path): bool {
    $campaignId = (int)$campaignId;
    $path = str_replace('\\', '/', trim($path));
    if ($campaignId <= 0 || $path === '') {
        return false;
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT 1 FROM campaigns
        WHERE id = ? AND (script_path = ? OR tal_path = ? OR suppression_path = ? OR recording_path = ?)
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('issss', $campaignId, $path, $path, $path, $path);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok) {
            return true;
        }
    }
    $stmt = $conn->prepare("SELECT 1 FROM campaign_delivery_files WHERE campaign_id = ? AND file_path = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $campaignId, $path);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok) {
            return true;
        }
    }
    $stmt = $conn->prepare("SELECT 1 FROM campaign_additional_files WHERE campaign_id = ? AND file_path = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $campaignId, $path);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok) {
            return true;
        }
    }
    return false;
}

function campaignFileDownloadUrl(int $campaignId, string $relPath = '', int $deliveryFileId = 0): string {
    $base = appBasePath() . '/modules/files/download';
    if ($deliveryFileId > 0) {
        return $base . '?' . http_build_query([
            'kind' => 'campaign_delivery',
            'id' => $deliveryFileId,
            'campaign_id' => $campaignId,
        ]);
    }
    return $base . '?' . http_build_query([
        'kind' => 'campaign_path',
        'campaign_id' => $campaignId,
        'path' => $relPath,
    ]);
}

function saveCampaignDelivery(int $campaignId, ?string $format, string $notes, array $file, int $uploaderId): bool {
    if (empty($file['name']) || empty($file['tmp_name'])) return false;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['csv','xls','xlsx','zip','pdf','json'];
    if ($ext === '' || !in_array($ext, $allowed, true)) return false;

    $baseDir = __DIR__ . '/../uploads/campaign_files/deliveries';
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

    $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME));
    $safeBase = $safeBase !== '' ? $safeBase : 'delivery';
    $destName = 'C'.$campaignId.'_'.time().'_'.$safeBase.'.'.$ext;
    $destAbs = $baseDir . '/' . $destName;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) return false;
    $relPath = 'uploads/campaign_files/deliveries/'.$destName;

    $conn = getDbConnection();
    $fileType = 'Delivery File';
    $fileName = (string)$file['name'];
    $stmt = $conn->prepare("INSERT INTO campaign_delivery_files (campaign_id, file_type, file_name, file_path, uploader_id, format, notes, created_at)
        VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->bind_param('isssiss', $campaignId, $fileType, $fileName, $relPath, $uploaderId, $format, $notes);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureLeadTagsSchema(): void {
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS lead_tags (
        lead_id INT NOT NULL,
        tag_id INT NOT NULL,
        added_by INT NULL,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (lead_id, tag_id),
        INDEX (tag_id),
        FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getLeadTags(int $leadId): array {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT t.id, t.name FROM lead_tags lt JOIN tags t ON lt.tag_id = t.id WHERE lt.lead_id = ? ORDER BY t.name");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLeadTagAssignments(int $leadId): array {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT t.id AS tag_id, t.name AS tag_name, lt.added_at, lt.added_by,
               u.full_name AS added_by_name, u.role AS added_by_role
        FROM lead_tags lt
        JOIN tags t ON t.id = lt.tag_id
        LEFT JOIN users u ON u.id = lt.added_by
        WHERE lt.lead_id = ?
        ORDER BY lt.added_at DESC
    ");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getLeadTagTimeline(int $leadId, int $limit = 100): array {
    $conn = getDbConnection();
    $limit = max(1, min(200, $limit));
    $stmt = $conn->prepare("
        SELECT a.id, a.actor_id, a.action, a.meta_json, a.created_at, u.full_name AS actor_name, u.role AS actor_role
        FROM lead_activity a
        LEFT JOIN users u ON u.id = a.actor_id
        WHERE a.lead_id = ? AND a.action IN ('lead_tag_added','lead_tag_removed','lead_tag_edited')
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT $limit
    ");
    $stmt->bind_param('i', $leadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as &$r) {
        $meta = !empty($r['meta_json']) ? json_decode((string)$r['meta_json'], true) : [];
        if (!is_array($meta)) $meta = [];
        $r['meta'] = $meta;
        unset($r['meta_json']);
    }
    unset($r);
    return $rows;
}

function addTagToLead(int $leadId, string $tagName, int $userId, string $note = '', string $stage = ''): bool {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $tagName = trim($tagName);
    if ($tagName === '') return false;
    $note = trim($note);
    $stage = trim($stage);
    $stmt = $conn->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
    $stmt->bind_param('s', $tagName);
    $stmt->execute();
    $stmt->close();
    $stmt2 = $conn->prepare("SELECT id FROM tags WHERE name = ? LIMIT 1");
    $stmt2->bind_param('s', $tagName);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if (!$row) return false;
    $tagId = (int)$row['id'];
    $stmt3 = $conn->prepare("INSERT IGNORE INTO lead_tags (lead_id, tag_id, added_by) VALUES (?,?,?)");
    $stmt3->bind_param('iii', $leadId, $tagId, $userId);
    $ok = $stmt3->execute();
    $stmt3->close();
    if ($ok) {
        $meta = ['tag' => $tagName];
        if ($note !== '') $meta['note'] = $note;
        if ($stage !== '') {
            $meta['stage'] = $stage;
            // Sync Stage with Client Delivery Status if it matches one of the valid statuses
            $validStatuses = function_exists('getClientDeliveryStatuses') ? getClientDeliveryStatuses() : ['Pending', 'Delivered', 'Accepted', 'Rejected', 'TBD(To be discussed)', 'In Progress'];
            $normalizedStage = function_exists('normalizeClientDeliveryStatus') ? normalizeClientDeliveryStatus($stage) : $stage;
            
            $isMatch = false;
            foreach ($validStatuses as $vs) {
                if (strtolower($stage) === strtolower($vs)) {
                    $isMatch = true;
                    $normalizedStage = $vs;
                    break;
                }
            }
            
            if ($isMatch) {
                $updatedAtUtc = appNowUtc();
                $stmtS = $conn->prepare("UPDATE leads SET client_delivery_status = ?, updated_at_utc = ?, updated_at_source = 'lead_stage_sync' WHERE id = ?");
                if ($stmtS) {
                    $stmtS->bind_param('ssi', $normalizedStage, $updatedAtUtc, $leadId);
                    if ($stmtS->execute()) {
                        logLeadActivity($leadId, $userId, 'qa_updated', [
                            'client_delivery_status' => $normalizedStage,
                            'qa_client_comment' => 'Status updated via Stage change (' . $stage . ')'
                        ]);
                    }
                    $stmtS->close();
                }
            }
        }
        logLeadActivity($leadId, $userId, 'lead_tag_added', $meta);
    }
    return $ok;
}

function removeTagFromLead(int $leadId, int $tagId, int $userId): bool {
    ensureLeadTagsSchema();
    $conn = getDbConnection();
    $tagName = '';
    $stmt0 = $conn->prepare("SELECT name FROM tags WHERE id = ? LIMIT 1");
    $stmt0->bind_param('i', $tagId);
    $stmt0->execute();
    $tagName = (string)($stmt0->get_result()->fetch_row()[0] ?? '');
    $stmt0->close();
    $stmt = $conn->prepare("DELETE FROM lead_tags WHERE lead_id = ? AND tag_id = ?");
    $stmt->bind_param('ii', $leadId, $tagId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $meta = ['tag_id' => $tagId];
        if ($tagName !== '') $meta['tag'] = $tagName;
        logLeadActivity($leadId, $userId, 'lead_tag_removed', $meta);
    }
    return $ok;
}

function getClientUsersByRole(int $clientId, string $role = 'client_sdr'): array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE client_id = ? AND role = ? AND is_active = 1 ORDER BY full_name");
    $stmt->bind_param('is', $clientId, $role);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

function getClientCampaignIdsForClient(int $clientId): array {
    $clientId = (int)$clientId;
    if ($clientId <= 0) return [];
    $clientCode = getClientCodeById($clientId);
    $conn = getDbConnection();
    if ($clientCode !== null && $clientCode !== '') {
        $stmt = $conn->prepare("
            SELECT c.id
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE d.client_id = ?
               OR TRIM(COALESCE(d.client_code, '')) = ?
            ORDER BY c.name
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT c.id
            FROM campaigns c
            JOIN campaign_details d ON d.campaign_id = c.id
            WHERE d.client_id = ?
            ORDER BY c.name
        ");
    }
    if (!$stmt) return [];
    if ($clientCode !== null && $clientCode !== '') {
        $stmt->bind_param('is', $clientId, $clientCode);
    } else {
        $stmt->bind_param('i', $clientId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $ids = [];
    foreach ($rows as $row) {
        $cid = (int)($row['id'] ?? 0);
        if ($cid > 0) $ids[$cid] = true;
    }
    return array_keys($ids);
}

function clientOwnsCampaign(int $clientId, int $campaignId): bool {
    $clientId = (int)$clientId;
    $campaignId = (int)$campaignId;
    if ($clientId <= 0 || $campaignId <= 0) return false;
    $campaignIds = getClientCampaignIdsForClient($clientId);
    return in_array($campaignId, $campaignIds, true);
}

function syncClientUserCampaignAssignments(int $userId, int $clientId, int $assignedBy = 0): int {
    $userId = (int)$userId;
    $clientId = (int)$clientId;
    $assignedBy = (int)$assignedBy;
    if ($userId <= 0 || $clientId <= 0) return 0;

    $campaignIds = getClientCampaignIdsForClient($clientId);
    if (empty($campaignIds)) return 0;

    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT IGNORE INTO campaign_user_assignments (campaign_id, user_id, assigned_by) VALUES (?,?,?)");
    if (!$stmt) return 0;

    $count = 0;
    foreach ($campaignIds as $campaignId) {
        $campaignId = (int)$campaignId;
        if ($campaignId <= 0) continue;
        $assignerId = $assignedBy > 0 ? $assignedBy : $userId;
        $stmt->bind_param('iii', $campaignId, $userId, $assignerId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $count++;
        }
    }
    $stmt->close();
    return $count;
}

function assignLeadToUser(int $leadId, int $userId, int $assignerId): bool {
    ensureLeadsTrackingColumns();
    $conn = getDbConnection();
    $updatedAtUtc = appNowUtc();
    $stmt = $conn->prepare("UPDATE leads SET assigned_to_user = ?, updated_by = ?, updated_at_utc = ?, updated_at_source = 'lead_assignment' WHERE id = ?");
    $stmt->bind_param('iisi', $userId, $assignerId, $updatedAtUtc, $leadId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        logLeadActivity($leadId, $assignerId, 'lead_assigned', ['assigned_to' => $userId]);
    }
    return $ok;
}

function ensureBillingProfilesSchema(): void {
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS client_billing_profiles (
        client_id INT NOT NULL PRIMARY KEY,
        billing_name VARCHAR(180) NULL,
        billing_email VARCHAR(180) NULL,
        billing_phone VARCHAR(40) NULL,
        billing_address TEXT NULL,
        tax_id VARCHAR(120) NULL,
        bank_name VARCHAR(180) NULL,
        bank_account_name VARCHAR(180) NULL,
        bank_account_number VARCHAR(120) NULL,
        bank_ifsc_swift VARCHAR(120) NULL,
        bank_iban VARCHAR(120) NULL,
        notes TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS vendor_billing_profiles (
        vendor_id INT NOT NULL PRIMARY KEY,
        billing_name VARCHAR(180) NULL,
        billing_email VARCHAR(180) NULL,
        billing_phone VARCHAR(40) NULL,
        billing_address TEXT NULL,
        tax_id VARCHAR(120) NULL,
        bank_name VARCHAR(180) NULL,
        bank_account_name VARCHAR(180) NULL,
        bank_account_number VARCHAR(120) NULL,
        bank_ifsc_swift VARCHAR(120) NULL,
        bank_iban VARCHAR(120) NULL,
        notes TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getClientBillingProfile(int $clientId): array {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM client_billing_profiles WHERE client_id = ? LIMIT 1");
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function upsertClientBillingProfile(int $clientId, array $data, int $userId): bool {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $billingName = trim((string)($data['billing_name'] ?? ''));
    $billingEmail = trim((string)($data['billing_email'] ?? ''));
    $billingPhone = trim((string)($data['billing_phone'] ?? ''));
    $billingAddress = trim((string)($data['billing_address'] ?? ''));
    $taxId = trim((string)($data['tax_id'] ?? ''));
    $bankName = trim((string)($data['bank_name'] ?? ''));
    $bankAccountName = trim((string)($data['bank_account_name'] ?? ''));
    $bankAccountNumber = trim((string)($data['bank_account_number'] ?? ''));
    $bankIfscSwift = trim((string)($data['bank_ifsc_swift'] ?? ''));
    $bankIban = trim((string)($data['bank_iban'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));

    $stmt = $conn->prepare("
        INSERT INTO client_billing_profiles
        (client_id, billing_name, billing_email, billing_phone, billing_address, tax_id, bank_name, bank_account_name, bank_account_number, bank_ifsc_swift, bank_iban, notes, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
          billing_name=VALUES(billing_name),
          billing_email=VALUES(billing_email),
          billing_phone=VALUES(billing_phone),
          billing_address=VALUES(billing_address),
          tax_id=VALUES(tax_id),
          bank_name=VALUES(bank_name),
          bank_account_name=VALUES(bank_account_name),
          bank_account_number=VALUES(bank_account_number),
          bank_ifsc_swift=VALUES(bank_ifsc_swift),
          bank_iban=VALUES(bank_iban),
          notes=VALUES(notes),
          updated_by=VALUES(updated_by),
          updated_at=NOW()
    ");
    $stmt->bind_param('isssssssssssi', $clientId, $billingName, $billingEmail, $billingPhone, $billingAddress, $taxId, $bankName, $bankAccountName, $bankAccountNumber, $bankIfscSwift, $bankIban, $notes, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getVendorBillingProfile(int $vendorId): array {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM vendor_billing_profiles WHERE vendor_id = ? LIMIT 1");
    $stmt->bind_param('i', $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function upsertVendorBillingProfile(int $vendorId, array $data, int $userId): bool {
    ensureBillingProfilesSchema();
    $conn = getDbConnection();
    $billingName = trim((string)($data['billing_name'] ?? ''));
    $billingEmail = trim((string)($data['billing_email'] ?? ''));
    $billingPhone = trim((string)($data['billing_phone'] ?? ''));
    $billingAddress = trim((string)($data['billing_address'] ?? ''));
    $taxId = trim((string)($data['tax_id'] ?? ''));
    $bankName = trim((string)($data['bank_name'] ?? ''));
    $bankAccountName = trim((string)($data['bank_account_name'] ?? ''));
    $bankAccountNumber = trim((string)($data['bank_account_number'] ?? ''));
    $bankIfscSwift = trim((string)($data['bank_ifsc_swift'] ?? ''));
    $bankIban = trim((string)($data['bank_iban'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));

    $stmt = $conn->prepare("
        INSERT INTO vendor_billing_profiles
        (vendor_id, billing_name, billing_email, billing_phone, billing_address, tax_id, bank_name, bank_account_name, bank_account_number, bank_ifsc_swift, bank_iban, notes, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
          billing_name=VALUES(billing_name),
          billing_email=VALUES(billing_email),
          billing_phone=VALUES(billing_phone),
          billing_address=VALUES(billing_address),
          tax_id=VALUES(tax_id),
          bank_name=VALUES(bank_name),
          bank_account_name=VALUES(bank_account_name),
          bank_account_number=VALUES(bank_account_number),
          bank_ifsc_swift=VALUES(bank_ifsc_swift),
          bank_iban=VALUES(bank_iban),
          notes=VALUES(notes),
          updated_by=VALUES(updated_by),
          updated_at=NOW()
    ");
    $stmt->bind_param('isssssssssssi', $vendorId, $billingName, $billingEmail, $billingPhone, $billingAddress, $taxId, $bankName, $bankAccountName, $bankAccountNumber, $bankIfscSwift, $bankIban, $notes, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function saveCampaignAdditionalFiles(int $campaignId, array $files, array $titles, array $types, array $descriptions, int $uploadedBy): void {
    if (empty($files) || empty($files['name']) || !is_array($files['name'])) return;
    $conn = getDbConnection();
    $baseDir = __DIR__ . '/../uploads/campaign_files/additional';
    if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

    $allowed = ['pdf','doc','docx','txt','csv','xls','xlsx','png','jpg','jpeg'];
    $maxSize = 15 * 1024 * 1024;
    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        $orig = (string)($files['name'][$i] ?? '');
        if (trim($orig) === '') continue;
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) continue;
        if ($size > $maxSize) continue;
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;

        $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $safeBase = $safeBase !== '' ? $safeBase : 'file';
        $destName = 'C'.$campaignId.'_'.time().'_'.$i.'_'.$safeBase.'.'.$ext;
        $destAbs = $baseDir . '/' . $destName;
        if (!move_uploaded_file($tmp, $destAbs)) continue;
        $relPath = 'uploads/campaign_files/additional/'.$destName;

        $title = trim((string)($titles[$i] ?? ''));
        if ($title === '') $title = $orig;
        $tag = trim((string)($types[$i] ?? ''));
        $desc = trim((string)($descriptions[$i] ?? ''));

        $stmt = $conn->prepare("INSERT INTO campaign_additional_files (campaign_id, file_title, file_path, file_type, description, uploaded_by, created_at)
            VALUES (?,?,?,?,?,?,NOW())");
        if (!$stmt) continue;
        $stmt->bind_param('issssi', $campaignId, $title, $relPath, $tag, $desc, $uploadedBy);
        $stmt->execute();
        $stmt->close();
    }
}

function getCampaignCreateFormOptionValues(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $path = __DIR__ . '/../modules/campaigns/campaign-form-options-source.php';
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        $cache = [
            'delivery_format' => ['Internal CRM','Client CRM','CSV','XLSX','Other'],
            'status' => ['Draft','Active','Pause','Complete','Live'],
            'campaign_type' => ['Email Marketing','MQL','SQL','HQL','BANT','Call Back','Webinar - Live','Webinar - OnDemand','Appointment Generation','ABM Lead'],
            'pacing_type' => ['Daily','Weekly','Monthly'],
            'targeted_country' => [],
            'departments' => [],
            'seniority_levels' => [],
            'employee_sizes' => [],
            'industries' => [],
            'revenue_sizes' => [],
        ];
        return $cache;
    }

    $extract = function(string $id) use ($src): array {
        $re = '/id="' . preg_quote($id, '/') . '"[^>]*>([\s\S]*?)<\/select>/';
        if (!preg_match($re, $src, $m)) return [];
        preg_match_all('/<option\s+value="([^"]*)"/', $m[1], $mm);
        $vals = array_values(array_filter(array_map('trim', $mm[1] ?? []), fn($v) => $v !== ''));
        $seen = [];
        $out = [];
        foreach ($vals as $v) {
            if (isset($seen[$v])) continue;
            $seen[$v] = true;
            $out[] = $v;
        }
        return $out;
    };

    $cache = [
        'delivery_format' => ['Internal CRM','Client CRM','CSV','XLSX','Other'],
        'status' => ['Draft','Active','Pause','Complete','Live'],
        'campaign_type' => ['Email Marketing','MQL','SQL','HQL','BANT','Call Back','Webinar - Live','Webinar - OnDemand','Appointment Generation','ABM Lead'],
        'pacing_type' => ['Daily','Weekly','Monthly'],
        'targeted_country' => $extract('geoSel'),
        'departments' => $extract('deptSel'),
        'seniority_levels' => $extract('levelSel'),
        'employee_sizes' => $extract('empSel'),
        'industries' => $extract('indSel'),
        'revenue_sizes' => $extract('revSel'),
    ];
    return $cache;
}

/**
 * Create campaign with details and files
 */
function createCampaignWithDetails(array $basic, array $criteria, array $customFields, array $files): int {
    $conn = getDbConnection();
    $st = $basic['status'] ?? 'Draft';
    if ($st === 'Live') {
        throw new RuntimeException('Assign a lead form before switching status to Live.');
    }
    $active = (in_array($st, ['Active','Live'], true)) ? 1 : 0;
    $ownerId = isset($basic['owner_id']) ? (int)$basic['owner_id'] : null;
    $createdBy = isset($basic['created_by']) ? (int)$basic['created_by'] : null;
    $stmt = $conn->prepare("INSERT INTO campaigns (name, active, owner_id, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('siii', $basic['name'], $active, $ownerId, $createdBy);
    if (!$stmt->execute()) { throw new RuntimeException('Failed to create campaign'); }
    $campaignId = (int)$conn->insert_id;
    $stmt->close();

    $clientCode = ($basic['client_code'] ?? '') !== '' ? trim((string)$basic['client_code']) : '';
    $code = generateCampaignCode($clientCode);
    // Save files
    $filePaths = saveCampaignFiles($files, $code);
    $customJson = !empty($customFields) ? json_encode($customFields, JSON_UNESCAPED_UNICODE) : null;
    $toJson = function($v) {
        if ($v === null) return null;
        if (is_array($v)) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $j === false ? '[]' : $j;
        }
        return $v;
    };

    $sql = "INSERT INTO campaign_details (
                campaign_id, code, client_id, client_code, status, start_date, end_date, total_leads,
                pacing_type, pacing_count, cpc, cpl, cpl_currency, campaign_type, delivery_format, targeted_country,
                job_title, departments, seniority_levels, industries, employee_sizes,
                revenue_sizes, instruction, script_path, tal_path, suppression_path, recording_path,
                custom_fields_json, updated_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
    $ins = $conn->prepare($sql);
    if (!$ins) { throw new RuntimeException('Failed to save campaign details'); }

    $startDate = ($basic['start_date'] ?? '') !== '' ? $basic['start_date'] : null;
    $endDate = ($basic['end_date'] ?? '') !== '' ? $basic['end_date'] : null;
    $totalLeads = ($basic['total_leads'] ?? '') !== '' ? (int)$basic['total_leads'] : null;
    $pacingCount = ($basic['pacing_count'] ?? '') !== '' ? (int)$basic['pacing_count'] : null;
    $cpc = ($basic['cpc'] ?? '') !== '' ? (string)$basic['cpc'] : null;
    $cpl = ($basic['cpl'] ?? '') !== '' ? (string)$basic['cpl'] : null;
    $clientCode = ($basic['client_code'] ?? '') !== '' ? trim((string)$basic['client_code']) : null;
    $clientId = ($basic['client_id'] ?? '') !== '' ? (int)$basic['client_id'] : null;

    $targetedCountryJson = $toJson($criteria['targeted_country'] ?? []);
    $departmentsJson = $toJson($criteria['departments'] ?? []);
    $levelsJson = $toJson($criteria['seniority_levels'] ?? []);
    $industriesJson = $toJson($criteria['industries'] ?? []);
    $employeeSizesJson = $toJson($criteria['employee_sizes'] ?? []);
    $revenueSizesJson = $toJson($criteria['revenue_sizes'] ?? []);

    $params = [
        $campaignId,
        $code,
        $clientId,
        $clientCode,
        $st,
        $startDate,
        $endDate,
        $totalLeads,
        $basic['pacing_type'],
        $pacingCount,
        $cpc,
        $cpl,
        $basic['cpl_currency'],
        $basic['campaign_type'],
        $basic['delivery_format'],
        $targetedCountryJson,
        $criteria['job_title'],
        $departmentsJson,
        $levelsJson,
        $industriesJson,
        $employeeSizesJson,
        $revenueSizesJson,
        $basic['instruction'],
        $filePaths['script_path'],
        $filePaths['tal_path'],
        $filePaths['suppression_path'],
        $filePaths['recording_path'],
        $customJson
    ];
    $ins->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$ins->execute()) { throw new RuntimeException('Failed to save campaign details'); }
    $ins->close();

    $uploadedBy = $createdBy ?: ($ownerId ?: 0);
    saveCampaignSetupFilesToDb($campaignId, $filePaths, $uploadedBy);

    if (function_exists('notifyCampaignCreated')) {
        notifyCampaignCreated($campaignId, (int)($createdBy ?: 0));
    }
    return $campaignId;
}

function updateCampaignDetails(int $campaignId, array $basic, array $criteria, array $customFields, array $files): bool {
    $conn = getDbConnection();
    $current = getCampaignDetailsById($campaignId);
    if (!$current) { throw new RuntimeException('Campaign not found'); }

    $st = $basic['status'] ?? ($current['status'] ?? 'Draft');
    if ($st === 'Live' && ($current['status'] ?? '') !== 'Live') {
        $fid = getCampaignAssignedFormId($campaignId);
        if (!$fid) {
            throw new RuntimeException('Assign a lead form before switching status to Live.');
        }
    }
    $active = (in_array($st, ['Active','Live'], true)) ? 1 : 0;
    $updatedBy = isset($basic['updated_by']) ? (int)$basic['updated_by'] : null;
    $stmt = $conn->prepare("UPDATE campaigns SET name = ?, active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('siii', $basic['name'], $active, $updatedBy, $campaignId);
    if (!$stmt->execute()) { throw new RuntimeException('Failed to update campaign'); }
    $stmt->close();

    $code = (string)($current['code'] ?? '');
    if ($code === '') { throw new RuntimeException('Campaign code missing'); }

    $filePaths = saveCampaignFiles($files, $code);
    $mergedFiles = [
        'script_path' => $filePaths['script_path'] ?: ($current['script_path'] ?? null),
        'tal_path' => $filePaths['tal_path'] ?: ($current['tal_path'] ?? null),
        'suppression_path' => $filePaths['suppression_path'] ?: ($current['suppression_path'] ?? null),
        'recording_path' => $filePaths['recording_path'] ?: ($current['recording_path'] ?? null),
    ];
    $customJson = !empty($customFields) ? json_encode($customFields, JSON_UNESCAPED_UNICODE) : (is_string($current['custom_fields_json'] ?? null) ? $current['custom_fields_json'] : json_encode($current['custom_fields_json'] ?? []));

    $toJson = function($v) {
        if ($v === null) return null;
        if (is_array($v)) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $j === false ? '[]' : $j;
        }
        return $v;
    };

    $startDate = ($basic['start_date'] ?? '') !== '' ? $basic['start_date'] : null;
    $endDate = ($basic['end_date'] ?? '') !== '' ? $basic['end_date'] : null;
    $totalLeads = ($basic['total_leads'] ?? '') !== '' ? (int)$basic['total_leads'] : null;
    $pacingCount = ($basic['pacing_count'] ?? '') !== '' ? (int)$basic['pacing_count'] : null;
    $cpc = ($basic['cpc'] ?? '') !== '' ? (string)$basic['cpc'] : ($current['cpc'] ?? null);
    $cpl = ($basic['cpl'] ?? '') !== '' ? (string)$basic['cpl'] : ($current['cpl'] ?? null);
    $clientCode = ($basic['client_code'] ?? '') !== '' ? trim((string)$basic['client_code']) : ($current['client_code'] ?? null);
    $clientId = ($basic['client_id'] ?? '') !== '' ? (int)$basic['client_id'] : ($current['client_id'] ?? null);

    $targetedCountryJson = $toJson($criteria['targeted_country'] ?? ($current['targeted_country'] ?? []));
    $departmentsJson = $toJson($criteria['departments'] ?? ($current['departments'] ?? []));
    $levelsJson = $toJson($criteria['seniority_levels'] ?? ($current['seniority_levels'] ?? []));
    $industriesJson = $toJson($criteria['industries'] ?? ($current['industries'] ?? []));
    $employeeSizesJson = $toJson($criteria['employee_sizes'] ?? ($current['employee_sizes'] ?? []));
    $revenueSizesJson = $toJson($criteria['revenue_sizes'] ?? ($current['revenue_sizes'] ?? []));

    $sql = "UPDATE campaign_details SET
                client_id = ?,
                client_code = ?,
                status = ?,
                start_date = ?,
                end_date = ?,
                total_leads = ?,
                pacing_type = ?,
                pacing_count = ?,
                cpc = ?,
                cpl = ?,
                cpl_currency = ?,
                campaign_type = ?,
                delivery_format = ?,
                targeted_country = ?,
                job_title = ?,
                departments = ?,
                seniority_levels = ?,
                industries = ?,
                employee_sizes = ?,
                revenue_sizes = ?,
                instruction = ?,
                script_path = ?,
                tal_path = ?,
                suppression_path = ?,
                recording_path = ?,
                custom_fields_json = ?,
                updated_at = NOW()
            WHERE campaign_id = ?";
    $upd = $conn->prepare($sql);
    if (!$upd) { throw new RuntimeException('Failed to update campaign details'); }
    $params = [
        $clientId,
        $clientCode,
        $st,
        $startDate,
        $endDate,
        $totalLeads,
        $basic['pacing_type'],
        $pacingCount,
        $cpc,
        $cpl,
        $basic['cpl_currency'],
        $basic['campaign_type'],
        $basic['delivery_format'],
        $targetedCountryJson,
        $criteria['job_title'],
        $departmentsJson,
        $levelsJson,
        $industriesJson,
        $employeeSizesJson,
        $revenueSizesJson,
        $basic['instruction'],
        $mergedFiles['script_path'],
        $mergedFiles['tal_path'],
        $mergedFiles['suppression_path'],
        $mergedFiles['recording_path'],
        $customJson,
        $campaignId
    ];
    $upd->bind_param(str_repeat('s', count($params)), ...$params);
    if (!$upd->execute()) { throw new RuntimeException('Failed to update campaign details'); }
    $upd->close();

    $uploadedBy = $updatedBy ?: 0;
    saveCampaignSetupFilesToDb($campaignId, $filePaths, $uploadedBy);

    if (function_exists('notifyCampaignUpdated')) {
        notifyCampaignUpdated($campaignId, (int)($updatedBy ?: 0), []);
    }
    return true;
}

/**
 * Fetch campaign and details by ID
 */
function getCampaignDetailsById(int $campaignId): ?array {
    $conn = getDbConnection();
    $sql = 'SELECT c.id, c.name, c.active, d.* FROM campaigns c LEFT JOIN campaign_details d ON d.campaign_id = c.id WHERE c.id = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $jsonFields = ['targeted_country','departments','seniority_levels','industries','employee_sizes','revenue_sizes','custom_fields_json'];
    foreach ($jsonFields as $f) {
        if (!empty($row[$f])) {
            $dec = json_decode($row[$f], true);
            if (json_last_error() === JSON_ERROR_NONE) $row[$f] = $dec;
        }
    }
    return $row;
}

/**
 * Get stats for Agent Dashboard
 */
function getAgentDashboardStats(int $userId): array {
    $conn = getDbConnection();
    $today = opsTodayRange();
    $todayStart = (string)($today['start'] ?? '1970-01-01 00:00:00');
    $todayEnd = (string)($today['end'] ?? '2999-12-31 23:59:59');
    $createdExpr = leadCreatedAtExpr('');
    $sql = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) as disqualified_leads,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) as pending_leads,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as today_qualified,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as today_disqualified
        FROM leads
        WHERE agent_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssi', $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [
        'total_leads' => 0,
        'qualified_leads' => 0,
        'disqualified_leads' => 0,
        'pending_leads' => 0,
        'today_leads' => 0,
        'today_qualified' => 0,
        'today_disqualified' => 0,
    ];
}

/**
 * Get stats for Form Filler Dashboard
 */
function getFormFillerDashboardStats(string $rangeStart, string $rangeEnd, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    if (is_array($campaignIds) && empty($campaignIds)) {
        return [
            'total_leads' => 0,
            'filled_forms' => 0,
            'pending_form_filling' => 0,
            'qa_pending' => 0,
            'qualified_leads' => 0,
            'disqualified_leads' => 0,
        ];
    }
    $whereSql = '';
    $typesPrefix = '';
    $paramsPrefix = [];
    if (is_array($campaignIds) && !empty($campaignIds)) {
        $campaignIds = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($campaignIds)) return [];
        $in = implode(',', array_fill(0, count($campaignIds), '?'));
        $whereSql = "WHERE campaign_id IN ($in)";
        $typesPrefix = str_repeat('i', count($campaignIds));
        $paramsPrefix = $campaignIds;
    }
    
    // Add date range to WHERE clause
    if ($whereSql === '') {
        $whereSql = "WHERE created_at BETWEEN ? AND ?";
    } else {
        $whereSql .= " AND created_at BETWEEN ? AND ?";
    }
    
    $sql = "
        SELECT 
            COUNT(*) AS total_leads,
            SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) AS filled_forms,
            SUM(CASE WHEN (form_done = 'No') AND qa_status = 'Qualified' THEN 1 ELSE 0 END) AS pending_form_filling,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') OR qa_status IS NULL THEN 1 ELSE 0 END) AS qa_pending,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified_leads
        FROM leads
        $whereSql
    ";
    $stmt = $conn->prepare($sql);
    $bind = array_merge($paramsPrefix, [$rangeStart, $rangeEnd]);
    mysqliBindParams($stmt, $typesPrefix . 'ss', $bind);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();
    return $row ?: [];
}

function normalizePreviewUrl(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('/^https?:\\/\\//i', $url)) return $url;
    if (str_starts_with($url, '//')) return 'https:' . $url;
    return 'https://' . $url;
}

function isSafeExternalUrl(string $url): bool {
    $parts = @parse_url($url);
    if (!is_array($parts)) return false;
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http','https'], true)) return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost') return false;
    if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;

    $ips = @gethostbynamel($host);
    if (!$ips || !is_array($ips)) return false;
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function resolveUrl(string $baseUrl, string $maybeRelative): string {
    $maybeRelative = trim($maybeRelative);
    if ($maybeRelative === '') return '';
    if (preg_match('/^https?:\\/\\//i', $maybeRelative)) return $maybeRelative;
    if (str_starts_with($maybeRelative, '//')) {
        $p = parse_url($baseUrl);
        $scheme = (string)($p['scheme'] ?? 'https');
        return $scheme . ':' . $maybeRelative;
    }

    $b = parse_url($baseUrl);
    $scheme = (string)($b['scheme'] ?? 'https');
    $host = (string)($b['host'] ?? '');
    $port = isset($b['port']) ? (':' . (int)$b['port']) : '';
    $origin = $scheme . '://' . $host . $port;

    if (str_starts_with($maybeRelative, '/')) return $origin . $maybeRelative;
    $path = (string)($b['path'] ?? '/');
    $dir = preg_replace('~/[^/]*$~', '/', $path);
    return $origin . $dir . $maybeRelative;
}

function parseHtmlPreviewMeta(string $html, string $baseUrl): array {
    $getMeta = function(\DOMXPath $xp, string $q): ?string {
        $n = $xp->query($q);
        if (!$n || $n->length === 0) return null;
        $node = $n->item(0);
        if (!$node || !($node instanceof DOMElement)) return null;
        $v = trim((string)($node->getAttribute('content') ?? ''));
        return $v !== '' ? $v : null;
    };

    $title = null;
    $desc = null;
    $img = null;

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($dom);

    $title = $getMeta($xp, "//meta[@property='og:title']") ?? $getMeta($xp, "//meta[@name='twitter:title']");
    if (!$title) {
        $t = $xp->query('//title');
        if ($t && $t->length > 0) $title = trim((string)($t->item(0)->textContent ?? '')) ?: null;
    }

    $desc = $getMeta($xp, "//meta[@property='og:description']") ?? $getMeta($xp, "//meta[@name='twitter:description']") ?? $getMeta($xp, "//meta[@name='description']");
    $img = $getMeta($xp, "//meta[@property='og:image']") ?? $getMeta($xp, "//meta[@name='twitter:image']");
    if ($img) $img = resolveUrl($baseUrl, $img);

    if ($title !== null) $title = (function_exists('mb_substr') ? mb_substr($title, 0, 255) : substr($title, 0, 255));
    if ($desc !== null) $desc = (function_exists('mb_substr') ? mb_substr($desc, 0, 400) : substr($desc, 0, 400));

    return [
        'preview_title' => $title,
        'preview_description' => $desc,
        'preview_image' => $img,
    ];
}

function fetchUrlPreviewMeta(string $url): array {
    $out = [
        'preview_title' => null,
        'preview_description' => null,
        'preview_image' => null,
        'preview_url' => $url,
        'fetch_status' => 'error',
        'http_status' => null,
        'last_error' => null,
    ];

    if (!isSafeExternalUrl($url)) {
        $out['fetch_status'] = 'blocked';
        return $out;
    }

    $body = '';
    $effective = $url;
    $http = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'DemandFlowBridgeBot/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if (curl_errno($ch)) $out['last_error'] = curl_error($ch);
        unset($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'header' => "User-Agent: DemandFlowBridgeBot/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
            ],
        ]);
        $body = (string)@file_get_contents($url, false, $ctx);
        $http = null;
        $effective = $url;
    }

    $out['http_status'] = $http;
    $out['preview_url'] = $effective ?: $url;

    if ($body === '' || ($http !== null && ($http < 200 || $http >= 400))) {
        $out['fetch_status'] = 'error';
        if (!$out['last_error']) $out['last_error'] = $http ? ('HTTP ' . $http) : 'Empty response';
        return $out;
    }

    $meta = parseHtmlPreviewMeta($body, $out['preview_url']);
    $out['preview_title'] = $meta['preview_title'];
    $out['preview_description'] = $meta['preview_description'];
    $out['preview_image'] = $meta['preview_image'];
    $out['fetch_status'] = 'ok';
    return $out;
}

function getUrlPreviewCached(string $url): array {
    $conn = getDbConnection();
    static $tableReady = false;
    if (!$tableReady) {
        @$conn->query("CREATE TABLE IF NOT EXISTS url_previews (
            url_hash CHAR(64) PRIMARY KEY,
            url TEXT NOT NULL,
            final_url TEXT NULL,
            preview_title VARCHAR(255) NULL,
            preview_description TEXT NULL,
            preview_image VARCHAR(512) NULL,
            fetch_status VARCHAR(20) NOT NULL DEFAULT 'ok',
            http_status INT NULL,
            last_error VARCHAR(255) NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fetched(fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $tableReady = true;
    }
    $url = normalizePreviewUrl($url);
    if ($url === '') return [];

    $hash = hash('sha256', $url);
    $stmt = $conn->prepare("SELECT * FROM url_previews WHERE url_hash = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) {
            $ts = strtotime((string)($row['fetched_at'] ?? '')) ?: 0;
            $age = time() - $ts;
            $ttl = ((string)($row['fetch_status'] ?? 'ok')) === 'ok' ? (7 * 86400) : (86400);
            if ($age >= 0 && $age < $ttl) {
                return [
                    'preview_title' => $row['preview_title'] ?? null,
                    'preview_description' => $row['preview_description'] ?? null,
                    'preview_image' => $row['preview_image'] ?? null,
                    'preview_url' => $row['final_url'] ?: ($row['url'] ?? $url),
                    'fetch_status' => $row['fetch_status'] ?? 'ok',
                ];
            }
        }
    }

    $meta = fetchUrlPreviewMeta($url);
    $stmt2 = $conn->prepare("INSERT INTO url_previews (url_hash, url, final_url, preview_title, preview_description, preview_image, fetch_status, http_status, last_error, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          url = VALUES(url),
          final_url = VALUES(final_url),
          preview_title = VALUES(preview_title),
          preview_description = VALUES(preview_description),
          preview_image = VALUES(preview_image),
          fetch_status = VALUES(fetch_status),
          http_status = VALUES(http_status),
          last_error = VALUES(last_error),
          fetched_at = NOW()");
    if ($stmt2) {
        $finalUrl = (string)($meta['preview_url'] ?? $url);
        $pt = $meta['preview_title'];
        $pd = $meta['preview_description'];
        $pi = $meta['preview_image'];
        $fs = (string)($meta['fetch_status'] ?? 'error');
        $hs = $meta['http_status'] !== null ? (int)$meta['http_status'] : 0;
        $le = $meta['last_error'];
        $stmt2->bind_param('sssssssis', $hash, $url, $finalUrl, $pt, $pd, $pi, $fs, $hs, $le);
        $stmt2->execute();
        $stmt2->close();
    }

    return $meta;
}

function renderUrlPreviewCard(string $url, string $fallbackTitle, string $fallbackLabel): string {
    $url = normalizePreviewUrl($url);
    if ($url === '') return '';
    $meta = getUrlPreviewCached($url);
    $title = trim((string)($meta['preview_title'] ?? ''));
    $desc = trim((string)($meta['preview_description'] ?? ''));
    $img = trim((string)($meta['preview_image'] ?? ''));
    $final = trim((string)($meta['preview_url'] ?? $url));
    if ($title === '') $title = $fallbackTitle;
    $showDesc = $desc !== '';

    $icon = '<div class="bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded d-flex align-items-center justify-content-center" style="width:56px;height:56px;"><i class="bi bi-link-45deg fs-4 text-primary"></i></div>';
    $thumb = $img !== '' ? '<img src="'.htmlspecialchars($img).'" class="rounded border" style="width:56px;height:56px;object-fit:cover;" alt="">' : $icon;

    $html = '<div class="card border-0 bg-light shadow-sm mt-2 overflow-hidden" style="max-width: 450px;">'
        .'<div class="card-body p-2">'
        .'<div class="d-flex gap-3 align-items-start">'
        .'<div class="flex-shrink-0">'.$thumb.'</div>'
        .'<div class="flex-grow-1" style="min-width:0;">'
        .'<div class="fw-bold small text-dark text-truncate mb-0">'.htmlspecialchars($title).'</div>';
    if ($showDesc) {
        $html .= '<div class="text-muted x-small mb-1" style="display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;">'.htmlspecialchars($desc).'</div>';
    }
    $html .= '<div class="d-flex align-items-center gap-2">'
        .'<a href="'.htmlspecialchars($final).'" target="_blank" rel="noopener" class="btn btn-xs btn-primary rounded-pill px-2 py-0 fw-bold" style="font-size: 0.65rem;"><i class="bi bi-box-arrow-up-right me-1"></i>'.$fallbackLabel.'</a>'
        .'<div class="text-muted x-small text-truncate opacity-75">'.htmlspecialchars($final).'</div>'
        .'</div></div></div></div></div>';
    return $html;
}

/**
 * Get counts of campaigns for each status tab
 */
function getCampaignTabCounts($dateFrom = null, $dateTo = null, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if ($dateFrom) { $where[] = "start_date >= ?"; $params[] = $dateFrom; $types .= "s"; }
    if ($dateTo) { $where[] = "end_date <= ?"; $params[] = $dateTo; $types .= "s"; }
    if (is_array($campaignIds)) {
        $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($ids)) {
            return ['Draft' => 0, 'Active' => 0, 'Live' => 0, 'Pause' => 0, 'Complete' => 0, 'All' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= "i"; }
    }
    
    $whereSql = implode(" AND ", $where);
    
    $sql = "SELECT status, COUNT(*) as count FROM campaign_details WHERE $whereSql GROUP BY status";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['Draft' => 0, 'Active' => 0, 'Live' => 0, 'Pause' => 0, 'Complete' => 0, 'All' => 0];
    }
    if ($types) { mysqliBindParams($stmt, $types, $params); }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $counts = [
        'Draft' => 0,
        'Active' => 0,
        'Live' => 0,
        'Pause' => 0,
        'Complete' => 0,
        'All' => 0,
    ];
    
    $total = 0;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $status = $row['status'];
            $count = (int)$row['count'];
            
            if ($status === 'Draft') $counts['Draft'] += $count;
            elseif ($status === 'Active') $counts['Active'] += $count;
            elseif ($status === 'Live') $counts['Live'] += $count;
            elseif ($status === 'Pause' || $status === 'Paused') $counts['Pause'] += $count;
            elseif ($status === 'Complete' || $status === 'Completed') $counts['Complete'] += $count;
            
            $total += $count;
        }
    }
    $stmt->close();
    $counts['All'] = $total;
    
    return $counts;
}

/**
 * Get detailed overview of campaigns by status
 */
function getCampaignOverviewByStatus($status = null, $dateFrom = null, $dateTo = null, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    $where = ["1=1"];
    $params = [];
    $types = "";
    
    if ($status && $status !== 'All') {
        $where[] = "d.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    if ($dateFrom) { $where[] = "d.start_date >= ?"; $params[] = $dateFrom; $types .= "s"; }
    if ($dateTo) { $where[] = "d.end_date <= ?"; $params[] = $dateTo; $types .= "s"; }
    if (is_array($campaignIds)) {
        $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "c.id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= "i"; }
    }
    
    $whereSql = implode(" AND ", $where);
    
    $sql = "
        SELECT 
            c.id,
            c.name,
            c.owner_id,
            ou.full_name AS owner_name,
            ou.role AS owner_role,
            d.code,
            d.client_id,
            d.client_code,
            cl.name AS client_name,
            d.status,
            d.status_updated_by,
            d.campaign_type,
            d.pacing_type,
            d.start_date,
            d.end_date,
            d.total_leads,
            d.cpl,
            d.cpl_currency,
            cf.form_id AS assigned_form_id
        FROM campaigns c
        JOIN campaign_details d ON c.id = d.campaign_id
        LEFT JOIN clients cl ON cl.id = d.client_id
        LEFT JOIN users ou ON ou.id = c.owner_id
        LEFT JOIN campaign_forms cf ON cf.campaign_id = c.id
        WHERE $whereSql
        ORDER BY d.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types) { mysqliBindParams($stmt, $types, $params); }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

/**
 * Get metrics for a specific campaign
 */
function getCampaignMetrics(int $campaignId): ?array {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM campaign_metrics WHERE campaign_id = ?");
    if (!$stmt) return null;
    $stmt->bind_param("i", $campaignId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function ensureCampaignNotesTable(): void {
    $conn = getDbConnection();
    if (!campaignLeadTableExists('campaign_notes')) {
        $sql = "
            CREATE TABLE IF NOT EXISTS campaign_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                user_id INT NOT NULL,
                note_text TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                updated_by INT NULL,
                attachment_path TEXT NULL,
                attachment_name VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $conn->query($sql);
    }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_notes' AND COLUMN_NAME = 'updated_by'");
    $chk->execute();
    $c1 = $chk->get_result()->fetch_assoc();
    if ((int)($c1['cnt'] ?? 0) === 0) { $conn->query("ALTER TABLE campaign_notes ADD COLUMN updated_by INT NULL"); }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_notes' AND COLUMN_NAME = 'attachment_path'");
    $chk->execute();
    $c2 = $chk->get_result()->fetch_assoc();
    if ((int)($c2['cnt'] ?? 0) === 0) { $conn->query("ALTER TABLE campaign_notes ADD COLUMN attachment_path TEXT NULL"); }
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_notes' AND COLUMN_NAME = 'attachment_name'");
    $chk->execute();
    $c3 = $chk->get_result()->fetch_assoc();
    if ((int)($c3['cnt'] ?? 0) === 0) { $conn->query("ALTER TABLE campaign_notes ADD COLUMN attachment_name VARCHAR(255) NULL"); }
}

function getCampaignNotes(int $campaignId): array {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $sql = "
        SELECT n.id, n.campaign_id, n.user_id, n.note_text, n.created_at, n.updated_at, n.updated_by, n.attachment_path, n.attachment_name,
               u.full_name AS author_name, up.full_name AS updated_by_name
        FROM campaign_notes n
        LEFT JOIN users u ON u.id = n.user_id
        LEFT JOIN users up ON up.id = n.updated_by
        WHERE n.campaign_id = ?
        ORDER BY n.created_at DESC, n.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    }
    $stmt->close();
    return $rows;
}

function getCampaignNoteById(int $noteId): ?array {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $sql = "
        SELECT n.id, n.campaign_id, n.user_id, n.note_text, n.created_at, n.updated_at, n.updated_by, n.attachment_path, n.attachment_name,
               u.full_name AS author_name, up.full_name AS updated_by_name
        FROM campaign_notes n
        LEFT JOIN users u ON u.id = n.user_id
        LEFT JOIN users up ON up.id = n.updated_by
        WHERE n.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $noteId);
    $stmt->execute();
    $res = $stmt->get_result();
    $r = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $r ?: null;
}

function addCampaignNote(int $campaignId, int $userId, string $text, ?string $attachmentPath = null, ?string $attachmentName = null): ?array {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO campaign_notes (campaign_id, user_id, note_text, created_at, updated_by, attachment_path, attachment_name) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
    $stmt->bind_param('iissss', $campaignId, $userId, $text, $userId, $attachmentPath, $attachmentName);
    if (!$stmt->execute()) { return null; }
    $id = (int)$conn->insert_id;
    return getCampaignNoteById($id);
}

function updateCampaignNote(int $noteId, string $text, int $updatedBy, ?string $attachmentPath = null, ?string $attachmentName = null, bool $removeAttachment = false): bool {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $sql = "UPDATE campaign_notes SET note_text = ?, updated_at = NOW(), updated_by = ?";
    $types = "si";
    $params = [$text, $updatedBy];
    if ($removeAttachment) { $sql .= ", attachment_path = NULL, attachment_name = NULL"; }
    else {
        if ($attachmentPath !== null) { $sql .= ", attachment_path = ?"; $types .= "s"; $params[] = $attachmentPath; }
        if ($attachmentName !== null) { $sql .= ", attachment_name = ?"; $types .= "s"; $params[] = $attachmentName; }
    }
    $sql .= " WHERE id = ?";
    $types .= "i";
    $params[] = $noteId;
    $stmt = $conn->prepare($sql);
    mysqliBindParams($stmt, $types, $params);
    return $stmt->execute();
}

function deleteCampaignNote(int $noteId): bool {
    ensureCampaignNotesTable();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM campaign_notes WHERE id = ?");
    $stmt->bind_param('i', $noteId);
    return $stmt->execute();
}

function saveCampaignNoteAttachment(array $file, int $campaignId): ?array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $name = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) return null;
    if ($size <= 0 || $size > 20*1024*1024) return null;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','txt','csv','xls','xlsx','png','jpg','jpeg','gif'];
    if (!in_array($ext, $allowed, true)) return null;
    $base = realpath(__DIR__.'/..');
    if ($base === false) $base = __DIR__.'/..';
    $dir = $base.'/uploads/campaign_notes/'.(int)$campaignId;
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    $fname = date('Ymd_His').'_'.$safe;
    $dest = $dir.'/'.$fname;
    if (!@move_uploaded_file($tmp, $dest)) return null;
    $relPath = 'uploads/campaign_notes/'.(int)$campaignId.'/'.$fname;
    return ['path' => $relPath, 'name' => $name];
}

/**
 * Update metrics for a specific campaign
 */
function updateCampaignMetrics(int $campaignId, array $metrics, int $updatedBy): bool {
    $conn = getDbConnection();
    $sql = "
        INSERT INTO campaign_metrics 
            (campaign_id, delivered, generated, qualified, disqualified, pending, rejected, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            delivered = VALUES(delivered),
            generated = VALUES(generated),
            qualified = VALUES(qualified),
            disqualified = VALUES(disqualified),
            pending = VALUES(pending),
            rejected = VALUES(rejected),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiiiii", 
        $campaignId, 
        $metrics['delivered'], 
        $metrics['generated'], 
        $metrics['qualified'], 
        $metrics['disqualified'], 
        $metrics['pending'], 
        $metrics['rejected'], 
        $updatedBy
    );
    return $stmt->execute();
}

/**
 * Get basic list of all campaigns for selection dropdowns
 */
function getAllCampaignsBasic(): array {
    $conn = getDbConnection();
    $sql = "
        SELECT c.id, c.name, d.code, d.client_code, d.status, d.status_updated_by,
               (SELECT full_name FROM users WHERE id = d.status_updated_by LIMIT 1) AS status_updated_by_name
        FROM campaigns c 
        JOIN campaign_details d ON c.id = d.campaign_id 
        ORDER BY c.name
    ";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function getActiveCampaignsBasic(): array {
    $conn = getDbConnection();
    $sql = "
        SELECT c.id, c.name, d.code, d.client_code, d.status
        FROM campaigns c
        JOIN campaign_details d ON c.id = d.campaign_id
        WHERE c.active = 1 AND d.status = 'Live'
        ORDER BY c.name
    ";
    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function ensureTeamSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS teams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_name VARCHAR(120) NOT NULL,
        manager_user_id INT NOT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        UNIQUE KEY uniq_team_name (team_name),
        INDEX (manager_user_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS team_members (
        team_id INT NOT NULL,
        user_id INT NOT NULL,
        member_role VARCHAR(16) NOT NULL DEFAULT 'member',
        added_by INT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_id, user_id),
        INDEX (user_id),
        INDEX (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS team_campaigns (
        team_id INT NOT NULL,
        campaign_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_id, campaign_id),
        INDEX (campaign_id),
        INDEX (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $verified = true;
}

function ensureTaskManagementSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();

    @$conn->query("CREATE TABLE IF NOT EXISTS tasks (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_code VARCHAR(24) NOT NULL,
        title VARCHAR(255) NOT NULL,
        category VARCHAR(80) NULL,
        department VARCHAR(80) NULL,
        related_department VARCHAR(80) NULL,
        priority ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
        status ENUM('Not Started','Assigned','In Progress','Waiting For Input','On Hold','Under Review','Completed','Rejected','Cancelled','Overdue') NOT NULL DEFAULT 'Assigned',
        progress TINYINT NOT NULL DEFAULT 0,
        task_type VARCHAR(40) NOT NULL DEFAULT 'General Task',
        description MEDIUMTEXT NULL,
        instructions MEDIUMTEXT NULL,
        notes MEDIUMTEXT NULL,
        checklist_total INT NOT NULL DEFAULT 0,
        checklist_done INT NOT NULL DEFAULT 0,
        expected_hours DECIMAL(6,2) NULL,
        start_at DATETIME NULL,
        due_at DATETIME NULL,
        completed_at DATETIME NULL,
        campaign_id INT NULL,
        lead_id INT NULL,
        sales_lead_id INT NULL,
        qa_audit_log_id INT NULL,
        client_id INT NULL,
        vendor_id INT NULL,
        team_id INT NULL,
        deadline_warning_level TINYINT NOT NULL DEFAULT 0,
        escalation_level TINYINT NOT NULL DEFAULT 0,
        created_by INT NOT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_task_code (task_code),
        INDEX idx_status (status),
        INDEX idx_priority (priority),
        INDEX idx_department (department),
        INDEX idx_due (due_at),
        INDEX idx_created (created_at),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_assignees (
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (task_id, user_id),
        INDEX idx_user (user_id),
        INDEX idx_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_checklist_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        item_text VARCHAR(255) NOT NULL,
        is_done TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_task (task_id),
        INDEX idx_done (is_done)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_comments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task (task_id),
        INDEX idx_user (user_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_files (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NULL,
        file_size INT NULL,
        mime_type VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task (task_id),
        INDEX idx_user (user_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_activity (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NULL,
        action VARCHAR(40) NOT NULL,
        meta_json TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task (task_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_time_logs (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        event ENUM('opened','started','paused','resumed','completed') NOT NULL,
        event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task (task_id),
        INDEX idx_user (user_id),
        INDEX idx_event_at (event_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_deadline_alerts (
        task_id INT NOT NULL PRIMARY KEY,
        last_level TINYINT NOT NULL DEFAULT 0,
        last_sent_at DATETIME NULL,
        INDEX idx_level (last_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS task_escalations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        level TINYINT NOT NULL,
        reason VARCHAR(255) NULL,
        triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notified_user_ids TEXT NULL,
        INDEX idx_task (task_id),
        INDEX idx_level (level),
        INDEX idx_triggered (triggered_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $verified = true;
}

function ensureProductivitySchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();

    @$conn->query("CREATE TABLE IF NOT EXISTS productivity_targets (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        working_days INT NOT NULL,
        daily_target INT NOT NULL,
        monthly_target INT NOT NULL,
        minimum_target INT NULL,
        assigned_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_agent_month (agent_id, year, month),
        KEY idx_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    if (!$hasColumn('productivity_targets', 'updated_by')) {
        @$conn->query("ALTER TABLE productivity_targets ADD COLUMN updated_by INT NULL AFTER created_at");
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS productivity_month_locks (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        month INT NOT NULL,
        locked_by INT NOT NULL,
        locked_at DATETIME NOT NULL,
        UNIQUE KEY uniq_year_month (year, month),
        KEY idx_locked_at (locked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS productivity_month_snapshots (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        daily_target_mql INT NOT NULL,
        monthly_target_mql INT NOT NULL,
        working_days INT NOT NULL,
        days_elapsed INT NOT NULL,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        total_leads INT NOT NULL,
        total_mql DECIMAL(10,2) NOT NULL,
        overall_percent DECIMAL(6,2) NOT NULL,
        days_met_daily INT NOT NULL,
        met_monthly TINYINT(1) NOT NULL,
        daily_incentives INT NOT NULL,
        monthly_incentive INT NOT NULL,
        total_incentives INT NOT NULL,
        locked_by INT NOT NULL,
        locked_at DATETIME NOT NULL,
        UNIQUE KEY uniq_agent_month (agent_id, year, month),
        KEY idx_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS productivity_day_snapshots (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        work_date DATE NOT NULL,
        daily_target_mql INT NOT NULL,
        achieved_mql DECIMAL(10,2) NOT NULL,
        met_daily TINYINT(1) NOT NULL DEFAULT 0,
        qualified_leads INT NOT NULL DEFAULT 0,
        incentive_amount INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_agent_date (agent_id, work_date),
        KEY idx_agent_month (agent_id, year, month),
        KEY idx_work_date (work_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $verified = true;
}

function ensureFunnelTrack360Schema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();

    @$conn->query("CREATE TABLE IF NOT EXISTS funneltrack_escalations (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY idx_user_active (user_id, active),
        KEY idx_dates (start_date, end_date),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS funneltrack_overrides (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year INT NOT NULL,
        month INT NOT NULL,
        override_key VARCHAR(64) NOT NULL,
        override_value VARCHAR(255) NOT NULL,
        reason VARCHAR(255) NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_month_key (user_id, year, month, override_key),
        KEY idx_user_month (user_id, year, month),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    if (!$hasColumn('funneltrack_escalations', 'updated_by')) {
        @$conn->query("ALTER TABLE funneltrack_escalations ADD COLUMN updated_by INT NULL AFTER created_at");
    }
    if (!$hasColumn('funneltrack_overrides', 'updated_by')) {
        @$conn->query("ALTER TABLE funneltrack_overrides ADD COLUMN updated_by INT NULL AFTER created_at");
    }
    if (!$hasColumn('funneltrack_overrides', 'updated_at')) {
        @$conn->query("ALTER TABLE funneltrack_overrides ADD COLUMN updated_at DATETIME NULL AFTER updated_by");
    }

    $verified = true;
}

function ensureDbmsSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    $autoMigrate = (defined('DBMS_SCHEMA_AUTO_MIGRATE') && DBMS_SCHEMA_AUTO_MIGRATE) ? true : false;

    $hasColumn = function(string $table, string $column) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $addColumn = function(string $table, string $column, string $definition) use ($conn, $hasColumn): void {
        if (!(defined('DBMS_SCHEMA_AUTO_MIGRATE') && DBMS_SCHEMA_AUTO_MIGRATE)) return;
        if ($hasColumn($table, $column)) return;
        @$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    };
    $hasIndex = function(string $table, string $indexName) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $indexName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        return ((int)($row['cnt'] ?? 0)) > 0;
    };
    $addIndex = function(string $table, string $indexName, string $definition) use ($conn, $hasIndex): void {
        if (!(defined('DBMS_SCHEMA_AUTO_MIGRATE') && DBMS_SCHEMA_AUTO_MIGRATE)) return;
        if ($hasIndex($table, $indexName)) return;
        @$conn->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($definition)");
    };

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_batches (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(40) NOT NULL DEFAULT 'upload',
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        uploaded_by INT NOT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(30) NOT NULL DEFAULT 'uploaded',
        total_rows INT NOT NULL DEFAULT 0,
        valid_rows INT NOT NULL DEFAULT 0,
        append_rows INT NOT NULL DEFAULT 0,
        rejected_rows INT NOT NULL DEFAULT 0,
        duplicate_rows INT NOT NULL DEFAULT 0,
        notes TEXT NULL,
        KEY idx_uploaded_at (uploaded_at),
        KEY idx_uploaded_by (uploaded_by),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('dbms_batches', 'source', "VARCHAR(40) NOT NULL DEFAULT 'upload'");
    $addColumn('dbms_batches', 'uploaded_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $addColumn('dbms_batches', 'status', "VARCHAR(30) NOT NULL DEFAULT 'uploaded'");
    $addColumn('dbms_batches', 'total_rows', "INT NOT NULL DEFAULT 0");
    $addColumn('dbms_batches', 'valid_rows', "INT NOT NULL DEFAULT 0");
    $addColumn('dbms_batches', 'append_rows', "INT NOT NULL DEFAULT 0");
    $addColumn('dbms_batches', 'rejected_rows', "INT NOT NULL DEFAULT 0");
    $addColumn('dbms_batches', 'duplicate_rows', "INT NOT NULL DEFAULT 0");
    $addColumn('dbms_batches', 'notes', "TEXT NULL");

    $addIndex('dbms_batches', 'idx_uploaded_at', '`uploaded_at`');
    $addIndex('dbms_batches', 'idx_uploaded_by', '`uploaded_by`');
    $addIndex('dbms_batches', 'idx_status', '`status`');

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_contacts (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NULL,
        duplicate_of_contact_id BIGINT NULL,
        source VARCHAR(60) NULL,
        stage VARCHAR(20) NOT NULL DEFAULT 'valid',
        review_status VARCHAR(30) NOT NULL DEFAULT 'pending_cdqa',
        assigned_department VARCHAR(30) NULL,
        owner_user_id INT NULL,
        assigned_to_user_id INT NULL,
        assigned_at DATETIME NULL,
        agent_status VARCHAR(40) NOT NULL DEFAULT 'Pending',
        agent_status_updated_at DATETIME NULL,
        email_status VARCHAR(20) NULL,
        last_email_status VARCHAR(20) NULL,
        email_status_at DATETIME NULL,
        phone_status VARCHAR(30) NULL,
        phone_status_at DATETIME NULL,
        data_status VARCHAR(20) NOT NULL DEFAULT 'Valid',
        confidence_score INT NOT NULL DEFAULT 0,
        freshness_score INT NOT NULL DEFAULT 0,

        first_name VARCHAR(120) NULL,
        last_name VARCHAR(120) NULL,
        email VARCHAR(190) NULL,
        email_norm VARCHAR(190) NULL,
        direct_number VARCHAR(40) NULL,
        direct_number_norm VARCHAR(40) NULL,
        company_phone VARCHAR(40) NULL,
        company_phone_norm VARCHAR(40) NULL,
        extension VARCHAR(20) NULL,

        job_title VARCHAR(180) NULL,
        seniority VARCHAR(80) NULL,
        department VARCHAR(120) NULL,

        company_name VARCHAR(200) NULL,
        industry VARCHAR(160) NULL,
        website VARCHAR(255) NULL,
        website_norm VARCHAR(255) NULL,
        revenue_link VARCHAR(500) NULL,
        revenue VARCHAR(80) NULL,
        funding VARCHAR(80) NULL,
        employee_count VARCHAR(60) NULL,

        city VARCHAR(120) NULL,
        state VARCHAR(120) NULL,
        country VARCHAR(120) NULL,
        address VARCHAR(255) NULL,
        postal_code VARCHAR(40) NULL,

        linkedin_url TEXT NULL,
        linkedin_norm VARCHAR(255) NULL,
        technologies TEXT NULL,
        sic VARCHAR(40) NULL,
        naics VARCHAR(40) NULL,
        industry_keywords TEXT NULL,

        intent_topics TEXT NULL,
        last_activity_at DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,

        KEY idx_stage (stage),
        KEY idx_review (review_status),
        KEY idx_assigned_to (assigned_to_user_id),
        KEY idx_owner (owner_user_id),
        KEY idx_department (assigned_department),
        KEY idx_country (country),
        KEY idx_state (state),
        KEY idx_city (city),
        KEY idx_company (company_name),
        KEY idx_job (job_title),
        KEY idx_batch (batch_id),
        KEY idx_dup_of (duplicate_of_contact_id),
        UNIQUE KEY uniq_email_norm (email_norm),
        UNIQUE KEY uniq_linkedin_norm (linkedin_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('dbms_contacts', 'duplicate_of_contact_id', "BIGINT NULL AFTER `batch_id`");
    $addColumn('dbms_contacts', 'name', "VARCHAR(240) NULL AFTER `freshness_score`");
    $addColumn('dbms_contacts', 'employee_size_bracket', "VARCHAR(60) NULL AFTER `employee_count`");
    $addColumn('dbms_contacts', 'revenue_bracket', "VARCHAR(80) NULL AFTER `revenue`");
    $addColumn('dbms_contacts', 'total_funding', "VARCHAR(80) NULL AFTER `funding`");
    $addColumn('dbms_contacts', 'revenue_link', "VARCHAR(500) NULL AFTER `website_norm`");
    $addColumn('dbms_contacts', 'company_address', "VARCHAR(255) NULL AFTER `address`");
    $addColumn('dbms_contacts', 'company_city', "VARCHAR(120) NULL AFTER `city`");
    $addColumn('dbms_contacts', 'company_state', "VARCHAR(120) NULL AFTER `state`");
    $addColumn('dbms_contacts', 'post_code', "VARCHAR(40) NULL AFTER `postal_code`");
    $addColumn('dbms_contacts', 'company_country', "VARCHAR(120) NULL AFTER `country`");
    $addColumn('dbms_contacts', 'prospect_location', "VARCHAR(180) NULL AFTER `department`");
    $addColumn('dbms_contacts', 'prospect_country', "VARCHAR(120) NULL AFTER `country`");
    $addColumn('dbms_contacts', 'retail_locations', "VARCHAR(60) NULL AFTER `naics`");

    $addIndex('dbms_contacts', 'idx_emp_bracket', '`employee_size_bracket`');
    $addIndex('dbms_contacts', 'idx_rev_bracket', '`revenue_bracket`');
    $addIndex('dbms_contacts', 'idx_email_status', '`email_status`');
    $addIndex('dbms_contacts', 'idx_last_email_status', '`last_email_status`');
    $addIndex('dbms_contacts', 'idx_email_status_at', '`email_status_at`');
    $addIndex('dbms_contacts', 'idx_phone_status', '`phone_status`');
    $addIndex('dbms_contacts', 'idx_phone_status_at', '`phone_status_at`');
    $addIndex('dbms_contacts', 'idx_data_status', '`data_status`');
    $addIndex('dbms_contacts', 'idx_assigned_at', '`assigned_at`');
    $addIndex('dbms_contacts', 'idx_dup_of', '`duplicate_of_contact_id`');
    $addIndex('dbms_contacts', 'idx_revenue_link', '`revenue_link`(191)');

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_batch_rows (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        row_num INT NOT NULL,
        status VARCHAR(20) NOT NULL,
        reason VARCHAR(255) NULL,
        contact_id BIGINT NULL,
        raw_json MEDIUMTEXT NULL,
        normalized_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_batch (batch_id),
        KEY idx_status (status),
        KEY idx_contact (contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $addColumn('dbms_batch_rows', 'reason', "VARCHAR(255) NULL");
    $addColumn('dbms_batch_rows', 'contact_id', "BIGINT NULL");
    $addColumn('dbms_batch_rows', 'raw_json', "MEDIUMTEXT NULL");
    $addColumn('dbms_batch_rows', 'normalized_json', "MEDIUMTEXT NULL");
    $addColumn('dbms_batch_rows', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");

    $addIndex('dbms_batch_rows', 'idx_batch', '`batch_id`');
    $addIndex('dbms_batch_rows', 'idx_status', '`status`');
    $addIndex('dbms_batch_rows', 'idx_contact', '`contact_id`');

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_assignments (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id BIGINT NOT NULL,
        department VARCHAR(30) NULL,
        assigned_to_user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note VARCHAR(255) NULL,
        KEY idx_contact (contact_id),
        KEY idx_assigned_to (assigned_to_user_id),
        KEY idx_assigned_at (assigned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_contact_comments (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        comment TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_contact (contact_id),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_intent_topic_history (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id BIGINT NOT NULL,
        prev_topics TEXT NULL,
        new_topics TEXT NULL,
        updated_by INT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_contact (contact_id),
        KEY idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("CREATE TABLE IF NOT EXISTS dbms_audit_logs (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(40) NOT NULL,
        entity_id BIGINT NOT NULL,
        action VARCHAR(60) NOT NULL,
        field_name VARCHAR(60) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        remarks TEXT NULL,
        user_id INT NULL,
        module_source VARCHAR(60) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_entity (entity_type, entity_id),
        KEY idx_created_at (created_at),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $verified = true;
}

function dbmsNormalizeFilterText(string $value): string {
    $value = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", '–', '—'], '-', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function dbmsTableHasColumn(string $table, string $column): bool {
    static $cache = [];
    $table = strtolower(trim($table));
    $column = strtolower(trim($column));
    if ($table === '' || $column === '') return false;
    if (!isset($cache[$table]) || !is_array($cache[$table])) {
        $cache[$table] = [];
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        if ($stmt) {
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $c = strtolower((string)($r['COLUMN_NAME'] ?? ''));
                if ($c !== '') $cache[$table][$c] = true;
            }
            $stmt->close();
        }
    }
    return isset($cache[$table][$column]);
}

function dbmsContactsHasColumn(string $column): bool {
    return dbmsTableHasColumn('dbms_contacts', $column);
}

function dbmsAssignmentsHasColumn(string $column): bool {
    return dbmsTableHasColumn('dbms_assignments', $column);
}

function dbmsBatchesHasColumn(string $column): bool {
    return dbmsTableHasColumn('dbms_batches', $column);
}

function dbmsBatchRowsHasColumn(string $column): bool {
    return dbmsTableHasColumn('dbms_batch_rows', $column);
}

function dbmsNormalizeWorkflowStatus(string $status): string {
    $status = strtolower(trim($status));
    return preg_replace('/[^a-z0-9_]+/', '_', $status);
}

function dbmsWorkflowStatusFromRow(array $row): string {
    $workflow = dbmsNormalizeWorkflowStatus((string)($row['workflow_status'] ?? ''));
    if ($workflow !== '') return $workflow;

    $reviewStatus = dbmsNormalizeWorkflowStatus((string)($row['review_status'] ?? ''));
    $stage = dbmsNormalizeWorkflowStatus((string)($row['stage'] ?? ''));
    $assignedDepartment = trim((string)($row['assigned_department'] ?? ''));
    $assignedTo = (int)($row['assigned_to_user_id'] ?? 0);

    if ($reviewStatus === 'approved') return 'approved';
    if ($reviewStatus === 'research_rework') return 'research_rework';
    if ($reviewStatus === 'pending_research') return 'pending_research';
    if ($reviewStatus === 'pending_cdqa') {
        if ($assignedTo > 0 && strcasecmp($assignedDepartment, 'CDQA') === 0) return 'cdqa_assigned';
        return 'pending_cdqa';
    }
    if ($reviewStatus === 'duplicate' || $stage === 'duplicate') return 'duplicate';
    if ($reviewStatus === 'rejected' || $stage === 'rejected') return 'rejected';
    if ($stage === 'append' || $reviewStatus === 'append_queue') {
        if ($assignedTo > 0 && strcasecmp($assignedDepartment, 'Research') === 0) return 'research_assigned';
        return 'pending_research';
    }
    if ($assignedTo > 0) {
        if (strcasecmp($assignedDepartment, 'Research') === 0) return 'research_assigned';
        if (strcasecmp($assignedDepartment, 'CDQA') === 0) return 'cdqa_assigned';
    }
    if ($stage === 'ready' || $stage === 'valid' || $stage === 'assigned' || $reviewStatus === 'ready') {
        return 'pending_cdqa';
    }
    return 'pending_cdqa';
}

function dbmsWorkflowStatusSql(string $alias = 'c'): string {
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    $workflowExpr = dbmsContactsHasColumn('workflow_status')
        ? "LOWER(TRIM(COALESCE({$prefix}workflow_status, '')))"
        : "''";
    $reviewExpr = "LOWER(TRIM(COALESCE({$prefix}review_status, '')))";
    $stageExpr = "LOWER(TRIM(COALESCE({$prefix}stage, '')))";
    $deptExpr = "LOWER(TRIM(COALESCE({$prefix}assigned_department, '')))";
    $assignedExpr = "COALESCE({$prefix}assigned_to_user_id, 0)";

    return "(CASE
        WHEN {$workflowExpr} <> '' THEN {$workflowExpr}
        WHEN {$reviewExpr} = 'approved' THEN 'approved'
        WHEN {$reviewExpr} = 'research_rework' THEN 'research_rework'
        WHEN {$reviewExpr} = 'pending_research' THEN 'pending_research'
        WHEN {$reviewExpr} = 'pending_cdqa' AND {$assignedExpr} > 0 AND {$deptExpr} = 'cdqa' THEN 'cdqa_assigned'
        WHEN {$reviewExpr} = 'pending_cdqa' THEN 'pending_cdqa'
        WHEN {$reviewExpr} = 'duplicate' OR {$stageExpr} = 'duplicate' THEN 'duplicate'
        WHEN {$reviewExpr} = 'rejected' OR {$stageExpr} = 'rejected' THEN 'rejected'
        WHEN ({$stageExpr} = 'append' OR {$reviewExpr} = 'append_queue') AND {$assignedExpr} > 0 AND {$deptExpr} = 'research' THEN 'research_assigned'
        WHEN {$stageExpr} = 'append' OR {$reviewExpr} = 'append_queue' THEN 'pending_research'
        WHEN {$assignedExpr} > 0 AND {$deptExpr} = 'research' THEN 'research_assigned'
        WHEN {$assignedExpr} > 0 AND {$deptExpr} = 'cdqa' THEN 'cdqa_assigned'
        ELSE 'pending_cdqa'
    END)";
}

function dbmsWorkflowQueueDefinitions(): array {
    return [
        'pending_research' => ['label' => 'Pending Research', 'department' => 'Research', 'assignable' => true],
        'research_assigned' => ['label' => 'Research Assigned', 'department' => 'Research', 'assignable' => true],
        'research_rework' => ['label' => 'Research Rework', 'department' => 'Research', 'assignable' => true],
        'pending_cdqa' => ['label' => 'Pending CDQA', 'department' => 'CDQA', 'assignable' => true],
        'cdqa_assigned' => ['label' => 'CDQA Assigned', 'department' => 'CDQA', 'assignable' => true],
        'approved' => ['label' => 'Approved', 'department' => 'CDQA', 'assignable' => false],
        'duplicate' => ['label' => 'Duplicate', 'department' => '', 'assignable' => false],
        'rejected' => ['label' => 'Rejected', 'department' => '', 'assignable' => false],
    ];
}

function dbmsUploadOptionalFields(): array {
    return [
        'email_status',
        'last_email_status',
        'email_timestamp',
        'intent_topics',
        'phone_connection_status',
        'phone_timestamp',
        'data_status',
        'ranking',
    ];
}

function dbmsUploadRequiredFields(): array {
    return [
        'first_name',
        'last_name',
        'email',
        'job_title',
        'seniority',
        'department',
        'company_name',
        'employee_count',
        'employee_size_bracket',
        'industry',
        'linkedin_url',
        'website',
        'company_linkedin_url',
        'prospect_location',
        'prospect_country',
        'company_address',
        'company_city',
        'company_state',
        'post_code',
        'company_country',
        'company_phone',
        'extension',
        'direct_number',
        'revenue_link',
        'revenue',
        'revenue_bracket',
        'total_funding',
        'industry_keywords',
        'technologies',
        'sic',
        'naics',
        'retail_locations',
    ];
}

function dbmsHasPrimaryIdentifier(array $row): bool {
    return trim((string)($row['email'] ?? '')) !== ''
        || trim((string)($row['linkedin_url'] ?? '')) !== ''
        || trim((string)($row['website'] ?? '')) !== ''
        || trim((string)($row['direct_number'] ?? '')) !== '';
}

function dbmsCdqaReadyFields(): array {
    return dbmsUploadRequiredFields();
}

function dbmsIsCdqaReadyRecord(array $row): bool {
    foreach (dbmsCdqaReadyFields() as $field) {
        if (trim((string)($row[$field] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

function dbmsWorkflowLabel(string $status): string {
    $status = dbmsNormalizeWorkflowStatus($status);
    $defs = dbmsWorkflowQueueDefinitions();
    return (string)($defs[$status]['label'] ?? ucwords(str_replace('_', ' ', $status)));
}

function dbmsWorkflowAssignmentDepartments(): array {
    return [
        'Research' => 'Research',
        'CDQA' => 'CDQA',
    ];
}

function dbmsWorkflowPermissionForDepartment(string $department): string {
    $department = strtolower(trim($department));
    if ($department === 'cdqa') return 'dbms.cdqa';
    return 'dbms.research';
}

function dbmsUserCanHandleDepartment(array $user, array $permCfg, string $department): bool {
    $role = normalizeRole((string)($user['role'] ?? ''));
    if ($role === 'admin') return true;
    if ($role === '') return false;
    $permission = dbmsWorkflowPermissionForDepartment($department);
    if (roleHasPermission($role, $permission, $permCfg)) return true;
    return strtolower(trim($department)) === 'research' && roleHasPermission($role, 'dbms.my', $permCfg);
}

function dbmsWorkflowQueueConditionSql(string $queue, string $alias = 'c', bool $unassignedOnly = false): string {
    $queue = dbmsNormalizeWorkflowStatus($queue);
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    $workflowExpr = dbmsWorkflowStatusSql($alias);
    $assignedExpr = "COALESCE({$prefix}assigned_to_user_id, 0)";
    $unassignedExpr = "({$assignedExpr} = 0)";
    $isAssignedExpr = "({$assignedExpr} <> 0)";

    switch ($queue) {
        case 'pending_research':
        case 'research_rework':
        case 'pending_cdqa':
        case 'approved':
        case 'duplicate':
        case 'rejected':
            $condition = "({$workflowExpr} = '{$queue}')";
            if ($unassignedOnly && in_array($queue, ['pending_research', 'research_rework', 'pending_cdqa'], true)) {
                $condition .= " AND {$unassignedExpr}";
            }
            return $condition;
        case 'research_assigned':
        case 'cdqa_assigned':
            $condition = "({$workflowExpr} = '{$queue}')";
            if ($unassignedOnly) $condition .= " AND {$isAssignedExpr}";
            return $condition;
        case 'assigned':
            return "({$workflowExpr} IN ('research_assigned', 'cdqa_assigned'))";
        default:
            return "({$workflowExpr} = 'pending_cdqa')";
    }
}

function dbmsWorkflowFilterSqlQueue(string $queue, bool $unassignedOnly = false, string $alias = 'c'): string {
    return dbmsWorkflowQueueConditionSql($queue, $alias, $unassignedOnly);
}

function dbmsDetermineAssignedWorkflowStatus(string $currentWorkflowStatus, string $department): string {
    $currentWorkflowStatus = dbmsNormalizeWorkflowStatus($currentWorkflowStatus);
    $department = strtolower(trim($department));
    if (in_array($currentWorkflowStatus, ['approved', 'duplicate', 'rejected'], true)) return $currentWorkflowStatus;
    if ($department === 'cdqa') return 'cdqa_assigned';
    return 'research_assigned';
}

function dbmsWorkflowLegacyStateForStatus(string $workflowStatus): array {
    $workflowStatus = dbmsNormalizeWorkflowStatus($workflowStatus);
    switch ($workflowStatus) {
        case 'pending_research':
        case 'research_assigned':
        case 'research_rework':
            return ['stage' => 'append', 'review_status' => $workflowStatus === 'research_rework' ? 'research_rework' : 'append_queue'];
        case 'pending_cdqa':
        case 'cdqa_assigned':
            return ['stage' => 'ready', 'review_status' => 'pending_cdqa'];
        case 'approved':
            return ['stage' => 'ready', 'review_status' => 'approved'];
        case 'duplicate':
            return ['stage' => 'duplicate', 'review_status' => 'duplicate'];
        case 'rejected':
            return ['stage' => 'rejected', 'review_status' => 'rejected'];
        default:
            return ['stage' => 'ready', 'review_status' => 'pending_cdqa'];
    }
}

function dbmsCanAssignWorkflowStatus(string $workflowStatus, string $department): bool {
    $workflowStatus = dbmsNormalizeWorkflowStatus($workflowStatus);
    $department = strtolower(trim($department));
    if ($department === 'research') {
        return in_array($workflowStatus, ['pending_research', 'research_rework', 'research_assigned'], true);
    }
    if ($department === 'cdqa') {
        return in_array($workflowStatus, ['pending_cdqa', 'cdqa_assigned'], true);
    }
    return false;
}

function dbmsAssignContacts(array $contactIds, string $department, int $assignToUserId, int $assignedByUserId, string $note = '', bool $allowReassign = false, string $moduleSource = 'dbms.workflow'): array {
    $department = trim($department);
    $moduleSource = trim($moduleSource) !== '' ? trim($moduleSource) : 'dbms.workflow';
    $contactIds = array_values(array_unique(array_values(array_filter(array_map('intval', $contactIds), fn($id) => $id > 0))));
    if ($assignToUserId <= 0 || $assignedByUserId <= 0 || $department === '' || empty($contactIds)) {
        return ['assigned_count' => 0, 'assigned_ids' => [], 'skipped_ids' => $contactIds];
    }
    if (!isset(dbmsWorkflowAssignmentDepartments()[$department])) {
        throw new RuntimeException('Invalid DBMS assignment department.');
    }

    $conn = getDbConnection();
    $hasWorkflowStatus = dbmsContactsHasColumn('workflow_status');
    $hasWorkflowUpdatedAt = dbmsContactsHasColumn('workflow_updated_at');
    $hasAssignmentType = dbmsAssignmentsHasColumn('assignment_type');
    $hasQueueName = dbmsAssignmentsHasColumn('queue_name');
    $hasFromWorkflowStatus = dbmsAssignmentsHasColumn('from_workflow_status');
    $hasToWorkflowStatus = dbmsAssignmentsHasColumn('to_workflow_status');
    $hasFromUserId = dbmsAssignmentsHasColumn('from_user_id');
    $hasIsReassignment = dbmsAssignmentsHasColumn('is_reassignment');

    $selectCols = 'id, stage, review_status, assigned_to_user_id, assigned_department';
    if ($hasWorkflowStatus) $selectCols .= ', workflow_status';
    $stmtBefore = $conn->prepare("SELECT {$selectCols} FROM dbms_contacts WHERE id = ? LIMIT 1");
    if (!$stmtBefore) throw new RuntimeException('Database error.');

    $updateCols = [
        'assigned_department = ?',
        'assigned_to_user_id = ?',
        'assigned_at = NOW()',
    ];
    $updateTypes = 'si';
    if ($hasWorkflowStatus) {
        $updateCols[] = 'workflow_status = ?';
        $updateTypes .= 's';
    }
    if ($hasWorkflowUpdatedAt) {
        $updateCols[] = 'workflow_updated_at = NOW()';
    }
    $updateCols[] = 'stage = ?';
    $updateTypes .= 's';
    $updateCols[] = 'review_status = ?';
    $updateTypes .= 's';
    $updateCols[] = 'updated_at = NOW()';
    $stmtUpdate = $conn->prepare('UPDATE dbms_contacts SET ' . implode(', ', $updateCols) . ' WHERE id = ?');
    if (!$stmtUpdate) {
        $stmtBefore->close();
        throw new RuntimeException('Database error.');
    }

    $assignmentColumns = ['contact_id', 'department', 'assigned_to_user_id', 'assigned_by', 'note'];
    $assignmentValues = ['?', '?', '?', '?', '?'];
    $assignmentTypes = 'isiis';
    if ($hasAssignmentType) {
        $assignmentColumns[] = 'assignment_type';
        $assignmentValues[] = '?';
        $assignmentTypes .= 's';
    }
    if ($hasQueueName) {
        $assignmentColumns[] = 'queue_name';
        $assignmentValues[] = '?';
        $assignmentTypes .= 's';
    }
    if ($hasFromWorkflowStatus) {
        $assignmentColumns[] = 'from_workflow_status';
        $assignmentValues[] = '?';
        $assignmentTypes .= 's';
    }
    if ($hasToWorkflowStatus) {
        $assignmentColumns[] = 'to_workflow_status';
        $assignmentValues[] = '?';
        $assignmentTypes .= 's';
    }
    if ($hasFromUserId) {
        $assignmentColumns[] = 'from_user_id';
        $assignmentValues[] = '?';
        $assignmentTypes .= 'i';
    }
    if ($hasIsReassignment) {
        $assignmentColumns[] = 'is_reassignment';
        $assignmentValues[] = '?';
        $assignmentTypes .= 'i';
    }
    $stmtAssign = $conn->prepare('INSERT INTO dbms_assignments (' . implode(', ', $assignmentColumns) . ') VALUES (' . implode(', ', $assignmentValues) . ')');
    if (!$stmtAssign) {
        $stmtBefore->close();
        $stmtUpdate->close();
        throw new RuntimeException('Database error.');
    }

    $stmtAudit = $conn->prepare("INSERT INTO dbms_audit_logs (entity_type, entity_id, action, field_name, old_value, new_value, remarks, user_id, module_source, ip_address, user_agent) VALUES ('contact', ?, 'Assignment', ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmtAudit) {
        $stmtBefore->close();
        $stmtUpdate->close();
        $stmtAssign->close();
        throw new RuntimeException('Database error.');
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $assignedIds = [];
    $skippedIds = [];

    foreach ($contactIds as $contactId) {
        $stmtBefore->bind_param('i', $contactId);
        $stmtBefore->execute();
        $before = $stmtBefore->get_result()->fetch_assoc() ?: null;
        if (!$before) {
            $skippedIds[] = $contactId;
            continue;
        }

        $previousAssignee = (int)($before['assigned_to_user_id'] ?? 0);
        $previousDepartment = trim((string)($before['assigned_department'] ?? ''));
        $currentWorkflowStatus = dbmsWorkflowStatusFromRow($before);
        if (!dbmsCanAssignWorkflowStatus($currentWorkflowStatus, $department)) {
            $skippedIds[] = $contactId;
            continue;
        }
        if (!$allowReassign && $previousAssignee > 0 && $previousAssignee !== $assignToUserId) {
            $skippedIds[] = $contactId;
            continue;
        }

        $nextWorkflowStatus = dbmsDetermineAssignedWorkflowStatus($currentWorkflowStatus, $department);
        $legacyState = dbmsWorkflowLegacyStateForStatus($nextWorkflowStatus);

        $updateParams = [$department, $assignToUserId];
        if ($hasWorkflowStatus) $updateParams[] = $nextWorkflowStatus;
        $updateParams[] = (string)$legacyState['stage'];
        $updateParams[] = (string)$legacyState['review_status'];
        $updateParams[] = $contactId;
        mysqliBindParams($stmtUpdate, $updateTypes . 'i', $updateParams);
        $stmtUpdate->execute();
        if ($stmtUpdate->affected_rows <= 0) {
            $skippedIds[] = $contactId;
            continue;
        }

        $assignmentType = strtolower($department) === 'cdqa' ? 'cdqa_assignment' : 'research_assignment';
        $isReassignment = ($previousAssignee > 0 && $previousAssignee !== $assignToUserId) ? 1 : 0;
        $assignmentParams = [$contactId, $department, $assignToUserId, $assignedByUserId, $note];
        if ($hasAssignmentType) $assignmentParams[] = $assignmentType;
        if ($hasQueueName) $assignmentParams[] = $currentWorkflowStatus;
        if ($hasFromWorkflowStatus) $assignmentParams[] = $currentWorkflowStatus;
        if ($hasToWorkflowStatus) $assignmentParams[] = $nextWorkflowStatus;
        if ($hasFromUserId) $assignmentParams[] = $previousAssignee;
        if ($hasIsReassignment) $assignmentParams[] = $isReassignment;
        mysqliBindParams($stmtAssign, $assignmentTypes, $assignmentParams);
        $stmtAssign->execute();

        $remarks = trim($note !== '' ? $note : ('Workflow: ' . dbmsWorkflowLabel($currentWorkflowStatus) . ' -> ' . dbmsWorkflowLabel($nextWorkflowStatus)));
        $field = 'assigned_to_user_id';
        $auditParams = [$contactId, $field, (string)$previousAssignee, (string)$assignToUserId, $remarks, $assignedByUserId, $moduleSource, $ip, $ua];
        mysqliBindParams($stmtAudit, 'issssisss', $auditParams);
        $stmtAudit->execute();
        $field = 'assigned_department';
        $auditParams = [$contactId, $field, $previousDepartment, $department, $remarks, $assignedByUserId, $moduleSource, $ip, $ua];
        mysqliBindParams($stmtAudit, 'issssisss', $auditParams);
        $stmtAudit->execute();

        $assignedIds[] = $contactId;
    }

    $stmtBefore->close();
    $stmtUpdate->close();
    $stmtAssign->close();
    $stmtAudit->close();

    return [
        'assigned_count' => count($assignedIds),
        'assigned_ids' => $assignedIds,
        'skipped_ids' => $skippedIds,
    ];
}

function dbmsAddContactComment(int $contactId, int $userId, string $comment): void {
    $comment = trim($comment);
    if ($contactId <= 0 || $userId <= 0 || $comment === '') return;
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO dbms_contact_comments (contact_id, user_id, comment) VALUES (?,?,?)");
    if ($stmt) {
        $stmt->bind_param('iis', $contactId, $userId, $comment);
        $stmt->execute();
        $stmt->close();
    }
}

function dbmsInsertAuditLog(int $contactId, string $action, string $fieldName, ?string $oldValue, ?string $newValue, ?string $remarks, int $userId, string $moduleSource): void {
    if ($contactId <= 0 || $userId <= 0) return;
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO dbms_audit_logs (entity_type, entity_id, action, field_name, old_value, new_value, remarks, user_id, module_source, ip_address, user_agent) VALUES ('contact', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)($_SERVER['REMOTE_ADDR'] ?? '') : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)($_SERVER['HTTP_USER_AGENT'] ?? '') : '';
        $stmt->bind_param('isssssisss', $contactId, $action, $fieldName, $oldValue, $newValue, $remarks, $userId, $moduleSource, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }
}

function dbmsTransitionContactWorkflow(int $contactId, string $nextWorkflowStatus, int $updatedByUserId, string $moduleSource, array $options = []): bool {
    if ($contactId <= 0 || $updatedByUserId <= 0) return false;
    $nextWorkflowStatus = dbmsNormalizeWorkflowStatus($nextWorkflowStatus);
    if ($nextWorkflowStatus === '') return false;

    $conn = getDbConnection();
    $hasWorkflowStatus = dbmsContactsHasColumn('workflow_status');
    $hasWorkflowUpdatedAt = dbmsContactsHasColumn('workflow_updated_at');
    $hasResearchSubmittedAt = dbmsContactsHasColumn('research_submitted_at');
    $hasResearchSubmittedBy = dbmsContactsHasColumn('research_submitted_by');
    $hasCdqaDecision = dbmsContactsHasColumn('cdqa_decision');
    $hasCdqaDecisionAt = dbmsContactsHasColumn('cdqa_decision_at');
    $hasCdqaDecisionBy = dbmsContactsHasColumn('cdqa_decision_by');
    $hasCdqaComment = dbmsContactsHasColumn('cdqa_comment');
    $hasReworkCount = dbmsContactsHasColumn('rework_count');
    $hasLastActivityAt = dbmsContactsHasColumn('last_activity_at');

    $selectCols = 'id, stage, review_status, assigned_to_user_id, assigned_department';
    if ($hasWorkflowStatus) $selectCols .= ', workflow_status';
    if ($hasCdqaComment) $selectCols .= ', cdqa_comment';
    $stmtBefore = $conn->prepare("SELECT {$selectCols} FROM dbms_contacts WHERE id = ? LIMIT 1");
    if (!$stmtBefore) throw new RuntimeException('Database error.');
    $stmtBefore->bind_param('i', $contactId);
    $stmtBefore->execute();
    $before = $stmtBefore->get_result()->fetch_assoc() ?: null;
    $stmtBefore->close();
    if (!$before) throw new RuntimeException('Record not found.');

    $expectedAssigneeId = isset($options['expected_assignee_id']) ? (int)$options['expected_assignee_id'] : 0;
    if ($expectedAssigneeId > 0 && (int)($before['assigned_to_user_id'] ?? 0) !== $expectedAssigneeId) {
        throw new RuntimeException('Record not found or not assigned to you.');
    }
    $expectedDepartment = trim((string)($options['expected_department'] ?? ''));
    if ($expectedDepartment !== '' && strcasecmp((string)($before['assigned_department'] ?? ''), $expectedDepartment) !== 0) {
        throw new RuntimeException('Record is not assigned to this workflow lane.');
    }

    $legacyState = dbmsWorkflowLegacyStateForStatus($nextWorkflowStatus);
    $set = [];
    $types = '';
    $params = [];

    if ($hasWorkflowStatus) {
        $set[] = 'workflow_status = ?';
        $types .= 's';
        $params[] = $nextWorkflowStatus;
    }
    if ($hasWorkflowUpdatedAt) {
        $set[] = 'workflow_updated_at = NOW()';
    }
    $set[] = 'stage = ?';
    $types .= 's';
    $params[] = (string)$legacyState['stage'];
    $set[] = 'review_status = ?';
    $types .= 's';
    $params[] = (string)$legacyState['review_status'];

    if (!empty($options['clear_assignment'])) {
        $set[] = 'assigned_to_user_id = NULL';
        $set[] = 'assigned_department = NULL';
        $set[] = 'assigned_at = NULL';
    } else {
        if (array_key_exists('assigned_to_user_id', $options)) {
            $assignTo = $options['assigned_to_user_id'];
            if ($assignTo === null) {
                $set[] = 'assigned_to_user_id = NULL';
            } else {
                $set[] = 'assigned_to_user_id = ?';
                $types .= 'i';
                $params[] = (int)$assignTo;
            }
        }
        if (array_key_exists('assigned_department', $options)) {
            $assignDept = $options['assigned_department'];
            if ($assignDept === null || trim((string)$assignDept) === '') {
                $set[] = 'assigned_department = NULL';
            } else {
                $set[] = 'assigned_department = ?';
                $types .= 's';
                $params[] = trim((string)$assignDept);
            }
        }
    }

    if (!empty($options['touch_research_submission'])) {
        if ($hasResearchSubmittedAt) $set[] = 'research_submitted_at = NOW()';
        if ($hasResearchSubmittedBy) {
            $set[] = 'research_submitted_by = ?';
            $types .= 'i';
            $params[] = $updatedByUserId;
        }
    }

    $cdqaDecisionValue = array_key_exists('cdqa_decision', $options) ? trim((string)$options['cdqa_decision']) : '';
    if ($cdqaDecisionValue !== '') {
        if ($hasCdqaDecision) {
            $set[] = 'cdqa_decision = ?';
            $types .= 's';
            $params[] = $cdqaDecisionValue;
        }
        if ($hasCdqaDecisionAt) $set[] = 'cdqa_decision_at = NOW()';
        if ($hasCdqaDecisionBy) {
            $set[] = 'cdqa_decision_by = ?';
            $types .= 'i';
            $params[] = $updatedByUserId;
        }
    }

    if ($hasCdqaComment && array_key_exists('cdqa_comment', $options)) {
        $set[] = 'cdqa_comment = ?';
        $types .= 's';
        $params[] = trim((string)$options['cdqa_comment']);
    }

    if (!empty($options['increment_rework_count']) && $hasReworkCount) {
        $set[] = 'rework_count = COALESCE(rework_count, 0) + 1';
    }
    if ($hasLastActivityAt) {
        $set[] = 'last_activity_at = NOW()';
    }
    $set[] = 'updated_at = NOW()';

    $params[] = $contactId;
    $types .= 'i';
    $stmtUpdate = $conn->prepare('UPDATE dbms_contacts SET ' . implode(', ', $set) . ' WHERE id = ?');
    if (!$stmtUpdate) throw new RuntimeException('Database error.');
    mysqliBindParams($stmtUpdate, $types, $params);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    $currentWorkflow = dbmsWorkflowStatusFromRow($before);
    $remarks = trim((string)($options['remarks'] ?? ''));
    if ($remarks === '') {
        $remarks = dbmsWorkflowLabel($currentWorkflow) . ' -> ' . dbmsWorkflowLabel($nextWorkflowStatus);
    }
    dbmsInsertAuditLog(
        $contactId,
        'Workflow Update',
        $hasWorkflowStatus ? 'workflow_status' : 'review_status',
        $currentWorkflow,
        $nextWorkflowStatus,
        $remarks,
        $updatedByUserId,
        $moduleSource
    );

    return true;
}

function dbmsDeleteInvalidContact(int $contactId, int $deletedByUserId = 0, string $moduleSource = 'dbms.batch'): array {
    $contactId = (int)$contactId;
    if ($contactId <= 0) {
        throw new InvalidArgumentException('Invalid DBMS record.');
    }

    $conn = getDbConnection();
    $workflowExpr = dbmsWorkflowStatusSql('c');
    $stmt = $conn->prepare("
        SELECT c.id, c.batch_id, c.data_status, {$workflowExpr} AS workflow_queue
        FROM dbms_contacts c
        WHERE c.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Database error.');
    }
    $stmt->bind_param('i', $contactId);
    $stmt->execute();
    $contact = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$contact) {
        throw new RuntimeException('DBMS record not found.');
    }

    $workflowQueue = dbmsNormalizeWorkflowStatus((string)($contact['workflow_queue'] ?? ''));
    $dataStatus = strtolower(trim((string)($contact['data_status'] ?? '')));
    if ($workflowQueue !== 'rejected' && $dataStatus !== 'invalid') {
        throw new RuntimeException('Only invalid DBMS records can be deleted.');
    }

    $hasAuditLogs = dbmsTableHasColumn('dbms_audit_logs', 'entity_id');

    $conn->begin_transaction();
    try {
        $cleanup = [
            ["DELETE FROM dbms_assignments WHERE contact_id = ?", 'i', [$contactId]],
            ["DELETE FROM dbms_contact_comments WHERE contact_id = ?", 'i', [$contactId]],
            ["DELETE FROM dbms_intent_topic_history WHERE contact_id = ?", 'i', [$contactId]],
            ["DELETE FROM dbms_batch_rows WHERE contact_id = ?", 'i', [$contactId]],
        ];
        if ($hasAuditLogs) {
            $cleanup[] = ["DELETE FROM dbms_audit_logs WHERE entity_type = 'contact' AND entity_id = ?", 'i', [$contactId]];
        }

        foreach ($cleanup as [$sql, $types, $params]) {
            $stmtCleanup = $conn->prepare($sql);
            if (!$stmtCleanup) {
                throw new RuntimeException('Database error.');
            }
            mysqliBindParams($stmtCleanup, $types, $params);
            $stmtCleanup->execute();
            $stmtCleanup->close();
        }

        $stmtDelete = $conn->prepare("DELETE FROM dbms_contacts WHERE id = ? LIMIT 1");
        if (!$stmtDelete) {
            throw new RuntimeException('Database error.');
        }
        $stmtDelete->bind_param('i', $contactId);
        $stmtDelete->execute();
        $deletedRows = $stmtDelete->affected_rows;
        $stmtDelete->close();

        if ($deletedRows <= 0) {
            throw new RuntimeException('DBMS record could not be deleted.');
        }

        $conn->commit();
        return [
            'contact_id' => $contactId,
            'batch_id' => (int)($contact['batch_id'] ?? 0),
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function dbmsDeleteInvalidBatchRow(int $batchRowId, int $deletedByUserId = 0, string $moduleSource = 'dbms.batch'): array {
    $batchRowId = (int)$batchRowId;
    if ($batchRowId <= 0) {
        throw new InvalidArgumentException('Invalid batch row.');
    }

    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT id, batch_id, status, reason, contact_id
        FROM dbms_batch_rows
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Database error.');
    }
    $stmt->bind_param('i', $batchRowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Invalid batch row not found.');
    }

    $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
    $reason = strtolower(trim((string)($row['reason'] ?? '')));
    if ($rowStatus !== 'rejected' && !str_contains($reason, 'invalid')) {
        throw new RuntimeException('Only invalid DBMS rows can be deleted.');
    }

    $contactId = (int)($row['contact_id'] ?? 0);
    if ($contactId > 0) {
        $deleted = dbmsDeleteInvalidContact($contactId, $deletedByUserId, $moduleSource);
        $deleted['batch_row_id'] = $batchRowId;
        return $deleted;
    }

    $stmtDelete = $conn->prepare("DELETE FROM dbms_batch_rows WHERE id = ? LIMIT 1");
    if (!$stmtDelete) {
        throw new RuntimeException('Database error.');
    }
    $stmtDelete->bind_param('i', $batchRowId);
    $stmtDelete->execute();
    $deletedRows = $stmtDelete->affected_rows;
    $stmtDelete->close();

    if ($deletedRows <= 0) {
        throw new RuntimeException('Invalid batch row could not be deleted.');
    }

    return [
        'contact_id' => 0,
        'batch_id' => (int)($row['batch_id'] ?? 0),
        'batch_row_id' => $batchRowId,
    ];
}

function dbmsExplorerFiltersFromRequest(): array {
    return [
        'q' => trim((string)($_GET['q'] ?? '')),
        'name' => trim((string)($_GET['name'] ?? '')),
        'email' => trim((string)($_GET['email'] ?? '')),
        'job_title' => trim((string)($_GET['job_title'] ?? '')),
        'seniority' => trim((string)($_GET['seniority'] ?? '')),
        'dept' => trim((string)($_GET['dept'] ?? '')),
        'company_name' => trim((string)($_GET['company_name'] ?? '')),
        'employee_size_bracket' => dbmsNormalizeFilterText((string)($_GET['employee_size_bracket'] ?? '')),
        'website' => trim((string)($_GET['website'] ?? '')),
        'revenue_link' => trim((string)($_GET['revenue_link'] ?? '')),
        'prospect_location' => trim((string)($_GET['prospect_location'] ?? '')),
        'prospect_country' => trim((string)($_GET['prospect_country'] ?? '')),
        'company_address' => trim((string)($_GET['company_address'] ?? '')),
        'company_city' => trim((string)($_GET['company_city'] ?? '')),
        'company_state' => trim((string)($_GET['company_state'] ?? '')),
        'post_code' => trim((string)($_GET['post_code'] ?? '')),
        'company_country' => trim((string)($_GET['company_country'] ?? '')),
        'revenue_bracket' => dbmsNormalizeFilterText((string)($_GET['revenue_bracket'] ?? '')),
        'total_funding' => trim((string)($_GET['total_funding'] ?? '')),
        'industry_keywords' => trim((string)($_GET['industry_keywords'] ?? '')),
        'technologies' => trim((string)($_GET['technologies'] ?? '')),
        'sic' => trim((string)($_GET['sic'] ?? '')),
        'naics' => trim((string)($_GET['naics'] ?? '')),
        'retail_locations' => trim((string)($_GET['retail_locations'] ?? '')),
        'email_status' => trim((string)($_GET['email_status'] ?? '')),
        'last_email_status' => trim((string)($_GET['last_email_status'] ?? '')),
        'email_from' => trim((string)($_GET['email_from'] ?? '')),
        'email_to' => trim((string)($_GET['email_to'] ?? '')),
        'intent_topics' => trim((string)($_GET['intent_topics'] ?? '')),
        'phone_status' => trim((string)($_GET['phone_status'] ?? '')),
        'data_status' => trim((string)($_GET['data_status'] ?? '')),
        'stage' => trim((string)($_GET['stage'] ?? '')),
        'review_status' => trim((string)($_GET['review_status'] ?? '')),
        'country' => trim((string)($_GET['country'] ?? '')),
        'state' => trim((string)($_GET['state'] ?? '')),
        'industry' => trim((string)($_GET['industry'] ?? '')),
        'batch_id' => (int)($_GET['batch_id'] ?? 0),
        'assigned_department' => trim((string)($_GET['assigned_department'] ?? ($_GET['department'] ?? ''))),
        'assigned_to' => (int)($_GET['assigned_to'] ?? 0),
        'workflow' => trim((string)($_GET['workflow'] ?? ($_GET['workflow_status'] ?? ($_GET['workflow_queue'] ?? '')))),
        'unassigned_only' => (int)($_GET['unassigned_only'] ?? 0) === 1 || (string)($_GET['unassigned_only'] ?? '') === 'true',
    ];
}

function dbmsBuildExplorerWhere(array $filters, array &$params, string &$types): string {
    $w = [];
    $params = [];
    $types = '';

    if (($filters['workflow'] ?? '') !== '') {
        $queue = dbmsNormalizeWorkflowStatus((string)$filters['workflow']);
        $w[] = dbmsWorkflowFilterSqlQueue($queue, !empty($filters['unassigned_only']), 'c');
    }
    if (($filters['q'] ?? '') !== '') {
        $q = '%' . (string)$filters['q'] . '%';
        $sql = "(c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company_name LIKE ? OR c.job_title LIKE ?";
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $types .= 'ssssss';
        if (dbmsContactsHasColumn('revenue_link')) {
            $sql .= " OR c.revenue_link LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        $sql .= ")";
        $w[] = $sql;
    }
    if (($filters['name'] ?? '') !== '') {
        $q = '%' . (string)$filters['name'] . '%';
        $w[] = "(c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $types .= 'sss';
    }
    if (($filters['email'] ?? '') !== '') {
        $q = '%' . (string)$filters['email'] . '%';
        $w[] = "c.email LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['job_title'] ?? '') !== '') {
        $q = '%' . (string)$filters['job_title'] . '%';
        $w[] = "c.job_title LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['seniority'] ?? '') !== '') {
        $w[] = "TRIM(c.seniority) = ?";
        $params[] = (string)$filters['seniority'];
        $types .= 's';
    }
    if (($filters['dept'] ?? '') !== '') {
        $w[] = "TRIM(c.department) = ?";
        $params[] = (string)$filters['dept'];
        $types .= 's';
    }
    if (($filters['company_name'] ?? '') !== '') {
        $q = '%' . (string)$filters['company_name'] . '%';
        $w[] = "c.company_name LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['employee_size_bracket'] ?? '') !== '' && dbmsContactsHasColumn('employee_size_bracket')) {
        $w[] = "REPLACE(REPLACE(TRIM(c.employee_size_bracket), '–', '-'), '—', '-') = ?";
        $params[] = (string)$filters['employee_size_bracket'];
        $types .= 's';
    }
    if (($filters['website'] ?? '') !== '') {
        $q = '%' . (string)$filters['website'] . '%';
        $qNorm = '%' . strtolower((string)$filters['website']) . '%';
        $w[] = "(c.website LIKE ? OR c.website_norm LIKE ?)";
        $params[] = $q;
        $params[] = $qNorm;
        $types .= 'ss';
    }
    if (($filters['revenue_link'] ?? '') !== '' && dbmsContactsHasColumn('revenue_link')) {
        $q = '%' . (string)$filters['revenue_link'] . '%';
        $w[] = "c.revenue_link LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['prospect_location'] ?? '') !== '') {
        $q = '%' . (string)$filters['prospect_location'] . '%';
        $parts = [];
        if (dbmsContactsHasColumn('prospect_location')) {
            $parts[] = "c.prospect_location LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        $parts[] = "c.city LIKE ?";
        $params[] = $q;
        $types .= 's';
        $parts[] = "c.state LIKE ?";
        $params[] = $q;
        $types .= 's';
        if (dbmsContactsHasColumn('company_city')) {
            $parts[] = "c.company_city LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        if (dbmsContactsHasColumn('company_state')) {
            $parts[] = "c.company_state LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        if (!empty($parts)) {
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    if (($filters['prospect_country'] ?? '') !== '') {
        $v = (string)$filters['prospect_country'];
        $parts = [];
        if (dbmsContactsHasColumn('prospect_country')) {
            $parts[] = "TRIM(c.prospect_country) = ?";
            $params[] = $v;
            $types .= 's';
        }
        $parts[] = "TRIM(c.country) = ?";
        $params[] = $v;
        $types .= 's';
        if (dbmsContactsHasColumn('company_country')) {
            $parts[] = "TRIM(c.company_country) = ?";
            $params[] = $v;
            $types .= 's';
        }
        if (!empty($parts)) {
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    if (($filters['company_address'] ?? '') !== '') {
        $q = '%' . (string)$filters['company_address'] . '%';
        $parts = [];
        if (dbmsContactsHasColumn('company_address')) {
            $parts[] = "c.company_address LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        $parts[] = "c.address LIKE ?";
        $params[] = $q;
        $types .= 's';
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($filters['company_city'] ?? '') !== '') {
        $v = (string)$filters['company_city'];
        $parts = [];
        if (dbmsContactsHasColumn('company_city')) {
            $parts[] = "TRIM(c.company_city) = ?";
            $params[] = $v;
            $types .= 's';
        }
        $parts[] = "TRIM(c.city) = ?";
        $params[] = $v;
        $types .= 's';
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($filters['company_state'] ?? '') !== '') {
        $v = (string)$filters['company_state'];
        $parts = [];
        if (dbmsContactsHasColumn('company_state')) {
            $parts[] = "TRIM(c.company_state) = ?";
            $params[] = $v;
            $types .= 's';
        }
        $parts[] = "TRIM(c.state) = ?";
        $params[] = $v;
        $types .= 's';
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($filters['post_code'] ?? '') !== '') {
        $q = '%' . (string)$filters['post_code'] . '%';
        $parts = [];
        if (dbmsContactsHasColumn('post_code')) {
            $parts[] = "c.post_code LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        $parts[] = "c.postal_code LIKE ?";
        $params[] = $q;
        $types .= 's';
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($filters['company_country'] ?? '') !== '') {
        $v = (string)$filters['company_country'];
        $parts = [];
        if (dbmsContactsHasColumn('company_country')) {
            $parts[] = "TRIM(c.company_country) = ?";
            $params[] = $v;
            $types .= 's';
        }
        $parts[] = "TRIM(c.country) = ?";
        $params[] = $v;
        $types .= 's';
        if (dbmsContactsHasColumn('prospect_country')) {
            $parts[] = "TRIM(c.prospect_country) = ?";
            $params[] = $v;
            $types .= 's';
        }
        if (!empty($parts)) {
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    if (($filters['revenue_bracket'] ?? '') !== '' && dbmsContactsHasColumn('revenue_bracket')) {
        $w[] = "REPLACE(REPLACE(TRIM(c.revenue_bracket), '–', '-'), '—', '-') = ?";
        $params[] = (string)$filters['revenue_bracket'];
        $types .= 's';
    }
    if (($filters['total_funding'] ?? '') !== '') {
        $q = '%' . (string)$filters['total_funding'] . '%';
        $parts = [];
        if (dbmsContactsHasColumn('total_funding')) {
            $parts[] = "c.total_funding LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
        $parts[] = "c.funding LIKE ?";
        $params[] = $q;
        $types .= 's';
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($filters['industry_keywords'] ?? '') !== '') {
        $q = '%' . (string)$filters['industry_keywords'] . '%';
        $w[] = "c.industry_keywords LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['technologies'] ?? '') !== '') {
        $q = '%' . (string)$filters['technologies'] . '%';
        $w[] = "c.technologies LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['sic'] ?? '') !== '') {
        $q = '%' . (string)$filters['sic'] . '%';
        $w[] = "c.sic LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['naics'] ?? '') !== '') {
        $q = '%' . (string)$filters['naics'] . '%';
        $w[] = "c.naics LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['retail_locations'] ?? '') !== '') {
        $q = '%' . (string)$filters['retail_locations'] . '%';
        if (dbmsContactsHasColumn('retail_locations')) {
            $w[] = "c.retail_locations LIKE ?";
            $params[] = $q;
            $types .= 's';
        }
    }
    if (($filters['email_status'] ?? '') !== '') {
        $w[] = "TRIM(c.email_status) = ?";
        $params[] = (string)$filters['email_status'];
        $types .= 's';
    }
    if (($filters['last_email_status'] ?? '') !== '') {
        $w[] = "TRIM(c.last_email_status) = ?";
        $params[] = (string)$filters['last_email_status'];
        $types .= 's';
    }
    if (($filters['email_from'] ?? '') !== '') {
        $ts = strtotime((string)$filters['email_from']);
        if ($ts !== false) {
            $w[] = "c.email_status_at >= ?";
            $params[] = date('Y-m-d 00:00:00', $ts);
            $types .= 's';
        }
    }
    if (($filters['email_to'] ?? '') !== '') {
        $ts = strtotime((string)$filters['email_to']);
        if ($ts !== false) {
            $w[] = "c.email_status_at <= ?";
            $params[] = date('Y-m-d 23:59:59', $ts);
            $types .= 's';
        }
    }
    if (($filters['intent_topics'] ?? '') !== '') {
        $q = '%' . (string)$filters['intent_topics'] . '%';
        $w[] = "c.intent_topics LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['phone_status'] ?? '') !== '') {
        $w[] = "TRIM(c.phone_status) = ?";
        $params[] = (string)$filters['phone_status'];
        $types .= 's';
    }
    if (($filters['data_status'] ?? '') !== '') {
        $w[] = "TRIM(c.data_status) = ?";
        $params[] = (string)$filters['data_status'];
        $types .= 's';
    }
    if (($filters['stage'] ?? '') !== '' && in_array($filters['stage'], ['ready', 'valid', 'append', 'rejected', 'duplicate', 'assigned'], true)) {
        $st = (string)$filters['stage'];
        if ($st === 'ready') {
            $w[] = "(c.stage = 'ready' OR c.stage = 'valid')";
        } elseif ($st === 'assigned') {
            $w[] = "(c.stage = 'assigned' OR c.assigned_to_user_id IS NOT NULL)";
        } else {
            $w[] = "c.stage = ?";
            $params[] = $st;
            $types .= 's';
        }
    }
    if (($filters['review_status'] ?? '') !== '') {
        $q = '%' . (string)$filters['review_status'] . '%';
        $w[] = "c.review_status LIKE ?";
        $params[] = $q;
        $types .= 's';
    }
    if (($filters['country'] ?? '') !== '') {
        $v = (string)$filters['country'];
        $parts = [];
        $parts[] = "TRIM(c.country) = ?";
        $params[] = $v;
        $types .= 's';
        if (dbmsContactsHasColumn('prospect_country')) {
            $parts[] = "TRIM(c.prospect_country) = ?";
            $params[] = $v;
            $types .= 's';
        }
        if (dbmsContactsHasColumn('company_country')) {
            $parts[] = "TRIM(c.company_country) = ?";
            $params[] = $v;
            $types .= 's';
        }
        if (!empty($parts)) {
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    if (($filters['state'] ?? '') !== '') {
        $v = (string)$filters['state'];
        $parts = [];
        $parts[] = "TRIM(c.state) = ?";
        $params[] = $v;
        $types .= 's';
        if (dbmsContactsHasColumn('company_state')) {
            $parts[] = "TRIM(c.company_state) = ?";
            $params[] = $v;
            $types .= 's';
        }
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($filters['industry'] ?? '') !== '') {
        $w[] = "TRIM(c.industry) = ?";
        $params[] = (string)$filters['industry'];
        $types .= 's';
    }
    if ((int)($filters['batch_id'] ?? 0) > 0) {
        $w[] = "c.batch_id = ?";
        $params[] = (int)$filters['batch_id'];
        $types .= 'i';
    }
    if (($filters['assigned_department'] ?? '') !== '') {
        $w[] = "c.assigned_department = ?";
        $params[] = (string)$filters['assigned_department'];
        $types .= 's';
    }
    if ((int)($filters['assigned_to'] ?? 0) > 0) {
        $w[] = "c.assigned_to_user_id = ?";
        $params[] = (int)$filters['assigned_to'];
        $types .= 'i';
    }

    return !empty($w) ? ('WHERE ' . implode(' AND ', $w)) : '';
}

function dbmsExplorerHasActiveFilters(array $filters): bool {
    foreach ($filters as $k => $v) {
        if ($k === 'assigned_to' || $k === 'batch_id') {
            if ((int)$v > 0) {
                return true;
            }
            continue;
        }
        if (is_string($v) && trim($v) !== '') {
            return true;
        }
    }
    return false;
}

function funneltrackDefaultConfig(): array {
    return [
        'weights' => [
            'productivity' => 60,
            'adherence' => 10,
            'absenteeism' => 10,
            'quality' => 20,
        ],
        'thresholds' => [
            'min_expected_productivity_pct' => 90,
            'required_login_minutes' => 465,
            'min_attendance_pct' => 90,
            'max_disqualification_ratio_pct' => 10,
        ],
        'holiday_country_code' => 'US',
        'campaign_points' => [
            'Email Marketing' => 0.25,
            'MQL' => 1,
            'HQL / Callback' => 2,
            'BANT' => 3,
            'Appointment Generation' => 5,
        ],
        'daily_incentive' => [
            'base_threshold_pct' => 100,
            'base_amount' => 500,
            'slabs' => [
                ['from_pct' => 100, 'to_pct' => 150, 'per_point' => 100],
                ['from_pct' => 150, 'to_pct' => 200, 'per_point' => 200],
                ['from_pct' => 200, 'to_pct' => null, 'per_point' => 300],
            ],
        ],
        'monthly_incentive' => [
            'slabs' => [
                ['min_pct' => 100, 'amount' => 15000],
                ['min_pct' => 125, 'amount' => 20000],
                ['min_pct' => 150, 'amount' => 30000],
                ['min_pct' => 175, 'amount' => 40000],
                ['min_pct' => 200, 'amount' => 50000],
                ['min_pct' => 225, 'amount' => 60000],
                ['min_pct' => 250, 'amount' => 70000],
                ['min_pct' => 275, 'amount' => 80000],
                ['min_pct' => 300, 'amount' => 100000],
            ],
            'above_pct' => 300,
            'step_pct' => 25,
            'step_amount' => 5000,
        ],
        'rank_ranges' => [
            ['label' => 'Rank 5 – Top Performer', 'min' => 90, 'max' => 100],
            ['label' => 'Rank 4 – High Performer', 'min' => 80, 'max' => 89.999],
            ['label' => 'Rank 3 – Good Performer', 'min' => 70, 'max' => 79.999],
            ['label' => 'Rank 2 – Needs Improvement', 'min' => 60, 'max' => 69.999],
            ['label' => 'Rank 1 – Low Performer', 'min' => 0, 'max' => 59.999],
        ],
        'escalation_rule' => [
            'mode' => 'disqualify',
            'deduction_pct' => 100,
            'fixed_deduction_amount' => 0,
        ],
    ];
}

function funneltrackGetConfig(): array {
    $raw = getAppSetting('funneltrack360.config', '');
    $cfg = funneltrackDefaultConfig();
    if (!is_string($raw) || trim($raw) === '') return $cfg;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $cfg;
    
    // Keys that should be completely replaced, not merged
    $replaceKeys = ['slabs', 'rank_ranges'];
    
    $merge = function(array $base, array $in, string $parentKey = '') use (&$merge, $replaceKeys): array {
        foreach ($in as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                // Check if this key should be replaced completely
                if (in_array($k, $replaceKeys, true)) {
                    $base[$k] = $v;
                } else {
                    $base[$k] = $merge($base[$k], $v, $k);
                }
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    };
    return $merge($cfg, $decoded);
}

function funneltrackSaveConfig(array $cfg): bool {
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') return false;
    setAppSetting('funneltrack360.config', $json);
    return true;
}

function funneltrackNormalizeCampaignType(?string $t): string {
    $t = strtolower(trim((string)$t));
    if ($t === '') return '';
    if ($t === 'email' || $t === 'eml') return 'Email Marketing';
    if ($t === 'email marketing') return 'Email Marketing';
    if ($t === 'marketing-qualified leads' || $t === 'marketing qualified leads' || $t === 'marketing qualified lead' || $t === 'mql') return 'MQL';
    if ($t === 'mql' || $t === 'marketing qualified leads' || $t === 'marketing qualified lead') return 'MQL';
    if ($t === 'hql' || $t === 'callback' || $t === 'call back' || $t === 'call-back' || $t === 'hql / callback' || $t === 'hql/callback') return 'HQL / Callback';
    if ($t === 'bant') return 'BANT';
    if ($t === 'appointment generation' || $t === 'appointment gen' || $t === 'ag') return 'Appointment Generation';
    return '';
}

function funneltrackCampaignPointsMap(array $cfg): array {
    $m = [];
    $src = $cfg['campaign_points'] ?? [];
    if (!is_array($src)) return $m;
    foreach ($src as $k => $v) {
        $rawKey = trim((string)$k);
        if ($rawKey === '') continue;
        $normalizedKey = funneltrackNormalizeCampaignType($rawKey);
        $key = $normalizedKey !== '' ? $normalizedKey : $rawKey;
        $m[$key] = (float)$v;
    }
    return $m;
}

function funneltrackFinalQaStatuses(): array {
    return ['Qualified', 'Disqualified', 'Duplicate', 'Duplicate/Supp', 'Approved', 'Rejected'];
}

function funneltrackComputeDailyIncentive(float $targetPoints, float $achievedPoints, array $cfg): int {
    if ($targetPoints <= 0) return 0;
    $daily = $cfg['daily_incentive'] ?? [];
    $baseThresholdPct = (float)($daily['base_threshold_pct'] ?? 100);
    $baseAmount = (int)($daily['base_amount'] ?? 0);
    $slabs = is_array($daily['slabs'] ?? null) ? $daily['slabs'] : [];

    $thresholdPoints = ($baseThresholdPct / 100.0) * $targetPoints;
    if ($achievedPoints + 1e-9 < $thresholdPoints) return 0;

    $extraPointsInt = (int)ceil(max(0.0, $achievedPoints - $targetPoints));
    $amount = max(0, $baseAmount);
    if ($extraPointsInt <= 0) return $amount;

    $remaining = $extraPointsInt;
    foreach ($slabs as $slab) {
        $fromPct = isset($slab['from_pct']) ? (float)$slab['from_pct'] : 0.0;
        $toPct = array_key_exists('to_pct', $slab) && $slab['to_pct'] !== null ? (float)$slab['to_pct'] : null;
        $perPoint = (int)($slab['per_point'] ?? 0);
        if ($perPoint <= 0) continue;
        if ($toPct !== null && $toPct <= $fromPct) continue;
        if ($fromPct < 100.0) $fromPct = 100.0;

        $cap = null;
        if ($toPct !== null) {
            $spanPct = max(0.0, $toPct - $fromPct);
            $cap = (int)ceil(($targetPoints * $spanPct) / 100.0);
        }

        if ($cap === null) {
            $amount += $remaining * $perPoint;
            $remaining = 0;
            break;
        }
        if ($cap <= 0) continue;
        $take = min($remaining, $cap);
        if ($take > 0) $amount += $take * $perPoint;
        $remaining -= $take;
        if ($remaining <= 0) break;
    }
    return (int)$amount;
}

function funneltrackComputeMonthlyIncentive(float $productivityPct, array $cfg): int {
    $m = $cfg['monthly_incentive'] ?? [];
    $slabs = is_array($m['slabs'] ?? null) ? $m['slabs'] : [];
    usort($slabs, fn($a, $b) => ((float)($a['min_pct'] ?? 0)) <=> ((float)($b['min_pct'] ?? 0)));
    $best = 0;
    foreach ($slabs as $s) {
        $minPct = (float)($s['min_pct'] ?? 0);
        $amt = (int)($s['amount'] ?? 0);
        if ($productivityPct + 1e-9 >= $minPct) $best = max($best, $amt);
    }
    $abovePct = (float)($m['above_pct'] ?? 300);
    $stepPct = (float)($m['step_pct'] ?? 25);
    $stepAmt = (int)($m['step_amount'] ?? 0);
    if ($stepPct > 0 && $stepAmt > 0 && $productivityPct > $abovePct) {
        $extraSteps = (int)floor(($productivityPct - $abovePct) / $stepPct);
        if ($extraSteps > 0) $best += $extraSteps * $stepAmt;
    }
    return (int)$best;
}

function funneltrackHasActiveEscalation(int $userId, string $startDate, string $endDate): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return false;
    ensureFunnelTrack360Schema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT 1
        FROM funneltrack_escalations
        WHERE user_id = ?
          AND active = 1
          AND start_date <= ?
          AND (end_date IS NULL OR end_date = '' OR end_date >= ?)
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('iss', $userId, $endDate, $startDate);
    $stmt->execute();
    $ok = (bool)($stmt->get_result()->fetch_row());
    $stmt->close();
    return $ok;
}

function funneltrackGetOverrideMap(int $userId, int $year, int $month): array {
    $userId = (int)$userId;
    $year = (int)$year;
    $month = (int)$month;
    if ($userId <= 0 || $year < 1970 || $year > 2100 || $month < 1 || $month > 12) return [];
    ensureFunnelTrack360Schema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT override_key, override_value FROM funneltrack_overrides WHERE user_id = ? AND year = ? AND month = ?");
    if (!$stmt) return [];
    $stmt->bind_param('iii', $userId, $year, $month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $m = [];
    foreach ($rows as $r) {
        $k = (string)($r['override_key'] ?? '');
        $v = (string)($r['override_value'] ?? '');
        if ($k !== '') $m[$k] = $v;
    }
    return $m;
}

function funneltrackComputeAgentPeriodPoints(int $agentId, string $startTs, string $endTs, array $cfg): array {
    $agentId = (int)$agentId;
    if ($agentId <= 0) return ['points' => 0.0, 'breakdown' => [], 'qualified' => 0];
    $pointsMap = funneltrackCampaignPointsMap($cfg);
    if (empty($pointsMap)) return ['points' => 0.0, 'breakdown' => [], 'qualified' => 0];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $startDate = opsDateFromUtc($startTs);
    $endDate = opsDateFromUtc($endTs);
    $paidLeaveSet = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $paidLeaveSet = funneltrackPaidLeaveDateSet($agentId, $startDate, $endDate);
    }
    $stmt = $conn->prepare("
        SELECT COALESCE(l.qa_updated_at, l.created_at) AS qualified_at, d.campaign_type
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.agent_id = ?
          AND l.qa_status = 'Qualified'
          AND COALESCE(l.qa_updated_at, l.created_at) >= ?
          AND COALESCE(l.qa_updated_at, l.created_at) <= ?
    ");
    $rows = [];
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $startTs, $endTs);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
    $total = 0.0;
    $breakdown = [];
    $qualified = 0;
    foreach ($rows as $r) {
        $day = opsDateFromUtc((string)($r['qualified_at'] ?? ''));
        if ($day !== '' && isset($paidLeaveSet[$day])) continue;
        $ct = funneltrackNormalizeCampaignType((string)($r['campaign_type'] ?? ''));
        if ($ct === '' || !array_key_exists($ct, $pointsMap)) continue;
        $p = (float)$pointsMap[$ct];
        $total += $p;
        if (!isset($breakdown[$ct])) $breakdown[$ct] = ['count' => 0, 'points' => 0.0];
        $breakdown[$ct]['count']++;
        $breakdown[$ct]['points'] += $p;
        $qualified++;
    }
    return ['points' => (float)$total, 'breakdown' => $breakdown, 'qualified' => $qualified];
}

function funneltrackComputeAgentQuality(int $agentId, string $startTs, string $endTs): array {
    $agentId = (int)$agentId;
    if ($agentId <= 0) return ['qualified' => 0, 'disqualified' => 0, 'ratio_pct' => 0.0];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT
          SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) AS q,
          SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS d
        FROM leads
        WHERE agent_id = ?
          AND qa_status IN ('Qualified','Disqualified')
          AND COALESCE(qa_updated_at, created_at) >= ?
          AND COALESCE(qa_updated_at, created_at) <= ?
    ");
    $q = 0;
    $d = 0;
    if ($stmt) {
        $stmt->bind_param('iss', $agentId, $startTs, $endTs);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $q = (int)($row['q'] ?? 0);
        $d = (int)($row['d'] ?? 0);
    }
    $total = $q + $d;
    $ratio = $total > 0 ? round(($d / $total) * 100.0, 2) : 0.0;
    return ['qualified' => $q, 'disqualified' => $d, 'ratio_pct' => $ratio];
}

function funneltrackComputeAdherence(int $userId, int $year, int $month, int $requiredMinutes): array {
    $userId = (int)$userId;
    $year = (int)$year;
    $month = (int)$month;
    $requiredMinutes = max(1, (int)$requiredMinutes);
    if ($userId <= 0 || $year < 1970 || $year > 2100 || $month < 1 || $month > 12) return ['avg_minutes' => 0, 'adherence_pct' => 0.0];
    ensureDatabaseSchema();
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT AVG(working_minutes) AS avgm
        FROM hr_attendance_days
        WHERE user_id = ?
          AND work_date >= ?
          AND work_date <= ?
          AND punch_in IS NOT NULL AND punch_out IS NOT NULL
          AND status NOT IN ('Paid Leave','paid_leave')
    ");
    $avg = 0.0;
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $start, $end);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $avg = (float)($row['avgm'] ?? 0.0);
    }
    $pct = min(100.0, round(($avg / (float)$requiredMinutes) * 100.0, 2));
    if ($pct < 0) $pct = 0.0;
    return ['avg_minutes' => (int)round($avg), 'adherence_pct' => $pct];
}

function funneltrackRankLabel(float $score, array $cfg): string {
    $ranges = $cfg['rank_ranges'] ?? [];
    if (!is_array($ranges)) return '';
    foreach ($ranges as $r) {
        $min = isset($r['min']) ? (float)$r['min'] : null;
        $max = isset($r['max']) ? (float)$r['max'] : null;
        $label = (string)($r['label'] ?? '');
        if ($min === null || $max === null || $label === '') continue;
        if ($score + 1e-9 >= $min && $score <= $max + 1e-9) return $label;
    }
    return '';
}

function funneltrackComputeAgentMonthSummaries(array $userIds, int $year, int $month, ?array $cfg = null): array {
    $cfg = $cfg ?? funneltrackGetConfig();
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($v): bool => $v > 0)));
    $year = (int)$year;
    $month = (int)$month;
    if (empty($userIds) || $year < 1970 || $year > 2100 || $month < 1 || $month > 12) return [];

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    $monthWorkingDates = funneltrackWorkingDatesInRange($startDate, $endDate, $cfg);
    $workingDays = count($monthWorkingDates);
    $requiredLoginMinutes = (int)($cfg['thresholds']['required_login_minutes'] ?? 465);
    $rangeSummaries = funneltrackComputeAgentRangeSummaries($userIds, $startDate, $endDate, $cfg);

    $targetMap = [];
    foreach (getMonthlyTargets($year, $month) as $targetRow) {
        $uid = (int)($targetRow['agent_id'] ?? 0);
        if ($uid > 0) $targetMap[$uid] = $targetRow;
    }

    ensureDatabaseSchema();
    ensureFunnelTrack360Schema();
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    $attendanceAgg = [];
    $stmt = $conn->prepare("
        SELECT
            user_id,
            SUM(CASE WHEN status IN ('Full Day','Present','present') THEN 1 ELSE 0 END) AS present_days,
            SUM(CASE WHEN status IN ('Half Day','half_day') THEN 1 ELSE 0 END) AS half_days,
            SUM(CASE WHEN status IN ('Unpaid Leave','unpaid_leave') THEN 1 ELSE 0 END) AS unpaid_leave_days,
            SUM(CASE WHEN status NOT IN ('Paid Leave','paid_leave') THEN COALESCE(working_minutes, 0) ELSE 0 END) AS worked_total_minutes
        FROM hr_attendance_days
        WHERE user_id IN ($in)
          AND work_date >= ?
          AND work_date <= ?
        GROUP BY user_id
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startDate, $endDate]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) $attendanceAgg[$uid] = $row;
        }
    }

    $overrideMaps = [];
    $stmt = $conn->prepare("
        SELECT user_id, override_key, override_value
        FROM funneltrack_overrides
        WHERE user_id IN ($in) AND year = ? AND month = ?
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$year, $month]);
        mysqliBindParams($stmt, $types . 'ii', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $key = (string)($row['override_key'] ?? '');
            if ($uid <= 0 || $key === '') continue;
            if (!isset($overrideMaps[$uid])) $overrideMaps[$uid] = [];
            $overrideMaps[$uid][$key] = (string)($row['override_value'] ?? '');
        }
    }

    $t = $cfg['thresholds'] ?? [];
    $maxDisq = (float)($t['max_disqualification_ratio_pct'] ?? 10);
    $escMode = (string)($cfg['escalation_rule']['mode'] ?? 'disqualify');

    $out = [];
    foreach ($userIds as $uid) {
        $summary = $rangeSummaries[$uid] ?? [];
        $dailyTarget = (int)($targetMap[$uid]['daily_target'] ?? 0);
        $eligibleWorkingDays = (int)($summary['targets']['eligible_working_days'] ?? 0);
        $monthlyTarget = (float)($summary['targets']['total'] ?? 0.0);
        $achievedPoints = (float)($summary['points']['achieved'] ?? 0.0);
        $productivityPct = (float)($summary['productivity_pct'] ?? 0.0);
        $quality = $summary['quality'] ?? ['qualified' => 0, 'disqualified' => 0, 'ratio_pct' => 0.0];
        $disqRatioPct = (float)($quality['ratio_pct'] ?? 0.0);
        $adherence = $summary['adherence'] ?? ['adherence_pct' => 0.0, 'avg_minutes' => 0, 'required_minutes' => $requiredLoginMinutes];
        $attendancePct = (float)($summary['attendance']['attendance_pct'] ?? 0.0);
        $scores = $summary['score'] ?? ['productivity' => 0.0, 'adherence' => 0.0, 'absenteeism' => 0.0, 'quality' => 0.0, 'total' => 0.0];
        $rankLabel = (string)($summary['rank'] ?? '');
        $hasEsc = (bool)($summary['incentives']['escalation_active'] ?? false);
        $eligible = (bool)($summary['incentives']['eligible'] ?? false);

        $att = $attendanceAgg[$uid] ?? [];
        $present = (int)($att['present_days'] ?? 0);
        $half = (int)($att['half_days'] ?? 0);
        $absentDays = (int)($att['absent_days'] ?? 0);
        $workedTotalMinutes = (int)round((float)($att['worked_total_minutes'] ?? 0.0));
        $paidLeaveDays = max(0, $workingDays - $eligibleWorkingDays);
        $requiredTotalMinutes = max(0, $requiredLoginMinutes * $eligibleWorkingDays);

        $monthlyIncentive = $eligible ? funneltrackComputeMonthlyIncentive($productivityPct, $cfg) : 0;
        if ($hasEsc && $monthlyIncentive > 0) {
            if ($escMode === 'partial') {
                $dedPct = (float)($cfg['escalation_rule']['deduction_pct'] ?? 100);
                if ($dedPct < 0) $dedPct = 0;
                if ($dedPct > 100) $dedPct = 100;
                $monthlyIncentive = (int)round($monthlyIncentive * (1.0 - ($dedPct / 100.0)));
            } elseif ($escMode === 'fixed') {
                $fixed = (int)($cfg['escalation_rule']['fixed_deduction_amount'] ?? 0);
                if ($fixed < 0) $fixed = 0;
                $monthlyIncentive = max(0, $monthlyIncentive - $fixed);
            }
        }

        $deductions = [];
        $monthlyIncentiveBeforeDeductions = (int)$monthlyIncentive;
        if ($monthlyIncentive > 0) {
            if ($disqRatioPct > $maxDisq + 1e-9) {
                $monthlyIncentive = (int)round($monthlyIncentive * 0.90);
                $deductions[] = ['key' => 'quality_disq_ratio', 'label' => 'Disqualification ratio above threshold', 'pct' => 10];
            }
            if ($requiredTotalMinutes > 0 && $workedTotalMinutes + 1e-9 < $requiredTotalMinutes) {
                $monthlyIncentive = (int)round($monthlyIncentive * 0.80);
                $deductions[] = ['key' => 'adherence_cumulative', 'label' => 'Login adherence below required cumulative minutes', 'pct' => 20];
            }
            if ($absentDays > 0) {
                $monthlyIncentive = (int)round($monthlyIncentive * 0.70);
                $deductions[] = ['key' => 'absent_days', 'label' => 'Absent days in incentive period', 'pct' => 30];
            }
        }

        $overrides = $overrideMaps[$uid] ?? [];
        if (isset($overrides['score.total'])) {
            $scores['total'] = (float)$overrides['score.total'];
            $rankLabel = funneltrackRankLabel((float)$scores['total'], $cfg);
        }
        if (isset($overrides['incentive.monthly'])) {
            $monthlyIncentive = max(0, (int)$overrides['incentive.monthly']);
            $monthlyIncentiveBeforeDeductions = $monthlyIncentive;
            $deductions = [];
        }

        $out[$uid] = [
            'period' => ['year' => $year, 'month' => $month, 'start' => $startDate, 'end' => $endDate],
            'targets' => ['daily' => $dailyTarget, 'monthly' => $monthlyTarget, 'eligible_working_days' => $eligibleWorkingDays, 'working_days' => $workingDays],
            'points' => ['achieved' => $achievedPoints, 'breakdown' => $summary['points']['breakdown'] ?? []],
            'productivity_pct' => $productivityPct,
            'quality' => $quality,
            'attendance' => ['attendance_pct' => $attendancePct, 'present_days' => $present, 'half_days' => $half, 'absent_days' => $absentDays, 'paid_leave_days' => $paidLeaveDays, 'working_days' => $workingDays],
            'adherence' => ['adherence_pct' => (float)($adherence['adherence_pct'] ?? 0.0), 'avg_minutes' => (int)($adherence['avg_minutes'] ?? 0), 'required_minutes' => $requiredLoginMinutes, 'required_total_minutes' => $requiredTotalMinutes, 'worked_total_minutes' => $workedTotalMinutes],
            'score' => ['productivity' => round((float)($scores['productivity'] ?? 0.0), 2), 'adherence' => round((float)($scores['adherence'] ?? 0.0), 2), 'absenteeism' => round((float)($scores['absenteeism'] ?? 0.0), 2), 'quality' => round((float)($scores['quality'] ?? 0.0), 2), 'total' => (float)($scores['total'] ?? 0.0)],
            'rank' => $rankLabel,
            'incentives' => ['eligible' => $eligible, 'monthly' => $monthlyIncentive, 'monthly_before_deductions' => $monthlyIncentiveBeforeDeductions, 'escalation_active' => $hasEsc, 'deductions' => $deductions],
            'overrides' => $overrides,
        ];
    }

    return $out;
}

function funneltrackComputeAgentMonthSummary(int $agentId, int $year, int $month, ?array $cfg = null): array {
    $agentId = (int)$agentId;
    if ($agentId <= 0) return [];
    $rows = funneltrackComputeAgentMonthSummaries([$agentId], (int)$year, (int)$month, $cfg);
    return $rows[$agentId] ?? [];
}

function funneltrackGetIncentiveMetrics(?array $cfg = null): array {
    $cfg = $cfg ?? funneltrackGetConfig();
    $tz = opsBusinessTz();
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $currentYear = (int)$now->format('Y');
    $currentMonth = (int)$now->format('n');
    $currentMonthEnd = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear));
    $isCurrentMonthComplete = ($today >= $currentMonthEnd);
    
    // Previous month
    $prevDate = $now->modify('-1 month');
    $prevYear = (int)$prevDate->format('Y');
    $prevMonth = (int)$prevDate->format('n');
    
    ensureDatabaseSchema();
    ensureFunnelTrack360Schema();
    $conn = getDbConnection();
    
    // Get all active agent IDs
    $agentRoles = ['operations_agent','agent','email_marketing_agent','email_marketing_executive','form_filler','qa_agent','qa'];
    $inRoles = implode(',', array_fill(0, count($agentRoles), '?'));
    $typesRoles = str_repeat('s', count($agentRoles));
    $agentIds = [];
    $stmt = $conn->prepare("SELECT id FROM users WHERE is_active = 1 AND role IN ($inRoles)");
    if ($stmt) {
        mysqliBindParams($stmt, $typesRoles, $agentRoles);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) $agentIds[] = (int)($row['id'] ?? 0);
        $stmt->close();
    }
    
    if (empty($agentIds)) {
        return [
            'today_daily_incentive' => 0,
            'total_daily_incentive_paid' => 0,
            'monthly_incentive' => 0,
            'monthly_productivity_pct_avg' => 0.0,
            'monthly_eligible_users' => 0,
            'monthly_visible_users' => 0,
            'bonus' => 0,
            'this_month_total' => 0,
            'previous_month_total' => 0,
            'is_current_month_complete' => $isCurrentMonthComplete,
        ];
    }
    
    // Today's daily incentive
    $todaySummaries = funneltrackComputeAgentRangeSummaries($agentIds, $today, $today, $cfg);
    $todayDailyIncentive = 0;
    foreach ($todaySummaries as $sum) {
        $todayDailyIncentive += (int)($sum['incentives']['range_total'] ?? 0);
    }
    
    // Current month's daily incentives (from month start to today)
    $currentMonthSummaries = funneltrackComputeAgentRangeSummaries($agentIds, sprintf('%04d-%02d-01', $currentYear, $currentMonth), $today, $cfg);
    $currentMonthDailyIncentive = 0;
    $currentMonthMonthlyIncentive = 0;
    $currentMonthProductivityTotal = 0.0;
    $currentMonthVisibleUsers = 0;
    $currentMonthEligibleUsers = 0;
    
    foreach ($currentMonthSummaries as $sum) {
        $currentMonthDailyIncentive += (int)($sum['incentives']['range_total'] ?? 0);
    }
    
    // If month is complete, calculate full monthly incentives using funneltrackComputeAgentMonthSummaries
    if ($isCurrentMonthComplete) {
        $fullMonthSummaries = funneltrackComputeAgentMonthSummaries($agentIds, $currentYear, $currentMonth, $cfg);
        foreach ($fullMonthSummaries as $sum) {
            $currentMonthProductivityTotal += (float)($sum['productivity_pct'] ?? 0.0);
            $currentMonthVisibleUsers++;
            if (!empty($sum['incentives']['eligible'])) {
                $currentMonthEligibleUsers++;
                $currentMonthMonthlyIncentive += (int)($sum['incentives']['monthly'] ?? 0);
            }
        }
    }
    
    $currentMonthProductivityAvg = $currentMonthVisibleUsers > 0 ? round($currentMonthProductivityTotal / $currentMonthVisibleUsers, 1) : 0.0;
    
    // Total daily incentive paid for current month only (up to today)
    $totalDailyIncentivePaid = $currentMonthDailyIncentive;
    
    // Manual bonus from overrides for current month
    $bonus = 0;
    $in = implode(',', array_fill(0, count($agentIds), '?'));
    $types = str_repeat('i', count($agentIds));
    $stmt = $conn->prepare("
        SELECT override_value
        FROM funneltrack_overrides
        WHERE user_id IN ($in) AND year = ? AND month = ? AND override_key = 'incentive.monthly'
    ");
    if ($stmt) {
        $params = array_merge($agentIds, [$currentYear, $currentMonth]);
        mysqliBindParams($stmt, $types . 'ii', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $bonus += max(0, (int)($row['override_value'] ?? 0));
        }
    }
    
    // Previous month's incentives
    $prevMonthStart = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
    $prevMonthEnd = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, cal_days_in_month(CAL_GREGORIAN, $prevMonth, $prevYear));
    $prevMonthSummaries = funneltrackComputeAgentRangeSummaries($agentIds, $prevMonthStart, $prevMonthEnd, $cfg);
    $prevMonthDailyIncentive = 0;
    $prevMonthMonthlyIncentive = 0;
    foreach ($prevMonthSummaries as $sum) {
        $prevMonthDailyIncentive += (int)($sum['incentives']['range_total'] ?? 0);
        $prevMonthMonthlyIncentive += (int)($sum['incentives']['monthly_bonus'] ?? 0);
    }
    
    return [
        'today_daily_incentive' => $todayDailyIncentive,
        'total_daily_incentive_paid' => $totalDailyIncentivePaid,
        'monthly_incentive' => $currentMonthMonthlyIncentive,
        'monthly_productivity_pct_avg' => $currentMonthProductivityAvg,
        'monthly_eligible_users' => $currentMonthEligibleUsers,
        'monthly_visible_users' => $currentMonthVisibleUsers,
        'bonus' => $bonus,
        'this_month_total' => $currentMonthDailyIncentive + $currentMonthMonthlyIncentive + $bonus,
        'previous_month_total' => $prevMonthDailyIncentive + $prevMonthMonthlyIncentive,
        'is_current_month_complete' => $isCurrentMonthComplete,
    ];
}

function funneltrackRangeFromPreset(string $preset, string $startInput = '', string $endInput = ''): array {
    $preset = strtolower(trim($preset));
    $tz = opsBusinessTz();
    $now = new DateTimeImmutable('now', $tz);
    $start = $now->format('Y-m-d');
    $end = $start;
    $label = 'Today';

    if ($preset === 'week') {
        $mon = $now->modify('monday this week');
        $sun = $mon->modify('+6 days');
        $start = $mon->format('Y-m-d');
        $end = $sun->format('Y-m-d');
        $label = 'This Week';
    } elseif ($preset === 'month') {
        $y = (int)$now->format('Y');
        $m = (int)$now->format('n');
        $start = sprintf('%04d-%02d-01', $y, $m);
        $end = sprintf('%04d-%02d-%02d', $y, $m, cal_days_in_month(CAL_GREGORIAN, $m, $y));
        $label = 'This Month';
    } elseif ($preset === 'quarter') {
        $y = (int)$now->format('Y');
        $m = (int)$now->format('n');
        $q = (int)floor(($m - 1) / 3) + 1;
        $qStartMonth = 1 + (($q - 1) * 3);
        $qEndMonth = $qStartMonth + 2;
        $start = sprintf('%04d-%02d-01', $y, $qStartMonth);
        $end = sprintf('%04d-%02d-%02d', $y, $qEndMonth, cal_days_in_month(CAL_GREGORIAN, $qEndMonth, $y));
        $label = 'This Quarter';
    } elseif ($preset === 'year') {
        $y = (int)$now->format('Y');
        $start = sprintf('%04d-01-01', $y);
        $end = sprintf('%04d-12-31', $y);
        $label = 'This Year';
    } elseif ($preset === 'custom') {
        $s = trim($startInput);
        $e = trim($endInput);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
            if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }
            $start = $s;
            $end = $e;
            $label = $start . ' → ' . $end;
        } else {
            $label = 'Custom Range';
        }
    } else {
        $preset = 'today';
    }

    return ['preset' => $preset, 'start' => $start, 'end' => $end, 'label' => $label];
}

function funneltrackHolidaySetForRange(string $startDate, string $endDate, array $cfg): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    $country = (string)($cfg['holiday_country_code'] ?? 'US');
    if ($country === '') $country = 'US';
    if ($endDate < $startDate) { $tmp = $startDate; $startDate = $endDate; $endDate = $tmp; }
    $y1 = (int)substr($startDate, 0, 4);
    $m1 = (int)substr($startDate, 5, 2);
    $y2 = (int)substr($endDate, 0, 4);
    $m2 = (int)substr($endDate, 5, 2);
    $set = [];
    $y = $y1;
    $m = $m1;
    while ($y < $y2 || ($y === $y2 && $m <= $m2)) {
        foreach (getHolidaysForMonth($y, $m, $country) as $h) {
            $d = (string)($h['holiday_date'] ?? '');
            if ($d !== '') $set[$d] = true;
        }
        $m++;
        if ($m > 12) { $m = 1; $y++; }
    }
    return $set;
}

function funneltrackWorkingDatesInRange(string $startDate, string $endDate, array $cfg): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    if ($endDate < $startDate) { $tmp = $startDate; $startDate = $endDate; $endDate = $tmp; }
    $tz = hrBaseTz();
    $hol = funneltrackHolidaySetForRange($startDate, $endDate, $cfg);
    $out = [];
    try {
        $cur = new DateTimeImmutable($startDate . ' 00:00:00', $tz);
        $end = new DateTimeImmutable($endDate . ' 00:00:00', $tz);
        while ($cur <= $end) {
            $d = $cur->format('Y-m-d');
            $weekday = (int)$cur->format('N');
            if ($weekday <= 5 && !isset($hol[$d])) $out[] = $d;
            $cur = $cur->modify('+1 day');
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function funneltrackPaidLeaveDateSet(int $userId, string $startDate, string $endDate): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    if ($endDate < $startDate) { $tmp = $startDate; $startDate = $endDate; $endDate = $tmp; }
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT work_date FROM hr_attendance_days WHERE user_id = ? AND work_date >= ? AND work_date <= ? AND status IN ('Paid Leave','paid_leave')");
    if (!$stmt) return [];
    $stmt->bind_param('iss', $userId, $startDate, $endDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $set = [];
    foreach ($rows as $r) {
        $d = (string)($r['work_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $set[$d] = true;
    }
    return $set;
}

function funneltrackDailyTargetForMonth(int $userId, int $year, int $month): int {
    $t = getAgentMonthlyTarget($userId, $year, $month);
    return (int)($t['daily_target'] ?? 0);
}

function funneltrackComputeAdherenceRange(int $userId, string $startDate, string $endDate, int $requiredMinutes): array {
    $userId = (int)$userId;
    $requiredMinutes = max(1, (int)$requiredMinutes);
    if ($userId <= 0) return ['avg_minutes' => 0, 'adherence_pct' => 0.0];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return ['avg_minutes' => 0, 'adherence_pct' => 0.0];
    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT AVG(working_minutes) AS avgm
        FROM hr_attendance_days
        WHERE user_id = ?
          AND work_date >= ?
          AND work_date <= ?
          AND punch_in IS NOT NULL AND punch_out IS NOT NULL
          AND status NOT IN ('Paid Leave','paid_leave')
    ");
    $avg = 0.0;
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $startDate, $endDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $avg = (float)($row['avgm'] ?? 0.0);
    }
    $pct = min(100.0, round(($avg / (float)$requiredMinutes) * 100.0, 2));
    if ($pct < 0) $pct = 0.0;
    return ['avg_minutes' => (int)round($avg), 'adherence_pct' => $pct];
}

function funneltrackComputeAgentRangeSummaries(array $userIds, string $startDate, string $endDate, ?array $cfg = null): array {
    $cfg = $cfg ?? funneltrackGetConfig();
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($v): bool => $v > 0)));
    if (empty($userIds)) return [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    if ($endDate < $startDate) { $tmp = $startDate; $startDate = $endDate; $endDate = $tmp; }

    $rangeUtc = opsDateRangeUtc($startDate, $endDate);
    if (empty($rangeUtc)) return [];
    $startTs = (string)($rangeUtc['start'] ?? '');
    $endTs = (string)($rangeUtc['end'] ?? '');
    if ($startTs === '' || $endTs === '') return [];

    ensureDatabaseSchema();
    ensureFunnelTrack360Schema();
    $conn = getDbConnection();

    $workingDates = funneltrackWorkingDatesInRange($startDate, $endDate, $cfg);
    $workingMonthKeys = [];
    foreach ($workingDates as $d) {
        $k = substr($d, 0, 7);
        if ($k === false || $k === '') continue;
        $workingMonthKeys[$k] = ['year' => (int)substr($d, 0, 4), 'month' => (int)substr($d, 5, 2)];
    }

    $requestedUsers = array_fill_keys($userIds, true);
    $dailyTargetsByUser = [];
    foreach ($workingMonthKeys as $k => $meta) {
        $monthTargets = getMonthlyTargets((int)$meta['year'], (int)$meta['month']);
        foreach ($monthTargets as $mt) {
            $uid = (int)($mt['agent_id'] ?? 0);
            if ($uid <= 0 || !isset($requestedUsers[$uid])) continue;
            if (!isset($dailyTargetsByUser[$uid])) $dailyTargetsByUser[$uid] = [];
            $dailyTargetsByUser[$uid][$k] = (int)($mt['daily_target'] ?? 0);
        }
    }

    $in = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    $paidLeaveSets = [];
    $stmt = $conn->prepare("
        SELECT user_id, work_date
        FROM hr_attendance_days
        WHERE user_id IN ($in)
          AND work_date >= ?
          AND work_date <= ?
          AND status IN ('Paid Leave','paid_leave')
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startDate, $endDate]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $d = (string)($row['work_date'] ?? '');
            if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
            if (!isset($paidLeaveSets[$uid])) $paidLeaveSets[$uid] = [];
            $paidLeaveSets[$uid][$d] = true;
        }
    }

    $eligibleDatesByUser = [];
    $eligibleWorkingDaysByUser = array_fill_keys($userIds, 0);
    $targetPointsByUser = array_fill_keys($userIds, 0.0);
    foreach ($userIds as $uid) {
        $eligibleDatesByUser[$uid] = [];
    }
    foreach ($workingDates as $d) {
        $k = substr($d, 0, 7);
        foreach ($userIds as $uid) {
            if (isset($paidLeaveSets[$uid][$d])) continue;
            $eligibleDatesByUser[$uid][] = $d;
            $eligibleWorkingDaysByUser[$uid]++;
            $targetPointsByUser[$uid] += (float)($dailyTargetsByUser[$uid][$k] ?? 0);
        }
    }

    $pointsMap = funneltrackCampaignPointsMap($cfg);
    $breakdownByUser = [];
    $perDayPointsByUser = [];
    $achievedPointsByUser = array_fill_keys($userIds, 0.0);
    $qualityByUser = [];
    foreach ($userIds as $uid) {
        $qualityByUser[$uid] = ['qualified' => 0, 'disqualified' => 0, 'ratio_pct' => 0.0];
    }

    $stmt = $conn->prepare("
        SELECT l.agent_id, l.qa_status, COALESCE(l.qa_updated_at, l.created_at) AS qualified_at, d.campaign_type
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.agent_id IN ($in)
          AND l.qa_status IN ('Qualified','Disqualified')
          AND COALESCE(l.qa_updated_at, l.created_at) >= ?
          AND COALESCE(l.qa_updated_at, l.created_at) <= ?
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startTs, $endTs]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['agent_id'] ?? 0);
            if ($uid <= 0 || !isset($requestedUsers[$uid])) continue;
            $status = (string)($row['qa_status'] ?? '');
            if ($status === 'Qualified') {
                $qualityByUser[$uid]['qualified']++;
                $day = opsDateFromUtc((string)($row['qualified_at'] ?? ''));
                $ct = funneltrackNormalizeCampaignType((string)($row['campaign_type'] ?? ''));
                if ($day === '' || isset($paidLeaveSets[$uid][$day]) || $ct === '' || !array_key_exists($ct, $pointsMap)) {
                    continue;
                }
                $p = (float)$pointsMap[$ct];
                $achievedPointsByUser[$uid] += $p;
                if (!isset($breakdownByUser[$uid])) $breakdownByUser[$uid] = [];
                if (!isset($breakdownByUser[$uid][$ct])) $breakdownByUser[$uid][$ct] = ['count' => 0, 'points' => 0.0];
                $breakdownByUser[$uid][$ct]['count']++;
                $breakdownByUser[$uid][$ct]['points'] += $p;
                if (!isset($perDayPointsByUser[$uid])) $perDayPointsByUser[$uid] = [];
                if (!isset($perDayPointsByUser[$uid][$day])) $perDayPointsByUser[$uid][$day] = 0.0;
                $perDayPointsByUser[$uid][$day] += $p;
            } elseif ($status === 'Disqualified') {
                $qualityByUser[$uid]['disqualified']++;
            }
        }
    }
    foreach ($qualityByUser as $uid => $quality) {
        $total = (int)($quality['qualified'] ?? 0) + (int)($quality['disqualified'] ?? 0);
        $qualityByUser[$uid]['ratio_pct'] = $total > 0 ? round((((int)$quality['disqualified']) / $total) * 100.0, 2) : 0.0;
    }

    $presentEqByUser = array_fill_keys($userIds, 0.0);
    $stmt = $conn->prepare("
        SELECT user_id, status, COUNT(*) AS c
        FROM hr_attendance_days
        WHERE user_id IN ($in)
          AND work_date >= ?
          AND work_date <= ?
          AND status IN ('Full Day','Present','present','Half Day','half_day')
        GROUP BY user_id, status
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startDate, $endDate]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $status = strtolower((string)($row['status'] ?? ''));
            $count = (int)($row['c'] ?? 0);
            if ($uid <= 0 || !isset($requestedUsers[$uid])) continue;
            if ($status === 'full day' || $status === 'present') $presentEqByUser[$uid] += $count;
            if ($status === 'half day' || $status === 'half_day') $presentEqByUser[$uid] += ($count * 0.5);
        }
    }

    $requiredLoginMinutes = (int)($cfg['thresholds']['required_login_minutes'] ?? 465);
    $avgMinutesByUser = array_fill_keys($userIds, 0.0);
    $stmt = $conn->prepare("
        SELECT user_id, AVG(working_minutes) AS avgm
        FROM hr_attendance_days
        WHERE user_id IN ($in)
          AND work_date >= ?
          AND work_date <= ?
          AND punch_in IS NOT NULL AND punch_out IS NOT NULL
          AND status NOT IN ('Paid Leave','paid_leave')
        GROUP BY user_id
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startDate, $endDate]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid <= 0 || !isset($requestedUsers[$uid])) continue;
            $avgMinutesByUser[$uid] = (float)($row['avgm'] ?? 0.0);
        }
    }

    $hasEscalationByUser = array_fill_keys($userIds, false);
    $stmt = $conn->prepare("
        SELECT DISTINCT user_id
        FROM funneltrack_escalations
        WHERE user_id IN ($in)
          AND active = 1
          AND start_date <= ?
          AND (end_date IS NULL OR end_date = '' OR end_date >= ?)
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$endDate, $startDate]);
        mysqliBindParams($stmt, $types . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0 && isset($requestedUsers[$uid])) $hasEscalationByUser[$uid] = true;
        }
    }

    $w = $cfg['weights'] ?? [];
    $weights = [
        'productivity' => (float)($w['productivity'] ?? 60),
        'adherence' => (float)($w['adherence'] ?? 10),
        'absenteeism' => (float)($w['absenteeism'] ?? 10),
        'quality' => (float)($w['quality'] ?? 20),
    ];
    $t = $cfg['thresholds'] ?? [];
    $minProd = (float)($t['min_expected_productivity_pct'] ?? 90);
    $minAtt = (float)($t['min_attendance_pct'] ?? 90);
    $maxDisq = (float)($t['max_disqualification_ratio_pct'] ?? 10);
    $escMode = (string)($cfg['escalation_rule']['mode'] ?? 'disqualify');

    $out = [];
    foreach ($userIds as $uid) {
        $targetPoints = (float)($targetPointsByUser[$uid] ?? 0.0);
        $achievedPoints = (float)($achievedPointsByUser[$uid] ?? 0.0);
        $productivityPct = $targetPoints > 0 ? round(($achievedPoints / $targetPoints) * 100.0, 2) : 0.0;
        $avgMinutes = (float)($avgMinutesByUser[$uid] ?? 0.0);
        $adherencePct = min(100.0, round(($avgMinutes / max(1.0, (float)$requiredLoginMinutes)) * 100.0, 2));
        if ($adherencePct < 0) $adherencePct = 0.0;
        $eligibleWorkingDays = (int)($eligibleWorkingDaysByUser[$uid] ?? 0);
        $attendancePct = $eligibleWorkingDays > 0 ? round((((float)($presentEqByUser[$uid] ?? 0.0)) / $eligibleWorkingDays) * 100.0, 2) : 0.0;
        $disqRatioPct = (float)($qualityByUser[$uid]['ratio_pct'] ?? 0.0);

        $prodScore = $weights['productivity'] * min(1.0, max(0.0, $productivityPct / 100.0));
        $adhScore = $weights['adherence'] * min(1.0, max(0.0, $adherencePct / 100.0));
        $absScore = $weights['absenteeism'] * min(1.0, max(0.0, $attendancePct / max(1.0, $minAtt)));
        $qualScore = $weights['quality'] * ($disqRatioPct <= $maxDisq ? 1.0 : min(1.0, max(0.0, $maxDisq / max(0.01, $disqRatioPct))));
        $totalScore = round($prodScore + $adhScore + $absScore + $qualScore, 2);
        if ($totalScore < 0) $totalScore = 0.0;
        if ($totalScore > 100) $totalScore = 100.0;

        $hasEsc = (bool)($hasEscalationByUser[$uid] ?? false);
        $eligible = ($productivityPct + 1e-9 >= $minProd);
        if ($hasEsc && $escMode === 'disqualify') $eligible = false;

        $rangeIncentive = 0;
        foreach ($eligibleDatesByUser[$uid] as $d) {
            $k = substr($d, 0, 7);
            $dt = (float)($dailyTargetsByUser[$uid][$k] ?? 0);
            $pts = (float)($perDayPointsByUser[$uid][$d] ?? 0.0);
            $rangeIncentive += funneltrackComputeDailyIncentive($dt, $pts, $cfg);
        }
        if ($hasEsc && $rangeIncentive > 0) {
            if ($escMode === 'partial') {
                $dedPct = (float)($cfg['escalation_rule']['deduction_pct'] ?? 100);
                if ($dedPct < 0) $dedPct = 0;
                if ($dedPct > 100) $dedPct = 100;
                $rangeIncentive = (int)round($rangeIncentive * (1.0 - ($dedPct / 100.0)));
            } elseif ($escMode === 'fixed') {
                $fixed = (int)($cfg['escalation_rule']['fixed_deduction_amount'] ?? 0);
                if ($fixed < 0) $fixed = 0;
                $rangeIncentive = max(0, $rangeIncentive - $fixed);
            } elseif ($escMode === 'disqualify') {
                $rangeIncentive = 0;
            }
        }

        $monthlyBonus = 0;
        if ($eligible && !$hasEsc) {
            $monthlyBonus = funneltrackComputeMonthlyIncentive($productivityPct, $cfg);
        }

        $out[$uid] = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'targets' => ['total' => $targetPoints, 'eligible_working_days' => $eligibleWorkingDays],
            'points' => ['achieved' => $achievedPoints, 'breakdown' => $breakdownByUser[$uid] ?? []],
            'productivity_pct' => $productivityPct,
            'quality' => $qualityByUser[$uid] ?? ['qualified' => 0, 'disqualified' => 0, 'ratio_pct' => 0.0],
            'attendance' => ['attendance_pct' => $attendancePct, 'eligible_working_days' => $eligibleWorkingDays],
            'adherence' => ['adherence_pct' => $adherencePct, 'avg_minutes' => (int)round($avgMinutes), 'required_minutes' => $requiredLoginMinutes],
            'score' => ['productivity' => round($prodScore, 2), 'adherence' => round($adhScore, 2), 'absenteeism' => round($absScore, 2), 'quality' => round($qualScore, 2), 'total' => $totalScore],
            'rank' => funneltrackRankLabel($totalScore, $cfg),
            'incentives' => ['eligible' => $eligible, 'range_total' => (int)$rangeIncentive, 'monthly_bonus' => (int)$monthlyBonus, 'escalation_active' => $hasEsc],
        ];
    }

    return $out;
}

function funneltrackComputeAgentRangeSummary(int $agentId, string $startDate, string $endDate, ?array $cfg = null): array {
    $agentId = (int)$agentId;
    if ($agentId <= 0) return [];
    $rows = funneltrackComputeAgentRangeSummaries([$agentId], $startDate, $endDate, $cfg);
    return $rows[$agentId] ?? [];
}

function funneltrackPerformanceAgentRoles(): array {
    return ['agent','operations_agent','email_marketing_agent','email_marketing_executive','form_filler'];
}

function funneltrackCampaignBucketMeta(?array $cfg = null): array {
    $cfg = $cfg ?? funneltrackGetConfig();
    $pointsMap = funneltrackCampaignPointsMap($cfg);
    return [
        'Email Marketing' => ['key' => 'Email Marketing', 'label' => 'EML', 'points' => (float)($pointsMap['Email Marketing'] ?? 0.0)],
        'MQL' => ['key' => 'MQL', 'label' => 'MQL', 'points' => (float)($pointsMap['MQL'] ?? 0.0)],
        'HQL / Callback' => ['key' => 'HQL / Callback', 'label' => 'HQL/Call', 'points' => (float)($pointsMap['HQL / Callback'] ?? 0.0)],
        'BANT' => ['key' => 'BANT', 'label' => 'BANT', 'points' => (float)($pointsMap['BANT'] ?? 0.0)],
        'Appointment Generation' => ['key' => 'Appointment Generation', 'label' => 'AG', 'points' => (float)($pointsMap['Appointment Generation'] ?? 0.0)],
    ];
}

function funneltrackGetVisiblePerformanceUsers(int $viewerUserId): array {
    $viewerUserId = (int)$viewerUserId;
    if ($viewerUserId <= 0) return [];

    $roleAllow = array_flip(funneltrackPerformanceAgentRoles());
    $visibleUserIds = [];
    if (isAdmin() || isDirector()) {
        foreach (getInternalPayrollUsers() as $userRow) {
            $uid = (int)($userRow['id'] ?? 0);
            $role = strtolower((string)($userRow['role'] ?? ''));
            if ($uid <= 0 || !isset($roleAllow[$role])) continue;
            $visibleUserIds[$uid] = true;
        }
    } else {
        $teamIds = getUserTeamIds($viewerUserId);
        if (!empty($teamIds)) {
            $conn = getDbConnection();
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $types = str_repeat('i', count($teamIds));
            $stmt = $conn->prepare("SELECT DISTINCT tm.user_id FROM team_members tm WHERE tm.team_id IN ($placeholders)");
            if ($stmt) {
                $stmt->bind_param($types, ...$teamIds);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt->close();
                foreach ($rows as $row) {
                    $uid = (int)($row['user_id'] ?? 0);
                    if ($uid > 0) $visibleUserIds[$uid] = true;
                }
            }
        }
        $visibleUserIds[$viewerUserId] = true;
    }

    if (empty($visibleUserIds)) return [];

    $userIds = array_values(array_keys($visibleUserIds));
    $conn = getDbConnection();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    $stmt = $conn->prepare("
        SELECT id, full_name, role, job_title, department
        FROM users
        WHERE id IN ($placeholders) AND is_active = 1
        ORDER BY full_name
    ");
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$userIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $out = [];
    foreach ($rows as $row) {
        $uid = (int)($row['id'] ?? 0);
        $role = strtolower((string)($row['role'] ?? ''));
        if ($uid <= 0 || !isset($roleAllow[$role])) continue;
        $out[$uid] = $row;
    }
    return $out;
}

function funneltrackComputeDailyDetailRows(array $userIds, string $startDate, string $endDate, ?array $cfg = null, ?int $viewerUserId = null): array {
    $cfg = $cfg ?? funneltrackGetConfig();
    $userIds = array_values(array_filter(array_map('intval', $userIds), static fn($v): bool => $v > 0));
    if (empty($userIds)) return [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) return [];
    if ($endDate < $startDate) {
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }

    $viewerUserId = (int)($viewerUserId ?? ($userIds[0] ?? 0));
    $visibleUsers = funneltrackGetVisiblePerformanceUsers($viewerUserId);
    $allowedIds = [];
    if (!empty($visibleUsers)) {
        foreach (array_keys($visibleUsers) as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) $allowedIds[$vid] = true;
        }
    }
    if ($viewerUserId > 0) $allowedIds[$viewerUserId] = true;

    $userMeta = [];
    if (!empty($visibleUsers)) {
        foreach ($userIds as $uid) {
            if ($uid === $viewerUserId && isset($allowedIds[$uid])) {
                continue;
            }
            if (isset($allowedIds[$uid]) && isset($visibleUsers[$uid])) $userMeta[$uid] = $visibleUsers[$uid];
        }
    }

    $missing = [];
    foreach ($userIds as $uid) {
        if (!isset($allowedIds[$uid])) continue;
        if (!isset($userMeta[$uid])) $missing[] = $uid;
    }

    if (!empty($missing)) {
        $conn = getDbConnection();
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $types = str_repeat('i', count($missing));
        $stmt = $conn->prepare("SELECT id, full_name, role, job_title, department FROM users WHERE id IN ($placeholders) AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param($types, ...$missing);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            foreach ($rows as $row) {
                $uid = (int)($row['id'] ?? 0);
                if ($uid > 0 && isset($allowedIds[$uid])) $userMeta[$uid] = $row;
            }
        }
    }

    $rangeUtc = opsDateRangeUtc($startDate, $endDate);
    $startTs = (string)($rangeUtc['start'] ?? ($startDate . ' 00:00:00'));
    $endTs = (string)($rangeUtc['end'] ?? ($endDate . ' 23:59:59'));
    $bucketMeta = funneltrackCampaignBucketMeta($cfg);
    $bucketKeys = array_keys($bucketMeta);
    $pointsMap = funneltrackCampaignPointsMap($cfg);
    $workingDateSet = array_fill_keys(funneltrackWorkingDatesInRange($startDate, $endDate, $cfg), true);

    $monthTargetByAgent = [];
    $monthCursor = substr($startDate, 0, 7) . '-01';
    $monthEndCursor = substr($endDate, 0, 7) . '-01';
    while ($monthCursor <= $monthEndCursor) {
        $yy = (int)substr($monthCursor, 0, 4);
        $mm = (int)substr($monthCursor, 5, 2);
        $key = substr($monthCursor, 0, 7);
        $monthTargetByAgent[$key] = [];
        foreach (getMonthlyTargets($yy, $mm) as $targetRow) {
            $aid = (int)($targetRow['agent_id'] ?? 0);
            if ($aid > 0) $monthTargetByAgent[$key][$aid] = (int)($targetRow['daily_target'] ?? 0);
        }
        $monthCursor = date('Y-m-01', strtotime($monthCursor . ' +1 month'));
    }

    $conn = getDbConnection();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $idTypes = str_repeat('i', count($userIds));
    $createdExpr = function_exists('leadCreatedAtExpr') ? leadCreatedAtExpr('l') : 'l.created_at';
    $qaUpdatedExpr = function_exists('leadCanonicalTimestampExpr') ? leadCanonicalTimestampExpr('qa_updated_at', 'l') : 'l.qa_updated_at';
    $qualifiedExpr = "COALESCE($qaUpdatedExpr, $createdExpr)";

    $generatedByKey = [];
    $closedQaList = implode("','", array_map('addslashes', funneltrackFinalQaStatuses()));
    $stmt = $conn->prepare("
        SELECT
          l.agent_id,
          DATE($createdExpr) AS work_date,
          COUNT(*) AS generated_count,
          SUM(
            CASE
              WHEN l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' THEN 1
              WHEN l.qa_status NOT IN ('" . $closedQaList . "') THEN 1
              ELSE 0
            END
          ) AS pending_qa_count
        FROM leads l
        WHERE l.agent_id IN ($placeholders)
          AND $createdExpr >= ?
          AND $createdExpr <= ?
        GROUP BY l.agent_id, DATE($createdExpr)
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startTs, $endTs]);
        mysqliBindParams($stmt, $idTypes . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['agent_id'] ?? 0);
            $date = (string)($row['work_date'] ?? '');
            if ($uid <= 0 || $date === '') continue;
            $generatedByKey[$uid . '|' . $date] = [
                'generated' => (int)($row['generated_count'] ?? 0),
                'pending_qa' => (int)($row['pending_qa_count'] ?? 0),
            ];
        }
    }

    $qualifiedByKey = [];
    $dqByKey = [];
    // First get all unique leads with their data
    $stmt = $conn->prepare("
        SELECT DISTINCT
          l.id AS lead_id,
          l.agent_id,
          l.campaign_id,
          DATE($qualifiedExpr) AS work_date,
          l.qa_status
        FROM leads l
        WHERE l.agent_id IN ($placeholders)
          AND l.qa_status IN ('Qualified','Disqualified')
          AND $qualifiedExpr >= ?
          AND $qualifiedExpr <= ?
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startTs, $endTs]);
        mysqliBindParams($stmt, $idTypes . 'ss', $params);
        $stmt->execute();
        $leadRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        
        // Now get campaign types for all relevant campaigns in one query
        $campaignIds = [];
        foreach ($leadRows as $row) {
            if (!empty($row['campaign_id'])) {
                $campaignIds[(int)$row['campaign_id']] = true;
            }
        }
        
        $campaignTypes = [];
        if (!empty($campaignIds)) {
            $cPlaceholders = implode(',', array_fill(0, count($campaignIds), '?'));
            $cTypes = str_repeat('i', count($campaignIds));
            $stmt2 = $conn->prepare("SELECT campaign_id, campaign_type FROM campaign_details WHERE campaign_id IN ($cPlaceholders)");
            if ($stmt2) {
                mysqliBindParams($stmt2, $cTypes, array_keys($campaignIds));
                $stmt2->execute();
                $cRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stmt2->close();
                foreach ($cRows as $cRow) {
                    $cid = (int)$cRow['campaign_id'];
                    if (!isset($campaignTypes[$cid])) { // Take first campaign type for each campaign
                        $campaignTypes[$cid] = $cRow['campaign_type'];
                    }
                }
            }
        }
        
        // Process each lead exactly once
        foreach ($leadRows as $row) {
            $uid = (int)($row['agent_id'] ?? 0);
            $date = (string)($row['work_date'] ?? '');
            $qaStatus = (string)($row['qa_status'] ?? '');
            if ($uid <= 0 || $date === '') continue;
            
            $campaignId = (int)($row['campaign_id'] ?? 0);
            $campaignType = $campaignTypes[$campaignId] ?? null;
            $bucket = funneltrackNormalizeCampaignType((string)$campaignType);
            $key = $uid . '|' . $date;
            
            if (!isset($qualifiedByKey[$key])) {
                $qualifiedByKey[$key] = ['total' => 0, 'buckets' => []];
                foreach ($bucketKeys as $bucketKey) {
                    $qualifiedByKey[$key]['buckets'][$bucketKey] = 0;
                }
            }
            if (!isset($dqByKey[$key])) {
                $dqByKey[$key] = ['total' => 0, 'buckets' => []];
                foreach ($bucketKeys as $bucketKey) {
                    $dqByKey[$key]['buckets'][$bucketKey] = 0;
                }
                $dqByKey[$key]['buckets']['Other'] = 0;
            }
            
            if ($qaStatus === 'Qualified') {
                $qualifiedByKey[$key]['total'] += 1;
                if ($bucket !== '' && isset($qualifiedByKey[$key]['buckets'][$bucket])) {
                    $qualifiedByKey[$key]['buckets'][$bucket] += 1;
                }
            } else {
                $dqByKey[$key]['total'] += 1;
                if ($bucket !== '' && isset($dqByKey[$key]['buckets'][$bucket])) {
                    $dqByKey[$key]['buckets'][$bucket] += 1;
                } else {
                    $dqByKey[$key]['buckets']['Other'] += 1;
                }
            }
        }
    }

    $paidLeaveByUserDate = [];
    $stmt = $conn->prepare("
        SELECT user_id, work_date
        FROM hr_attendance_days
        WHERE user_id IN ($placeholders)
          AND work_date >= ?
          AND work_date <= ?
          AND status IN ('Paid Leave','paid_leave')
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$startDate, $endDate]);
        mysqliBindParams($stmt, $idTypes . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $date = (string)($row['work_date'] ?? '');
            if ($uid > 0 && $date !== '') $paidLeaveByUserDate[$uid . '|' . $date] = true;
        }
    }

    $escalationCoverage = [];
    if (function_exists('ensureFunnelTrack360Schema')) ensureFunnelTrack360Schema();
    $stmt = $conn->prepare("
        SELECT user_id, start_date, end_date
        FROM funneltrack_escalations
        WHERE active = 1
          AND user_id IN ($placeholders)
          AND start_date <= ?
          AND (end_date IS NULL OR end_date = '' OR end_date >= ?)
    ");
    if ($stmt) {
        $params = array_merge($userIds, [$endDate, $startDate]);
        mysqliBindParams($stmt, $idTypes . 'ss', $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $escStart = (string)($row['start_date'] ?? '');
            $escEnd = trim((string)($row['end_date'] ?? ''));
            if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $escStart)) continue;
            if ($escStart < $startDate) $escStart = $startDate;
            if ($escEnd === '' || $escEnd > $endDate) $escEnd = $endDate;
            $cursor = $escStart;
            while ($cursor <= $escEnd) {
                $escalationCoverage[$uid . '|' . $cursor] = true;
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }
        }
    }

    $escMode = (string)($cfg['escalation_rule']['mode'] ?? 'disqualify');
    $partialDeductionPct = (float)($cfg['escalation_rule']['deduction_pct'] ?? 100);
    if ($partialDeductionPct < 0) $partialDeductionPct = 0;
    if ($partialDeductionPct > 100) $partialDeductionPct = 100;
    $fixedDeductionAmount = max(0, (int)($cfg['escalation_rule']['fixed_deduction_amount'] ?? 0));

    $rowsOut = [];
    foreach ($userIds as $uid) {
        if (!isset($userMeta[$uid])) continue;
        $dateCursor = $startDate;
        while ($dateCursor <= $endDate) {
            $rowKey = $uid . '|' . $dateCursor;
            $monthKey = substr($dateCursor, 0, 7);
            $isWorkingDate = isset($workingDateSet[$dateCursor]);
            $isPaidLeave = isset($paidLeaveByUserDate[$rowKey]);
            $targetPoints = ($isWorkingDate && !$isPaidLeave) ? (int)($monthTargetByAgent[$monthKey][$uid] ?? 0) : 0;

            $generatedInfo = $generatedByKey[$rowKey] ?? ['generated' => 0, 'pending_qa' => 0];
            $qualifiedInfo = $qualifiedByKey[$rowKey] ?? ['total' => 0, 'buckets' => array_fill_keys($bucketKeys, 0)];
            $dqInfo = $dqByKey[$rowKey] ?? ['total' => 0, 'buckets' => array_merge(array_fill_keys($bucketKeys, 0), ['Other' => 0])];

            $points = 0.0;
            $formulaParts = [];
            foreach ($bucketKeys as $bucketKey) {
                $count = (int)($qualifiedInfo['buckets'][$bucketKey] ?? 0);
                $pointValue = (float)($pointsMap[$bucketKey] ?? 0.0);
                if ($count > 0 && $pointValue > 0) {
                    $points += ($count * $pointValue);
                    $formulaParts[] = $bucketMeta[$bucketKey]['label'] . ' ' . $count . 'x' . rtrim(rtrim(number_format($pointValue, 2, '.', ''), '0'), '.');
                }
            }
            $points = round($points, 2);

            $dailyIncentive = funneltrackComputeDailyIncentive((float)$targetPoints, $points, $cfg);
            if ($dailyIncentive > 0 && isset($escalationCoverage[$rowKey])) {
                if ($escMode === 'partial') {
                    $dailyIncentive = (int)round($dailyIncentive * (1.0 - ($partialDeductionPct / 100.0)));
                } elseif ($escMode === 'fixed') {
                    $dailyIncentive = max(0, $dailyIncentive - $fixedDeductionAmount);
                } elseif ($escMode === 'disqualify') {
                    $dailyIncentive = 0;
                }
            }

            $shouldInclude = $targetPoints > 0
                || (int)($generatedInfo['generated'] ?? 0) > 0
                || (int)($generatedInfo['pending_qa'] ?? 0) > 0
                || (int)($qualifiedInfo['total'] ?? 0) > 0
                || (int)($dqInfo['total'] ?? 0) > 0;

            if ($shouldInclude) {
                $rowsOut[] = [
                    'date' => $dateCursor,
                    'user_id' => $uid,
                    'agent_name' => (string)($userMeta[$uid]['full_name'] ?? ''),
                    'role' => (string)($userMeta[$uid]['role'] ?? ''),
                    'job_title' => (string)($userMeta[$uid]['job_title'] ?? ''),
                    'department' => (string)($userMeta[$uid]['department'] ?? ''),
                    'target_points' => $targetPoints,
                    'generated' => (int)($generatedInfo['generated'] ?? 0),
                    'pending_qa' => (int)($generatedInfo['pending_qa'] ?? 0),
                    'total_qualified' => (int)($qualifiedInfo['total'] ?? 0),
                    'qualified_buckets' => $qualifiedInfo['buckets'],
                    'total_dq' => (int)($dqInfo['total'] ?? 0),
                    'dq_buckets' => $dqInfo['buckets'],
                    'points' => $points,
                    'points_formula' => empty($formulaParts) ? '—' : (implode(' + ', $formulaParts) . ' = ' . number_format($points, 2)),
                    'daily_incentive' => $dailyIncentive,
                    'escalation_active' => isset($escalationCoverage[$rowKey]),
                ];
            }

            $dateCursor = date('Y-m-d', strtotime($dateCursor . ' +1 day'));
        }
    }

    usort($rowsOut, static function(array $a, array $b): int {
        $dateCmp = strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        if ($dateCmp !== 0) return $dateCmp;
        $nameCmp = strcmp((string)($a['agent_name'] ?? ''), (string)($b['agent_name'] ?? ''));
        if ($nameCmp !== 0) return $nameCmp;
        return ((int)($a['user_id'] ?? 0)) <=> ((int)($b['user_id'] ?? 0));
    });

    return $rowsOut;
}

function generateTaskCode(): string {
    return 'TSK' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function logTaskActivity(int $taskId, ?int $userId, string $action, array $meta = []): void {
    if ($taskId <= 0) return;
    $action = trim($action);
    if ($action === '') return;
    ensureTaskManagementSchema();
    $conn = getDbConnection();
    $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare("INSERT INTO task_activity (task_id, user_id, action, meta_json, created_at) VALUES (?,?,?,?,NOW())");
    if (!$stmt) return;
    $uid = $userId !== null ? (int)$userId : null;
    $stmt->bind_param('iiss', $taskId, $uid, $action, $metaJson);
    @$stmt->execute();
    $stmt->close();
}

function extractMentionUserIds(string $text): array {
    $text = (string)$text;
    if ($text === '') return [];
    preg_match_all('/@([a-zA-Z0-9._-]{2,50})/', $text, $m);
    $names = $m[1] ?? [];
    if (!is_array($names) || empty($names)) return [];
    $names = array_values(array_unique(array_map('strtolower', array_map('trim', $names))));
    $names = array_values(array_filter($names, fn($x) => $x !== ''));
    if (empty($names)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($names), '?'));
    $types = str_repeat('s', count($names));
    $sql = "SELECT id FROM users WHERE is_active = 1 AND LOWER(username) IN ($in)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    mysqliBindParams($stmt, $types, $names);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)($r['id'] ?? 0); }
    $ids = array_values(array_filter(array_unique($ids), fn($x) => $x > 0));
    return $ids;
}

function notifyTaskUsers(array $userIds, string $type, string $title, string $message, string $link, array $meta = []): void {
    if (!function_exists('createNotificationSmart')) return;
    $u = [];
    foreach ($userIds as $id) {
        $id = (int)$id;
        if ($id > 0) $u[$id] = true;
    }
    $uids = array_keys($u);
    if (empty($uids)) return;
    foreach ($uids as $uid) {
        createNotificationSmart($uid, $type, $title, $message, $link, $meta);
    }
}

function getMyTaskWidgetCounts(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
    ensureTaskManagementSchema();
    $conn = getDbConnection();
    $today = opsTodayRange();
    $todayStart = (string)($today['start'] ?? '1970-01-01 00:00:00');
    $todayEnd = (string)($today['end'] ?? '2999-12-31 23:59:59');
    $nowUtc = appNowUtc();
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS pending_cnt,
            SUM(CASE WHEN t.due_at IS NOT NULL AND t.due_at BETWEEN ? AND ? AND t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS due_today_cnt,
            SUM(CASE WHEN t.due_at IS NOT NULL AND t.due_at < ? AND t.status NOT IN ('Completed','Cancelled','Rejected') THEN 1 ELSE 0 END) AS overdue_cnt
        FROM tasks t
        JOIN task_assignees ta ON ta.task_id = t.id
        WHERE ta.user_id = ?
    ");
    if (!$stmt) return ['pending' => 0, 'due_today' => 0, 'overdue' => 0];
    $stmt->bind_param('sssi', $todayStart, $todayEnd, $nowUtc, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'pending' => (int)($row['pending_cnt'] ?? 0),
        'due_today' => (int)($row['due_today_cnt'] ?? 0),
        'overdue' => (int)($row['overdue_cnt'] ?? 0),
    ];
}

function crmBuildDashboardRangeForTimezone(array $query, string $defaultPreset, DateTimeZone $businessTz): array {
    $defaultPreset = strtolower(trim($defaultPreset));
    if ($defaultPreset === '') $defaultPreset = 'this_month';

    $presetRaw = strtolower(trim((string)($query['range'] ?? $query['range_preset'] ?? $defaultPreset)));
    $startInput = trim((string)($query['start_date'] ?? $query['date_from'] ?? ''));
    $endInput = trim((string)($query['end_date'] ?? $query['date_to'] ?? ''));

    $map = [
        'current_month' => 'this_month',
        'month' => 'this_month',
        'thismonth' => 'this_month',
        'today' => 'today',
        'day' => 'today',
        'overall' => 'overall',
        'all' => 'overall',
        'custom' => 'custom',
        'range' => 'custom',
        'last_day' => 'yesterday',
        'yesterday' => 'yesterday',
        'last_week' => 'last_week',
    ];
    $preset = $map[$presetRaw] ?? $presetRaw;

    try {
        $now = new DateTimeImmutable('now', $businessTz);
    } catch (Throwable $e) {
        $now = null;
    }

    $resolved = false;
    $s = null;
    $e = null;
    $label = '';

    for ($i = 0; $i < 2 && !$resolved; $i++) {
        $p = $i === 0 ? $preset : $defaultPreset;
        if (!($now instanceof DateTimeImmutable)) {
            $p = $p === 'overall' ? 'overall' : 'this_month';
        }

        if ($p === 'today') {
            $s = $now->setTime(0, 0, 0);
            $e = $now->setTime(23, 59, 59);
            $label = 'Today';
            $resolved = true;
        } elseif ($p === 'this_month') {
            $s = $now->modify('first day of this month')->setTime(0, 0, 0);
            $e = $now->modify('last day of this month')->setTime(23, 59, 59);
            $label = 'This Month';
            $resolved = true;
        } elseif ($p === 'yesterday') {
            $s = $now->modify('yesterday')->setTime(0, 0, 0);
            $e = $now->modify('yesterday')->setTime(23, 59, 59);
            $label = 'Yesterday (' . $s->format('d M Y') . ')';
            $resolved = true;
        } elseif ($p === 'last_week') {
            $thisMon = $now->modify('monday this week')->setTime(0, 0, 0);
            $lastMon = $thisMon->modify('-7 days');
            $lastSun = $lastMon->modify('+6 days')->setTime(23, 59, 59);
            $s = $lastMon;
            $e = $lastSun;
            $label = 'Last Week ' . $s->format('d M Y') . ' → ' . $e->format('d M Y');
            $resolved = true;
        } elseif ($p === 'custom') {
            $sd = $startInput !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $startInput, $businessTz) : null;
            $ed = $endInput !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $endInput, $businessTz) : null;
            if ($sd instanceof DateTimeImmutable && $ed instanceof DateTimeImmutable) {
                $s = $sd->setTime(0, 0, 0);
                $e = $ed->setTime(23, 59, 59);
                $label = $s->format('d M Y') . ' → ' . $e->format('d M Y');
                $resolved = true;
            }
        } elseif ($p === 'overall') {
            $s = new DateTimeImmutable('1970-01-01 00:00:00', $businessTz);
            $e = new DateTimeImmutable('2999-12-31 23:59:59', $businessTz);
            $label = 'Overall';
            $resolved = true;
        }
    }

    if (!$resolved || !($s instanceof DateTimeImmutable) || !($e instanceof DateTimeImmutable)) {
        $s = new DateTimeImmutable('1970-01-01 00:00:00', $businessTz);
        $e = new DateTimeImmutable('2999-12-31 23:59:59', $businessTz);
        $label = 'Overall';
        $preset = 'overall';
    }

    $startUtc = $s->setTimezone(appUtcTz());
    $endUtc = $e->setTimezone(appUtcTz());

    return [
        'preset' => $preset,
        'label' => $label,
        'start' => $startUtc->format('Y-m-d H:i:s'),
        'end' => $endUtc->format('Y-m-d H:i:s'),
        'start_date' => $s->format('Y-m-d'),
        'end_date' => $e->format('Y-m-d'),
        'start_local' => $s->format('Y-m-d H:i:s'),
        'end_local' => $e->format('Y-m-d H:i:s'),
        'timezone' => $businessTz->getName(),
        'storage_timezone' => appUtcTz()->getName(),
    ];
}

function dfDashboardRange(array $query, string $defaultPreset = 'this_month'): array {
    return crmBuildDashboardRangeForTimezone($query, $defaultPreset, opsBusinessTz());
}

function hrDashboardRange(array $query, string $defaultPreset = 'this_month'): array {
    return crmBuildDashboardRangeForTimezone($query, $defaultPreset, hrBaseTz());
}

function dfDashboardAllBannersHtml(int $viewerUserId): array {
    if (function_exists('dfRecognitionDashboardBannersHtml')) {
        return dfRecognitionDashboardBannersHtml($viewerUserId);
    }
    return ['html' => '', 'refresh_sec' => 0];
}

function ensureAppSettingsSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $verified = true;
}

function ensurePasswordResetSchema(): void {
    static $verified = false;
    if ($verified) return;
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        requested_ip VARCHAR(45) NULL,
        requested_ua VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_selector (selector),
        INDEX idx_user (user_id),
        INDEX idx_expires (expires_at),
        INDEX idx_used (used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $verified = true;
}

function dfEncodeEmailHeader(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (!preg_match('/[^\x20-\x7E]/', $value)) return $value;
    $b64 = base64_encode($value);
    return '=?UTF-8?B?' . $b64 . '?=';
}

function dfSmtpSendMail(array $opts): array {
    $host = trim((string)($opts['host'] ?? ''));
    $port = (int)($opts['port'] ?? 0);
    $secure = strtolower(trim((string)($opts['secure'] ?? 'tls')));
    $username = trim((string)($opts['username'] ?? ''));
    $password = (string)($opts['password'] ?? '');
    $fromEmail = trim((string)($opts['from_email'] ?? $username));
    $fromName = trim((string)($opts['from_name'] ?? ''));
    $toEmail = trim((string)($opts['to_email'] ?? ''));
    $subject = (string)($opts['subject'] ?? '');
    $html = (string)($opts['html'] ?? '');
    $replyTo = trim((string)($opts['reply_to'] ?? ''));

    if ($host === '' || $port <= 0) return ['ok' => false, 'error' => 'SMTP host/port not configured'];
    if ($username === '' || $password === '') return ['ok' => false, 'error' => 'SMTP username/password not configured'];
    if ($fromEmail === '' || $toEmail === '') return ['ok' => false, 'error' => 'Email addresses missing'];

    $remote = ($secure === 'ssl') ? ('ssl://' . $host . ':' . $port) : ($host . ':' . $port);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) return ['ok' => false, 'error' => 'SMTP connect failed: ' . trim($errstr)];
    stream_set_timeout($fp, 20);

    $read = function() use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function(string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function(array $codes) use ($read): array {
        $resp = $read();
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) return ['ok' => false, 'code' => $code, 'resp' => $resp];
        return ['ok' => true, 'code' => $code, 'resp' => $resp];
    };

    $r = $expect([220]);
    if (!$r['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP greeting failed: ' . trim((string)$r['resp'])]; }

    $ehloName = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    if ($ehloName === '') $ehloName = 'localhost';
    $write('EHLO ' . $ehloName);
    $ehlo = $expect([250]);
    if (!$ehlo['ok']) {
        $write('HELO ' . $ehloName);
        $helo = $expect([250]);
        if (!$helo['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP HELO/EHLO failed']; }
        $ehlo['resp'] = (string)$helo['resp'];
    }

    if ($secure === 'tls') {
        if (stripos((string)$ehlo['resp'], 'STARTTLS') !== false) {
            $write('STARTTLS');
            $st = $expect([220]);
            if (!$st['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP STARTTLS failed']; }
            $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoOk !== true) { fclose($fp); return ['ok' => false, 'error' => 'SMTP TLS negotiation failed']; }
            $write('EHLO ' . $ehloName);
            $ehlo2 = $expect([250]);
            if (!$ehlo2['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP EHLO after STARTTLS failed']; }
        }
    }

    $authOk = false;
    $authErr = '';

    $tryAuthPlain = function() use ($write, $expect, $username, $password): array {
        $b64 = base64_encode("\0" . $username . "\0" . $password);
        $write('AUTH PLAIN ' . $b64);
        $r1 = $expect([235, 334]);
        if (!$r1['ok']) return ['ok' => false, 'resp' => (string)($r1['resp'] ?? ''), 'code' => (int)($r1['code'] ?? 0)];
        if ((int)($r1['code'] ?? 0) === 235) return ['ok' => true];
        $write($b64);
        $r2 = $expect([235]);
        if (!$r2['ok']) return ['ok' => false, 'resp' => (string)($r2['resp'] ?? ''), 'code' => (int)($r2['code'] ?? 0)];
        return ['ok' => true];
    };

    $tryAuthLogin = function() use ($write, $expect, $username, $password): array {
        $write('AUTH LOGIN');
        $a1 = $expect([334]);
        if (!$a1['ok']) return ['ok' => false, 'resp' => (string)($a1['resp'] ?? ''), 'code' => (int)($a1['code'] ?? 0)];
        $write(base64_encode($username));
        $a2 = $expect([334]);
        if (!$a2['ok']) return ['ok' => false, 'resp' => (string)($a2['resp'] ?? ''), 'code' => (int)($a2['code'] ?? 0)];
        $write(base64_encode($password));
        $a3 = $expect([235]);
        if (!$a3['ok']) return ['ok' => false, 'resp' => (string)($a3['resp'] ?? ''), 'code' => (int)($a3['code'] ?? 0)];
        return ['ok' => true];
    };

    $auth1 = $tryAuthPlain();
    if (($auth1['ok'] ?? false) === true) {
        $authOk = true;
    } else {
        $auth2 = $tryAuthLogin();
        if (($auth2['ok'] ?? false) === true) {
            $authOk = true;
        } else {
            $lastResp = trim((string)($auth2['resp'] ?? ($auth1['resp'] ?? '')));
            $authErr = $lastResp !== '' ? $lastResp : 'SMTP AUTH failed';
        }
    }

    if (!$authOk) {
        fclose($fp);
        return ['ok' => false, 'error' => 'SMTP AUTH failed: ' . $authErr];
    }

    $write('MAIL FROM:<' . $fromEmail . '>');
    $mf = $expect([250]);
    if (!$mf['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP MAIL FROM failed']; }

    $write('RCPT TO:<' . $toEmail . '>');
    $rt = $expect([250, 251]);
    if (!$rt['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP RCPT TO failed']; }

    $write('DATA');
    $dt = $expect([354]);
    if (!$dt['ok']) { fclose($fp); return ['ok' => false, 'error' => 'SMTP DATA failed']; }

    $headers = [];
    $fromHeader = $fromName !== '' ? (dfEncodeEmailHeader($fromName) . ' <' . $fromEmail . '>') : $fromEmail;
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . $toEmail;
    if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'Subject: ' . dfEncodeEmailHeader($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $body = $html;
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    $safeLines = [];
    foreach ($lines as $ln) {
        if (isset($ln[0]) && $ln[0] === '.') $ln = '.' . $ln;
        $safeLines[] = $ln;
    }
    $data = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $safeLines) . "\r\n";
    fwrite($fp, $data);
    $write('.');
    $done = $expect([250]);
    $write('QUIT');
    fclose($fp);

    if (!$done['ok']) return ['ok' => false, 'error' => 'SMTP send failed'];
    return ['ok' => true];
}

function sendEmailViaSmtp(string $toEmail, string $subject, string $html, ?array $override = null): array {
    $smtpHost = (string)(getAppSetting('smtp_host', 'smtp.gmail.com') ?? 'smtp.gmail.com');
    $smtpPort = (int)(getAppSetting('smtp_port', '587') ?? 587);
    $smtpSecure = (string)(getAppSetting('smtp_secure', 'tls') ?? 'tls');
    $smtpUser = trim((string)(getAppSetting('smtp_user', 'falaktushar8@gmail.com') ?? 'falaktushar8@gmail.com'));
    $smtpPass = (string)(getAppSetting('smtp_pass', '') ?? '');
    $smtpPass = trim($smtpPass);
    $smtpPass = str_replace([' ', "\t", "\r", "\n"], '', $smtpPass);

    $fromEmail = trim((string)(getAppSetting('smtp_from_email', $smtpUser) ?? $smtpUser));
    $fromName = (string)(getAppSetting('smtp_from_name', 'DemandFlow Bridge') ?? 'DemandFlow Bridge');

    $smtpUserLc = strtolower($smtpUser);
    if (($smtpUserLc !== '') && (str_ends_with($smtpUserLc, '@gmail.com') || str_ends_with($smtpUserLc, '@googlemail.com'))) {
        $fromEmail = $smtpUser;
    }

    $opts = [
        'host' => $smtpHost,
        'port' => $smtpPort,
        'secure' => $smtpSecure,
        'username' => $smtpUser,
        'password' => $smtpPass,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'to_email' => $toEmail,
        'subject' => $subject,
        'html' => $html,
    ];
    if (is_array($override)) $opts = array_merge($opts, $override);
    return dfSmtpSendMail($opts);
}

function getAppSetting(string $key, ?string $default = null): ?string {
    $key = trim($key);
    if ($key === '') return $default;
    if (!isset($GLOBALS['__app_settings_cache']) || !is_array($GLOBALS['__app_settings_cache'])) {
        $GLOBALS['__app_settings_cache'] = [];
    }
    $cache = &$GLOBALS['__app_settings_cache'];
    if (array_key_exists($key, $cache)) return $cache[$key];
    ensureAppSettingsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $val = $row ? $row['setting_value'] : $default;
    $cache[$key] = $val;
    return $val;
}

function setAppSetting(string $key, ?string $value): bool {
    $key = trim($key);
    if ($key === '') return false;
    ensureAppSettingsSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        if (!isset($GLOBALS['__app_settings_cache']) || !is_array($GLOBALS['__app_settings_cache'])) {
            $GLOBALS['__app_settings_cache'] = [];
        }
        $GLOBALS['__app_settings_cache'][$key] = $value;
        if (str_starts_with($key, 'access.')) {
            unset($GLOBALS['__access_role_permissions_cache']);
        }
    }
    return $ok;
}

function generateAppSecretToken(int $bytes = 24): string {
    $bytes = max(16, min(64, $bytes));
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $e) {
        return sha1(uniqid((string)mt_rand(), true)) . sha1((string)microtime(true));
    }
}

function getSalesFxSchedulerToken(bool $autoCreate = true): ?string {
    $envToken = trim((string)(getenv('FX_SYNC_SCHEDULER_TOKEN') ?: ''));
    if ($envToken !== '') return $envToken;

    $key = 'sales.fx_sync_scheduler_token';
    $token = trim((string)(getAppSetting($key, '') ?? ''));
    if ($token !== '') return $token;
    if (!$autoCreate) return null;

    $token = generateAppSecretToken(24);
    if (!setAppSetting($key, $token)) return null;
    return $token;
}

function getHrAttendanceSchedulerToken(bool $autoCreate = true): ?string {
    $envToken = trim((string)(getenv('HR_ATTENDANCE_SCHEDULER_TOKEN') ?: ''));
    if ($envToken !== '') return $envToken; 

    $key = 'hr.attendance_scheduler_token';
    $token = trim((string)(getAppSetting($key, '') ?? ''));
    if ($token !== '') return $token;
    if (!$autoCreate) return null;

    $token = generateAppSecretToken(24);
    if (!setAppSetting($key, $token)) return null;
    return $token;
}

function getRecognitionSchedulerToken(bool $autoCreate = true): ?string {
    $envToken = trim((string)(getenv('RECOGNITION_SCHEDULER_TOKEN') ?: ''));
    if ($envToken !== '') return $envToken;

    $key = 'recognition.scheduler_token';
    $token = trim((string)(getAppSetting($key, '') ?? ''));
    if ($token !== '') return $token;
    if (!$autoCreate) return null;

    $token = generateAppSecretToken(24);
    if (!setAppSetting($key, $token)) return null;
    return $token;
}

function ensureUserIpAccessSchema(): void {
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS user_ip_access (
        user_id INT NOT NULL PRIMARY KEY,
        mode VARCHAR(16) NOT NULL DEFAULT 'open',
        allowed_ips TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (mode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function canonicalizeIp(string $ip): ?string {
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
    $bin = @inet_pton($ip);
    if ($bin === false) return null;
    $txt = @inet_ntop($bin);
    if (!is_string($txt) || $txt === '') return null;
    return $txt;
}

function parseAllowedIpList(?string $raw): array {
    $raw = (string)$raw;
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $raw = str_replace(',', "\n", $raw);
    $parts = array_map('trim', explode("\n", $raw));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $canon = canonicalizeIp($p);
        if ($canon !== null) {
            $out[$canon] = true;
            continue;
        }
        if (str_contains($p, '/')) {
            $cidr = explode('/', $p, 2);
            $baseIp = trim((string)($cidr[0] ?? ''));
            $prefix = trim((string)($cidr[1] ?? ''));
            $baseCanon = canonicalizeIp($baseIp);
            if ($baseCanon !== null && $prefix !== '' && ctype_digit($prefix)) {
                $prefixInt = (int)$prefix;
                $isV6 = str_contains($baseCanon, ':');
                $max = $isV6 ? 128 : 32;
                if ($prefixInt >= 0 && $prefixInt <= $max) {
                    $out[$baseCanon . '/' . $prefixInt] = true;
                }
            }
            continue;
        }
        if (str_contains($p, '*')) {
            $parts4 = array_map('trim', explode('.', $p));
            if (count($parts4) === 4) {
                $ok = true;
                foreach ($parts4 as $seg) {
                    if ($seg === '*') continue;
                    if (!ctype_digit($seg)) { $ok = false; break; }
                    $n = (int)$seg;
                    if ($n < 0 || $n > 255) { $ok = false; break; }
                }
                if ($ok) $out[implode('.', $parts4)] = true;
            }
        }
    }
    return array_keys($out);
}

function ipMatchesCidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '' || !str_contains($cidr, '/')) return false;
    [$net, $prefixRaw] = array_map('trim', explode('/', $cidr, 2));
    if ($net === '' || $prefixRaw === '' || !ctype_digit($prefixRaw)) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($net, FILTER_VALIDATE_IP)) return false;
    $isV6 = str_contains($net, ':');
    $maxBits = $isV6 ? 128 : 32;
    $prefix = (int)$prefixRaw;
    if ($prefix < 0 || $prefix > $maxBits) return false;
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) !== strlen($netBin)) return false;
    $bytes = intdiv($prefix, 8);
    $bits = $prefix % 8;
    if ($bytes > 0) {
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    }
    if ($bits === 0) return true;
    $mask = (0xFF << (8 - $bits)) & 0xFF;
    $ipByte = ord($ipBin[$bytes]);
    $netByte = ord($netBin[$bytes]);
    return (($ipByte & $mask) === ($netByte & $mask));
}

function ipMatchesWildcard(string $ip, string $pattern): bool {
    $pattern = trim($pattern);
    if ($pattern === '' || !str_contains($pattern, '*')) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP) || str_contains($ip, ':')) return false;
    $ipParts = explode('.', $ip);
    $patParts = array_map('trim', explode('.', $pattern));
    if (count($ipParts) !== 4 || count($patParts) !== 4) return false;
    for ($i = 0; $i < 4; $i++) {
        $seg = (string)$patParts[$i];
        if ($seg === '*') continue;
        if (!ctype_digit($seg)) return false;
        if ((int)$seg !== (int)$ipParts[$i]) return false;
    }
    return true;
}

function isIpAllowedByPolicy(string $ip, array $allowedRules): bool {
    $ipCanon = canonicalizeIp($ip);
    if ($ipCanon === null) return false;
    $ipBin = @inet_pton($ipCanon);
    if ($ipBin === false) return false;
    foreach ($allowedRules as $rule) {
        $r = trim((string)$rule);
        if ($r === '') continue;
        $rCanon = canonicalizeIp($r);
        if ($rCanon !== null) {
            if (($ipCanon === '127.0.0.1' || $ipCanon === '::1') && ($rCanon === '127.0.0.1' || $rCanon === '::1')) return true;
            $rBin = @inet_pton($rCanon);
            if ($rBin !== false && $rBin === $ipBin) return true;
            continue;
        }
        if (str_contains($r, '/')) {
            if (ipMatchesCidr($ipCanon, $r)) return true;
        } elseif (str_contains($r, '*')) {
            if (ipMatchesWildcard($ipCanon, $r)) return true;
        }
    }
    return false;
}

function getUserIpAccessPolicy(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['mode' => 'open', 'allowed_ips' => []];
    ensureUserIpAccessSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT mode, allowed_ips FROM user_ip_access WHERE user_id = ? LIMIT 1");
    if (!$stmt) return ['mode' => 'open', 'allowed_ips' => []];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    $mode = strtolower(trim((string)($row['mode'] ?? 'open')));
    if ($mode !== 'static') $mode = 'open';
    $allowed = parseAllowedIpList($row['allowed_ips'] ?? '');
    return ['mode' => $mode, 'allowed_ips' => $allowed];
}

function setUserIpAccessPolicy(int $userId, string $mode, ?string $allowedIpsRaw): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    ensureUserIpAccessSchema();
    $allowedList = parseAllowedIpList($allowedIpsRaw);
    $mode = !empty($allowedList) ? 'static' : 'open';
    $allowed = implode("\n", $allowedList);
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO user_ip_access (user_id, mode, allowed_ips) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mode = VALUES(mode), allowed_ips = VALUES(allowed_ips), updated_at = NOW()");
    if (!$stmt) return false;
    $stmt->bind_param('iss', $userId, $mode, $allowed);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getInvoiceNumberingSettings(): array {
    $prefix = (string)(getAppSetting('invoice.numbering.prefix', 'INV') ?? 'INV');
    $sep = (string)(getAppSetting('invoice.numbering.separator', '-') ?? '-');
    $pad = (int)(getAppSetting('invoice.numbering.padding', '4') ?? 4);
    if ($pad < 1) $pad = 1;
    if ($pad > 12) $pad = 12;
    $resetMonthly = (string)(getAppSetting('invoice.numbering.reset_monthly', '1') ?? '1') === '1';
    $dateFmt = (string)(getAppSetting('invoice.numbering.date_format', 'Ym') ?? 'Ym');
    if ($dateFmt === '') $dateFmt = 'Ym';
    return [
        'prefix' => $prefix,
        'separator' => $sep,
        'padding' => $pad,
        'reset_monthly' => $resetMonthly,
        'date_format' => $dateFmt,
    ];
}

function nextInvoiceNumber(string $issueDateYmd): string {
    ensureAppSettingsSchema();
    $s = getInvoiceNumberingSettings();
    $prefix = (string)($s['prefix'] ?? 'INV');
    $sep = (string)($s['separator'] ?? '-');
    $pad = (int)($s['padding'] ?? 4);
    $resetMonthly = (bool)($s['reset_monthly'] ?? true);
    $dateFmt = (string)($s['date_format'] ?? 'Ym');

    $ts = strtotime($issueDateYmd . ' 00:00:00');
    if ($ts === false) $ts = time();
    $datePart = date($dateFmt, $ts);

    $key = $resetMonthly ? ('invoice.numbering.next.' . date('Y-m', $ts)) : 'invoice.numbering.next';
    $cur = (int)(getAppSetting($key, '1') ?? 1);
    if ($cur < 1) $cur = 1;
    $next = $cur + 1;
    setAppSetting($key, (string)$next);

    $num = str_pad((string)$cur, $pad, '0', STR_PAD_LEFT);
    $parts = [];
    if ($prefix !== '') $parts[] = $prefix;
    if ($datePart !== '') $parts[] = $datePart;
    $parts[] = $num;
    return implode($sep, $parts);
}

function getAttendancePolicySettings(): array {
    $grace = (int)(getAppSetting('attendance.grace_minutes', '10') ?? 10);
    if ($grace < 0) $grace = 0;
    if ($grace > 120) $grace = 120;
    $halfAt = (int)(getAppSetting('attendance.late_halfday_at', '3') ?? 3);
    if ($halfAt < 1) $halfAt = 1;
    if ($halfAt > 31) $halfAt = 31;
    $absentAt = (int)(getAppSetting('attendance.late_absent_at', '4') ?? 4);
    if ($absentAt < $halfAt) $absentAt = $halfAt;
    if ($absentAt > 31) $absentAt = 31;
    return ['grace_minutes' => $grace, 'late_halfday_at' => $halfAt, 'late_absent_at' => $absentAt];
}

function getUserTeamIds(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureTeamSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT DISTINCT team_id FROM team_members WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ids = [];
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $tid = (int)($r['team_id'] ?? 0);
        if ($tid > 0) $ids[$tid] = true;
    }
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT id FROM teams WHERE manager_user_id = ?");
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $rs2 = $stmt2->get_result();
    while ($r = $rs2->fetch_assoc()) {
        $tid = (int)($r['id'] ?? 0);
        if ($tid > 0) $ids[$tid] = true;
    }
    $stmt2->close();

    return array_keys($ids);
}

function getTeamCampaignIds(array $teamIds): array {
    ensureTeamSchema();
    $ids = array_values(array_filter(array_map('intval', $teamIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("SELECT DISTINCT campaign_id FROM team_campaigns WHERE team_id IN ($in)");
    mysqliBindParams($stmt, $types, $ids);
    $stmt->execute();
    $out = [];
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $cid = (int)($r['campaign_id'] ?? 0);
        if ($cid > 0) $out[$cid] = true;
    }
    $stmt->close();
    return $out;
}

function getTeamVisibleCampaignIdsForUser(int $userId, ?array $assignedMap = null): ?array {
    static $cache = [];
    $cacheKey = $userId . '_' . ($assignedMap === null ? 'null' : md5(serialize($assignedMap)));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $userId = (int)$userId;
    if ($userId <= 0) return $assignedMap;
    if (isAdmin() || isDirector()) return null;
    $teamIds = getUserTeamIds($userId);
    if (empty($teamIds)) return $assignedMap;
    $teamCampaigns = getTeamCampaignIds($teamIds);
    if (empty($teamCampaigns)) return $assignedMap;
    if ($assignedMap === null) return $teamCampaigns;
    $out = [];
    foreach ($assignedMap as $cid => $v) {
        $cid = (int)$cid;
        if ($cid > 0) $out[$cid] = true;
    }
    foreach ($teamCampaigns as $cid => $v) {
        $cid = (int)$cid;
        if ($cid > 0) $out[$cid] = true;
    }
    $cache[$cacheKey] = $out;
    return $out;
}

function getCampaignsBasicByIds(array $campaignIds, bool $onlyActive = true): array {
    $ids = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
    if (empty($ids)) return [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "
        SELECT c.id, c.name, d.code, d.client_code, d.status
        FROM campaigns c
        JOIN campaign_details d ON d.campaign_id = c.id
        WHERE c.id IN ($in)
    ";
    if ($onlyActive) $sql .= " AND c.active = 1";
    $sql .= " ORDER BY c.name";
    $stmt = $conn->prepare($sql);
    mysqliBindParams($stmt, $types, $ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

/**
 * Get summary of all agents and their lead status counts
 */
function getAgentSummary(?string $rangeStart = null, ?string $rangeEnd = null, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('l');
    $where = [];
    $params = [];
    $types = '';
    if ($rangeStart !== null && $rangeEnd !== null && trim($rangeStart) !== '' && trim($rangeEnd) !== '') {
        $where[] = "$createdExpr BETWEEN ? AND ?";
        $params[] = $rangeStart;
        $params[] = $rangeEnd;
        $types .= 'ss';
    }
    if (is_array($campaignIds)) {
        $campaignIds = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($campaignIds)) return [];
        $in = implode(',', array_fill(0, count($campaignIds), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($campaignIds as $id) { $params[] = $id; $types .= 'i'; }
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "
        SELECT
            IFNULL(u.full_name, 'N/A') AS agent,
            COUNT(*) AS total,
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
            SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) AS filled,
            SUM(CASE WHEN l.form_done='No' AND l.qa_status='Qualified' THEN 1 ELSE 0 END) AS pending
        FROM leads l
        LEFT JOIN users u ON l.agent_id = u.id
        $whereSql
        GROUP BY l.agent_id, u.full_name
        ORDER BY total DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

/**
 * Get summary of all campaigns and their lead status counts
 */
function getCampaignSummary(?string $rangeStart = null, ?string $rangeEnd = null, ?array $campaignIds = null): array {
    $conn = getDbConnection();
    $where = [];
    $params = [];
    $types = '';
    if ($rangeStart !== null && $rangeEnd !== null && trim($rangeStart) !== '' && trim($rangeEnd) !== '') {
        $where[] = "l.created_at BETWEEN ? AND ?";
        $params[] = $rangeStart;
        $params[] = $rangeEnd;
        $types .= 'ss';
    }
    if (is_array($campaignIds)) {
        $campaignIds = array_values(array_filter(array_map('intval', $campaignIds), fn($v) => $v > 0));
        if (empty($campaignIds)) return [];
        $in = implode(',', array_fill(0, count($campaignIds), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($campaignIds as $id) { $params[] = $id; $types .= 'i'; }
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "
        SELECT
            IFNULL(c.name, 'N/A') AS campaign,
            COUNT(*) AS total,
            SUM(CASE WHEN l.qa_status='Qualified' THEN 1 ELSE 0 END) AS qualified,
            SUM(CASE WHEN l.form_done='Yes' THEN 1 ELSE 0 END) AS filled,
            SUM(CASE WHEN l.form_done='No' AND l.qa_status='Qualified' THEN 1 ELSE 0 END) AS pending
        FROM leads l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        $whereSql
        GROUP BY l.campaign_id, c.name
        ORDER BY total DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
}

/**
 * Refresh user data in the current session
 */
function refreshUserSession(int $userId): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && ($user = $result->fetch_assoc())) {
        unset($user['password']); // Never store password in session
        $_SESSION['user'] = $user;
    }
    $stmt->close();
}

/**
 * Get overall stats for Admin Dashboard
 */
function getOverallStats(string $todayStart, string $todayEnd, string $monthStart, string $monthEnd): array {
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('');
    $sql = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) as disqualified_leads,
            SUM(CASE WHEN qa_status = 'Pending' THEN 1 ELSE 0 END) as pending_leads,
            SUM(CASE WHEN form_done = 'Yes' THEN 1 ELSE 0 END) as filled_forms,
            SUM(CASE WHEN qa_status = 'Qualified' AND form_done = 'No' THEN 1 ELSE 0 END) as pending_form_filling,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as today_qualified,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as today_disqualified,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND form_done = 'Yes' THEN 1 ELSE 0 END) as today_filled_forms,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) as month_leads,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as month_qualified,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as month_disqualified,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND form_done = 'Yes' THEN 1 ELSE 0 END) as month_filled_forms
        FROM leads
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssssssssssss',
        $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd,
        $monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd, $monthStart, $monthEnd
    );
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

/**
 * Get QA stats for QA Dashboard
 */
function getQaStats(string $todayStart, string $todayEnd): array {
    $overall = getLeadWorkflowCounts([]);
    $todayGenerated = getLeadWorkflowCounts(['date_from' => substr($todayStart, 0, 10), 'date_to' => substr($todayEnd, 0, 10)]);
    $todayReviewed = getLeadWorkflowCounts(['qa_date_from' => substr($todayStart, 0, 10), 'qa_date_to' => substr($todayEnd, 0, 10)]);
    return [
        'total_generated' => (int)($overall['total'] ?? 0),
        'total_reviewed' => (int)($overall['qa_reviewed'] ?? 0),
        'total_pass' => (int)(($overall['qa_counts']['Qualified'] ?? 0)),
        'total_fail' => (int)(($overall['qa_counts']['Disqualified'] ?? 0)),
        'total_pending' => (int)($overall['qa_open_total'] ?? 0),
        'today_generated' => (int)($todayGenerated['total'] ?? 0),
        'today_reviewed' => (int)($todayReviewed['qa_reviewed'] ?? 0),
        'today_pass' => (int)(($todayReviewed['qa_counts']['Qualified'] ?? 0)),
        'today_fail' => (int)(($todayReviewed['qa_counts']['Disqualified'] ?? 0)),
        'qa_counts' => (array)($overall['qa_counts'] ?? []),
        'client_delivery_counts' => (array)($overall['client_delivery_counts'] ?? []),
        'today_qa_counts' => (array)($todayReviewed['qa_counts'] ?? []),
        'today_client_delivery_counts' => (array)($todayGenerated['client_delivery_counts'] ?? []),
    ];
}

function getQaStatsScoped(?array $campaignIds, string $todayStart, string $todayEnd): array {
    if ($campaignIds === null) return getQaStats($todayStart, $todayEnd);
    $ids = [];
    foreach ($campaignIds as $k => $v) $ids[] = is_int($k) ? (int)$v : (int)$k;
    $ids = array_values(array_filter($ids, fn($x) => $x > 0));
    if (empty($ids)) {
        return [
            'total_generated' => 0, 'total_reviewed' => 0, 'total_pass' => 0, 'total_fail' => 0, 'total_pending' => 0,
            'today_generated' => 0, 'today_reviewed' => 0, 'today_pass' => 0, 'today_fail' => 0,
            'qa_counts' => getQaCountBuckets(), 'client_delivery_counts' => getClientDeliveryCountBuckets(),
            'today_qa_counts' => getQaCountBuckets(), 'today_client_delivery_counts' => getClientDeliveryCountBuckets(),
        ];
    }
    $baseFilters = ['campaign_ids' => $ids];
    $overall = getLeadWorkflowCounts($baseFilters);
    $todayGenerated = getLeadWorkflowCounts(array_merge($baseFilters, ['date_from' => substr($todayStart, 0, 10), 'date_to' => substr($todayEnd, 0, 10)]));
    $todayReviewed = getLeadWorkflowCounts(array_merge($baseFilters, ['qa_date_from' => substr($todayStart, 0, 10), 'qa_date_to' => substr($todayEnd, 0, 10)]));
    return [
        'total_generated' => (int)($overall['total'] ?? 0),
        'total_reviewed' => (int)($overall['qa_reviewed'] ?? 0),
        'total_pass' => (int)(($overall['qa_counts']['Qualified'] ?? 0)),
        'total_fail' => (int)(($overall['qa_counts']['Disqualified'] ?? 0)),
        'total_pending' => (int)($overall['qa_open_total'] ?? 0),
        'today_generated' => (int)($todayGenerated['total'] ?? 0),
        'today_reviewed' => (int)($todayReviewed['qa_reviewed'] ?? 0),
        'today_pass' => (int)(($todayReviewed['qa_counts']['Qualified'] ?? 0)),
        'today_fail' => (int)(($todayReviewed['qa_counts']['Disqualified'] ?? 0)),
        'qa_counts' => (array)($overall['qa_counts'] ?? []),
        'client_delivery_counts' => (array)($overall['client_delivery_counts'] ?? []),
        'today_qa_counts' => (array)($todayReviewed['qa_counts'] ?? []),
        'today_client_delivery_counts' => (array)($todayGenerated['client_delivery_counts'] ?? []),
    ];
}

function getLeadWorkflowCounts(array $filters = []): array {
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('l');
    $joins = [
        "LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id",
        "LEFT JOIN clients cl ON cl.id = d.client_id",
    ];
    $where = ['1=1'];
    $params = [];
    $types = '';

    if (!empty($filters['campaign_id'])) { $where[] = 'l.campaign_id = ?'; $params[] = (int)$filters['campaign_id']; $types .= 'i'; }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return ['total' => 0, 'qa_open_total' => 0, 'qa_reviewed' => 0, 'qa_counts' => getQaCountBuckets(), 'client_delivery_counts' => getClientDeliveryCountBuckets()];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($filters['agent_id'])) { $where[] = 'l.agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['assigned_to_user'])) { $where[] = 'l.assigned_to_user = ?'; $params[] = (int)$filters['assigned_to_user']; $types .= 'i'; }
    if (!empty($filters['vendor_id'])) { $where[] = 'l.vendor_id = ?'; $params[] = (int)$filters['vendor_id']; $types .= 'i'; }
    if (!empty($filters['vendor_only'])) { $where[] = "((l.vendor_id IS NOT NULL AND l.vendor_id > 0) OR LOWER(COALESCE(l.lead_source,'')) = 'vendor')"; }
    if (!empty($filters['client_id'])) { $where[] = 'd.client_id = ?'; $params[] = (int)$filters['client_id']; $types .= 'i'; }
    if (!empty($filters['client_code'])) {
        $where[] = "COALESCE(NULLIF(TRIM(cl.client_code), ''), NULLIF(TRIM(d.client_code), '')) = ?";
        $params[] = trim((string)$filters['client_code']);
        $types .= 's';
    }
    $leadDateRange = [];
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $fromDate = (string)($filters['date_from'] ?? $filters['date_to'] ?? '');
        $toDate = (string)($filters['date_to'] ?? $filters['date_from'] ?? '');
        $leadDateRange = opsDateRangeUtc($fromDate, $toDate);
    }
    if (!empty($leadDateRange['start'])) { $where[] = "$createdExpr >= ?"; $params[] = $leadDateRange['start']; $types .= 's'; }
    if (!empty($leadDateRange['end'])) { $where[] = "$createdExpr <= ?"; $params[] = $leadDateRange['end']; $types .= 's'; }
    if (!empty($filters['qa_date_from']) || !empty($filters['qa_date_to'])) {
        $qaFromDate = (string)($filters['qa_date_from'] ?? $filters['qa_date_to'] ?? '');
        $qaToDate = (string)($filters['qa_date_to'] ?? $filters['qa_date_from'] ?? '');
        $qaDateRange = opsDateRangeUtc($qaFromDate, $qaToDate);
        if (!empty($qaDateRange['start'])) { $where[] = "l.qa_updated_at >= ?"; $params[] = $qaDateRange['start']; $types .= 's'; }
        if (!empty($qaDateRange['end'])) { $where[] = "l.qa_updated_at <= ?"; $params[] = $qaDateRange['end']; $types .= 's'; }
    }
    if (!empty($filters['qa_status'])) {
        $qaVals = qaStatusFilterValues((string)$filters['qa_status']);
        $qaNorm = normalizeQaStatus((string)$filters['qa_status']);
        if ($qaNorm === 'Pending') {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "(l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status IN ($in))";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        } elseif (count($qaVals) === 1) {
            $where[] = 'l.qa_status = ?';
            $params[] = $qaVals[0];
            $types .= 's';
        } else {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "l.qa_status IN ($in)";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        }
    }
    if (!empty($filters['client_delivery_status'])) {
        $deliveryVals = clientDeliveryStatusFilterValues((string)$filters['client_delivery_status']);
        if (count($deliveryVals) === 1) {
            $where[] = 'l.client_delivery_status = ?';
            $params[] = $deliveryVals[0];
            $types .= 's';
        } else {
            $in = implode(',', array_fill(0, count($deliveryVals), '?'));
            $where[] = "l.client_delivery_status IN ($in)";
            foreach ($deliveryVals as $v) { $params[] = $v; $types .= 's'; }
        }
    }
    if (!empty($filters['form_done'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_done']); $types .= 's'; }
    if (!empty($filters['form_filled'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_filled']); $types .= 's'; }
    if (!empty($filters['tag_id'])) {
        ensureLeadTagsSchema();
        $joins[] = "JOIN lead_tags lt ON lt.lead_id = l.id";
        $where[] = 'lt.tag_id = ?';
        $params[] = (int)$filters['tag_id'];
        $types .= 'i';
    }
    if (!empty($filters['search'])) {
        $where[] = '(l.lead_id LIKE ? OR CAST(l.id AS CHAR) LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)';
        $q = '%' . $filters['search'] . '%';
        array_push($params, $q, $q, $q, $q, $q, $q);
        $types .= 'ssssss';
    }

    $sql = "SELECT l.qa_status, l.client_delivery_status, COUNT(DISTINCT l.id) AS cnt
            FROM leads l
            " . implode("\n", $joins) . "
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.qa_status, l.client_delivery_status";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['total' => 0, 'qa_open_total' => 0, 'qa_reviewed' => 0, 'qa_counts' => getQaCountBuckets(), 'client_delivery_counts' => getClientDeliveryCountBuckets()];
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $qaCounts = getQaCountBuckets();
    $deliveryCounts = getClientDeliveryCountBuckets();
    $total = 0;
    foreach ($rows as $row) {
        $cnt = (int)($row['cnt'] ?? 0);
        if ($cnt <= 0) continue;
        $total += $cnt;
        accumulateQaStatusCount($qaCounts, $row['qa_status'] ?? null, $cnt);
        accumulateClientDeliveryStatusCount($deliveryCounts, $row['client_delivery_status'] ?? null, $cnt);
    }
    $qaOpenTotal = 0;
    foreach (getQaOpenStatuses() as $status) $qaOpenTotal += (int)($qaCounts[$status] ?? 0);
    $qaReviewed = 0;
    foreach (getQaFinalStatuses() as $status) $qaReviewed += (int)($qaCounts[$status] ?? 0);
    return [
        'total' => $total,
        'qa_open_total' => $qaOpenTotal,
        'qa_reviewed' => $qaReviewed,
        'qa_counts' => $qaCounts,
        'client_delivery_counts' => $deliveryCounts,
    ];
}

/**
 * Get campaign stats by date
 */
function getCampaignStatsByDate(int $campaignId, string $todayStart, string $todayEnd): array {
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('');
    $sql = "
        SELECT 
            COUNT(*) as total_leads,
            SUM(CASE WHEN qa_status = 'Qualified' THEN 1 ELSE 0 END) as qualified_leads,
            SUM(CASE WHEN qa_status = 'Disqualified' THEN 1 ELSE 0 END) as disqualified_leads,
            SUM(CASE WHEN qa_status IN ('Pending','Reopened') THEN 1 ELSE 0 END) as pending_leads,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? THEN 1 ELSE 0 END) as today_leads,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Qualified' THEN 1 ELSE 0 END) as today_qualified,
            SUM(CASE WHEN $createdExpr BETWEEN ? AND ? AND qa_status = 'Disqualified' THEN 1 ELSE 0 END) as today_disqualified
        FROM leads
        WHERE campaign_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssi', $todayStart, $todayEnd, $todayStart, $todayEnd, $todayStart, $todayEnd, $campaignId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

/**
 * Get leads with filters and pagination
 */
function getLeadSortSql(array $filters, string $createdExpr): string {
    $sortBy = strtolower(trim((string)($filters['sort_by'] ?? 'created_at')));
    $sortDir = strtoupper(trim((string)($filters['sort_dir'] ?? 'DESC')));
    if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
        $sortDir = 'DESC';
    }

    $sortMap = [
        'created_at' => $createdExpr,
        'lead_id' => 'COALESCE(l.lead_id, \'\')',
        'agent' => 'COALESCE(u.full_name, \'\')',
        'campaign' => 'COALESCE(c.name, \'\')',
        'email' => 'COALESCE(l.email, \'\')',
        'job_title' => 'COALESCE(l.job_title, \'\')',
        'company_name' => 'COALESCE(l.company_name, \'\')',
        'qa_status' => 'COALESCE(l.qa_status, \'\')',
        'form_status' => 'COALESCE(l.form_done, \'\')',
    ];

    $primary = $sortMap[$sortBy] ?? $createdExpr;
    if ($sortBy === 'created_at') {
        return $createdExpr . ' ' . $sortDir . ', l.id ' . $sortDir;
    }

    return $primary . ' ' . $sortDir . ', ' . $createdExpr . ' DESC, l.id DESC';
}

function getLeads(array $filters = [], int $perPage = 25, int $page = 1): array {
    $conn = getDbConnection();
    $includeSubmission = !empty($filters['include_submission']);
    $createdExpr = leadCreatedAtExpr('l');
    $joins = [
        "LEFT JOIN campaigns c ON l.campaign_id = c.id",
        "LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id",
        "LEFT JOIN clients cl ON cl.id = d.client_id",
        "LEFT JOIN users u ON l.agent_id = u.id",
        "LEFT JOIN users au ON l.assigned_to_user = au.id",
        "LEFT JOIN users cu ON l.created_by = cu.id",
        "LEFT JOIN users qu ON l.qa_reviewed_by = qu.id",
        "LEFT JOIN (SELECT lead_id, COUNT(*) AS call_recording_count FROM lead_files WHERE field_id = 'call_recording' GROUP BY lead_id) lfr ON lfr.lead_id = l.id",
        "LEFT JOIN (
            SELECT lf1.lead_id, lf1.id AS call_recording_id, lf1.file_path AS call_recording_path
            FROM lead_files lf1
            JOIN (
                SELECT lead_id, MAX(id) AS max_id
                FROM lead_files
                WHERE field_id = 'call_recording'
                GROUP BY lead_id
            ) lf2 ON lf2.max_id = lf1.id
        ) lfr_last ON lfr_last.lead_id = l.id",
    ];
    if ($includeSubmission) {
        $joins[] = "LEFT JOIN (
            SELECT fs1.lead_id, fs1.campaign_id, fs1.data_json
            FROM form_submissions fs1
            JOIN (
                SELECT lead_id, campaign_id, MAX(id) AS max_id
                FROM form_submissions
                GROUP BY lead_id, campaign_id
            ) fs2 ON fs2.max_id = fs1.id
        ) fs ON fs.lead_id = l.id AND fs.campaign_id = l.campaign_id";
    }
    $where = ['1=1'];
    $params = [];
    $types = '';

    if (!empty($filters['campaign_id'])) { $where[] = 'l.campaign_id = ?'; $params[] = (int)$filters['campaign_id']; $types .= 'i'; }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return ['leads' => [], 'total' => 0, 'totalPages' => 1];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($filters['agent_id'])) { $where[] = 'l.agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['assigned_to_user'])) { $where[] = 'l.assigned_to_user = ?'; $params[] = (int)$filters['assigned_to_user']; $types .= 'i'; }
    if (!empty($filters['vendor_id'])) { $where[] = 'l.vendor_id = ?'; $params[] = (int)$filters['vendor_id']; $types .= 'i'; }
    if (!empty($filters['vendor_only'])) {
        $where[] = "((l.vendor_id IS NOT NULL AND l.vendor_id > 0) OR LOWER(COALESCE(l.lead_source,'')) = 'vendor')";
    }
    if (!empty($filters['client_id'])) { $where[] = 'd.client_id = ?'; $params[] = (int)$filters['client_id']; $types .= 'i'; }
    if (!empty($filters['client_code'])) {
        $where[] = "COALESCE(NULLIF(TRIM(cl.client_code), ''), NULLIF(TRIM(d.client_code), '')) = ?";
        $params[] = trim((string)$filters['client_code']);
        $types .= 's';
    }
    $leadDateRange = [];
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $fromDate = (string)($filters['date_from'] ?? $filters['date_to'] ?? '');
        $toDate = (string)($filters['date_to'] ?? $filters['date_from'] ?? '');
        $leadDateRange = opsDateRangeUtc($fromDate, $toDate);
    }
    if (!empty($leadDateRange['start'])) { $where[] = "$createdExpr >= ?"; $params[] = $leadDateRange['start']; $types .= 's'; }
    if (!empty($leadDateRange['end'])) { $where[] = "$createdExpr <= ?"; $params[] = $leadDateRange['end']; $types .= 's'; }
    if (!empty($filters['qa_status'])) {
        $qaVals = qaStatusFilterValues((string)$filters['qa_status']);
        $qaNorm = normalizeQaStatus((string)$filters['qa_status']);
        if ($qaNorm === 'Pending') {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "(l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status IN ($in))";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        } elseif (count($qaVals) === 1) {
            $where[] = 'l.qa_status = ?';
            $params[] = $qaVals[0];
            $types .= 's';
        } else {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "l.qa_status IN ($in)";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        }
    }
    if (!empty($filters['client_delivery_status'])) {
        if (is_array($filters['client_delivery_status'])) {
            $vals = array_values(array_filter(array_map('strval', $filters['client_delivery_status'])));
            if (!empty($vals)) {
                $in = implode(',', array_fill(0, count($vals), '?'));
                $where[] = "l.client_delivery_status IN ($in)";
                foreach ($vals as $v) { $params[] = normalizeClientDeliveryStatus($v); $types .= 's'; }
            }
        } else {
            $where[] = 'l.client_delivery_status = ?';
            $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']);
            $types .= 's';
        }
    }
    if (!empty($filters['form_done'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_done']); $types .= 's'; }
    if (!empty($filters['form_filled'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_filled']); $types .= 's'; }
    if (!empty($filters['tag_id'])) {
        ensureLeadTagsSchema();
        $joins[] = "JOIN lead_tags lt ON lt.lead_id = l.id";
        $where[] = 'lt.tag_id = ?';
        $params[] = (int)$filters['tag_id'];
        $types .= 'i';
    }
    if (!empty($filters['search'])) {
        $where[] = '(l.lead_id LIKE ? OR CAST(l.id AS CHAR) LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)';
        $q = '%'.$filters['search'].'%';
        array_push($params, $q, $q, $q, $q, $q, $q);
        $types .= 'ssssss';
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = implode("\n            ", $joins);
    $offset = max(0, ($page - 1) * $perPage);

    $fromTable = 'leads l';

    $cntSql = "SELECT COUNT(DISTINCT l.id) FROM $fromTable $joinSql WHERE $whereSql";
    $cntStmt = $conn->prepare($cntSql);
    if ($types) mysqliBindParams($cntStmt, $types, $params);
    $cntStmt->execute();
    $total = (int)($cntStmt->get_result()->fetch_row()[0] ?? 0);
    $cntStmt->close();

    $selectSubmission = $includeSubmission ? "fs.data_json AS submission_data_json" : "NULL AS submission_data_json";
    $orderSql = getLeadSortSql($filters, $createdExpr);
    $sql = "SELECT l.*, $selectSubmission, c.name AS campaign_name, u.full_name AS agent_name,
                   d.code AS campaign_code, d.client_id AS client_id,
                   cl.client_code AS client_code, cl.name AS client_name,
                   au.id AS assigned_to_id, au.full_name AS assigned_to_name, au.role AS assigned_to_role,
                   cu.full_name AS created_by_name,
                   qu.full_name AS qa_reviewed_by_name,
                   lfr_last.call_recording_id AS call_recording_id,
                   lfr_last.call_recording_path AS call_recording_path,
                   CASE WHEN COALESCE(lfr.call_recording_count, 0) > 0 THEN COALESCE(lfr.call_recording_count, 0)
                        ELSE (CASE WHEN l.recording_path IS NOT NULL AND l.recording_path <> '' THEN 1 ELSE 0 END)
                   END AS recording_count
            FROM $fromTable
            $joinSql
            WHERE $whereSql
            ORDER BY $orderSql
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $params2 = $params;
    $params2[] = $perPage;
    $params2[] = $offset;
    mysqliBindParams($stmt, $types . 'ii', $params2);
    $stmt->execute();
    $leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    foreach ($leads as &$r) {
        $r = deriveLeadDisplayFieldsFromSubmission($r, $r['submission_data_json'] ?? null);
    }
    unset($r);

    return ['leads' => $leads, 'total' => $total, 'totalPages' => max(1, (int)ceil($total/$perPage))];
}

function getLeadsNoPagination(array $filters = []): array {
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('l');
    $joins = [
        "LEFT JOIN campaigns c ON l.campaign_id = c.id",
        "LEFT JOIN campaign_details d ON d.campaign_id = l.campaign_id",
        "LEFT JOIN clients cl ON cl.id = d.client_id",
        "LEFT JOIN users u ON l.agent_id = u.id",
        "LEFT JOIN users au ON l.assigned_to_user = au.id",
        "LEFT JOIN (SELECT lead_id, COUNT(*) AS call_recording_count FROM lead_files WHERE field_id = 'call_recording' GROUP BY lead_id) lfr ON lfr.lead_id = l.id",
        "LEFT JOIN (
            SELECT lf1.lead_id, lf1.id AS call_recording_id, lf1.file_path AS call_recording_path
            FROM lead_files lf1
            JOIN (
                SELECT lead_id, MAX(id) AS max_id
                FROM lead_files
                WHERE field_id = 'call_recording'
                GROUP BY lead_id
            ) lf2 ON lf2.max_id = lf1.id
        ) lfr_last ON lfr_last.lead_id = l.id",
        "LEFT JOIN (
            SELECT fs1.lead_id, fs1.campaign_id, fs1.data_json
            FROM form_submissions fs1
            JOIN (
                SELECT lead_id, campaign_id, MAX(id) AS max_id
                FROM form_submissions
                GROUP BY lead_id, campaign_id
            ) fs2 ON fs2.max_id = fs1.id
        ) fs ON fs.lead_id = l.id AND fs.campaign_id = l.campaign_id",
    ];
    $where = ['1=1'];
    $params = [];
    $types = '';

    if (!empty($filters['campaign_id'])) { $where[] = 'l.campaign_id = ?'; $params[] = (int)$filters['campaign_id']; $types .= 'i'; }
    if (!empty($filters['campaign_ids']) && is_array($filters['campaign_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['campaign_ids']), fn($v) => $v > 0));
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "l.campaign_id IN ($in)";
        foreach ($ids as $id) { $params[] = $id; $types .= 'i'; }
    }
    if (!empty($filters['agent_id'])) { $where[] = 'l.agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['assigned_to_user'])) { $where[] = 'l.assigned_to_user = ?'; $params[] = (int)$filters['assigned_to_user']; $types .= 'i'; }
    if (!empty($filters['vendor_id'])) { $where[] = 'l.vendor_id = ?'; $params[] = (int)$filters['vendor_id']; $types .= 'i'; }
    if (!empty($filters['vendor_only'])) {
        $where[] = "((l.vendor_id IS NOT NULL AND l.vendor_id > 0) OR LOWER(COALESCE(l.lead_source,'')) = 'vendor')";
    }
    if (!empty($filters['client_id'])) { $where[] = 'd.client_id = ?'; $params[] = (int)$filters['client_id']; $types .= 'i'; }
    if (!empty($filters['client_code'])) {
        $where[] = "COALESCE(NULLIF(TRIM(cl.client_code), ''), NULLIF(TRIM(d.client_code), '')) = ?";
        $params[] = trim((string)$filters['client_code']);
        $types .= 's';
    }
    $leadDateRange = [];
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $fromDate = (string)($filters['date_from'] ?? $filters['date_to'] ?? '');
        $toDate = (string)($filters['date_to'] ?? $filters['date_from'] ?? '');
        $leadDateRange = opsDateRangeUtc($fromDate, $toDate);
    }
    if (!empty($leadDateRange['start'])) { $where[] = "$createdExpr >= ?"; $params[] = $leadDateRange['start']; $types .= 's'; }
    if (!empty($leadDateRange['end'])) { $where[] = "$createdExpr <= ?"; $params[] = $leadDateRange['end']; $types .= 's'; }
    if (!empty($filters['qa_status'])) {
        $qaVals = qaStatusFilterValues((string)$filters['qa_status']);
        $qaNorm = normalizeQaStatus((string)$filters['qa_status']);
        if ($qaNorm === 'Pending') {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "(l.qa_status IS NULL OR TRIM(COALESCE(l.qa_status, '')) = '' OR l.qa_status IN ($in))";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        } elseif (count($qaVals) === 1) {
            $where[] = 'l.qa_status = ?';
            $params[] = $qaVals[0];
            $types .= 's';
        } else {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "l.qa_status IN ($in)";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        }
    }
    if (!empty($filters['client_delivery_status'])) {
        if (is_array($filters['client_delivery_status'])) {
            $vals = array_values(array_filter(array_map('strval', $filters['client_delivery_status'])));
            if (!empty($vals)) {
                $in = implode(',', array_fill(0, count($vals), '?'));
                $where[] = "l.client_delivery_status IN ($in)";
                foreach ($vals as $v) { $params[] = normalizeClientDeliveryStatus($v); $types .= 's'; }
            }
        } else {
            $where[] = 'l.client_delivery_status = ?';
            $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']);
            $types .= 's';
        }
    }
    if (!empty($filters['form_done'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_done']); $types .= 's'; }
    if (!empty($filters['form_filled'])) { $where[] = 'l.form_done = ?'; $params[] = normalizeFormDone($filters['form_filled']); $types .= 's'; }
    if (!empty($filters['tag_id'])) {
        ensureLeadTagsSchema();
        $joins[] = "JOIN lead_tags lt ON lt.lead_id = l.id";
        $where[] = 'lt.tag_id = ?';
        $params[] = (int)$filters['tag_id'];
        $types .= 'i';
    }
    if (!empty($filters['search'])) {
        $where[] = '(l.lead_id LIKE ? OR CAST(l.id AS CHAR) LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)';
        $q = '%'.$filters['search'].'%';
        array_push($params, $q, $q, $q, $q, $q, $q);
        $types .= 'ssssss';
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = implode("\n            ", $joins);
    $orderSql = getLeadSortSql($filters, $createdExpr);
    $sql = "SELECT l.*, fs.data_json AS submission_data_json, c.name AS campaign_name, u.full_name AS agent_name,
                   d.code AS campaign_code, d.client_id AS client_id,
                   cl.client_code AS client_code, cl.name AS client_name,
                   au.id AS assigned_to_id, au.full_name AS assigned_to_name, au.role AS assigned_to_role,
                   CASE WHEN COALESCE(lfr.call_recording_count, 0) > 0 THEN COALESCE(lfr.call_recording_count, 0)
                        ELSE (CASE WHEN l.recording_path IS NOT NULL AND l.recording_path <> '' THEN 1 ELSE 0 END)
                   END AS recording_count
            FROM leads l
            $joinSql
            WHERE $whereSql
            ORDER BY $orderSql";
    $stmt = $conn->prepare($sql);
    if ($types) {
        mysqliBindParams($stmt, $types, $params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    foreach ($rows as &$r) {
        $r = enrichLeadRow(deriveLeadDisplayFieldsFromSubmission($r, $r['submission_data_json'] ?? null));
    }
    unset($r);
    return $rows;
}

/**
 * Get leads count based on filters
 */
function getLeadsCount(array $filters = []): int {
    $conn = getDbConnection();
    $createdExpr = leadCreatedAtExpr('');
    $where = ['1=1'];
    $params = [];
    $types = '';
    if (!empty($filters['agent_id'])) { $where[] = 'agent_id = ?'; $params[] = (int)$filters['agent_id']; $types .= 'i'; }
    if (!empty($filters['qa_status'])) {
        $qaVals = qaStatusFilterValues((string)$filters['qa_status']);
        $qaNorm = normalizeQaStatus((string)$filters['qa_status']);
        if ($qaNorm === 'Pending') {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "(qa_status IS NULL OR TRIM(COALESCE(qa_status, '')) = '' OR qa_status IN ($in))";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        } elseif (count($qaVals) === 1) {
            $where[] = 'qa_status = ?';
            $params[] = $qaVals[0];
            $types .= 's';
        } else {
            $in = implode(',', array_fill(0, count($qaVals), '?'));
            $where[] = "qa_status IN ($in)";
            foreach ($qaVals as $v) { $params[] = $v; $types .= 's'; }
        }
    }
    if (!empty($filters['client_delivery_status'])) { $where[] = 'client_delivery_status = ?'; $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']); $types .= 's'; }
    $leadDateRange = [];
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $fromDate = (string)($filters['date_from'] ?? $filters['date_to'] ?? '');
        $toDate = (string)($filters['date_to'] ?? $filters['date_from'] ?? '');
        $leadDateRange = opsDateRangeUtc($fromDate, $toDate);
    }
    if (!empty($leadDateRange['start'])) { $where[] = "$createdExpr >= ?"; $params[] = $leadDateRange['start']; $types .= 's'; }
    if (!empty($leadDateRange['end'])) { $where[] = "$createdExpr <= ?"; $params[] = $leadDateRange['end']; $types .= 's'; }
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM leads WHERE ".implode(' AND ', $where));
    if ($types) mysqliBindParams($stmt, $types, $params);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0];
}

function updateLeadQuality(int $id, string $status, string $comments, int $reviewerId, string $clientComments = '', string $clientDeliveryStatus = 'Pending'): bool {
    ensureLeadsTrackingColumns();
    $conn = getDbConnection();
    $before = getLeadById((int)$id);
    $prevStatus = $before ? normalizeQaStatus((string)($before['qa_status'] ?? 'Pending')) : null;
    $prevDelivery = $before ? normalizeClientDeliveryStatus((string)($before['client_delivery_status'] ?? 'Pending')) : null;
    $prevComment = $before ? (string)($before['qa_comment'] ?? '') : '';
    $prevClientComment = $before ? (string)($before['qa_client_comment'] ?? '') : '';
    $updatedAtUtc = appNowUtc();
    $norm = trim($status) !== '' ? normalizeQaStatus($status) : (string)($prevStatus ?? 'Pending');
    $delNorm = trim($clientDeliveryStatus) !== '' ? normalizeClientDeliveryStatus((string)$clientDeliveryStatus) : (string)($prevDelivery ?? 'Pending');
    $qaCommentToSave = trim($comments) !== '' ? $comments : $prevComment;
    $qaClientCommentToSave = trim($clientComments) !== '' ? $clientComments : $prevClientComment;
    
    $qaStatusChanged = $prevStatus !== $norm;
    
    $setParts = [
        "qa_status=?", 
        "qa_comment=?", 
        "qa_client_comment=?", 
        "client_delivery_status=?",
        "updated_by=?",
        "updated_at_utc=?",
        "updated_at_source='qa_review_utc'"
    ];
    $params = [$norm, $qaCommentToSave, $qaClientCommentToSave, $delNorm, $reviewerId, $updatedAtUtc];
    $types = "ssssis";
    
    if ($qaStatusChanged) {
        $setParts[] = "qa_reviewed_by=?";
        $setParts[] = "qa_updated_at=NOW()";
        $params[] = $reviewerId;
        $types .= "i";
    }
    $setClause = implode(", ", $setParts);
    
    $stmt = $conn->prepare("UPDATE leads SET $setClause WHERE id=?");
    $params[] = $id;
    $types .= "i";
    mysqliBindParams($stmt, $types, $params);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        syncLeadToCampaignTable((int)$id);
        logLeadActivity((int)$id, (int)$reviewerId, 'qa_updated', [
            'qa_prev_status' => $prevStatus,
            'qa_status' => $norm,
            'qa_comment' => (string)$qaCommentToSave,
            'qa_client_comment' => (string)$qaClientCommentToSave,
            'client_delivery_prev_status' => $prevDelivery,
            'client_delivery_status' => $delNorm,
        ]);
        $lead = getLeadById((int)$id);
        if ($lead) {
            $recipients = [];
            $agentId = (int)($lead['agent_id'] ?? 0);
            $assignedTo = (int)($lead['assigned_to_user'] ?? 0);
            $createdBy = (int)($lead['created_by'] ?? 0);
            foreach ([$agentId, $assignedTo, $createdBy] as $rid) {
                if ($rid > 0 && $rid !== (int)$reviewerId) $recipients[$rid] = true;
            }
            $title = 'Lead status updated';
            $leadLabel = (string)($lead['lead_id'] ?? ('#' . (string)$id));
            $campName = (string)($lead['campaign_name'] ?? '');
            $msg = $leadLabel . ($campName !== '' ? (' · ' . $campName) : '') . ' · QA: ' . $norm . ' · Delivery: ' . $delNorm;
            $link = '../leads/lead-details.php?id=' . (int)$id;
            notifyUsers(array_keys($recipients), 'lead.status_updated', $title, $msg, $link);
        }
        if ($lead && isset($lead['campaign_id'])) {
            $cid = (int)($lead['campaign_id'] ?? 0);
            if ($cid > 0) {
                $stmt2 = $conn->prepare("INSERT INTO qa_audit_logs (lead_id, prev_status, campaign_id, qa_status, qa_comment, qa_client_comment, client_delivery_status, qa_reviewed_by, reviewed_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
                if ($stmt2) {
                    $cmt = (string)$qaCommentToSave;
                    $ccmt = (string)$qaClientCommentToSave;
                    $prev = (string)($prevStatus ?? '');
                    $stmt2->bind_param('isissssi', $id, $prev, $cid, $norm, $cmt, $ccmt, $delNorm, $reviewerId);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }
        }
    }
    return $ok;
}

function ensureBreakAutomationTables(): void {
    $conn = getDbConnection();
    
    // Create hr_break_automation table
    $conn->query("
        CREATE TABLE IF NOT EXISTS hr_break_automation (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            is_enabled TINYINT(1) DEFAULT 0,
            team_break_1_time TIME NULL,
            dinner_time TIME NULL,
            team_break_2_time TIME NULL,
            team_break_1_duration INT DEFAULT 15,
            dinner_duration INT DEFAULT 30,
            team_break_2_duration INT DEFAULT 15,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Create hr_break_sessions table
    $conn->query("
        CREATE TABLE IF NOT EXISTS hr_break_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            break_type ENUM('team_break_1', 'dinner', 'team_break_2') NOT NULL,
            scheduled_time TIME NOT NULL,
            actual_start_time DATETIME NULL,
            actual_end_time DATETIME NULL,
            duration_minutes INT NULL,
            status ENUM('scheduled', 'started', 'completed', 'skipped', 'failed') DEFAULT 'scheduled',
            work_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (user_id, work_date),
            INDEX (work_date, status),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function normalizeBreakAutomationTime(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^\d{2}:\d{2}$/', $value)) return $value . ':00';
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) return $value;
    return null;
}

function getBreakAutomationSettings(int $userId): array {
    $userId = (int)$userId;
    if ($userId <= 0) return [];
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_break_automation WHERE user_id = ?");
    if (!$stmt) return [];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result) return [];
    foreach (['team_break_1_time', 'dinner_time', 'team_break_2_time'] as $field) {
        if (array_key_exists($field, $result) && $result[$field] !== null && $result[$field] !== '') {
            $result[$field] = normalizeBreakAutomationTime((string)$result[$field]);
        }
    }
    return $result;
}

function saveBreakAutomationSettings(int $userId, array $settings): bool {
    $userId = (int)$userId;
    if ($userId <= 0) {
        logBreakAutomation('ERROR', 'Invalid user ID in saveBreakAutomationSettings', ['user_id' => $userId]);
        return false;
    }
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    
    $isEnabled = isset($settings['is_enabled']) ? ((int)$settings['is_enabled'] === 1 ? 1 : 0) : 0;
    $tb1Time = normalizeBreakAutomationTime((string)($settings['team_break_1_time'] ?? ''));
    $dinnerTime = normalizeBreakAutomationTime((string)($settings['dinner_time'] ?? ''));
    $tb2Time = normalizeBreakAutomationTime((string)($settings['team_break_2_time'] ?? ''));
    $tb1Duration = (int)($settings['team_break_1_duration'] ?? 15);
    $dinnerDuration = (int)($settings['dinner_duration'] ?? 30);
    $tb2Duration = (int)($settings['team_break_2_duration'] ?? 15);
    
    logBreakAutomation('INFO', 'Saving break automation settings', [
        'user_id' => $userId,
        'is_enabled' => $isEnabled,
        'team_break_1_time' => $tb1Time,
        'dinner_time' => $dinnerTime,
        'team_break_2_time' => $tb2Time,
    ]);
    
    $stmt = $conn->prepare("
        INSERT INTO hr_break_automation (user_id, is_enabled, team_break_1_time, dinner_time, team_break_2_time, team_break_1_duration, dinner_duration, team_break_2_duration)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled),
            team_break_1_time = VALUES(team_break_1_time),
            dinner_time = VALUES(dinner_time),
            team_break_2_time = VALUES(team_break_2_time),
            team_break_1_duration = VALUES(team_break_1_duration),
            dinner_duration = VALUES(dinner_duration),
            team_break_2_duration = VALUES(team_break_2_duration),
            updated_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        logBreakAutomation('ERROR', 'Failed to prepare statement in saveBreakAutomationSettings', ['user_id' => $userId, 'error' => $conn->error]);
        return false;
    }
    $stmt->bind_param('issssiii', $userId, $isEnabled, $tb1Time, $dinnerTime, $tb2Time, $tb1Duration, $dinnerDuration, $tb2Duration);
    $ok = $stmt->execute();
    $stmt->close();
    
    if ($ok) {
        logBreakAutomation('INFO', 'Break automation settings saved successfully', ['user_id' => $userId]);
    } else {
        logBreakAutomation('ERROR', 'Failed to save break automation settings', ['user_id' => $userId, 'error' => $conn->error]);
    }
    
    return $ok;
}

function getAllBreakAutomationSettings(): array {
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT ba.*, u.full_name, u.job_title, u.department 
        FROM hr_break_automation ba 
        JOIN users u ON u.id = ba.user_id
        ORDER BY u.full_name
    ");
    if (!$stmt) return [];
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result ?: [];
}

function getActiveBreakAutomationUsers(): array {
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT user_id, team_break_1_time, dinner_time, team_break_2_time, team_break_1_duration, dinner_duration, team_break_2_duration FROM hr_break_automation WHERE is_enabled = 1");
    if (!$stmt) return [];
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result ?: [];
}

function getTodayBreakSession(int $userId, string $breakType, string $workDate): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM hr_break_sessions WHERE user_id = ? AND break_type = ? AND work_date = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iss', $userId, $breakType, $workDate);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: null;
}

function createBreakSession(int $userId, string $breakType, string $scheduledTime, string $workDate): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO hr_break_sessions (user_id, break_type, scheduled_time, work_date, status) VALUES (?, ?, ?, ?, 'scheduled')");
    if (!$stmt) return false;
    $stmt->bind_param('isss', $userId, $breakType, $scheduledTime, $workDate);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function updateBreakSessionStatus(int $sessionId, string $status, ?string $startTime = null, ?string $endTime = null, ?int $duration = null): bool {
    $sessionId = (int)$sessionId;
    if ($sessionId <= 0) return false;
    ensureBreakAutomationTables();
    $conn = getDbConnection();
    
    if ($startTime !== null && $endTime !== null && $duration !== null) {
        $stmt = $conn->prepare("UPDATE hr_break_sessions SET status = ?, actual_start_time = ?, actual_end_time = ?, duration_minutes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('sssii', $status, $startTime, $endTime, $duration, $sessionId);
    } elseif ($startTime !== null && $duration !== null) {
        $stmt = $conn->prepare("UPDATE hr_break_sessions SET status = ?, actual_start_time = ?, duration_minutes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ssii', $status, $startTime, $duration, $sessionId);
    } elseif ($startTime !== null) {
        $stmt = $conn->prepare("UPDATE hr_break_sessions SET status = ?, actual_start_time = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ssi', $status, $startTime, $sessionId);
    } else {
        $stmt = $conn->prepare("UPDATE hr_break_sessions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('si', $status, $sessionId);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function maybeStartDueAutomatedBreakForUser(int $userId): void {
    $userId = (int)$userId;
    if ($userId <= 0) return;
    $settings = getBreakAutomationSettings($userId);
    if (empty($settings) || (int)($settings['is_enabled'] ?? 0) !== 1) return;

    $now = new DateTime('now', hrDisplayTz());
    $workDate = $now->format('Y-m-d');
    $breaks = [
        ['type' => 'team_break_1', 'time' => $settings['team_break_1_time'] ?? null, 'label' => 'Team Break 1', 'state' => 'Break1', 'duration' => (int)($settings['team_break_1_duration'] ?? 15)],
        ['type' => 'dinner', 'time' => $settings['dinner_time'] ?? null, 'label' => 'Dinner', 'state' => 'Lunch', 'duration' => (int)($settings['dinner_duration'] ?? 30)],
        ['type' => 'team_break_2', 'time' => $settings['team_break_2_time'] ?? null, 'label' => 'Team Break 2', 'state' => 'Break2', 'duration' => (int)($settings['team_break_2_duration'] ?? 15)],
    ];

    foreach ($breaks as $break) {
        $scheduledRaw = normalizeBreakAutomationTime((string)($break['time'] ?? ''));
        if ($scheduledRaw === null) continue;
        $scheduledMinute = substr($scheduledRaw, 0, 5);
        $scheduledAt = DateTime::createFromFormat('Y-m-d H:i:s', $workDate . ' ' . $scheduledMinute . ':00', hrDisplayTz());
        if (!$scheduledAt) continue;
        $windowEnd = (clone $scheduledAt)->modify('+5 minutes');
        if (!($now >= $scheduledAt && $now <= $windowEnd)) continue;

        $session = getTodayBreakSession($userId, $break['type'], $workDate);
        if (!$session) {
            createBreakSession($userId, $break['type'], $scheduledRaw, $workDate);
            $session = getTodayBreakSession($userId, $break['type'], $workDate);
        }
        if (!$session || (string)($session['status'] ?? '') !== 'scheduled') continue;

        $eligibility = canStartAutomatedBreak($userId, $workDate);
        if (empty($eligibility['allowed'])) {
            logBreakAutomation('INFO', 'Countdown auto-start skipped', ['user_id' => $userId, 'break_type' => $break['type'], 'reason' => (string)($eligibility['reason'] ?? 'Not eligible')]);
            continue;
        }

        $result = startAttendanceState($userId, $break['state'], $now->format('Y-m-d H:i:s'));
        if (!empty($result['ok'])) {
            updateBreakSessionStatus((int)($session['id'] ?? 0), 'started', $now->format('Y-m-d H:i:s'), null, (int)$break['duration']);
            logBreakAutomation('INFO', 'Countdown auto-started break successfully', ['user_id' => $userId, 'break_type' => $break['type']]);
        } else {
            updateBreakSessionStatus((int)($session['id'] ?? 0), 'failed');
            logBreakAutomation('ERROR', 'Countdown auto-start failed', ['user_id' => $userId, 'break_type' => $break['type'], 'error' => (string)($result['error'] ?? 'Unknown error')]);
        }
    }
}

function getUpcomingBreakForUser(int $userId): ?array {
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    $settings = getBreakAutomationSettings($userId);
    if (empty($settings) || (int)($settings['is_enabled'] ?? 0) !== 1) return null;
    
    $now = new DateTime('now', hrDisplayTz());
    $currentTime = $now->format('H:i:s');
    $workDate = $now->format('Y-m-d');
    
    // First check if user is currently on a break
    $openDay = getOpenAttendanceDayForUser($userId, $now->format('Y-m-d H:i:s'));
    if ($openDay) {
        $openState = getOpenAttendanceState((int)($openDay['id'] ?? 0));
        if ($openState && in_array((string)($openState['state'] ?? ''), ['Break1', 'Break2', 'Lunch'], true)) {
            $breakStates = [
                'Break1' => ['type' => 'team_break_1', 'label' => 'Team Break 1'],
                'Break2' => ['type' => 'team_break_2', 'label' => 'Team Break 2'],
                'Lunch' => ['type' => 'dinner', 'label' => 'Dinner'],
            ];
            $currentState = (string)($openState['state'] ?? '');
            $breakInfo = $breakStates[$currentState] ?? null;
            if ($breakInfo) {
                $session = getTodayBreakSession($userId, $breakInfo['type'], $workDate);
                $duration = $session ? (int)($session['duration_minutes'] ?? 15) : 15;
                $startTime = hrResolveAttendanceDateTime((string)($openState['start_at'] ?? ''), is_array($openDay) ? $openDay : []);
                $endTime = $startTime ? $startTime->modify('+' . $duration . ' minutes') : null;
                $scheduledTime = $session ? (string)($session['scheduled_time'] ?? '') : '';
                
                if ($endTime) {
                    $remaining = $endTime->getTimestamp() - $now->getTimestamp();
                    if ($remaining > 0) {
                        $minutes = floor($remaining / 60);
                        $seconds = $remaining % 60;
                        return [
                            'type' => $breakInfo['type'],
                            'label' => $breakInfo['label'],
                            'scheduled_time' => $scheduledTime !== '' ? $scheduledTime : $currentTime,
                            'start_time' => $startTime ? $startTime->format('H:i') : null,
                            'countdown' => sprintf('%02d:%02d', $minutes, $seconds),
                            'duration_minutes' => $duration,
                            'work_date' => $workDate,
                            'status' => 'active',
                            'remaining_seconds' => $remaining,
                        ];
                    }
                }
            }
        }
    }
    
    // Check for upcoming breaks
    $breaks = [
        ['type' => 'team_break_1', 'time' => $settings['team_break_1_time'] ?? null, 'label' => 'Team Break 1', 'duration' => (int)($settings['team_break_1_duration'] ?? 15)],
        ['type' => 'dinner', 'time' => $settings['dinner_time'] ?? null, 'label' => 'Dinner', 'duration' => (int)($settings['dinner_duration'] ?? 30)],
        ['type' => 'team_break_2', 'time' => $settings['team_break_2_time'] ?? null, 'label' => 'Team Break 2', 'duration' => (int)($settings['team_break_2_duration'] ?? 15)],
    ];
    
    foreach ($breaks as $break) {
        if ($break['time'] === null) continue;
        if ($break['time'] > $currentTime) {
            return [
                'type' => $break['type'],
                'label' => $break['label'],
                'scheduled_time' => $break['time'],
                'duration_minutes' => $break['duration'],
                'work_date' => $workDate,
                'status' => 'upcoming',
            ];
        }
    }
    
    return null;
}

function logBreakAutomation(string $level, string $message, array $context = []): void {
    $logFile = __DIR__ . '/../storage/break_automation.log';
    $timestamp = (new DateTime('now', hrBaseTz()))->format('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $logLine = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

function canStartAutomatedBreak(int $userId, string $workDate): array {
    $userId = (int)$userId;
    if ($userId <= 0) return ['allowed' => false, 'reason' => 'Invalid user ID'];
    
    $day = getAttendanceDay($userId, $workDate);
    if (!$day) return ['allowed' => false, 'reason' => 'No attendance record for today'];
    
    // Check if user is absent
    $status = (string)($day['status'] ?? '');
    if (in_array($status, ['Absent', 'Paid Leave', 'Unpaid Leave'], true)) {
        return ['allowed' => false, 'reason' => 'User is ' . $status];
    }
    
    // Check if user has punched in
    if (empty($day['punch_in'])) {
        return ['allowed' => false, 'reason' => 'User has not punched in'];
    }
    
    // Check if user has already punched out
    if (!empty($day['punch_out'])) {
        return ['allowed' => false, 'reason' => 'User has already punched out'];
    }
    
    // Check if user is already on a break
    $openState = getOpenAttendanceState((int)($day['id'] ?? 0));
    if ($openState && in_array((string)($openState['state'] ?? ''), ['Break1', 'Break2', 'Lunch'], true)) {
        return ['allowed' => false, 'reason' => 'User is already on a break'];
    }
    
    return ['allowed' => true];
}
