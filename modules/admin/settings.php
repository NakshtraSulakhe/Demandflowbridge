<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('admin.settings.page', ['admin']);
ensureCsrfToken();
ensureDatabaseSchema();
ensureAppSettingsSchema();
ensureUserIpAccessSchema();

$conn = getDbConnection();
$message = '';
$messageType = 'success';

$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_global_security') {
            $enabled = !empty($_POST['ip_access_enabled']) ? '1' : '0';
            $trust = !empty($_POST['ip_access_trust_xff']) ? '1' : '0';
            $localBypass = !empty($_POST['ip_access_localhost_bypass']) ? '1' : '0';
            $bypassRoles = trim((string)($_POST['ip_access_bypass_roles'] ?? ''));
            setAppSetting('security.ip_access.enabled', $enabled);
            setAppSetting('security.ip_access.trust_xff', $trust);
            setAppSetting('security.ip_access.allow_localhost_bypass', $localBypass);
            setAppSetting('security.ip_access.bypass_roles', $bypassRoles);
            $message = 'Settings saved.';
            $messageType = 'success';
        } elseif ($action === 'save_ui') {
            $theme = (string)($_POST['ui_theme_default'] ?? 'light');
            $theme = $theme === 'dark' ? 'dark' : 'light';
            $allowOverride = !empty($_POST['ui_allow_user_override']) ? '1' : '0';
            $normalizeHex = function($v, string $fallback): string {
                $v = trim((string)$v);
                if ($v === '') return $fallback;
                if ($v[0] !== '#') $v = '#' . $v;
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) return $fallback;
                return strtoupper($v);
            };
            $accentLight = $normalizeHex($_POST['ui_accent_light'] ?? '', '#0EA5E9');
            $accentDark = $normalizeHex($_POST['ui_accent_dark'] ?? '', '#00FFFF');
            setAppSetting('ui.theme.default', $theme);
            setAppSetting('ui.theme.allow_user_override', $allowOverride);
            setAppSetting('ui.theme.accent_light', $accentLight);
            setAppSetting('ui.theme.accent_dark', $accentDark);
            $message = 'UI settings saved.';
            $messageType = 'success';
        } elseif ($action === 'save_attendance_policy') {
            $grace = (int)($_POST['attendance_grace_minutes'] ?? 10);
            $halfAt = (int)($_POST['attendance_late_halfday_at'] ?? 3);
            $absAt = (int)($_POST['attendance_late_absent_at'] ?? 4);
            if ($grace < 0) $grace = 0;
            if ($grace > 120) $grace = 120;
            if ($halfAt < 1) $halfAt = 1;
            if ($halfAt > 31) $halfAt = 31;
            if ($absAt < $halfAt) $absAt = $halfAt;
            if ($absAt > 31) $absAt = 31;
            setAppSetting('attendance.grace_minutes', (string)$grace);
            setAppSetting('attendance.late_halfday_at', (string)$halfAt);
            setAppSetting('attendance.late_absent_at', (string)$absAt);
            $message = 'Attendance policy saved.';
            $messageType = 'success';
        } elseif ($action === 'save_invoice_numbering') {
            $mode = (string)($_POST['invoice_numbering_mode'] ?? 'sequence');
            if (!in_array($mode, ['sequence','legacy'], true)) $mode = 'sequence';
            setAppSetting('invoice.numbering.mode', $mode);
            setAppSetting('invoice.numbering.prefix', (string)($_POST['invoice_prefix'] ?? 'INV'));
            setAppSetting('invoice.numbering.separator', (string)($_POST['invoice_separator'] ?? '-'));
            setAppSetting('invoice.numbering.padding', (string)(int)($_POST['invoice_padding'] ?? 4));
            setAppSetting('invoice.numbering.reset_monthly', !empty($_POST['invoice_reset_monthly']) ? '1' : '0');
            setAppSetting('invoice.numbering.date_format', (string)($_POST['invoice_date_format'] ?? 'Ym'));
            $message = 'Invoice numbering saved.';
            $messageType = 'success';
        } elseif ($action === 'save_lead_rules') {
            setAppSetting('leads.lifecycle.qa_statuses', trim((string)($_POST['qa_statuses'] ?? '')));
            setAppSetting('leads.lifecycle.delivery_statuses', trim((string)($_POST['delivery_statuses'] ?? '')));
            setAppSetting('leads.lifecycle.defaults', trim((string)($_POST['lead_defaults'] ?? '')));
            $message = 'Lead lifecycle rules saved.';
            $messageType = 'success';
        } elseif ($action === 'save_notifications') {
            setAppSetting('notifications.in_app.enabled', !empty($_POST['notif_in_app']) ? '1' : '0');
            setAppSetting('notifications.sound.enabled', !empty($_POST['notif_sound']) ? '1' : '0');
            $message = 'Notification settings saved.';
            $messageType = 'success';
        } elseif ($action === 'save_recognition_settings') {
            $ok = function_exists('dfRecognitionSaveSettings') && function_exists('dfRecognitionSettingsFromRequest')
                ? dfRecognitionSaveSettings(dfRecognitionSettingsFromRequest($_POST))
                : false;
            $message = $ok ? 'Recognition settings saved.' : 'Failed to save recognition settings.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'save_recognition_announcement') {
            $announcementId = (int)($_POST['announcement_id'] ?? 0);
            $payload = function_exists('dfRecognitionAnnouncementPayloadFromRequest')
                ? dfRecognitionAnnouncementPayloadFromRequest($_POST)
                : [];
            $startAt = (string)($payload['start_at'] ?? '');
            $endAt = (string)($payload['end_at'] ?? '');
            if ($startAt !== '' && $endAt !== '' && strcmp($endAt, $startAt) < 0) {
                $message = 'Announcement end date must be later than the start date.';
                $messageType = 'danger';
            } else {
                $ok = function_exists('dfRecognitionSaveAnnouncement')
                    ? dfRecognitionSaveAnnouncement($payload, (int)($_SESSION['user']['id'] ?? 0), $announcementId)
                    : false;
                $message = $ok
                    ? ($announcementId > 0 ? 'Announcement updated.' : 'Announcement created.')
                    : 'Failed to save announcement. Title and message are required.';
                $messageType = $ok ? 'success' : 'danger';
                if ($ok) {
                    $selectedUserId = 0;
                }
            }
        } elseif ($action === 'delete_recognition_announcement') {
            $announcementId = (int)($_POST['announcement_id'] ?? 0);
            $ok = $announcementId > 0 && function_exists('dfRecognitionDeleteAnnouncement')
                ? dfRecognitionDeleteAnnouncement($announcementId)
                : false;
            $message = $ok ? 'Announcement deleted.' : 'Failed to delete announcement.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'run_recognition_automation') {
            $summary = function_exists('dfRecognitionAutomationRun') ? dfRecognitionAutomationRun() : [];
            if (!empty($summary)) {
                $message = 'Recognition automation ran successfully. Users scanned: '
                    . number_format((int)($summary['users_scanned'] ?? 0))
                    . ' | Cards visible: '
                    . number_format((int)($summary['cards_visible'] ?? 0));
                $messageType = 'success';
            } else {
                $message = 'Recognition automation did not return a summary.';
                $messageType = 'danger';
            }
        } elseif ($action === 'save_notification_defaults') {
            $eventTypes = [
                'campaign.created' => 'New campaign created',
                'campaign.assigned' => 'Campaign allocated',
                'campaign.end_warning' => 'Campaign end date warning',
                'campaign.pacing_risk' => 'Low leads pacing alert',
                'campaign.updated' => 'Campaign updated',
                'lead.created' => 'New lead uploaded',
                'lead.updated' => 'Lead updated',
                'lead.status_updated' => 'Lead status updated',
                'client.lead.delivered' => 'Client: Lead delivered',
                'chat.message' => 'New chat message',
                'chat.group_message' => 'New group message',
                'chat.added_to_group' => 'Chat: added to group',
                'sales.followup_reminder' => 'Sales follow-up reminder',
                'sales.followup.added' => 'Sales: follow-up added',
                'sales.followup.updated' => 'Sales: follow-up updated',
                'invoice.created' => 'Invoice created',
                'invoice.status_changed' => 'Invoice status updated',
                'invoice.paid' => 'Invoice marked paid',
                'task.assigned' => 'Task: assigned',
                'task.status_changed' => 'Task: status changed',
                'task.progress' => 'Task: progress updated',
                'task.comment' => 'Task: comment added',
                'task.mention' => 'Task: mentioned',
                'task.file_uploaded' => 'Task: file uploaded',
                'task.completed' => 'Task: completed',
                'task.rejected' => 'Task: rejected',
                'task.overdue' => 'Task: overdue',
                'task.escalated' => 'Task: escalated',
                'task.deadline.warning.24h' => 'Task: deadline 24h warning',
                'task.deadline.warning.12h' => 'Task: deadline 12h warning',
                'task.deadline.warning.6h' => 'Task: deadline 6h warning',
                'task.deadline.warning.1h' => 'Task: deadline 1h warning',
            ];
            $defaults = [];
            foreach ($eventTypes as $type => $_label) {
                $enabled = !empty($_POST['def_enabled'][$type]) ? 1 : 0;
                $mode = (string)($_POST['def_mode'][$type] ?? 'instant');
                if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                $toast = !empty($_POST['def_toast'][$type]) ? 1 : 0;
                $defaults[$type] = ['enabled' => $enabled, 'mode' => $mode, 'toast' => $toast];
            }
            $ok = setAppSetting('notifications.default_prefs', json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $message = $ok ? 'Default notification preferences saved.' : 'Failed to save defaults.';
            $messageType = $ok ? 'success' : 'danger';
        } elseif ($action === 'apply_notification_defaults') {
            $overwrite = !empty($_POST['overwrite_existing']) ? 1 : 0;
            $raw = (string)(getAppSetting('notifications.default_prefs', '') ?? '');
            $defs = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($defs) || empty($defs)) {
                $message = 'Save default preferences first.';
                $messageType = 'danger';
            } else {
                $usersRs = $conn->query("SELECT id FROM users WHERE is_active = 1");
                $userIds = [];
                if ($usersRs) {
                    foreach ($usersRs->fetch_all(MYSQLI_ASSOC) ?: [] as $r) {
                        $uid = (int)($r['id'] ?? 0);
                        if ($uid > 0) $userIds[] = $uid;
                    }
                }
                $applied = 0;
                if (!empty($userIds)) {
                    if ($overwrite) {
                        $stmt = $conn->prepare("
                            INSERT INTO notification_preferences (user_id, type, delivery_mode, is_enabled, show_toast)
                            VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                delivery_mode=VALUES(delivery_mode),
                                is_enabled=VALUES(is_enabled),
                                show_toast=VALUES(show_toast),
                                updated_at=NOW()
                        ");
                        if ($stmt) {
                            foreach ($userIds as $uid) {
                                foreach ($defs as $type => $cfg) {
                                    $t = (string)$type;
                                    if ($t === '') continue;
                                    $en = (int)($cfg['enabled'] ?? 1);
                                    $mode = (string)($cfg['mode'] ?? 'instant');
                                    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                    $toast = (int)($cfg['toast'] ?? 0);
                                    $stmt->bind_param('issii', $uid, $t, $mode, $en, $toast);
                                    if ($stmt->execute()) $applied++;
                                }
                            }
                            $stmt->close();
                        }
                    } else {
                        $stmt = $conn->prepare("
                            INSERT IGNORE INTO notification_preferences (user_id, type, delivery_mode, is_enabled, show_toast)
                            VALUES (?,?,?,?,?)
                        ");
                        if ($stmt) {
                            foreach ($userIds as $uid) {
                                foreach ($defs as $type => $cfg) {
                                    $t = (string)$type;
                                    if ($t === '') continue;
                                    $en = (int)($cfg['enabled'] ?? 1);
                                    $mode = (string)($cfg['mode'] ?? 'instant');
                                    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                    $toast = (int)($cfg['toast'] ?? 0);
                                    $stmt->bind_param('issii', $uid, $t, $mode, $en, $toast);
                                    if ($stmt->execute() && $stmt->affected_rows > 0) $applied++;
                                }
                            }
                            $stmt->close();
                        }
                    }
                }
                $message = 'Defaults applied: ' . number_format($applied) . ($overwrite ? ' (overwritten)' : ' (only missing)');
                $messageType = 'success';
            }
        } elseif ($action === 'save_user_ip') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $mode = (string)($_POST['mode'] ?? 'open');
            $ips = (string)($_POST['allowed_ips'] ?? '');
            if ($uid <= 0) {
                $message = 'Select a user.';
                $messageType = 'danger';
            } else {
                $ok = setUserIpAccessPolicy($uid, $mode, $ips);
                $message = $ok ? 'User IP policy saved.' : 'Failed to save user policy.';
                $messageType = $ok ? 'success' : 'danger';
                $selectedUserId = $uid;
            }
        } elseif ($action === 'maintenance_clear_cache') {
            $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
            $tmpDir = $projectRoot . DIRECTORY_SEPARATOR . 'tmp';
            $sessionDir = $tmpDir . DIRECTORY_SEPARATOR . 'sessions';
            $keepSession = $sessionDir . DIRECTORY_SEPARATOR . 'sess_' . session_id();

            $deletedSessions = 0;
            $deletedTmpFiles = 0;
            $opcacheReset = null;

            if (!empty($_POST['clear_tmp_reports'])) {
                $patterns = [
                    $tmpDir . DIRECTORY_SEPARATOR . '*.md',
                    $tmpDir . DIRECTORY_SEPARATOR . '*.log',
                    $tmpDir . DIRECTORY_SEPARATOR . '*.tmp',
                ];
                foreach ($patterns as $p) {
                    foreach (glob($p) ?: [] as $f) {
                        if (is_file($f) && @unlink($f)) $deletedTmpFiles++;
                    }
                }
            }

            if (!empty($_POST['clear_sessions'])) {
                foreach (glob($sessionDir . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $f) {
                    if (!is_file($f)) continue;
                    if (strcasecmp($f, $keepSession) === 0) continue;
                    if (@unlink($f)) $deletedSessions++;
                }
            }

            if (!empty($_POST['reset_opcache'])) {
                if (function_exists('opcache_reset')) {
                    $opcacheReset = (bool)@opcache_reset();
                } else {
                    $opcacheReset = false;
                }
            }

            $messageParts = [];
            if (!empty($_POST['clear_tmp_reports'])) $messageParts[] = 'Temp files deleted: ' . number_format($deletedTmpFiles);
            if (!empty($_POST['clear_sessions'])) $messageParts[] = 'Session files deleted: ' . number_format($deletedSessions);
            if (!empty($_POST['reset_opcache'])) $messageParts[] = 'OPcache reset: ' . ($opcacheReset ? 'Yes' : 'No');
            $message = !empty($messageParts) ? implode(' · ', $messageParts) : 'No cleanup option selected.';
            $messageType = !empty($messageParts) ? 'success' : 'danger';
        } elseif ($action === 'save_ai_transcript') {
            require_once __DIR__ . '/../../includes/ai/AIConfig.php';
            $aiInput = [
                'qa.ai.enabled' => !empty($_POST['qa_ai_enabled']) ? '1' : '0',
                'qa.ai.gemini_api_key' => trim((string)($_POST['qa_ai_gemini_api_key'] ?? '')),
                'qa.ai.processing_mode' => (string)($_POST['qa_ai_processing_mode'] ?? 'automatic'),
                'qa.ai.transcription_model' => trim((string)($_POST['qa_ai_transcription_model'] ?? 'gemini-1.5-flash')),
                'qa.ai.formatting_model' => trim((string)($_POST['qa_ai_formatting_model'] ?? 'gemini-1.5-flash')),
                'qa.ai.output_language' => trim((string)($_POST['qa_ai_output_language'] ?? 'English')),
                'qa.ai.transcript_prompt' => trim((string)($_POST['qa_ai_transcript_prompt'] ?? '')),
                'qa.ai.exclusions' => trim((string)($_POST['qa_ai_exclusions'] ?? '')),
                'qa.ai.replacements' => trim((string)($_POST['qa_ai_replacements'] ?? '')),
                'qa.ai.exceptions' => trim((string)($_POST['qa_ai_exceptions'] ?? '')),
                'qa.ai.preserve_numbers' => !empty($_POST['qa_ai_preserve_numbers']) ? '1' : '0',
                'qa.ai.preserve_emails' => !empty($_POST['qa_ai_preserve_emails']) ? '1' : '0',
                'qa.ai.preserve_phones' => !empty($_POST['qa_ai_preserve_phones']) ? '1' : '0',
                'qa.ai.preserve_names' => !empty($_POST['qa_ai_preserve_names']) ? '1' : '0',
                'qa.ai.speaker_labels' => !empty($_POST['qa_ai_speaker_labels']) ? '1' : '0',
                'qa.ai.max_retries' => (string)max(1, (int)($_POST['qa_ai_max_retries'] ?? 3))
            ];
            AIConfig::saveSettings($aiInput);
            $message = 'AI Transcript settings saved successfully.';
            $messageType = 'success';
        }
    }
}

$projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$tmpDir = $projectRoot . DIRECTORY_SEPARATOR . 'tmp';
$sessionDir = $tmpDir . DIRECTORY_SEPARATOR . 'sessions';
$sessionFilesCount = count(glob($sessionDir . DIRECTORY_SEPARATOR . 'sess_*') ?: []);
$tmpReportFilesCount = 0;
foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $f) {
    if (is_file($f)) $tmpReportFilesCount++;
}
$opcacheAvailable = function_exists('opcache_reset');

$ipEnabled = (string)(getAppSetting('security.ip_access.enabled', '0') ?? '0') === '1';
$trustXff = (string)(getAppSetting('security.ip_access.trust_xff', '0') ?? '0') === '1';
$ipLocalhostBypass = (string)(getAppSetting('security.ip_access.allow_localhost_bypass', '0') ?? '0') === '1';
$ipBypassRoles = (string)(getAppSetting('security.ip_access.bypass_roles', '') ?? '');
$detectedRemoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$detectedXff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
$detectedXffFirst = '';
if ($detectedXff !== '') {
    $parts = array_map('trim', explode(',', $detectedXff));
    $detectedXffFirst = (string)($parts[0] ?? '');
}
$detectedRemoteIpCanon = function_exists('canonicalizeIp') ? canonicalizeIp($detectedRemoteIp) : null;
$detectedXffFirstCanon = function_exists('canonicalizeIp') ? canonicalizeIp($detectedXffFirst) : null;
$detectedEffectiveIp = $trustXff && $detectedXffFirstCanon ? $detectedXffFirstCanon : ($detectedRemoteIpCanon ?: '');
$uiThemeDefault = (string)(getAppSetting('ui.theme.default', 'light') ?? 'light');
$uiThemeDefault = $uiThemeDefault === 'dark' ? 'dark' : 'light';
$uiAllowOverride = (string)(getAppSetting('ui.theme.allow_user_override', '1') ?? '1') === '1';
$uiAccentLight = (string)(getAppSetting('ui.theme.accent_light', '#0EA5E9') ?? '#0EA5E9');
$uiAccentDark = (string)(getAppSetting('ui.theme.accent_dark', '#00FFFF') ?? '#00FFFF');
$attendancePolicy = getAttendancePolicySettings();
$invMode = (string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence');
if (!in_array($invMode, ['sequence','legacy'], true)) $invMode = 'sequence';
$invSet = getInvoiceNumberingSettings();
$leadQaStatuses = (string)(getAppSetting('leads.lifecycle.qa_statuses', '') ?? '');
$leadDeliveryStatuses = (string)(getAppSetting('leads.lifecycle.delivery_statuses', '') ?? '');
$leadDefaults = (string)(getAppSetting('leads.lifecycle.defaults', '') ?? '');
$notifInApp = (string)(getAppSetting('notifications.in_app.enabled', '1') ?? '1') === '1';
$notifSound = (string)(getAppSetting('notifications.sound.enabled', '0') ?? '0') === '1';

require_once __DIR__ . '/../../includes/ai/AIConfig.php';
$aiSettings = AIConfig::getSettings();

$recognitionSettings = function_exists('dfRecognitionGetSettings') ? dfRecognitionGetSettings() : [];
$recognitionDefs = function_exists('dfRecognitionModuleDefinitions') ? dfRecognitionModuleDefinitions() : [];
$recognitionOptions = function_exists('dfRecognitionGetSettingsOptions') ? dfRecognitionGetSettingsOptions() : ['departments' => [], 'roles' => [], 'teams' => [], 'campaigns' => [], 'users' => []];
$recognitionAnnouncements = function_exists('dfRecognitionListAnnouncements') ? dfRecognitionListAnnouncements() : [];
$recognitionLastRun = function_exists('dfRecognitionLastRunSummary') ? dfRecognitionLastRunSummary() : [];
$recognitionSchedulerToken = function_exists('getRecognitionSchedulerToken') ? (getRecognitionSchedulerToken(true) ?? '') : '';
$recognitionSchedulerPath = '/modules/admin/recognition-automation-cron.php';
$recognitionSchedulerUrl = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . $recognitionSchedulerPath;
$recognitionEditId = isset($_GET['announcement_id']) ? (int)$_GET['announcement_id'] : 0;
$recognitionEditAnnouncement = $recognitionEditId > 0 && function_exists('dfRecognitionGetAnnouncementById')
    ? (dfRecognitionGetAnnouncementById($recognitionEditId) ?? null)
    : null;
$recognitionDisplayModes = [
    'carousel' => 'Horizontal Auto Carousel',
    'hero' => 'Full Width Hero Banner',
    'slider' => 'Horizontal Slider',
    'marquee' => 'Horizontal Running Marquee',
    'card_rotation' => 'Horizontal Card Rotation',
];
$recognitionAnnouncementDefaults = [
    'id' => 0,
    'title' => '',
    'body' => '',
    'label' => '',
    'priority' => 'normal',
    'start_at' => '',
    'end_at' => '',
    'is_active' => 1,
    'scope' => ['all' => 1, 'departments' => [], 'team_ids' => [], 'roles' => [], 'campaign_ids' => [], 'user_ids' => []],
];
$recognitionAnnouncementForm = is_array($recognitionEditAnnouncement)
    ? array_merge($recognitionAnnouncementDefaults, $recognitionEditAnnouncement)
    : $recognitionAnnouncementDefaults;
$notifDefaultPrefsRaw = (string)(getAppSetting('notifications.default_prefs', '') ?? '');
$notifDefaultPrefs = $notifDefaultPrefsRaw !== '' ? json_decode($notifDefaultPrefsRaw, true) : null;
if (!is_array($notifDefaultPrefs)) $notifDefaultPrefs = [];
$notifEventTypes = [
    'campaign.created' => 'New campaign created',
    'campaign.assigned' => 'Campaign allocated',
    'campaign.end_warning' => 'Campaign end date warning',
    'campaign.pacing_risk' => 'Low leads pacing alert',
    'campaign.updated' => 'Campaign updated',
    'lead.created' => 'New lead uploaded',
    'lead.updated' => 'Lead updated',
    'lead.status_updated' => 'Lead status updated',
    'client.lead.delivered' => 'Client: Lead delivered',
    'chat.message' => 'New chat message',
    'chat.group_message' => 'New group message',
    'chat.added_to_group' => 'Chat: added to group',
    'sales.followup_reminder' => 'Sales follow-up reminder',
    'sales.followup.added' => 'Sales: follow-up added',
    'sales.followup.updated' => 'Sales: follow-up updated',
    'invoice.created' => 'Invoice created',
    'invoice.status_changed' => 'Invoice status updated',
    'invoice.paid' => 'Invoice marked paid',
    'task.assigned' => 'Task: assigned',
    'task.status_changed' => 'Task: status changed',
    'task.progress' => 'Task: progress updated',
    'task.comment' => 'Task: comment added',
    'task.mention' => 'Task: mentioned',
    'task.file_uploaded' => 'Task: file uploaded',
    'task.completed' => 'Task: completed',
    'task.rejected' => 'Task: rejected',
    'task.overdue' => 'Task: overdue',
    'task.escalated' => 'Task: escalated',
    'task.deadline.warning.24h' => 'Task: deadline 24h warning',
    'task.deadline.warning.12h' => 'Task: deadline 12h warning',
    'task.deadline.warning.6h' => 'Task: deadline 6h warning',
    'task.deadline.warning.1h' => 'Task: deadline 1h warning',
];
$users = [];
$rs = $conn->query("SELECT id, full_name, role, job_title, is_active FROM users WHERE is_active = 1 ORDER BY full_name");
if ($rs) $users = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$userLabelById = [];
foreach ($users as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid > 0) $userLabelById[$uid] = formatUserNameWithRole((string)($u['full_name'] ?? ''), (string)($u['role'] ?? ''), (string)($u['job_title'] ?? ''));
}

$selectedPolicy = $selectedUserId > 0 ? getUserIpAccessPolicy($selectedUserId) : ['mode' => 'open', 'allowed_ips' => []];
$selectedAllowed = is_array($selectedPolicy['allowed_ips'] ?? null) ? implode("\n", $selectedPolicy['allowed_ips']) : '';

$policyRows = [];
$rs = $conn->query("
    SELECT p.user_id, p.mode, p.allowed_ips, p.updated_at, u.full_name, u.role, u.job_title
    FROM user_ip_access p
    LEFT JOIN users u ON u.id = p.user_id
    ORDER BY p.updated_at DESC
    LIMIT 200
");
if ($rs) $policyRows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$activeTab = isset($_POST['tab']) ? (string)$_POST['tab'] : (isset($_GET['tab']) ? (string)$_GET['tab'] : 'security');
$activeTab = strtolower(trim($activeTab));
if (!in_array($activeTab, ['security','ui','recognition','ops','billing','maintenance','ai_transcript'], true)) $activeTab = 'security';

function settingsRecognitionIsSelected($value, array $selected, bool $normalizeText = false): bool
{
    if ($normalizeText) {
        $value = strtolower(trim((string)$value));
        $normalized = array_map(static fn($item): string => strtolower(trim((string)$item)), $selected);
        return in_array($value, $normalized, true);
    }
    return in_array((string)$value, array_map('strval', $selected), true);
}

function settingsRecognitionRenderMultiSelect(string $name, array $options, array $selected, string $label, bool $normalizeText = false): void
{
    ?>
    <div class="col-md-6">
        <label class="form-label small text-muted"><?php echo htmlspecialchars($label); ?></label>
        <div class="dropdown recognition-multiselect" data-recognition-multiselect data-placeholder="<?php echo htmlspecialchars('Select ' . strtolower($label)); ?>">
            <button class="btn btn-sm btn-light border recognition-multiselect-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-display="static">
                <span class="recognition-multiselect-text">Select <?php echo htmlspecialchars(strtolower($label)); ?></span>
                <i class="bi bi-chevron-down small"></i>
            </button>
            <div class="dropdown-menu p-2 recognition-multiselect-menu">
                <div class="small text-muted px-2 pb-2">Select one or more <?php echo htmlspecialchars(strtolower($label)); ?>.</div>
                <?php if (!empty($options)): ?>
                    <div class="recognition-multiselect-options">
                        <?php foreach ($options as $option): ?>
                            <?php
                                $value = $option['value'] ?? '';
                                $text = (string)($option['label'] ?? $value);
                                $isChecked = settingsRecognitionIsSelected($value, $selected, $normalizeText);
                            ?>
                            <label class="dropdown-item-text recognition-multiselect-option">
                                <input class="form-check-input recognition-multiselect-checkbox" type="checkbox" name="<?php echo htmlspecialchars($name); ?>[]" value="<?php echo htmlspecialchars((string)$value); ?>" data-label="<?php echo htmlspecialchars($text); ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                <span class="small"><?php echo htmlspecialchars($text); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="small text-muted px-2 py-2">No options available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function settingsRecognitionRenderScopeFields(string $prefix, array $scope, array $options): void
{
    ?>
    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input recognition-scope-all" type="checkbox" id="<?php echo htmlspecialchars($prefix); ?>_all" name="<?php echo htmlspecialchars($prefix); ?>_all" value="1" <?php echo !empty($scope['all']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="<?php echo htmlspecialchars($prefix); ?>_all">All users</label>
        </div>
        <div class="text-muted small mt-1">Turn this on to show the banner to everyone. Turn it off to choose specific departments, teams, roles, campaigns, or users.</div>
    </div>
    <?php
    settingsRecognitionRenderMultiSelect($prefix . '_departments', (array)($options['departments'] ?? []), (array)($scope['departments'] ?? []), 'Departments', true);
    settingsRecognitionRenderMultiSelect($prefix . '_team_ids', (array)($options['teams'] ?? []), (array)($scope['team_ids'] ?? []), 'Teams');
    settingsRecognitionRenderMultiSelect($prefix . '_roles', (array)($options['roles'] ?? []), (array)($scope['roles'] ?? []), 'Roles', true);
    settingsRecognitionRenderMultiSelect($prefix . '_campaign_ids', (array)($options['campaigns'] ?? []), (array)($scope['campaign_ids'] ?? []), 'Campaigns');
    settingsRecognitionRenderMultiSelect($prefix . '_user_ids', (array)($options['users'] ?? []), (array)($scope['user_ids'] ?? []), 'Individual Users');
}

$pageTitle = 'Settings';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <div class="h3 mb-1">System Settings</div>
            <div class="text-muted small">Manage global application configurations and integrations</div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/admin/access-management'); ?>">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-semibold">Access Management</div>
                            <div class="text-muted small">Roles, permissions, templates</div>
                        </div>
                        <div class="text-primary fs-3"><i class="bi bi-shield-check"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-4">
            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/notifications/notifications'); ?>">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-semibold">Notification Center</div>
                            <div class="text-muted small">Unread, warnings, history</div>
                        </div>
                        <div class="text-danger fs-3"><i class="bi bi-bell"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-4">
            <a class="text-decoration-none" href="<?php echo htmlspecialchars(appBasePath() . '/modules/leads/export'); ?>">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-semibold">Export Center</div>
                            <div class="text-muted small">Leads, campaigns, reports</div>
                        </div>
                        <div class="text-success fs-3"><i class="bi bi-file-earmark-excel"></i></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-2 fs-5"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <style>
      .settings-shell .nav-pills .nav-link{border-radius:12px}
      .settings-shell .nav-pills .nav-link.active{background:var(--app-primary);border-color:var(--app-primary)}
      .settings-shell .settings-nav-search{border-radius:12px}
      .settings-shell .card-header{border-top-left-radius:14px;border-top-right-radius:14px}
      .settings-shell .card{border-radius:14px}
      .settings-shell .settings-note{background:rgba(0,0,0,.02);border:1px solid var(--app-border);border-radius:14px;padding:12px 14px}
      .settings-shell .recognition-module-card{border:1px solid var(--app-border)}
      .settings-shell .recognition-module-card .card-header{background:rgba(14,165,233,.08)}
      .settings-shell .recognition-announcement-body{min-height:120px}
      .settings-shell .recognition-multiselect{position:relative;width:100%}
      .settings-shell .recognition-multiselect-toggle{min-height:38px;border-radius:10px;background:#fff}
      .settings-shell .recognition-multiselect-menu{position:absolute;top:calc(100% + 4px);left:0;right:auto;transform:none!important;inset:auto auto auto 0!important;width:100%;min-width:100%;max-width:100%;max-height:260px;overflow:hidden;border-radius:12px;z-index:1080}
      .settings-shell .recognition-multiselect-options{max-height:200px;overflow:auto}
      .settings-shell .recognition-multiselect-option{display:flex;align-items:flex-start;gap:10px;padding:8px 10px;border-radius:10px;cursor:pointer}
      .settings-shell .recognition-multiselect-option:hover{background:rgba(14,165,233,.08)}
      .settings-shell .recognition-multiselect-option .form-check-input{margin-top:2px}
      .settings-shell .recognition-multiselect-option .small{display:block;min-width:0;white-space:normal;word-break:break-word}
      .settings-shell .recognition-multiselect.is-disabled .recognition-multiselect-toggle{background:var(--bs-secondary-bg);opacity:.7;pointer-events:none}
      .settings-shell .recognition-multiselect-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:calc(100% - 24px)}
      [data-bs-theme="dark"] .settings-shell .settings-note{background:rgba(255,255,255,.03)}
      [data-bs-theme="dark"] .settings-shell .recognition-module-card .card-header{background:rgba(14,165,233,.12)}
      [data-bs-theme="dark"] .settings-shell .recognition-multiselect-toggle{background:rgba(255,255,255,.04)}
      [data-bs-theme="dark"] .settings-shell .recognition-multiselect-option:hover{background:rgba(14,165,233,.14)}
    </style>

    <div class="row settings-shell">
        <!-- Sidebar Navigation -->
        <div class="col-md-3 mb-4">
            <div class="card hr-card sticky-top" style="top: 1rem; z-index: 10;">
                <div class="card-body p-2">
                    <div class="px-2 pt-2 pb-1">
                        <input class="form-control form-control-sm settings-nav-search" id="settingsNavSearch" placeholder="Search settings...">
                    </div>
                    <ul class="nav nav-pills flex-column gap-1 mt-1" role="tablist" id="settingsNav">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'security' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-security" type="button" role="tab" data-tab-key="security" aria-selected="<?php echo $activeTab === 'security' ? 'true' : 'false'; ?>">
                                <i class="bi bi-shield-lock me-2"></i>Security
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'ui' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-ui" type="button" role="tab" data-tab-key="ui" aria-selected="<?php echo $activeTab === 'ui' ? 'true' : 'false'; ?>">
                                <i class="bi bi-palette2 me-2"></i>Appearance & UI
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'recognition' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-recognition" type="button" role="tab" data-tab-key="recognition" aria-selected="<?php echo $activeTab === 'recognition' ? 'true' : 'false'; ?>">
                                <i class="bi bi-stars me-2"></i>Recognition Center
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'ops' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-ops" type="button" role="tab" data-tab-key="ops" aria-selected="<?php echo $activeTab === 'ops' ? 'true' : 'false'; ?>">
                                <i class="bi bi-sliders me-2"></i>Operations
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'billing' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-billing" type="button" role="tab" data-tab-key="billing" aria-selected="<?php echo $activeTab === 'billing' ? 'true' : 'false'; ?>">
                                <i class="bi bi-receipt-cutoff me-2"></i>Billing
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'maintenance' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-maintenance" type="button" role="tab" data-tab-key="maintenance" aria-selected="<?php echo $activeTab === 'maintenance' ? 'true' : 'false'; ?>">
                                <i class="bi bi-wrench-adjustable-circle me-2"></i>Maintenance
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-start px-3 py-2 w-100 <?php echo $activeTab === 'ai_transcript' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-ai-transcript" type="button" role="tab" data-tab-key="ai_transcript" aria-selected="<?php echo $activeTab === 'ai_transcript' ? 'true' : 'false'; ?>">
                                <i class="bi bi-robot me-2"></i>AI Transcript Settings
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="col-md-9">
            <div class="tab-content">
        <div class="tab-pane fade <?php echo $activeTab === 'ai_transcript' ? 'show active' : ''; ?>" id="tab-ai-transcript" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-robot me-2 text-primary"></i>AI Transcript Settings</span>
                            <span class="badge bg-primary-subtle text-primary border">Gemini AI</span>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3" id="aiTranscriptSettingsForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_ai_transcript">
                                <input type="hidden" name="tab" value="ai_transcript">

                                <div class="col-12">
                                    <div class="settings-note">
                                        <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Automatic Call Recording Transcription & Formatting</div>
                                        <div class="small text-muted">Configure your Google Gemini AI credentials, dynamic transcript formatting template, word exclusions, replacement rules, and preservation options.</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-check form-switch pt-2">
                                        <input class="form-check-input" type="checkbox" id="qa_ai_enabled" name="qa_ai_enabled" value="1" <?php echo !empty($aiSettings['qa.ai.enabled']) && $aiSettings['qa.ai.enabled'] !== '0' ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="qa_ai_enabled">Enable Automatic AI Transcription</label>
                                    </div>
                                    <div class="small text-muted mt-1">Automatically registers and queues newly uploaded call recordings for background transcription.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Processing Mode</label>
                                    <select class="form-select form-select-sm" name="qa_ai_processing_mode">
                                        <option value="automatic" selected>Automatic on recording upload</option>
                                    </select>
                                    <div class="small text-muted mt-1">Background worker process handles queue asynchronously.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Gemini API Key</label>
                                    <input type="password" class="form-control form-control-sm" name="qa_ai_gemini_api_key" value="<?php echo htmlspecialchars(AIConfig::maskApiKey($aiSettings['qa.ai.gemini_api_key'] ?? '')); ?>" placeholder="AIzaSy...">
                                    <div class="small text-muted mt-1">Credentials are stored securely in database and never exposed to JavaScript.</div>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label small fw-bold">Transcription Model</label>
                                    <select class="form-select form-select-sm" name="qa_ai_transcription_model">
                                        <option value="gemini-1.5-flash" <?php echo ($aiSettings['qa.ai.transcription_model'] ?? '') === 'gemini-1.5-flash' ? 'selected' : ''; ?>>gemini-1.5-flash (Fast & Accurate)</option>
                                        <option value="gemini-2.0-flash" <?php echo ($aiSettings['qa.ai.transcription_model'] ?? '') === 'gemini-2.0-flash' ? 'selected' : ''; ?>>gemini-2.0-flash (Latest Multimodal)</option>
                                        <option value="gemini-1.5-pro" <?php echo ($aiSettings['qa.ai.transcription_model'] ?? '') === 'gemini-1.5-pro' ? 'selected' : ''; ?>>gemini-1.5-pro (High Quality)</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label small fw-bold">Formatting Model</label>
                                    <select class="form-select form-select-sm" name="qa_ai_formatting_model">
                                        <option value="gemini-1.5-flash" <?php echo ($aiSettings['qa.ai.formatting_model'] ?? '') === 'gemini-1.5-flash' ? 'selected' : ''; ?>>gemini-1.5-flash</option>
                                        <option value="gemini-2.0-flash" <?php echo ($aiSettings['qa.ai.formatting_model'] ?? '') === 'gemini-2.0-flash' ? 'selected' : ''; ?>>gemini-2.0-flash</option>
                                        <option value="gemini-1.5-pro" <?php echo ($aiSettings['qa.ai.formatting_model'] ?? '') === 'gemini-1.5-pro' ? 'selected' : ''; ?>>gemini-1.5-pro</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Output Language</label>
                                    <input type="text" class="form-control form-control-sm" name="qa_ai_output_language" value="<?php echo htmlspecialchars($aiSettings['qa.ai.output_language'] ?? 'English'); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Max Retries on Error</label>
                                    <input type="number" class="form-control form-control-sm" name="qa_ai_max_retries" min="1" max="10" value="<?php echo (int)($aiSettings['qa.ai.max_retries'] ?? 3); ?>">
                                </div>

                                <hr class="my-3">

                                <div class="col-12">
                                    <label class="form-label small fw-bold">Transcript Format Prompt Template</label>
                                    <textarea class="form-control font-monospace small" name="qa_ai_transcript_prompt" rows="5" placeholder="Define exactly how the final transcript should be structured..."><?php echo htmlspecialchars($aiSettings['qa.ai.transcript_prompt'] ?? ''); ?></textarea>
                                    <div class="small text-muted mt-1">Specify layout instructions for sections like Call Summary, Speaker Dialogue, Key Qualification Verification Answers, and Call Outcome.</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Excluded Words / Phrases</label>
                                    <textarea class="form-control font-monospace small" name="qa_ai_exclusions" rows="4" placeholder="um, uh, [coughing]"><?php echo htmlspecialchars($aiSettings['qa.ai.exclusions'] ?? ''); ?></textarea>
                                    <div class="small text-muted mt-1">Comma or line separated list of filler words or acoustic artifacts to exclude.</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Replacement Rules (Detected -> Target)</label>
                                    <textarea class="form-control font-monospace small" name="qa_ai_replacements" rows="4" placeholder="Demand Flow -> DemandFlow Bridge&#10;M Q L -> MQL"><?php echo htmlspecialchars($aiSettings['qa.ai.replacements'] ?? ''); ?></textarea>
                                    <div class="small text-muted mt-1">One rule per line: <code>Detected -> Replacement</code>.</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">Protected Terms / Exceptions</label>
                                    <textarea class="form-control font-monospace small" name="qa_ai_exceptions" rows="4" placeholder="Microsoft Azure, AWS, Power BI, BANT, MQL"><?php echo htmlspecialchars($aiSettings['qa.ai.exceptions'] ?? ''); ?></textarea>
                                    <div class="small text-muted mt-1">Comma-separated terms that must not be altered or rewritten.</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-bold mb-2">Preservation & Attribution Options</label>
                                    <div class="d-flex flex-wrap gap-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="qa_ai_preserve_numbers" name="qa_ai_preserve_numbers" value="1" <?php echo !empty($aiSettings['qa.ai.preserve_numbers']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="qa_ai_preserve_numbers">Preserve Numbers</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="qa_ai_preserve_emails" name="qa_ai_preserve_emails" value="1" <?php echo !empty($aiSettings['qa.ai.preserve_emails']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="qa_ai_preserve_emails">Preserve Email Addresses</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="qa_ai_preserve_phones" name="qa_ai_preserve_phones" value="1" <?php echo !empty($aiSettings['qa.ai.preserve_phones']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="qa_ai_preserve_phones">Preserve Phone Numbers</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="qa_ai_preserve_names" name="qa_ai_preserve_names" value="1" <?php echo !empty($aiSettings['qa.ai.preserve_names']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="qa_ai_preserve_names">Preserve Names</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="qa_ai_speaker_labels" name="qa_ai_speaker_labels" value="1" <?php echo !empty($aiSettings['qa.ai.speaker_labels']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="qa_ai_speaker_labels">Use Speaker Labels (Agent / Prospect)</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 d-flex justify-content-between align-items-center pt-3 border-top">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#testFormatContainer">
                                        <i class="bi bi-vial me-1"></i>Test Formatting
                                    </button>
                                    <button class="btn btn-primary btn-sm px-4 fw-bold" type="submit">
                                        <i class="bi bi-check2-circle me-1"></i>Save AI Settings
                                    </button>
                                </div>
                            </form>

                            <div class="collapse mt-3" id="testFormatContainer">
                                <div class="card card-body bg-light border">
                                    <h6 class="fw-bold mb-2"><i class="bi bi-vial me-1"></i>Test Formatting with Sample Raw Text</h6>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <label class="form-label small">Sample Raw Transcript</label>
                                            <textarea class="form-control form-control-sm font-monospace" id="testSampleInput" rows="5" placeholder="Agent: Hello, um this is John from Demand Flow..."></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small">Formatted Result</label>
                                            <textarea class="form-control form-control-sm font-monospace bg-white" id="testFormatOutput" rows="5" readonly placeholder="Result will appear here..."></textarea>
                                        </div>
                                        <div class="col-12 d-flex justify-content-end">
                                            <button type="button" class="btn btn-sm btn-dark" id="btnRunTestFormat">
                                                <i class="bi bi-play-fill me-1"></i>Run Test Formatting
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade <?php echo $activeTab === 'security' ? 'show active' : ''; ?>" id="tab-security" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-shield-lock me-1"></i>Security</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_global_security">
                                <input type="hidden" name="tab" value="security">
                                <div class="col-12">
                                    <div class="settings-note">
                                        <div class="fw-semibold mb-1"><i class="bi bi-router me-1"></i>Your current request IP</div>
                                        <div class="small text-muted">
                                            Remote: <span class="fw-semibold text-dark"><?php echo htmlspecialchars($detectedRemoteIpCanon !== null ? $detectedRemoteIpCanon : ($detectedRemoteIp !== '' ? $detectedRemoteIp : 'unknown')); ?></span>
                                            <?php if ($detectedXffFirstCanon !== null): ?>
                                                · XFF: <span class="fw-semibold text-dark"><?php echo htmlspecialchars($detectedXffFirstCanon); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            Effective (enforced): <span class="fw-semibold text-dark"><?php echo htmlspecialchars($detectedEffectiveIp !== '' ? $detectedEffectiveIp : 'unknown'); ?></span>
                                            · Tip: If you open via http://localhost, Remote can be 127.0.0.1 or ::1. To test real LAN IP, open via http://YOUR-LAN-IP/leads
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ip_access_enabled" name="ip_access_enabled" value="1" <?php echo $ipEnabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ip_access_enabled">
                                            Enable user IP access control (Live CRM)
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">When enabled, users configured as Static IP will be blocked outside their allowed IP list.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ip_access_trust_xff" name="ip_access_trust_xff" value="1" <?php echo $trustXff ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ip_access_trust_xff">
                                            Trust X-Forwarded-For header (proxy/VPN)
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">Enable only if you are behind a trusted reverse proxy that sets X-Forwarded-For.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ip_access_localhost_bypass" name="ip_access_localhost_bypass" value="1" <?php echo $ipLocalhostBypass ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ip_access_localhost_bypass">
                                            Allow localhost bypass (127.0.0.1 / ::1)
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">Recommended for development. Disable on production if you want strict blocking.</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Bypass roles (CSV)</label>
                                    <input class="form-control form-control-sm" name="ip_access_bypass_roles" value="<?php echo htmlspecialchars($ipBypassRoles); ?>" placeholder="e.g. admin,director">
                                    <div class="text-muted small mt-1">Leave empty to enforce IP rules for all roles (including admin).</div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2 me-1"></i>Save</button>
                                </div>
                            </form>

                            <hr class="my-4">

                            <div class="fw-semibold mb-2">User IP Access</div>
                            <form method="post" class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_user_ip">
                                <input type="hidden" name="tab" value="security">
                                <div class="col-12">
                                    <label class="form-label small text-muted">User</label>
                                    <select class="form-select form-select-sm" name="user_id" onchange="(function(v){var base=<?php echo htmlspecialchars(json_encode(rtrim((string)appBasePath(), '/') . '/modules/admin/settings?tab=security'), ENT_QUOTES, 'UTF-8'); ?>; window.location.href = v ? (base + '&user_id=' + encodeURIComponent(v)) : base; })(this.value)">
                                        <option value="">Select user</option>
                                        <?php foreach ($users as $u): ?>
                                            <?php $uid = (int)($u['id'] ?? 0); ?>
                                            <option value="<?php echo $uid; ?>" <?php echo $uid === $selectedUserId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), (string)($u['role'] ?? ''), (string)($u['job_title'] ?? ''))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted">Mode</label>
                                    <select class="form-select form-select-sm" name="mode" <?php echo $selectedUserId > 0 ? '' : 'disabled'; ?>>
                                        <option value="open" <?php echo (($selectedPolicy['mode'] ?? 'open') === 'open') ? 'selected' : ''; ?>>Open</option>
                                        <option value="static" <?php echo (($selectedPolicy['mode'] ?? 'open') === 'static') ? 'selected' : ''; ?>>Static IP</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small text-muted">Allowed IPs (one per line)</label>
                                    <textarea class="form-control form-control-sm" name="allowed_ips" rows="3" placeholder="e.g. 203.0.113.10&#10;or 203.0.113.0/24&#10;or 192.168.1.*" <?php echo $selectedUserId > 0 ? '' : 'disabled'; ?>><?php echo htmlspecialchars($selectedAllowed); ?></textarea>
                                    <div class="text-muted small mt-1">Supports exact IP, CIDR (x.x.x.x/24), and wildcard (192.168.1.*). If you enter any IPs, the user is enforced as Static IP.</div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit" <?php echo $selectedUserId > 0 ? '' : 'disabled'; ?>><i class="bi bi-save2 me-1"></i>Save User Policy</button>
                                </div>
                            </form>

                            <?php if (!empty($policyRows)): ?>
                                <div class="mt-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="fw-semibold">Recent IP Policies</div>
                                        <div class="text-muted small">Showing latest <?php echo number_format(count($policyRows)); ?></div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>User</th>
                                                    <th style="width:110px;">Mode</th>
                                                    <th style="width:120px;" class="text-center">Allowed</th>
                                                    <th style="width:190px;">Updated</th>
                                                    <th style="width:90px;" class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($policyRows as $pr): ?>
                                                    <?php
                                                        $uid = (int)($pr['user_id'] ?? 0);
                                                        $name = trim((string)($pr['full_name'] ?? ''));
                                                        $role = trim((string)($pr['role'] ?? ''));
                                                        $mode = strtolower(trim((string)($pr['mode'] ?? 'open')));
                                                        if ($mode !== 'static') $mode = 'open';
                                                        $rawAllowed = (string)($pr['allowed_ips'] ?? '');
                                                        $allowedList = parseAllowedIpList($rawAllowed);
                                                        $allowedCount = count($allowedList);
                                                        $updatedAt = (string)($pr['updated_at'] ?? '');
                                                        $label = $name !== '' ? formatUserNameWithRole($name, $role, (string)($pr['job_title'] ?? '')) : ('User #' . $uid);
                                                    ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo htmlspecialchars($label); ?></td>
                                                        <td>
                                                            <?php if ($mode === 'static'): ?>
                                                                <span class="badge text-bg-primary">Static</span>
                                                            <?php else: ?>
                                                                <span class="badge text-bg-secondary">Open</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="badge text-bg-light border"><?php echo number_format($allowedCount); ?></span>
                                                        </td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars($updatedAt !== '' ? $updatedAt : '-'); ?></td>
                                                        <td class="text-end">
                                                            <?php if ($uid > 0): ?>
                                                                <a class="btn btn-sm btn-light border" href="settings.php?tab=security&amp;user_id=<?php echo $uid; ?>">Edit</a>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'ui' ? 'show active' : ''; ?>" id="tab-ui" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-palette2 me-1"></i>Appearance</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_ui">
                                <input type="hidden" name="tab" value="ui">
                                <div class="col-md-6">
                                    <label class="form-label">Default Theme</label>
                                    <select class="form-select form-select-sm" name="ui_theme_default">
                                        <option value="light" <?php echo $uiThemeDefault === 'light' ? 'selected' : ''; ?>>Light</option>
                                        <option value="dark" <?php echo $uiThemeDefault === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                    </select>
                                    <div class="text-muted small mt-1">Applied when user has no theme saved locally.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">User Override</label>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="ui_allow_user_override" name="ui_allow_user_override" value="1" <?php echo $uiAllowOverride ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ui_allow_user_override">Allow users to switch theme on their browser</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Accent Color (Light)</label>
                                    <input class="form-control form-control-sm form-control-color" type="color" name="ui_accent_light" value="<?php echo htmlspecialchars($uiAccentLight); ?>" title="Pick accent color for light theme">
                                    <div class="text-muted small mt-1">Updates neon borders, active rings, and primary buttons for Light theme.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Accent Color (Dark)</label>
                                    <input class="form-control form-control-sm form-control-color" type="color" name="ui_accent_dark" value="<?php echo htmlspecialchars($uiAccentDark); ?>" title="Pick accent color for dark theme">
                                    <div class="text-muted small mt-1">Updates neon borders, active rings, and primary buttons for Dark theme.</div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-bell me-1"></i>Notifications</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_notifications">
                                <input type="hidden" name="tab" value="ui">
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notif_in_app" name="notif_in_app" value="1" <?php echo $notifInApp ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notif_in_app">Enable in-app notifications</label>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="notif_sound" name="notif_sound" value="1" <?php echo $notifSound ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notif_sound">Enable notification sound</label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-sliders me-1"></i>Default Notification Preferences</div>
                        <div class="card-body">
                            <div class="text-muted small mb-3">These defaults are used when a user has not saved their own preferences.</div>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_notification_defaults">
                                <input type="hidden" name="tab" value="ui">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Event</th>
                                                <th style="width:110px;" class="text-center">Enabled</th>
                                                <th style="width:160px;">Delivery</th>
                                                <th style="width:140px;" class="text-center">Popup (Toast)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($notifEventTypes as $type => $label): ?>
                                                <?php
                                                    $d = is_array($notifDefaultPrefs[$type] ?? null) ? $notifDefaultPrefs[$type] : null;
                                                    $enabled = $d ? ((int)($d['enabled'] ?? 1) === 1) : true;
                                                    $mode = $d ? (string)($d['mode'] ?? 'instant') : 'instant';
                                                    if (!in_array($mode, ['instant','digest'], true)) $mode = 'instant';
                                                    $toast = $d ? ((int)($d['toast'] ?? 0) === 1) : in_array($type, ['campaign.end_warning','campaign.pacing_risk','sales.followup_reminder','chat.message','chat.group_message','lead.created','lead.updated','task.assigned','task.comment','task.mention','task.completed','task.rejected','task.overdue','task.escalated','task.deadline.warning.24h','task.deadline.warning.12h','task.deadline.warning.6h','task.deadline.warning.1h'], true);
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars($label); ?><div class="text-muted small"><?php echo htmlspecialchars($type); ?></div></td>
                                                    <td class="text-center">
                                                        <input class="form-check-input" type="checkbox" name="def_enabled[<?php echo htmlspecialchars($type); ?>]" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                                                    </td>
                                                    <td>
                                                        <select class="form-select form-select-sm" name="def_mode[<?php echo htmlspecialchars($type); ?>]">
                                                            <option value="instant" <?php echo $mode === 'instant' ? 'selected' : ''; ?>>Instant</option>
                                                            <option value="digest" <?php echo $mode === 'digest' ? 'selected' : ''; ?>>Digest</option>
                                                        </select>
                                                    </td>
                                                    <td class="text-center">
                                                        <input class="form-check-input" type="checkbox" name="def_toast[<?php echo htmlspecialchars($type); ?>]" value="1" <?php echo $toast ? 'checked' : ''; ?>>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save Defaults</button>
                                </div>
                            </form>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="apply_notification_defaults">
                                <input type="hidden" name="tab" value="ui">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border rounded p-3 bg-light">
                                    <div>
                                        <div class="fw-semibold">Apply defaults to users</div>
                                        <div class="text-muted small">Writes the defaults into user preferences (useful for existing users).</div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="overwrite_existing" name="overwrite_existing" value="1">
                                            <label class="form-check-label" for="overwrite_existing">Overwrite existing</label>
                                        </div>
                                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-upload me-1"></i>Apply</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'recognition' ? 'show active' : ''; ?>" id="tab-recognition" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-stars me-1"></i>Recognition & Announcement Center</div>
                        <div class="card-body">
                            <div class="settings-note mb-3">
                                <div class="fw-semibold mb-1">Manage dashboard recognition in one place</div>
                                <div class="small text-muted">Choose which banner types appear on the dashboard, how they rotate, and which users can see each one.</div>
                            </div>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_recognition_settings">
                                <input type="hidden" name="tab" value="recognition">
                                <div class="col-md-3">
                                    <label class="form-label">Recognition Engine</label>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="recognition_enabled" name="recognition_enabled" value="1" <?php echo !empty($recognitionSettings['enabled']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="recognition_enabled">Enable top-of-dashboard recognition banners</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Display Mode</label>
                                    <select class="form-select form-select-sm" name="recognition_display_mode">
                                        <?php foreach ($recognitionDisplayModes as $modeKey => $modeLabel): ?>
                                            <option value="<?php echo htmlspecialchars($modeKey); ?>" <?php echo (($recognitionSettings['display_mode'] ?? 'carousel') === $modeKey) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($modeLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Rotation Interval (seconds)</label>
                                    <input class="form-control form-control-sm" type="number" min="3" max="20" name="recognition_rotation_seconds" value="<?php echo (int)($recognitionSettings['rotation_seconds'] ?? 6); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Max Cards Per Viewer</label>
                                    <input class="form-control form-control-sm" type="number" min="3" max="24" name="recognition_max_items" value="<?php echo (int)($recognitionSettings['max_items'] ?? 12); ?>">
                                </div>
                                <div class="col-12">
                                    <div class="row g-3">
                                        <?php foreach ($recognitionDefs as $moduleKey => $moduleDef): ?>
                                            <?php $moduleCfg = (array)($recognitionSettings['modules'][$moduleKey] ?? []); ?>
                                            <div class="col-12">
                                                <div class="card recognition-module-card">
                                                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                        <div>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars((string)($moduleDef['label'] ?? $moduleKey)); ?></div>
                                                            <div class="text-muted small">Enable this banner type, set its card limit, and choose who should see it.</div>
                                                        </div>
                                                        <div class="d-flex gap-3 align-items-center">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" id="recognition_module_enabled_<?php echo htmlspecialchars($moduleKey); ?>" name="recognition_module_enabled[<?php echo htmlspecialchars($moduleKey); ?>]" value="1" <?php echo !empty($moduleCfg['enabled']) ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="recognition_module_enabled_<?php echo htmlspecialchars($moduleKey); ?>">Enabled</label>
                                                            </div>
                                                            <div>
                                                                <label class="form-label small text-muted mb-1">Limit</label>
                                                                <input class="form-control form-control-sm" style="width:90px;" type="number" min="1" max="12" name="recognition_module_limit[<?php echo htmlspecialchars($moduleKey); ?>]" value="<?php echo (int)($moduleCfg['limit'] ?? ($moduleDef['default_limit'] ?? 3)); ?>">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="card-body">
                                                        <div class="row g-3 recognition-audience-grid">
                                                            <?php settingsRecognitionRenderScopeFields('recognition_scope_' . $moduleKey, (array)($moduleCfg['scope'] ?? []), $recognitionOptions); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save Recognition Settings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4">
                    <div class="card hr-card h-100">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-cpu me-1"></i>Automation</div>
                        <div class="card-body">
                            <div class="settings-note mb-3">
                                <div class="fw-semibold mb-1">Live generation and scheduled announcements</div>
                                <div class="small text-muted">Performance, birthday, anniversary, and achievement cards are generated from current CRM data at render time. The runner stores a visibility summary for monitoring and cron validation.</div>
                            </div>
                            <div class="small text-muted mb-2">Last run</div>
                            <?php if (!empty($recognitionLastRun)): ?>
                                <div class="border rounded p-3 mb-3 bg-light">
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($recognitionLastRun['ran_at'] ?? '')); ?></div>
                                    <div class="small text-muted mt-1">Users scanned: <?php echo number_format((int)($recognitionLastRun['users_scanned'] ?? 0)); ?> | Cards visible: <?php echo number_format((int)($recognitionLastRun['cards_visible'] ?? 0)); ?></div>
                                    <?php if (!empty($recognitionLastRun['types']) && is_array($recognitionLastRun['types'])): ?>
                                        <div class="small text-muted mt-2">
                                            <?php foreach ($recognitionLastRun['types'] as $type => $count): ?>
                                                <span class="badge bg-light text-dark border me-1 mb-1"><?php echo htmlspecialchars((string)$type); ?>: <?php echo number_format((int)$count); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small mb-3">The runner has not been executed yet.</div>
                            <?php endif; ?>
                            <form method="post" class="d-grid gap-2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="run_recognition_automation">
                                <input type="hidden" name="tab" value="recognition">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-play-circle me-1"></i>Run Automation Now</button>
                            </form>
                            <div class="d-grid gap-2 mt-2">
                                <a class="btn btn-light border btn-sm" href="recognition-run.php"><i class="bi bi-box-arrow-up-right me-1"></i>Open Runner Page</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-8">
                    <div class="card hr-card h-100">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-megaphone me-1"></i>Company Announcements</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_recognition_announcement">
                                <input type="hidden" name="tab" value="recognition">
                                <input type="hidden" name="announcement_id" value="<?php echo (int)($recognitionAnnouncementForm['id'] ?? 0); ?>">
                                <div class="col-md-6">
                                    <label class="form-label">Title</label>
                                    <input class="form-control form-control-sm" name="announcement_title" maxlength="180" value="<?php echo htmlspecialchars((string)($recognitionAnnouncementForm['title'] ?? '')); ?>" placeholder="Enter announcement title">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Label</label>
                                    <input class="form-control form-control-sm" name="announcement_label" maxlength="80" value="<?php echo htmlspecialchars((string)($recognitionAnnouncementForm['label'] ?? '')); ?>" placeholder="Optional badge">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Priority</label>
                                    <select class="form-select form-select-sm" name="announcement_priority">
                                        <?php foreach (['critical' => 'Critical', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $priorityKey => $priorityLabel): ?>
                                            <option value="<?php echo htmlspecialchars($priorityKey); ?>" <?php echo (($recognitionAnnouncementForm['priority'] ?? 'normal') === $priorityKey) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($priorityLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Message</label>
                                    <textarea class="form-control form-control-sm recognition-announcement-body" name="announcement_body" rows="4" placeholder="Announcement details"><?php echo htmlspecialchars((string)($recognitionAnnouncementForm['body'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Start Date</label>
                                    <input class="form-control form-control-sm" type="datetime-local" name="announcement_start_at" value="<?php echo htmlspecialchars(function_exists('dfRecognitionFormatDateTimeLocal') ? dfRecognitionFormatDateTimeLocal((string)($recognitionAnnouncementForm['start_at'] ?? '')) : ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">End Date</label>
                                    <input class="form-control form-control-sm" type="datetime-local" name="announcement_end_at" value="<?php echo htmlspecialchars(function_exists('dfRecognitionFormatDateTimeLocal') ? dfRecognitionFormatDateTimeLocal((string)($recognitionAnnouncementForm['end_at'] ?? '')) : ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Status</label>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="announcement_is_active" name="announcement_is_active" value="1" <?php echo !empty($recognitionAnnouncementForm['is_active']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="announcement_is_active">Active and eligible for rotation</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="fw-semibold mb-2">Visibility</div>
                                    <div class="row g-3 recognition-audience-grid">
                                        <?php settingsRecognitionRenderScopeFields('announcement_scope', (array)($recognitionAnnouncementForm['scope'] ?? []), $recognitionOptions); ?>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end gap-2">
                                    <?php if ((int)($recognitionAnnouncementForm['id'] ?? 0) > 0): ?>
                                        <a class="btn btn-light border btn-sm" href="settings.php?tab=recognition"><i class="bi bi-x-circle me-1"></i>Cancel Edit</a>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i><?php echo (int)($recognitionAnnouncementForm['id'] ?? 0) > 0 ? 'Update Announcement' : 'Create Announcement'; ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-list-task me-1"></i>Scheduled Announcement Queue</div>
                        <div class="card-body">
                            <?php if (!empty($recognitionAnnouncements)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Announcement</th>
                                                <th style="width:120px;">Priority</th>
                                                <th>Visibility</th>
                                                <th style="width:190px;">Schedule</th>
                                                <th style="width:120px;">Status</th>
                                                <th style="width:170px;" class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recognitionAnnouncements as $announcement): ?>
                                                <?php
                                                    $annId = (int)($announcement['id'] ?? 0);
                                                    $annPriority = ucfirst((string)($announcement['priority'] ?? 'normal'));
                                                    $annStart = trim((string)($announcement['start_at'] ?? ''));
                                                    $annEnd = trim((string)($announcement['end_at'] ?? ''));
                                                    $annScopeSummary = function_exists('dfRecognitionScopeSummary')
                                                        ? dfRecognitionScopeSummary((array)($announcement['scope'] ?? []))
                                                        : 'All users';
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($announcement['title'] ?? '')); ?></div>
                                                        <div class="text-muted small"><?php echo htmlspecialchars((string)($announcement['body'] ?? '')); ?></div>
                                                    </td>
                                                    <td><span class="badge text-bg-light border"><?php echo htmlspecialchars($annPriority); ?></span></td>
                                                    <td class="text-muted small"><?php echo htmlspecialchars($annScopeSummary); ?></td>
                                                    <td class="text-muted small">
                                                        <?php echo $annStart !== '' ? htmlspecialchars(date('d M Y H:i', strtotime($annStart))) : 'Start: immediate'; ?><br>
                                                        <?php echo $annEnd !== '' ? htmlspecialchars('End: ' . date('d M Y H:i', strtotime($annEnd))) : 'End: open'; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($announcement['is_active'])): ?>
                                                            <span class="badge text-bg-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge text-bg-secondary">Disabled</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-1">
                                                            <a class="btn btn-sm btn-light border" href="settings.php?tab=recognition&amp;announcement_id=<?php echo $annId; ?>">Edit</a>
                                                            <form method="post" onsubmit="return confirm('Delete this announcement?');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                                <input type="hidden" name="action" value="delete_recognition_announcement">
                                                                <input type="hidden" name="tab" value="recognition">
                                                                <input type="hidden" name="announcement_id" value="<?php echo $annId; ?>">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">No company announcements have been created yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'ops' ? 'show active' : ''; ?>" id="tab-ops" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-clock-history me-1"></i>Attendance Policy</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_attendance_policy">
                                <input type="hidden" name="tab" value="ops">
                                <div class="col-md-4">
                                    <label class="form-label">Late Buffer (minutes)</label>
                                    <input class="form-control form-control-sm" type="number" name="attendance_grace_minutes" value="<?php echo (int)($attendancePolicy['grace_minutes'] ?? 10); ?>" min="0" max="120">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Half Day at (late count)</label>
                                    <input class="form-control form-control-sm" type="number" name="attendance_late_halfday_at" value="<?php echo (int)($attendancePolicy['late_halfday_at'] ?? 3); ?>" min="1" max="31">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Absent at (late count)</label>
                                    <input class="form-control form-control-sm" type="number" name="attendance_late_absent_at" value="<?php echo (int)($attendancePolicy['late_absent_at'] ?? 4); ?>" min="1" max="31">
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Lead Lifecycle</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_lead_rules">
                                <input type="hidden" name="tab" value="ops">
                                <div class="col-12">
                                    <label class="form-label">QA Statuses (CSV or lines)</label>
                                    <textarea class="form-control form-control-sm" name="qa_statuses" rows="2" placeholder="Pending, Qualified, Disqualified"><?php echo htmlspecialchars($leadQaStatuses); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Delivery Statuses (CSV or lines)</label>
                                    <textarea class="form-control form-control-sm" name="delivery_statuses" rows="2" placeholder="Pending, Delivered"><?php echo htmlspecialchars($leadDeliveryStatuses); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Defaults (free text)</label>
                                    <textarea class="form-control form-control-sm" name="lead_defaults" rows="2" placeholder="Define default workflow rules"><?php echo htmlspecialchars($leadDefaults); ?></textarea>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'billing' ? 'show active' : ''; ?>" id="tab-billing" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-receipt-cutoff me-1"></i>Invoice Numbering</div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="save_invoice_numbering">
                                <input type="hidden" name="tab" value="billing">
                                <div class="col-md-4">
                                    <label class="form-label">Mode</label>
                                    <select class="form-select form-select-sm" name="invoice_numbering_mode">
                                        <option value="sequence" <?php echo $invMode === 'sequence' ? 'selected' : ''; ?>>Sequence</option>
                                        <option value="legacy" <?php echo $invMode === 'legacy' ? 'selected' : ''; ?>>Legacy</option>
                                    </select>
                                    <div class="text-muted small mt-1">Sequence uses app settings counter.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Prefix</label>
                                    <input class="form-control form-control-sm" name="invoice_prefix" value="<?php echo htmlspecialchars((string)($invSet['prefix'] ?? 'INV')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Separator</label>
                                    <input class="form-control form-control-sm" name="invoice_separator" value="<?php echo htmlspecialchars((string)($invSet['separator'] ?? '-')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Padding</label>
                                    <input class="form-control form-control-sm" type="number" name="invoice_padding" value="<?php echo (int)($invSet['padding'] ?? 4); ?>" min="1" max="12">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Date Format</label>
                                    <input class="form-control form-control-sm" name="invoice_date_format" value="<?php echo htmlspecialchars((string)($invSet['date_format'] ?? 'Ym')); ?>">
                                    <div class="text-muted small mt-1">PHP date() format, e.g. Ym, Y-m.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Reset Monthly</label>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="invoice_reset_monthly" name="invoice_reset_monthly" value="1" <?php echo !empty($invSet['reset_monthly']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="invoice_reset_monthly">Reset counter each month</label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-save2 me-1"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'maintenance' ? 'show active' : ''; ?>" id="tab-maintenance" role="tabpanel">
            <div class="row g-3">
                <div class="col-12">
                    <div class="card hr-card">
                        <div class="card-header bg-light fw-semibold"><i class="bi bi-wrench-adjustable-circle me-1"></i>Maintenance</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="settings-note">
                                        <div class="fw-semibold mb-1">Cache / Temp Summary</div>
                                        <div class="small text-muted d-flex flex-wrap gap-2">
                                            <span class="badge bg-light text-dark border">Sessions: <?php echo number_format($sessionFilesCount); ?></span>
                                            <span class="badge bg-light text-dark border">Temp reports: <?php echo number_format($tmpReportFilesCount); ?></span>
                                            <span class="badge bg-light text-dark border">OPcache: <?php echo $opcacheAvailable ? 'Available' : 'Not available'; ?></span>
                                        </div>
                                        <div class="small text-muted mt-2">Clearing sessions logs out other users. Temp cleanup removes audit files stored in /tmp.</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="settings-note">
                                        <div class="fw-semibold mb-1">Data Reset</div>
                                        <div class="small text-muted mb-2">Delete campaigns/forms/leads data and drop campaign lead tables for clean testing.</div>
                                        <?php if (isAdmin() || hasRole(['director','manager_director','operations_director','operations_manager'])): ?>
                                            <a class="btn btn-outline-danger btn-sm" href="data-reset.php">
                                                <i class="bi bi-exclamation-triangle me-1"></i>Open Data Reset
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Not permitted.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="settings-note">
                                        <div class="fw-semibold mb-1">Access Management</div>
                                        <div class="small text-muted mb-2">Configure role permissions for modules that support permission checks.</div>
                                        <?php if (isAdmin()): ?>
                                            <a class="btn btn-outline-primary btn-sm" href="access-management.php">
                                                <i class="bi bi-shield-lock me-1"></i>Open Access Management
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Not permitted.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="maintenance_clear_cache">
                                        <input type="hidden" name="tab" value="maintenance">
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="clear_tmp_reports" name="clear_tmp_reports" value="1" checked>
                                                <label class="form-check-label" for="clear_tmp_reports">Delete temp report files (tmp/*.md)</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="clear_sessions" name="clear_sessions" value="1">
                                                <label class="form-check-label" for="clear_sessions">Clear session cache (logs out other users)</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="reset_opcache" name="reset_opcache" value="1" <?php echo $opcacheAvailable ? '' : 'disabled'; ?>>
                                                <label class="form-check-label" for="reset_opcache">Reset PHP OPcache</label>
                                            </div>
                                        </div>
                                        <div class="col-12 d-flex justify-content-end">
                                            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash3 me-1"></i>Run Cleanup</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        </div> <!-- End of col-md-9 -->
    </div> <!-- End of row -->
</div>

<script>
(() => {
  const nav = document.getElementById('settingsNav');
  const q = document.getElementById('settingsNavSearch');
  if (nav && q) {
    q.addEventListener('input', () => {
      const term = (q.value || '').trim().toLowerCase();
      nav.querySelectorAll('[data-tab-key]').forEach((btn) => {
        const t = (btn.textContent || '').toLowerCase();
        btn.closest('li').style.display = !term || t.includes(term) ? '' : 'none';
      });
    });
  }

  document.querySelectorAll('[data-tab-key]').forEach((btn) => {
    btn.addEventListener('shown.bs.tab', (e) => {
      const tabKey = e.target && e.target.getAttribute ? e.target.getAttribute('data-tab-key') : '';
      if (!tabKey) return;
      const url = new URL(window.location.href);
      url.searchParams.set('tab', tabKey);
      window.history.replaceState({}, '', url.toString());
    });
  });

  const updateRecognitionMultiselect = (root) => {
    const textEl = root.querySelector('.recognition-multiselect-text');
    const placeholder = root.getAttribute('data-placeholder') || 'Select options';
    const checked = Array.from(root.querySelectorAll('.recognition-multiselect-checkbox:checked'));
    if (!textEl) return;
    if (!checked.length) {
      textEl.textContent = placeholder;
      return;
    }
    if (checked.length <= 2) {
      textEl.textContent = checked.map((el) => el.getAttribute('data-label') || el.value || '').filter(Boolean).join(', ');
      return;
    }
    textEl.textContent = `${checked.length} selected`;
  };

  document.querySelectorAll('[data-recognition-multiselect]').forEach((root) => {
    root.querySelectorAll('.recognition-multiselect-checkbox').forEach((checkbox) => {
      checkbox.addEventListener('change', () => updateRecognitionMultiselect(root));
    });
    updateRecognitionMultiselect(root);
  });

  document.querySelectorAll('.recognition-scope-all').forEach((checkbox) => {
    const syncScope = () => {
      const cardBody = checkbox.closest('.card-body') || checkbox.closest('form') || document;
      cardBody.querySelectorAll('[data-recognition-multiselect]').forEach((el) => {
        el.classList.toggle('is-disabled', checkbox.checked);
      });
      cardBody.querySelectorAll('.recognition-multiselect-checkbox').forEach((el) => {
        el.disabled = checkbox.checked;
      });
    };
    checkbox.addEventListener('change', syncScope);
    syncScope();
  });

  const btnRunTestFormat = document.getElementById('btnRunTestFormat');
  if (btnRunTestFormat) {
    btnRunTestFormat.addEventListener('click', () => {
      const sampleInput = document.getElementById('testSampleInput');
      const formatOutput = document.getElementById('testFormatOutput');
      const form = document.getElementById('aiTranscriptSettingsForm');
      if (!sampleInput || !formatOutput || !form) return;

      const sampleText = (sampleInput.value || '').trim();
      if (!sampleText) {
        alert('Please enter sample raw transcript text.');
        return;
      }

      const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
      const promptTemplate = form.querySelector('textarea[name="qa_ai_transcript_prompt"]')?.value || '';
      const exclusions = form.querySelector('textarea[name="qa_ai_exclusions"]')?.value || '';
      const replacements = form.querySelector('textarea[name="qa_ai_replacements"]')?.value || '';
      const exceptions = form.querySelector('textarea[name="qa_ai_exceptions"]')?.value || '';

      const origHtml = btnRunTestFormat.innerHTML;
      btnRunTestFormat.disabled = true;
      btnRunTestFormat.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Formatting...';
      formatOutput.value = 'Running test formatting via Gemini API...';

      fetch('../qa/transcript_action', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          action: 'test_format',
          csrf_token: csrfToken,
          sample_text: sampleText,
          prompt_template: promptTemplate,
          exclusions: exclusions,
          replacements: replacements,
          exceptions: exceptions
        })
      })
      .then(r => r.json())
      .then(data => {
        btnRunTestFormat.disabled = false;
        btnRunTestFormat.innerHTML = origHtml;
        if (data.success) {
          formatOutput.value = data.formatted_text || '(Empty output)';
        } else {
          formatOutput.value = 'ERROR: ' + (data.error || 'Formatting failed');
        }
      })
      .catch(err => {
        btnRunTestFormat.disabled = false;
        btnRunTestFormat.innerHTML = origHtml;
        formatOutput.value = 'ERROR: Network error sending request to transcript endpoint.';
      });
    });
  }

})();
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
