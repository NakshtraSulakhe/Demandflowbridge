<?php

function dfRecognitionModuleDefinitions(): array
{
    return [
        'daily_incentives' => [
            'label' => 'Daily Incentive Leaderboard',
            'type' => 'people',
            'priority' => 'high',
            'default_limit' => 3,
        ],
        'monthly_champions' => [
            'label' => 'Monthly Champions Leaderboard',
            'type' => 'people',
            'priority' => 'high',
            'default_limit' => 3,
        ],
        'birthdays' => [
            'label' => 'Birthday Celebrations',
            'type' => 'people',
            'priority' => 'normal',
            'default_limit' => 6,
        ],
        'anniversaries' => [
            'label' => 'Work Anniversaries',
            'type' => 'people',
            'priority' => 'normal',
            'default_limit' => 6,
        ],
        'achievements' => [
            'label' => 'Achievement Announcements',
            'type' => 'mixed',
            'priority' => 'high',
            'default_limit' => 4,
        ],
        'company_announcements' => [
            'label' => 'Company Announcements',
            'type' => 'notice',
            'priority' => 'high',
            'default_limit' => 8,
        ],
    ];
}

function dfRecognitionEnsureSchema(): void
{
    static $verified = false;
    if ($verified) {
        return;
    }
    $conn = getDbConnection();
    @$conn->query("CREATE TABLE IF NOT EXISTS recognition_announcements (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        body TEXT NOT NULL,
        label VARCHAR(80) NULL,
        priority VARCHAR(20) NOT NULL DEFAULT 'normal',
        start_at DATETIME NULL,
        end_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        audience_all TINYINT(1) NOT NULL DEFAULT 1,
        departments_json TEXT NULL,
        team_ids_json TEXT NULL,
        roles_json TEXT NULL,
        campaign_ids_json TEXT NULL,
        user_ids_json TEXT NULL,
        created_by INT NOT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_active_window (is_active, start_at, end_at),
        INDEX idx_priority (priority),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $verified = true;
}

function dfRecognitionDefaultSettings(): array
{
    $modules = [];
    foreach (dfRecognitionModuleDefinitions() as $key => $def) {
        $modules[$key] = [
            'enabled' => 1,
            'limit' => (int)($def['default_limit'] ?? 3),
            'scope' => [
                'all' => 1,
                'departments' => [],
                'team_ids' => [],
                'roles' => [],
                'campaign_ids' => [],
                'user_ids' => [],
            ],
        ];
    }

    return [
        'enabled' => 1,
        'display_mode' => 'carousel',
        'rotation_seconds' => 6,
        'max_items' => 12,
        'modules' => $modules,
    ];
}

function dfRecognitionNormalizeTextList(array $values, bool $lower = true): array
{
    $out = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        if ($lower) {
            $value = strtolower($value);
        }
        $out[$value] = $value;
    }
    return array_values($out);
}

function dfRecognitionNormalizeIdList(array $values): array
{
    $out = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function dfRecognitionNormalizeScope(array $scope): array
{
    $roles = [];
    foreach ((array)($scope['roles'] ?? []) as $role) {
        $role = trim((string)$role);
        if ($role === '') {
            continue;
        }
        $roles[] = function_exists('normalizeRole') ? normalizeRole($role) : strtolower($role);
    }

    return [
        'all' => !empty($scope['all']) ? 1 : 0,
        'departments' => dfRecognitionNormalizeTextList((array)($scope['departments'] ?? [])),
        'team_ids' => dfRecognitionNormalizeIdList((array)($scope['team_ids'] ?? [])),
        'roles' => dfRecognitionNormalizeTextList($roles),
        'campaign_ids' => dfRecognitionNormalizeIdList((array)($scope['campaign_ids'] ?? [])),
        'user_ids' => dfRecognitionNormalizeIdList((array)($scope['user_ids'] ?? [])),
    ];
}

function dfRecognitionNormalizeSettings(array $settings): array
{
    $defaults = dfRecognitionDefaultSettings();
    $out = $defaults;

    $out['enabled'] = !empty($settings['enabled']) ? 1 : 0;
    $mode = strtolower(trim((string)($settings['display_mode'] ?? $defaults['display_mode'])));
    if (!in_array($mode, ['slider', 'carousel', 'marquee', 'card_rotation', 'hero'], true)) {
        $mode = $defaults['display_mode'];
    }
    $out['display_mode'] = $mode;

    $rotation = (int)($settings['rotation_seconds'] ?? $defaults['rotation_seconds']);
    if ($rotation < 3) {
        $rotation = 3;
    }
    if ($rotation > 20) {
        $rotation = 20;
    }
    $out['rotation_seconds'] = $rotation;

    $maxItems = (int)($settings['max_items'] ?? $defaults['max_items']);
    if ($maxItems < 3) {
        $maxItems = 3;
    }
    if ($maxItems > 24) {
        $maxItems = 24;
    }
    $out['max_items'] = $maxItems;

    foreach ($defaults['modules'] as $key => $moduleDefaults) {
        $src = is_array($settings['modules'][$key] ?? null) ? $settings['modules'][$key] : [];
        $limit = (int)($src['limit'] ?? $moduleDefaults['limit']);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 12) {
            $limit = 12;
        }
        $out['modules'][$key] = [
            'enabled' => !empty($src['enabled']) ? 1 : 0,
            'limit' => $limit,
            'scope' => dfRecognitionNormalizeScope((array)($src['scope'] ?? $moduleDefaults['scope'])),
        ];
    }

    return $out;
}

function dfRecognitionGetSettings(): array
{
    ensureAppSettingsSchema();
    dfRecognitionEnsureSchema();
    $defaults = dfRecognitionDefaultSettings();
    $raw = (string)(getAppSetting('recognition.center.config', '') ?? '');
    if ($raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return dfRecognitionNormalizeSettings($decoded);
}

function dfRecognitionSaveSettings(array $settings): bool
{
    $normalized = dfRecognitionNormalizeSettings($settings);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }
    return setAppSetting('recognition.center.config', $json);
}

function dfRecognitionScopeFromRequest(array $src, string $prefix): array
{
    return dfRecognitionNormalizeScope([
        'all' => !empty($src[$prefix . '_all']),
        'departments' => (array)($src[$prefix . '_departments'] ?? []),
        'team_ids' => (array)($src[$prefix . '_team_ids'] ?? []),
        'roles' => (array)($src[$prefix . '_roles'] ?? []),
        'campaign_ids' => (array)($src[$prefix . '_campaign_ids'] ?? []),
        'user_ids' => (array)($src[$prefix . '_user_ids'] ?? []),
    ]);
}

function dfRecognitionSettingsFromRequest(array $src): array
{
    $settings = dfRecognitionGetSettings();
    $settings['enabled'] = !empty($src['recognition_enabled']) ? 1 : 0;
    $settings['display_mode'] = (string)($src['recognition_display_mode'] ?? 'carousel');
    $settings['rotation_seconds'] = (int)($src['recognition_rotation_seconds'] ?? 6);
    $settings['max_items'] = (int)($src['recognition_max_items'] ?? 12);
    foreach (array_keys(dfRecognitionModuleDefinitions()) as $key) {
        $settings['modules'][$key] = [
            'enabled' => !empty($src['recognition_module_enabled'][$key]),
            'limit' => (int)($src['recognition_module_limit'][$key] ?? 3),
            'scope' => dfRecognitionScopeFromRequest($src, 'recognition_scope_' . $key),
        ];
    }
    return dfRecognitionNormalizeSettings($settings);
}

function dfRecognitionGetSettingsOptions(): array
{
    ensureDatabaseSchema();
    ensureTeamSchema();
    $conn = getDbConnection();

    $departments = [];
    $rs = $conn->query("SELECT DISTINCT department FROM users WHERE is_active = 1 AND department IS NOT NULL AND TRIM(department) <> '' ORDER BY department");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $value = trim((string)($row['department'] ?? ''));
            if ($value !== '') {
                $departments[] = ['value' => $value, 'label' => $value];
            }
        }
    }

    $roles = [];
    $rs = $conn->query("SELECT DISTINCT role FROM users WHERE is_active = 1 AND role IS NOT NULL AND TRIM(role) <> '' ORDER BY role");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $value = trim((string)($row['role'] ?? ''));
            if ($value !== '') {
                $roles[] = ['value' => $value, 'label' => ucwords(str_replace('_', ' ', $value))];
            }
        }
    }

    $teams = [];
    $rs = $conn->query("SELECT id, team_name FROM teams ORDER BY team_name");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $teams[] = [
                'value' => (int)($row['id'] ?? 0),
                'label' => (string)($row['team_name'] ?? ''),
            ];
        }
    }

    $campaigns = [];
    $rs = $conn->query("SELECT c.id, c.name, d.status FROM campaigns c LEFT JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $status = trim((string)($row['status'] ?? ''));
            $label = (string)($row['name'] ?? '');
            if ($status !== '') {
                $label .= ' [' . $status . ']';
            }
            $campaigns[] = [
                'value' => (int)($row['id'] ?? 0),
                'label' => $label,
            ];
        }
    }

    $users = [];
    $rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE is_active = 1 ORDER BY full_name");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $users[] = [
                'value' => (int)($row['id'] ?? 0),
                'label' => formatUserNameWithRole((string)($row['full_name'] ?? ''), (string)($row['role'] ?? ''), (string)($row['job_title'] ?? '')),
            ];
        }
    }

    return [
        'departments' => $departments,
        'roles' => $roles,
        'teams' => $teams,
        'campaigns' => $campaigns,
        'users' => $users,
    ];
}

function dfRecognitionDecodeJsonList(?string $json, string $type = 'text'): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    if ($type === 'id') {
        return dfRecognitionNormalizeIdList($decoded);
    }
    return dfRecognitionNormalizeTextList($decoded);
}

function dfRecognitionNormalizeDateTimeValue(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    $tz = function_exists('opsBusinessTz') ? opsBusinessTz() : new DateTimeZone('UTC');
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s');
}

function dfRecognitionFormatDateTimeLocal(?string $value): string
{
    $normalized = dfRecognitionNormalizeDateTimeValue($value);
    if ($normalized === null) {
        return '';
    }
    $tz = function_exists('opsBusinessTz') ? opsBusinessTz() : new DateTimeZone('UTC');
    $dt = new DateTimeImmutable($normalized, $tz);
    return $dt->format('Y-m-d\TH:i');
}

function dfRecognitionAnnouncementPayloadFromRequest(array $src): array
{
    return [
        'title' => trim((string)($src['announcement_title'] ?? '')),
        'body' => trim((string)($src['announcement_body'] ?? '')),
        'label' => trim((string)($src['announcement_label'] ?? '')),
        'priority' => trim((string)($src['announcement_priority'] ?? 'normal')),
        'start_at' => dfRecognitionNormalizeDateTimeValue((string)($src['announcement_start_at'] ?? '')),
        'end_at' => dfRecognitionNormalizeDateTimeValue((string)($src['announcement_end_at'] ?? '')),
        'is_active' => !empty($src['announcement_is_active']) ? 1 : 0,
        'scope' => dfRecognitionScopeFromRequest($src, 'announcement_scope'),
    ];
}

function dfRecognitionScopeSummary(array $scope): string
{
    $scope = dfRecognitionNormalizeScope($scope);
    if (!empty($scope['all'])) {
        return 'All users';
    }
    $parts = [];
    if (!empty($scope['departments'])) {
        $parts[] = 'Departments (' . count($scope['departments']) . ')';
    }
    if (!empty($scope['team_ids'])) {
        $parts[] = 'Teams (' . count($scope['team_ids']) . ')';
    }
    if (!empty($scope['roles'])) {
        $parts[] = 'Roles (' . count($scope['roles']) . ')';
    }
    if (!empty($scope['campaign_ids'])) {
        $parts[] = 'Campaigns (' . count($scope['campaign_ids']) . ')';
    }
    if (!empty($scope['user_ids'])) {
        $parts[] = 'Users (' . count($scope['user_ids']) . ')';
    }
    return empty($parts) ? 'No audience selected' : implode(' | ', $parts);
}

function dfRecognitionLastRunSummary(): array
{
    $raw = (string)(getAppSetting('recognition.center.last_run', '') ?? '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function dfRecognitionSaveAnnouncement(array $payload, int $actorUserId, int $announcementId = 0): bool
{
    dfRecognitionEnsureSchema();
    $conn = getDbConnection();

    $title = trim((string)($payload['title'] ?? ''));
    $body = trim((string)($payload['body'] ?? ''));
    if ($title === '' || $body === '') {
        return false;
    }

    $priority = strtolower(trim((string)($payload['priority'] ?? 'normal')));
    if (!in_array($priority, ['critical', 'high', 'normal', 'low'], true)) {
        $priority = 'normal';
    }

    $label = trim((string)($payload['label'] ?? ''));
    $label = $label !== '' ? substr($label, 0, 80) : null;
    $startAt = dfRecognitionNormalizeDateTimeValue((string)($payload['start_at'] ?? ''));
    $endAt = dfRecognitionNormalizeDateTimeValue((string)($payload['end_at'] ?? ''));
    $isActive = !empty($payload['is_active']) ? 1 : 0;
    $scope = dfRecognitionNormalizeScope((array)($payload['scope'] ?? []));

    $deptJson = json_encode($scope['departments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $teamJson = json_encode($scope['team_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $roleJson = json_encode($scope['roles'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $campaignJson = json_encode($scope['campaign_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $userJson = json_encode($scope['user_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($announcementId > 0) {
        $stmt = $conn->prepare("UPDATE recognition_announcements
            SET title = ?, body = ?, label = ?, priority = ?, start_at = ?, end_at = ?, is_active = ?, audience_all = ?,
                departments_json = ?, team_ids_json = ?, roles_json = ?, campaign_ids_json = ?, user_ids_json = ?,
                updated_by = ?, updated_at = NOW()
            WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $all = (int)$scope['all'];
        $stmt->bind_param(
            'ssssssiisssssii',
            $title,
            $body,
            $label,
            $priority,
            $startAt,
            $endAt,
            $isActive,
            $all,
            $deptJson,
            $teamJson,
            $roleJson,
            $campaignJson,
            $userJson,
            $actorUserId,
            $announcementId
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $conn->prepare("INSERT INTO recognition_announcements
        (title, body, label, priority, start_at, end_at, is_active, audience_all, departments_json, team_ids_json, roles_json, campaign_ids_json, user_ids_json, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) {
        return false;
    }
    $all = (int)$scope['all'];
    $stmt->bind_param(
        'ssssssiisssssi',
        $title,
        $body,
        $label,
        $priority,
        $startAt,
        $endAt,
        $isActive,
        $all,
        $deptJson,
        $teamJson,
        $roleJson,
        $campaignJson,
        $userJson,
        $actorUserId
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function dfRecognitionDeleteAnnouncement(int $announcementId): bool
{
    $announcementId = (int)$announcementId;
    if ($announcementId <= 0) {
        return false;
    }
    dfRecognitionEnsureSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM recognition_announcements WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $announcementId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function dfRecognitionGetAnnouncementById(int $announcementId): ?array
{
    $announcementId = (int)$announcementId;
    if ($announcementId <= 0) {
        return null;
    }
    dfRecognitionEnsureSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM recognition_announcements WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $announcementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    $row['scope'] = [
        'all' => (int)($row['audience_all'] ?? 1),
        'departments' => dfRecognitionDecodeJsonList((string)($row['departments_json'] ?? '')),
        'team_ids' => dfRecognitionDecodeJsonList((string)($row['team_ids_json'] ?? ''), 'id'),
        'roles' => dfRecognitionDecodeJsonList((string)($row['roles_json'] ?? '')),
        'campaign_ids' => dfRecognitionDecodeJsonList((string)($row['campaign_ids_json'] ?? ''), 'id'),
        'user_ids' => dfRecognitionDecodeJsonList((string)($row['user_ids_json'] ?? ''), 'id'),
    ];
    return $row;
}

function dfRecognitionListAnnouncements(): array
{
    dfRecognitionEnsureSchema();
    $conn = getDbConnection();
    $sql = "SELECT ra.*, u.full_name AS created_by_name
        FROM recognition_announcements ra
        LEFT JOIN users u ON u.id = ra.created_by
        ORDER BY
            CASE ra.priority
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'normal' THEN 2
                ELSE 1
            END DESC,
            COALESCE(ra.start_at, ra.created_at) DESC,
            ra.id DESC";
    $rs = $conn->query($sql);
    $rows = $rs ? ($rs->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    foreach ($rows as &$row) {
        $row['scope'] = [
            'all' => (int)($row['audience_all'] ?? 1),
            'departments' => dfRecognitionDecodeJsonList((string)($row['departments_json'] ?? '')),
            'team_ids' => dfRecognitionDecodeJsonList((string)($row['team_ids_json'] ?? ''), 'id'),
            'roles' => dfRecognitionDecodeJsonList((string)($row['roles_json'] ?? '')),
            'campaign_ids' => dfRecognitionDecodeJsonList((string)($row['campaign_ids_json'] ?? ''), 'id'),
            'user_ids' => dfRecognitionDecodeJsonList((string)($row['user_ids_json'] ?? ''), 'id'),
        ];
    }
    unset($row);
    return $rows;
}

function dfRecognitionBuildInternalUserContextMap(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    ensureDatabaseSchema();
    ensureTeamSchema();
    $conn = getDbConnection();

    $rows = [];
    $rs = $conn->query("
        SELECT
            u.id,
            u.full_name,
            u.role,
            u.job_title,
            u.department,
            u.profile_pic,
            u.date_of_joining,
            COALESCE(upd.date_of_birth, u.date_of_birth) AS date_of_birth
        FROM users u
        LEFT JOIN user_personal_details upd ON upd.user_id = u.id
        WHERE u.is_active = 1
          AND (u.client_id IS NULL OR u.client_id = 0)
          AND (u.vendor_id IS NULL OR u.vendor_id = 0)
        ORDER BY u.full_name
    ");
    if ($rs) {
        $rows = $rs->fetch_all(MYSQLI_ASSOC) ?: [];
    }

    $contexts = [];
    $teamUsers = [];
    foreach ($rows as $row) {
        $uid = (int)($row['id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $contexts[$uid] = [
            'user_id' => $uid,
            'full_name' => (string)($row['full_name'] ?? ''),
            'role' => function_exists('normalizeRole')
                ? normalizeRole((string)($row['role'] ?? ''))
                : strtolower((string)($row['role'] ?? '')),
            'job_title' => (string)($row['job_title'] ?? ''),
            'department' => strtolower(trim((string)($row['department'] ?? ''))),
            'department_label' => trim((string)($row['department'] ?? '')),
            'profile_pic' => (string)($row['profile_pic'] ?? ''),
            'date_of_birth' => (string)($row['date_of_birth'] ?? ''),
            'date_of_joining' => (string)($row['date_of_joining'] ?? ''),
            'team_ids' => [],
            'campaign_ids' => [],
        ];
    }

    $rs = $conn->query("SELECT team_id, user_id FROM team_members");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $tid = (int)($row['team_id'] ?? 0);
            if ($uid <= 0 || $tid <= 0 || !isset($contexts[$uid])) {
                continue;
            }
            $contexts[$uid]['team_ids'][$tid] = $tid;
            if (!isset($teamUsers[$tid])) {
                $teamUsers[$tid] = [];
            }
            $teamUsers[$tid][$uid] = $uid;
        }
    }

    $rs = $conn->query("SELECT id, manager_user_id FROM teams");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $uid = (int)($row['manager_user_id'] ?? 0);
            $tid = (int)($row['id'] ?? 0);
            if ($uid <= 0 || $tid <= 0 || !isset($contexts[$uid])) {
                continue;
            }
            $contexts[$uid]['team_ids'][$tid] = $tid;
            if (!isset($teamUsers[$tid])) {
                $teamUsers[$tid] = [];
            }
            $teamUsers[$tid][$uid] = $uid;
        }
    }

    $assignmentQueries = [
        "SELECT user_id, campaign_id FROM operations_campaign_assignments",
        "SELECT user_id, campaign_id FROM qa_campaign_assignments",
        "SELECT user_id, campaign_id FROM campaign_user_assignments",
    ];
    foreach ($assignmentQueries as $sql) {
        $rs = $conn->query($sql);
        if (!$rs) {
            continue;
        }
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            $cid = (int)($row['campaign_id'] ?? 0);
            if ($uid <= 0 || $cid <= 0 || !isset($contexts[$uid])) {
                continue;
            }
            $contexts[$uid]['campaign_ids'][$cid] = $cid;
        }
    }

    $rs = $conn->query("SELECT team_id, campaign_id FROM team_campaigns");
    if ($rs) {
        foreach ($rs->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $tid = (int)($row['team_id'] ?? 0);
            $cid = (int)($row['campaign_id'] ?? 0);
            if ($tid <= 0 || $cid <= 0 || empty($teamUsers[$tid])) {
                continue;
            }
            foreach ($teamUsers[$tid] as $uid) {
                if (isset($contexts[$uid])) {
                    $contexts[$uid]['campaign_ids'][$cid] = $cid;
                }
            }
        }
    }

    foreach ($contexts as &$ctx) {
        $ctx['team_ids'] = array_values($ctx['team_ids']);
        $ctx['campaign_ids'] = array_values($ctx['campaign_ids']);
    }
    unset($ctx);

    $cache = $contexts;
    return $cache;
}

function dfRecognitionViewerContext(int $userId): array
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return [];
    }

    $internal = dfRecognitionBuildInternalUserContextMap();
    if (isset($internal[$userId])) {
        $ctx = $internal[$userId];
        $ctx['is_privileged'] = in_array($ctx['role'], ['admin', 'director', 'manager_director'], true);
        return $ctx;
    }

    ensureDatabaseSchema();
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, full_name, role, job_title, department, profile_pic FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (empty($row)) {
        return [];
    }

    $role = function_exists('normalizeRole')
        ? normalizeRole((string)($row['role'] ?? ''))
        : strtolower((string)($row['role'] ?? ''));
    $campaignMap = function_exists('getUserCampaignAccessMapForNotifications')
        ? getUserCampaignAccessMapForNotifications($userId)
        : [];

    return [
        'user_id' => $userId,
        'full_name' => (string)($row['full_name'] ?? ''),
        'role' => $role,
        'job_title' => (string)($row['job_title'] ?? ''),
        'department' => strtolower(trim((string)($row['department'] ?? ''))),
        'department_label' => trim((string)($row['department'] ?? '')),
        'profile_pic' => (string)($row['profile_pic'] ?? ''),
        'team_ids' => function_exists('getUserTeamIds') ? getUserTeamIds($userId) : [],
        'campaign_ids' => $campaignMap === null ? [] : array_map('intval', array_keys((array)$campaignMap)),
        'is_privileged' => in_array($role, ['admin', 'director', 'manager_director'], true),
    ];
}

function dfRecognitionContextMatchesScope(array $ctx, array $scope, bool $allowPrivilegedBypass = false): bool
{
    if (empty($ctx)) {
        return false;
    }
    $scope = dfRecognitionNormalizeScope($scope);
    if ($allowPrivilegedBypass && !empty($ctx['is_privileged'])) {
        return true;
    }
    if (!empty($scope['all'])) {
        return true;
    }

    $uid = (int)($ctx['user_id'] ?? 0);
    if ($uid > 0 && in_array($uid, $scope['user_ids'], true)) {
        return true;
    }

    $role = strtolower(trim((string)($ctx['role'] ?? '')));
    if ($role !== '' && in_array($role, $scope['roles'], true)) {
        return true;
    }

    $department = strtolower(trim((string)($ctx['department'] ?? '')));
    if ($department !== '' && in_array($department, $scope['departments'], true)) {
        return true;
    }

    foreach ((array)($ctx['team_ids'] ?? []) as $teamId) {
        if (in_array((int)$teamId, $scope['team_ids'], true)) {
            return true;
        }
    }

    foreach ((array)($ctx['campaign_ids'] ?? []) as $campaignId) {
        if (in_array((int)$campaignId, $scope['campaign_ids'], true)) {
            return true;
        }
    }

    return false;
}

function dfRecognitionSubjectAllowed(array $scope, array $subjectCtx, ?int $campaignId = null): bool
{
    if (!empty($scope['all'])) {
        return true;
    }
    if (!empty($scope['campaign_ids']) && $campaignId !== null && in_array((int)$campaignId, $scope['campaign_ids'], true)) {
        return true;
    }
    return dfRecognitionContextMatchesScope($subjectCtx, $scope, false);
}

function dfRecognitionFilterContextIds(array $contexts, array $scope, ?callable $extra = null): array
{
    $ids = [];
    foreach ($contexts as $uid => $ctx) {
        if (!dfRecognitionSubjectAllowed($scope, $ctx)) {
            continue;
        }
        if ($extra && !$extra($ctx)) {
            continue;
        }
        $ids[] = (int)$uid;
    }
    return $ids;
}

function dfRecognitionScopedCampaignIds(array $contexts, array $scope, ?callable $extra = null): array
{
    $scope = dfRecognitionNormalizeScope($scope);
    if (!empty($scope['all'])) {
        return [];
    }
    if (!empty($scope['campaign_ids'])) {
        return array_values(array_unique(array_map('intval', (array)$scope['campaign_ids'])));
    }

    $campaignIds = [];
    foreach ($contexts as $ctx) {
        if (!dfRecognitionSubjectAllowed($scope, (array)$ctx)) {
            continue;
        }
        if ($extra && !$extra((array)$ctx)) {
            continue;
        }
        foreach ((array)($ctx['campaign_ids'] ?? []) as $campaignId) {
            $campaignId = (int)$campaignId;
            if ($campaignId > 0) {
                $campaignIds[$campaignId] = true;
            }
        }
    }

    return array_map('intval', array_keys($campaignIds));
}

function dfRecognitionProfileUrl(?string $profilePic): string
{
    $profilePic = trim((string)$profilePic);
    if ($profilePic === '') {
        return '';
    }
    return appBasePath() . '/' . ltrim($profilePic, '/');
}

function dfRecognitionPriorityWeight(string $priority): int
{
    $priority = strtolower(trim($priority));
    if ($priority === 'critical') {
        return 400;
    }
    if ($priority === 'high') {
        return 300;
    }
    if ($priority === 'normal') {
        return 200;
    }
    return 100;
}

function dfRecognitionBuildCard(array $item): array
{
    $item['priority_weight'] = dfRecognitionPriorityWeight((string)($item['priority'] ?? 'normal'));
    $item['sort_at'] = (string)($item['sort_at'] ?? appNowUtc());
    $item['chips'] = array_values(array_filter(array_map('trim', (array)($item['chips'] ?? [])), fn($v) => $v !== ''));
    $item['initial'] = trim((string)($item['initial'] ?? ''));
    if ($item['initial'] === '') {
        $title = trim((string)($item['title'] ?? 'A'));
        $item['initial'] = strtoupper(substr($title, 0, 1));
    }
    return $item;
}

function dfRecognitionDailyItems(array $settings, array $viewerCtx, array $contexts): array
{
    $module = $settings['modules']['daily_incentives'] ?? [];
    if (empty($module['enabled'])) {
        return [];
    }
    if (!dfRecognitionContextMatchesScope($viewerCtx, (array)($module['scope'] ?? []), true)) {
        return [];
    }
    if (str_starts_with((string)($viewerCtx['role'] ?? ''), 'client_') || str_starts_with((string)($viewerCtx['role'] ?? ''), 'vendor_')) {
        return [];
    }

    $roles = array_flip(function_exists('funneltrackPerformanceAgentRoles') ? funneltrackPerformanceAgentRoles() : ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler']);
    $agentIds = dfRecognitionFilterContextIds($contexts, (array)($module['scope'] ?? []), static function(array $ctx) use ($roles): bool {
        return isset($roles[(string)($ctx['role'] ?? '')]);
    });
    if (empty($agentIds)) {
        return [];
    }

    $cfg = function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : [];
    $range = function_exists('opsTodayRange') ? opsTodayRange() : ['start' => gmdate('Y-m-d 00:00:00'), 'end' => gmdate('Y-m-d 23:59:59'), 'start_date' => gmdate('Y-m-d')];
    $startTs = (string)($range['start'] ?? '');
    $endTs = (string)($range['end'] ?? '');
    $todayDate = (string)($range['start_date'] ?? gmdate('Y-m-d'));
    $limit = max(1, min(10, (int)($module['limit'] ?? 3)));

    $summaries = function_exists('funneltrackComputeAgentRangeSummaries')
        ? funneltrackComputeAgentRangeSummaries($agentIds, $todayDate, $todayDate, $cfg)
        : [];

    $qualifiedMap = [];
    $disqualifiedMap = [];
    $appointmentMap = [];
    $conn = getDbConnection();
    $in = implode(',', array_fill(0, count($agentIds), '?'));
    $stmt = $conn->prepare("
        SELECT
            l.agent_id,
            SUM(CASE WHEN l.qa_status = 'Qualified' THEN 1 ELSE 0 END) AS qualified_total,
            SUM(CASE WHEN l.qa_status = 'Disqualified' THEN 1 ELSE 0 END) AS disqualified_total,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(d.campaign_type, ''))) IN ('appointment generation', 'appointment gen', 'ag') THEN 1 ELSE 0 END) AS appointment_total
        FROM leads l
        JOIN campaign_details d ON d.campaign_id = l.campaign_id
        WHERE l.agent_id IN ($in)
          AND l.qa_status IN ('Qualified', 'Disqualified')
          AND COALESCE(l.qa_updated_at, l.created_at) BETWEEN ? AND ?
        GROUP BY l.agent_id
    ");
    if ($stmt) {
        $types = str_repeat('i', count($agentIds)) . 'ss';
        $params = array_merge($agentIds, [$startTs, $endTs]);
        mysqliBindParams($stmt, $types, $params);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
            $uid = (int)($row['agent_id'] ?? 0);
            $qualifiedMap[$uid] = (int)($row['qualified_total'] ?? 0);
            $disqualifiedMap[$uid] = (int)($row['disqualified_total'] ?? 0);
            $appointmentMap[$uid] = (int)($row['appointment_total'] ?? 0);
        }
        $stmt->close();
    }

    $rows = [];
    foreach ($agentIds as $uid) {
        $ctx = $contexts[$uid] ?? null;
        if (!$ctx) {
            continue;
        }
        $sum = $summaries[$uid] ?? [];
        $productivity = (float)($sum['productivity_pct'] ?? 0.0);
        $incentive = (int)($sum['incentives']['range_total'] ?? 0);
        $qualified = (int)($qualifiedMap[$uid] ?? 0);
        $disqualified = (int)($disqualifiedMap[$uid] ?? 0);
        $scored = $qualified + $disqualified;
        // Only include agents who achieved daily target (productivity >= 100%) and are eligible for incentive (incentive > 0)
        if ($productivity < 100 || $incentive <= 0) {
            continue;
        }
        $rows[] = [
            'user_id' => $uid,
            'productivity' => $productivity,
            'incentive' => $incentive,
            'scored' => $scored,
        ];
    }

    usort($rows, static function(array $a, array $b): int {
        return ($b['incentive'] <=> $a['incentive'])
            ?: ($b['productivity'] <=> $a['productivity'])
            ?: ($b['scored'] <=> $a['scored']);
    });

    $cards = [];
    foreach (array_slice($rows, 0, $limit) as $index => $row) {
        $ctx = $contexts[(int)$row['user_id']] ?? [];
        $cards[] = dfRecognitionBuildCard([
            'type' => 'daily_incentives',
            'priority' => 'high',
            'accent' => 'warning',
            'icon' => 'bi-trophy-fill',
            'badge' => '#' . ($index + 1),
            'eyebrow' => 'Daily Incentive Winner',
            'title' => (string)($ctx['full_name'] ?? ''),
            'subtitle' => trim((string)($ctx['department_label'] ?? '')),
            'message' => number_format((float)$row['productivity'], 1) . '% productivity today with ' . number_format((int)$row['scored']) . ' scored leads and INR ' . number_format((int)$row['incentive']) . ' incentive earned.',
            'chips' => [
                'Productivity: ' . number_format((float)$row['productivity'], 1) . '%',
                'Scored Leads: ' . number_format((int)$row['scored']),
                'Incentive: INR ' . number_format((int)$row['incentive']),
            ],
            'image_url' => dfRecognitionProfileUrl((string)($ctx['profile_pic'] ?? '')),
            'initial' => strtoupper(substr((string)($ctx['full_name'] ?? 'A'), 0, 1)),
            'sort_at' => $todayDate . ' 23:59:59',
        ]);
    }

    return $cards;
}

function dfRecognitionMonthlyItems(array $settings, array $viewerCtx, array $contexts): array
{
    $module = $settings['modules']['monthly_champions'] ?? [];
    if (empty($module['enabled'])) {
        return [];
    }
    if (!dfRecognitionContextMatchesScope($viewerCtx, (array)($module['scope'] ?? []), true)) {
        return [];
    }
    if (str_starts_with((string)($viewerCtx['role'] ?? ''), 'client_') || str_starts_with((string)($viewerCtx['role'] ?? ''), 'vendor_')) {
        return [];
    }

    $roles = array_flip(function_exists('funneltrackPerformanceAgentRoles') ? funneltrackPerformanceAgentRoles() : ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler']);
    $agentIds = dfRecognitionFilterContextIds($contexts, (array)($module['scope'] ?? []), static function(array $ctx) use ($roles): bool {
        return isset($roles[(string)($ctx['role'] ?? '')]);
    });
    if (empty($agentIds)) {
        return [];
    }

    $cfg = function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : [];
    $now = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $year = (int)$now->format('Y');
    $month = (int)$now->format('n');
    $monthStart = $now->format('Y-m-01');
    $monthEnd = $now->format('Y-m-d');
    $limit = max(1, min(10, (int)($module['limit'] ?? 3)));

    $monthSummaries = function_exists('funneltrackComputeAgentMonthSummaries')
        ? funneltrackComputeAgentMonthSummaries($agentIds, $year, $month, $cfg)
        : [];
    $rangeSummaries = function_exists('funneltrackComputeAgentRangeSummaries')
        ? funneltrackComputeAgentRangeSummaries($agentIds, $monthStart, $monthEnd, $cfg)
        : [];

    $rows = [];
    foreach ($agentIds as $uid) {
        $sum = $monthSummaries[$uid] ?? [];
        $productivity = (float)($sum['productivity_pct'] ?? 0.0);
        $dailyTotal = (int)(($rangeSummaries[$uid]['incentives']['range_total'] ?? 0));
        $monthlyBonus = (int)($sum['incentives']['monthly'] ?? 0);
        $totalIncentive = $dailyTotal + $monthlyBonus;
        if ($productivity <= 0 && $totalIncentive <= 0) {
            continue;
        }
        $rows[] = [
            'user_id' => $uid,
            'productivity' => $productivity,
            'total_incentive' => $totalIncentive,
        ];
    }

    usort($rows, static function(array $a, array $b): int {
        return ($b['total_incentive'] <=> $a['total_incentive'])
            ?: ($b['productivity'] <=> $a['productivity']);
    });

    $cards = [];
    foreach (array_slice($rows, 0, $limit) as $index => $row) {
        $ctx = $contexts[(int)$row['user_id']] ?? [];
        $cards[] = dfRecognitionBuildCard([
            'type' => 'monthly_champions',
            'priority' => 'high',
            'accent' => 'primary',
            'icon' => 'bi-award-fill',
            'badge' => '#' . ($index + 1),
            'eyebrow' => 'Monthly Champion',
            'title' => (string)($ctx['full_name'] ?? ''),
            'subtitle' => trim((string)($ctx['department_label'] ?? '')),
            'message' => number_format((float)$row['productivity'], 1) . '% monthly productivity with INR ' . number_format((int)$row['total_incentive']) . ' total incentive earned.',
            'chips' => [
                'Monthly Productivity: ' . number_format((float)$row['productivity'], 1) . '%',
                'Total Incentive: INR ' . number_format((int)$row['total_incentive']),
            ],
            'image_url' => dfRecognitionProfileUrl((string)($ctx['profile_pic'] ?? '')),
            'initial' => strtoupper(substr((string)($ctx['full_name'] ?? 'A'), 0, 1)),
            'sort_at' => $monthEnd . ' 23:59:59',
        ]);
    }

    return $cards;
}

function dfRecognitionBirthdayItems(array $settings, array $viewerCtx, array $contexts): array
{
    $module = $settings['modules']['birthdays'] ?? [];
    if (empty($module['enabled'])) {
        return [];
    }
    if (!dfRecognitionContextMatchesScope($viewerCtx, (array)($module['scope'] ?? []), true)) {
        return [];
    }
    if (str_starts_with((string)($viewerCtx['role'] ?? ''), 'client_') || str_starts_with((string)($viewerCtx['role'] ?? ''), 'vendor_')) {
        return [];
    }

    $today = (new DateTimeImmutable('now', opsBusinessTz()))->format('m-d');
    $limit = max(1, min(12, (int)($module['limit'] ?? 6)));
    $cards = [];
    foreach ($contexts as $ctx) {
        $dob = (string)($ctx['date_of_birth'] ?? '');
        if ($dob === '' || substr($dob, 5, 5) !== $today) {
            continue;
        }
        if (!dfRecognitionSubjectAllowed((array)($module['scope'] ?? []), $ctx)) {
            continue;
        }
        $cards[] = dfRecognitionBuildCard([
            'type' => 'birthdays',
            'priority' => 'normal',
            'accent' => 'success',
            'icon' => 'bi-cake2-fill',
            'badge' => 'Today',
            'eyebrow' => 'Birthday Celebration',
            'title' => (string)($ctx['full_name'] ?? ''),
            'subtitle' => trim((string)($ctx['department_label'] ?? '')),
            'message' => 'Wishing a fantastic day and a year full of wins.',
            'chips' => [
                'Department: ' . ((string)($ctx['department_label'] ?? '') !== '' ? (string)$ctx['department_label'] : 'Team Member'),
                'Birthday: ' . date('d M', strtotime($dob)),
            ],
            'image_url' => dfRecognitionProfileUrl((string)($ctx['profile_pic'] ?? '')),
            'initial' => strtoupper(substr((string)($ctx['full_name'] ?? 'B'), 0, 1)),
            'sort_at' => $dob . ' 00:00:00',
        ]);
    }

    return array_slice($cards, 0, $limit);
}

function dfRecognitionAnniversaryItems(array $settings, array $viewerCtx, array $contexts): array
{
    $module = $settings['modules']['anniversaries'] ?? [];
    if (empty($module['enabled'])) {
        return [];
    }
    if (!dfRecognitionContextMatchesScope($viewerCtx, (array)($module['scope'] ?? []), true)) {
        return [];
    }
    if (str_starts_with((string)($viewerCtx['role'] ?? ''), 'client_') || str_starts_with((string)($viewerCtx['role'] ?? ''), 'vendor_')) {
        return [];
    }

    $today = new DateTimeImmutable('now', opsBusinessTz());
    $todayMd = $today->format('m-d');
    $limit = max(1, min(12, (int)($module['limit'] ?? 6)));
    $cards = [];
    foreach ($contexts as $ctx) {
        $doj = (string)($ctx['date_of_joining'] ?? '');
        if ($doj === '' || substr($doj, 5, 5) !== $todayMd) {
            continue;
        }
        if (!dfRecognitionSubjectAllowed((array)($module['scope'] ?? []), $ctx)) {
            continue;
        }
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $doj, opsBusinessTz());
        if (!$start) {
            continue;
        }
        $years = (int)$start->diff($today)->y;
        if ($years < 1) {
            continue;
        }
        $cards[] = dfRecognitionBuildCard([
            'type' => 'anniversaries',
            'priority' => 'normal',
            'accent' => 'info',
            'icon' => 'bi-stars',
            'badge' => $years . ' Year' . ($years === 1 ? '' : 's'),
            'eyebrow' => 'Work Anniversary',
            'title' => (string)($ctx['full_name'] ?? ''),
            'subtitle' => trim((string)($ctx['department_label'] ?? '')),
            'message' => 'Celebrating ' . $years . ' year' . ($years === 1 ? '' : 's') . ' of commitment and impact.',
            'chips' => [
                'Joining Date: ' . date('d M Y', strtotime($doj)),
                'Completed: ' . $years . ' year' . ($years === 1 ? '' : 's'),
            ],
            'image_url' => dfRecognitionProfileUrl((string)($ctx['profile_pic'] ?? '')),
            'initial' => strtoupper(substr((string)($ctx['full_name'] ?? 'W'), 0, 1)),
            'sort_at' => $doj . ' 00:00:00',
        ]);
    }

    return array_slice($cards, 0, $limit);
}

function dfRecognitionAchievementItems(array $settings, array $viewerCtx, array $contexts): array
{
    $module = $settings['modules']['achievements'] ?? [];
    if (empty($module['enabled'])) {
        return [];
    }
    if (!dfRecognitionContextMatchesScope($viewerCtx, (array)($module['scope'] ?? []), true)) {
        return [];
    }
    if (str_starts_with((string)($viewerCtx['role'] ?? ''), 'client_') || str_starts_with((string)($viewerCtx['role'] ?? ''), 'vendor_')) {
        return [];
    }

    $cfg = function_exists('funneltrackGetConfig') ? funneltrackGetConfig() : [];
    $now = function_exists('opsNowDt') ? opsNowDt() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $year = (int)$now->format('Y');
    $month = (int)$now->format('n');
    $monthStart = $now->format('Y-m-01 00:00:00');
    $monthEnd = $now->format('Y-m-d 23:59:59');
    $monthStartDate = $now->format('Y-m-01');
    $monthEndDate = $now->format('Y-m-d');
    $scope = (array)($module['scope'] ?? []);
    $limit = max(1, min(10, (int)($module['limit'] ?? 4)));

    $cards = [];

    $roles = array_flip(function_exists('funneltrackPerformanceAgentRoles') ? funneltrackPerformanceAgentRoles() : ['agent', 'operations_agent', 'email_marketing_agent', 'email_marketing_executive', 'form_filler']);
    $agentIds = dfRecognitionFilterContextIds($contexts, $scope, static function(array $ctx) use ($roles): bool {
        return isset($roles[(string)($ctx['role'] ?? '')]);
    });
    if (!empty($agentIds) && function_exists('funneltrackComputeAgentMonthSummaries')) {
        $monthSummaries = funneltrackComputeAgentMonthSummaries($agentIds, $year, $month, $cfg);
        $topUserId = 0;
        $topScore = -1.0;
        foreach ($monthSummaries as $uid => $sum) {
            $score = (float)($sum['score']['total'] ?? 0.0);
            if ($score > $topScore) {
                $topScore = $score;
                $topUserId = (int)$uid;
            }
        }
        if ($topUserId > 0 && !empty($contexts[$topUserId])) {
            $ctx = $contexts[$topUserId];
            $sum = $monthSummaries[$topUserId] ?? [];
            $cards[] = dfRecognitionBuildCard([
                'type' => 'achievements',
                'priority' => 'high',
                'accent' => 'primary',
                'icon' => 'bi-patch-check-fill',
                'badge' => 'Employee Of The Month',
                'eyebrow' => 'Achievement Unlocked',
                'title' => (string)($ctx['full_name'] ?? ''),
                'subtitle' => trim((string)($ctx['department_label'] ?? '')),
                'message' => 'Leads the month with a ' . number_format((float)($sum['score']['total'] ?? 0.0), 1) . '/100 score and ' . number_format((float)($sum['productivity_pct'] ?? 0.0), 1) . '% productivity.',
                'chips' => [
                    'Rank: ' . ((string)($sum['rank'] ?? '') !== '' ? (string)$sum['rank'] : '#1'),
                    'Score: ' . number_format((float)($sum['score']['total'] ?? 0.0), 1),
                    'Productivity: ' . number_format((float)($sum['productivity_pct'] ?? 0.0), 1) . '%',
                ],
                'image_url' => dfRecognitionProfileUrl((string)($ctx['profile_pic'] ?? '')),
                'initial' => strtoupper(substr((string)($ctx['full_name'] ?? 'E'), 0, 1)),
                'sort_at' => $monthEndDate . ' 23:59:59',
            ]);
        }
    }

    $conn = getDbConnection();
    if (!empty($agentIds)) {
        $leadPlaceholders = implode(',', array_fill(0, count($agentIds), '?'));
        $stmt = $conn->prepare("
            SELECT l.agent_id, COUNT(*) AS qualified_total
            FROM leads l
            WHERE l.qa_status = 'Qualified'
              AND COALESCE(l.qa_updated_at, l.created_at) BETWEEN ? AND ?
              AND l.agent_id IN ($leadPlaceholders)
            GROUP BY l.agent_id
            ORDER BY qualified_total DESC, l.agent_id ASC
            LIMIT 1
        ");
    } else {
        $stmt = null;
    }
    if ($stmt) {
        $params = array_merge([$monthStart, $monthEnd], $agentIds);
        mysqliBindParams($stmt, 'ss' . str_repeat('i', count($agentIds)), $params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $uid = (int)($row['agent_id'] ?? 0);
        if ($uid > 0 && !empty($contexts[$uid])) {
            $ctx = $contexts[$uid];
            $cards[] = dfRecognitionBuildCard([
                'type' => 'achievements',
                'priority' => 'normal',
                'accent' => 'warning',
                'icon' => 'bi-graph-up-arrow',
                'badge' => 'Lead Achievement',
                'eyebrow' => 'Top Lead Generator',
                'title' => (string)($ctx['full_name'] ?? ''),
                'subtitle' => trim((string)($ctx['department_label'] ?? '')),
                'message' => 'Closed ' . number_format((int)($row['qualified_total'] ?? 0)) . ' qualified leads so far this month.',
                'chips' => [
                    'Qualified Leads: ' . number_format((int)($row['qualified_total'] ?? 0)),
                    'Month: ' . $now->format('M Y'),
                ],
                'image_url' => dfRecognitionProfileUrl((string)($ctx['profile_pic'] ?? '')),
                'initial' => strtoupper(substr((string)($ctx['full_name'] ?? 'L'), 0, 1)),
                'sort_at' => $monthEndDate . ' 23:59:58',
            ]);
        }
    }

    $scopedCampaignIds = dfRecognitionScopedCampaignIds($contexts, $scope);
    $campaignFilterSql = '';
    $campaignFilterParams = [$monthStart, $monthEnd];
    $campaignFilterTypes = 'ss';
    if (empty($scope['all'])) {
        if (empty($scopedCampaignIds)) {
            $stmt = null;
        } else {
            $campaignFilterSql = ' AND ri.campaign_id IN (' . implode(',', array_fill(0, count($scopedCampaignIds), '?')) . ')';
            $campaignFilterParams = array_merge($campaignFilterParams, $scopedCampaignIds);
            $campaignFilterTypes .= str_repeat('i', count($scopedCampaignIds));
            $stmt = $conn->prepare("
                SELECT ri.campaign_id, c.name, COALESCE(SUM(ri.total), 0) AS revenue_total
                FROM revenue_invoices ri
                LEFT JOIN campaigns c ON c.id = ri.campaign_id
                WHERE ri.status = 'Paid'
                  AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) BETWEEN ? AND ?
                  AND ri.campaign_id IS NOT NULL
                  $campaignFilterSql
                GROUP BY ri.campaign_id, c.name
                ORDER BY revenue_total DESC, ri.campaign_id ASC
                LIMIT 1
            ");
        }
    } else {
        $stmt = $conn->prepare("
            SELECT ri.campaign_id, c.name, COALESCE(SUM(ri.total), 0) AS revenue_total
            FROM revenue_invoices ri
            LEFT JOIN campaigns c ON c.id = ri.campaign_id
            WHERE ri.status = 'Paid'
              AND COALESCE(ri.paid_at, ri.updated_at, ri.created_at) BETWEEN ? AND ?
              AND ri.campaign_id IS NOT NULL
            GROUP BY ri.campaign_id, c.name
            ORDER BY revenue_total DESC, ri.campaign_id ASC
            LIMIT 1
        ");
    }
    if ($stmt) {
        mysqliBindParams($stmt, $campaignFilterTypes, $campaignFilterParams);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $campaignId = (int)($row['campaign_id'] ?? 0);
        if ($campaignId > 0) {
            $cards[] = dfRecognitionBuildCard([
                'type' => 'achievements',
                'priority' => 'normal',
                'accent' => 'success',
                'icon' => 'bi-currency-dollar',
                'badge' => 'Revenue Achievement',
                'eyebrow' => 'Top Revenue Campaign',
                'title' => (string)($row['name'] ?? ('Campaign #' . $campaignId)),
                'subtitle' => 'Paid revenue this month',
                'message' => 'Leads the revenue board with INR/USD billed totals equivalent to ' . number_format((float)($row['revenue_total'] ?? 0), 2) . '.',
                'chips' => [
                    'Campaign ID: ' . $campaignId,
                    'Paid Revenue: ' . number_format((float)($row['revenue_total'] ?? 0), 2),
                ],
                'initial' => 'R',
                'sort_at' => $monthEndDate . ' 23:59:57',
            ]);
        }
    }

    if (empty($scope['all'])) {
        if (empty($scopedCampaignIds)) {
            $stmt = null;
        } else {
            $leadCampaignParams = array_merge([$monthStart, $monthEnd], $scopedCampaignIds);
            $leadCampaignTypes = 'ss' . str_repeat('i', count($scopedCampaignIds));
            $stmt = $conn->prepare("
                SELECT l.campaign_id, c.name, COUNT(*) AS qualified_total
                FROM leads l
                LEFT JOIN campaigns c ON c.id = l.campaign_id
                WHERE l.qa_status = 'Qualified'
                  AND COALESCE(l.qa_updated_at, l.created_at) BETWEEN ? AND ?
                  AND l.campaign_id IN (" . implode(',', array_fill(0, count($scopedCampaignIds), '?')) . ")
                GROUP BY l.campaign_id, c.name
                ORDER BY qualified_total DESC, l.campaign_id ASC
                LIMIT 1
            ");
        }
    } else {
        $leadCampaignParams = [$monthStart, $monthEnd];
        $leadCampaignTypes = 'ss';
        $stmt = $conn->prepare("
            SELECT l.campaign_id, c.name, COUNT(*) AS qualified_total
            FROM leads l
            LEFT JOIN campaigns c ON c.id = l.campaign_id
            WHERE l.qa_status = 'Qualified'
              AND COALESCE(l.qa_updated_at, l.created_at) BETWEEN ? AND ?
            GROUP BY l.campaign_id, c.name
            ORDER BY qualified_total DESC, l.campaign_id ASC
            LIMIT 1
        ");
    }
    if ($stmt) {
        mysqliBindParams($stmt, $leadCampaignTypes, $leadCampaignParams);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $campaignId = (int)($row['campaign_id'] ?? 0);
        if ($campaignId > 0) {
            $cards[] = dfRecognitionBuildCard([
                'type' => 'achievements',
                'priority' => 'normal',
                'accent' => 'info',
                'icon' => 'bi-bullseye',
                'badge' => 'Campaign Achievement',
                'eyebrow' => 'Best Performing Campaign',
                'title' => (string)($row['name'] ?? ('Campaign #' . $campaignId)),
                'subtitle' => 'Qualified lead output this month',
                'message' => 'Delivered ' . number_format((int)($row['qualified_total'] ?? 0)) . ' qualified leads in the active month window.',
                'chips' => [
                    'Campaign ID: ' . $campaignId,
                    'Qualified Leads: ' . number_format((int)($row['qualified_total'] ?? 0)),
                ],
                'initial' => 'C',
                'sort_at' => $monthEndDate . ' 23:59:56',
            ]);
        }
    }

    return array_slice($cards, 0, $limit);
}

function dfRecognitionCompanyAnnouncementItems(array $settings, array $viewerCtx): array
{
    $module = $settings['modules']['company_announcements'] ?? [];
    if (empty($module['enabled'])) {
        return [];
    }
    $viewerRole = (string)($viewerCtx['role'] ?? '');
    if (str_starts_with($viewerRole, 'client_') || str_starts_with($viewerRole, 'vendor_')) {
        return [];
    }
    if (!dfRecognitionContextMatchesScope($viewerCtx, (array)($module['scope'] ?? []), true)) {
        return [];
    }

    dfRecognitionEnsureSchema();
    $conn = getDbConnection();
    $nowUtc = appNowUtc();
    $stmt = $conn->prepare("
        SELECT *
        FROM recognition_announcements
        WHERE is_active = 1
          AND (start_at IS NULL OR start_at = '' OR start_at <= ?)
          AND (end_at IS NULL OR end_at = '' OR end_at >= ?)
        ORDER BY
            CASE priority
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'normal' THEN 2
                ELSE 1
            END DESC,
            COALESCE(start_at, created_at) DESC,
            id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ss', $nowUtc, $nowUtc);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();

    $cards = [];
    $limit = max(1, min(12, (int)($module['limit'] ?? 8)));
    foreach ($rows as $row) {
        $scope = [
            'all' => (int)($row['audience_all'] ?? 1),
            'departments' => dfRecognitionDecodeJsonList((string)($row['departments_json'] ?? '')),
            'team_ids' => dfRecognitionDecodeJsonList((string)($row['team_ids_json'] ?? ''), 'id'),
            'roles' => dfRecognitionDecodeJsonList((string)($row['roles_json'] ?? '')),
            'campaign_ids' => dfRecognitionDecodeJsonList((string)($row['campaign_ids_json'] ?? ''), 'id'),
            'user_ids' => dfRecognitionDecodeJsonList((string)($row['user_ids_json'] ?? ''), 'id'),
        ];
        if (!dfRecognitionContextMatchesScope($viewerCtx, $scope, true)) {
            continue;
        }
        $label = trim((string)($row['label'] ?? ''));
        $priority = strtolower(trim((string)($row['priority'] ?? 'normal')));
        $cards[] = dfRecognitionBuildCard([
            'type' => 'company_announcements',
            'priority' => $priority,
            'accent' => $priority === 'critical' ? 'danger' : ($priority === 'high' ? 'primary' : 'secondary'),
            'icon' => 'bi-megaphone-fill',
            'badge' => $label !== '' ? $label : 'Announcement',
            'eyebrow' => 'Company Announcement',
            'title' => (string)($row['title'] ?? ''),
            'subtitle' => $row['start_at'] ? ('Scheduled from ' . date('d M Y', strtotime((string)$row['start_at']))) : 'Live update',
            'message' => (string)($row['body'] ?? ''),
            'chips' => [
                'Priority: ' . ucfirst($priority),
                $row['end_at'] ? ('Ends: ' . date('d M Y', strtotime((string)$row['end_at']))) : 'No expiry',
            ],
            'initial' => 'A',
            'sort_at' => (string)($row['start_at'] ?? $row['created_at'] ?? $nowUtc),
        ]);
        if (count($cards) >= $limit) {
            break;
        }
    }

    return $cards;
}

function dfRecognitionCollectFeed(int $viewerUserId, ?array $settings = null): array
{
    $settings = is_array($settings) ? dfRecognitionNormalizeSettings($settings) : dfRecognitionGetSettings();
    if (empty($settings['enabled'])) {
        return [];
    }

    $viewerCtx = dfRecognitionViewerContext($viewerUserId);
    if (empty($viewerCtx)) {
        return [];
    }

    $contexts = dfRecognitionBuildInternalUserContextMap();
    $cards = array_merge(
        dfRecognitionDailyItems($settings, $viewerCtx, $contexts),
        dfRecognitionMonthlyItems($settings, $viewerCtx, $contexts),
        dfRecognitionBirthdayItems($settings, $viewerCtx, $contexts),
        dfRecognitionAnniversaryItems($settings, $viewerCtx, $contexts),
        dfRecognitionAchievementItems($settings, $viewerCtx, $contexts),
        dfRecognitionCompanyAnnouncementItems($settings, $viewerCtx)
    );

    usort($cards, static function(array $a, array $b): int {
        $cmp = ((int)($b['priority_weight'] ?? 0)) <=> ((int)($a['priority_weight'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string)($b['sort_at'] ?? ''), (string)($a['sort_at'] ?? ''));
    });

    return array_slice($cards, 0, max(1, (int)($settings['max_items'] ?? 12)));
}

function dfRecognitionRenderCard(array $item): string
{
    $imageUrl = trim((string)($item['image_url'] ?? ''));
    $title = trim((string)($item['title'] ?? ''));
    $subtitle = trim((string)($item['subtitle'] ?? ''));
    $message = trim((string)($item['message'] ?? ''));
    $eyebrow = trim((string)($item['eyebrow'] ?? 'Recognition'));
    $badge = trim((string)($item['badge'] ?? ''));
    $icon = trim((string)($item['icon'] ?? 'bi-stars'));
    $accent = trim((string)($item['accent'] ?? 'primary'));
    $initial = trim((string)($item['initial'] ?? 'R'));

    ob_start();
    ?>
    <div class="df-recognition-card df-recognition-<?php echo htmlspecialchars($accent); ?>">
        <div class="df-recognition-media">
            <?php if ($imageUrl !== ''): ?>
                <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($title); ?>">
            <?php else: ?>
                <span><?php echo htmlspecialchars($initial); ?></span>
            <?php endif; ?>
        </div>
        <div class="df-recognition-copy">
            <div class="df-recognition-inline-row">
                <span class="df-recognition-chip df-recognition-chip-icon"><i class="bi <?php echo htmlspecialchars($icon); ?>"></i><?php echo htmlspecialchars($eyebrow); ?></span>
                <?php if ($badge !== ''): ?>
                    <span class="df-recognition-chip"><?php echo htmlspecialchars($badge); ?></span>
                <?php endif; ?>
                <?php if ($title !== ''): ?>
                    <span class="df-recognition-title"><?php echo htmlspecialchars($title); ?></span>
                <?php endif; ?>
                <?php if ($subtitle !== ''): ?>
                    <span class="df-recognition-subtitle"><?php echo htmlspecialchars($subtitle); ?></span>
                <?php endif; ?>
                <?php if ($message !== ''): ?>
                    <span class="df-recognition-message"><?php echo htmlspecialchars($message); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

function dfRecognitionRenderBannerHtml(array $items, array $settings, int $viewerUserId): string
{
    if (empty($items)) {
        return '';
    }

    $mode = (string)($settings['display_mode'] ?? 'carousel');
    $rotationSeconds = max(3, min(20, (int)($settings['rotation_seconds'] ?? 6)));
    $id = 'dfRecognitionCarousel_' . $viewerUserId . '_' . substr(md5((string)json_encode([$mode, count($items), $rotationSeconds])), 0, 8);

    ob_start();
    ?>
    <section class="df-recognition-shell df-recognition-mode-<?php echo htmlspecialchars($mode); ?>">
        <div class="df-recognition-header">
            <div class="df-recognition-eyebrow"><i class="bi bi-stars"></i>Live Recognition & Announcements</div>
            <div class="df-recognition-header-pills">
                <span class="df-recognition-header-pill"><?php echo number_format(count($items)); ?> active cards</span>
                <span class="df-recognition-header-pill">Mode: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $mode))); ?></span>
            </div>
        </div>

        <?php if ($mode === 'marquee'): ?>
            <div class="df-recognition-marquee" aria-label="Recognition ticker">
                <div class="df-recognition-marquee-track">
                    <?php foreach (array_merge($items, $items) as $item): ?>
                        <div class="df-recognition-marquee-item"><?php echo dfRecognitionRenderCard($item); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($mode === 'slider'): ?>
            <div class="df-recognition-slider" tabindex="0" aria-label="Recognition slider">
                <?php foreach ($items as $item): ?>
                    <div class="df-recognition-slider-item"><?php echo dfRecognitionRenderCard($item); ?></div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($mode === 'card_rotation'): ?>
            <div class="df-recognition-rotation" data-recognition-rotation data-interval-ms="<?php echo (int)($rotationSeconds * 1000); ?>">
                <?php foreach ($items as $index => $item): ?>
                    <div class="df-recognition-rotation-item <?php echo $index === 0 ? 'is-active' : ''; ?>"><?php echo dfRecognitionRenderCard($item); ?></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div id="<?php echo htmlspecialchars($id); ?>" class="carousel slide df-recognition-carousel <?php echo $mode === 'hero' ? 'df-recognition-hero' : ''; ?>" data-bs-ride="carousel" data-bs-interval="<?php echo (int)($rotationSeconds * 1000); ?>">
                <?php if (count($items) > 1): ?>
                    <div class="carousel-indicators">
                        <?php foreach ($items as $index => $_item): ?>
                            <button type="button" data-bs-target="#<?php echo htmlspecialchars($id); ?>" data-bs-slide-to="<?php echo $index; ?>" class="<?php echo $index === 0 ? 'active' : ''; ?>" aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-label="Slide <?php echo $index + 1; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="carousel-inner">
                    <?php foreach ($items as $index => $item): ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <?php echo dfRecognitionRenderCard($item); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($items) > 1): ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#<?php echo htmlspecialchars($id); ?>" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#<?php echo htmlspecialchars($id); ?>" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}

function dfRecognitionDashboardBannersHtml(int $viewerUserId): array
{
    $settings = dfRecognitionGetSettings();
    if (empty($settings['enabled'])) {
        return ['html' => '', 'refresh_sec' => 0];
    }
    $items = dfRecognitionCollectFeed($viewerUserId, $settings);
    if (empty($items)) {
        return ['html' => '', 'refresh_sec' => 0];
    }
    return [
        'html' => dfRecognitionRenderBannerHtml($items, $settings, $viewerUserId),
        'refresh_sec' => 0,
    ];
}

function dfRecognitionAutomationRun(): array
{
    $settings = dfRecognitionGetSettings();
    $conn = getDbConnection();
    $summary = [
        'ran_at' => appNowUtc(),
        'settings_enabled' => !empty($settings['enabled']),
        'users_scanned' => 0,
        'cards_visible' => 0,
        'types' => [],
    ];

    $rs = $conn->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id");
    $userIds = $rs ? array_map('intval', array_column($rs->fetch_all(MYSQLI_ASSOC) ?: [], 'id')) : [];
    foreach ($userIds as $userId) {
        $feed = dfRecognitionCollectFeed($userId, $settings);
        $summary['users_scanned']++;
        $summary['cards_visible'] += count($feed);
        foreach ($feed as $item) {
            $type = (string)($item['type'] ?? 'unknown');
            if (!isset($summary['types'][$type])) {
                $summary['types'][$type] = 0;
            }
            $summary['types'][$type]++;
        }
    }

    setAppSetting('recognition.center.last_run', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $summary;
}
