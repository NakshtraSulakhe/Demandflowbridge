<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(['admin']);
ensureCsrfToken();
ensureTeamSchema();

$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
$conn = getDbConnection();

$message = '';
$messageType = 'success';

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editTeam = null;
$editMembers = [];

$loadEdit = function() use (&$editTeam, &$editMembers, $conn, $editId) {
    if ($editId <= 0) return;
    $stmt = $conn->prepare("SELECT * FROM teams WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editTeam = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$editTeam) return;
    $stmt = $conn->prepare("SELECT user_id, member_role FROM team_members WHERE team_id = ?");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid > 0) $editMembers[$uid] = (string)($r['member_role'] ?? 'member');
    }
    $stmt->close();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_team') {
            $tid = (int)($_POST['team_id'] ?? 0);
            if ($tid <= 0) {
                $message = 'Invalid team.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("DELETE FROM team_campaigns WHERE team_id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM team_members WHERE team_id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM teams WHERE id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $message = 'Team deleted.';
                $messageType = 'success';
                $editId = 0;
            }
        } elseif ($action === 'save_team') {
            $tid = (int)($_POST['team_id'] ?? 0);
            $teamName = trim((string)($_POST['team_name'] ?? ''));
            $managerId = (int)($_POST['manager_user_id'] ?? 0);
            $memberIdsRaw = $_POST['member_user_ids'] ?? [];

            if ($teamName === '') {
                $message = 'Team name is required.';
                $messageType = 'danger';
            } elseif ($managerId <= 0) {
                $message = 'Select a team manager.';
                $messageType = 'danger';
            } else {
                if (mb_strlen($teamName) > 120) $teamName = mb_substr($teamName, 0, 120);

                if ($tid > 0) {
                    $stmt = $conn->prepare("UPDATE teams SET team_name = ?, manager_user_id = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('sii', $teamName, $managerId, $tid);
                    $ok = $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO teams (team_name, manager_user_id, created_by, created_at) VALUES (?,?,?,NOW())");
                    $stmt->bind_param('sii', $teamName, $managerId, $userId);
                    $ok = $stmt->execute();
                    $tid = (int)($stmt->insert_id ?? 0);
                    $stmt->close();
                }

                if (!$ok || $tid <= 0) {
                    $message = 'Failed to save team.';
                    $messageType = 'danger';
                } else {
                    $memberIds = [];
                    if (is_array($memberIdsRaw)) {
                        foreach ($memberIdsRaw as $v) {
                            $id = (int)$v;
                            if ($id > 0) $memberIds[$id] = true;
                        }
                    }
                    $memberIds[$managerId] = true;
                    $memberIds = array_keys($memberIds);

                    $stmt = $conn->prepare("DELETE FROM team_members WHERE team_id = ?");
                    $stmt->bind_param('i', $tid);
                    $stmt->execute();
                    $stmt->close();

                    $stmtI = $conn->prepare("INSERT IGNORE INTO team_members (team_id, user_id, member_role, added_by, added_at) VALUES (?,?,?, ?, NOW())");
                    foreach ($memberIds as $mid) {
                        $role = ($mid === $managerId) ? 'manager' : 'member';
                        $stmtI->bind_param('iisi', $tid, $mid, $role, $userId);
                        $stmtI->execute();
                    }
                    $stmtI->close();

                    $message = 'Team saved.';
                    $messageType = 'success';
                    header('Location: team-management?edit_id=' . $tid);
                    exit;
                }
            }
        }
    }
}

$users = [];
$rs = $conn->query("SELECT id, full_name, role, job_title FROM users WHERE is_active = 1 AND role NOT LIKE 'client_%' AND role NOT LIKE 'vendor_%' ORDER BY full_name");
if ($rs) $users = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$campaigns = getAllCampaignsBasic();
$campaignIndex = [];
foreach ($campaigns as $campaignRow) {
    $campaignId = (int)($campaignRow['id'] ?? 0);
    if ($campaignId > 0) {
        $campaignIndex[$campaignId] = [
            'name' => (string)($campaignRow['name'] ?? ('Campaign #' . $campaignId)),
            'code' => (string)($campaignRow['code'] ?? ''),
        ];
    }
}

$teams = [];
$rs = $conn->query("
    SELECT t.id, t.team_name, t.manager_user_id, u.full_name AS manager_name,
           (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS member_count,
           (SELECT COUNT(*) FROM team_campaigns tc WHERE tc.team_id = t.id) AS campaign_count,
           t.created_at
    FROM teams t
    LEFT JOIN users u ON u.id = t.manager_user_id
    ORDER BY t.created_at DESC
");
if ($rs) $teams = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$teamCampaignsByTeam = [];
if (!empty($teams)) {
    $teamIds = array_values(array_filter(array_map(static function(array $team): int {
        return (int)($team['id'] ?? 0);
    }, $teams)));
    if (!empty($teamIds)) {
        $in = implode(',', array_map('intval', $teamIds));
        $sql = "
            SELECT tc.team_id, tc.campaign_id, tc.assigned_at
            FROM team_campaigns tc
            WHERE tc.team_id IN ($in)
            ORDER BY tc.assigned_at DESC, tc.campaign_id DESC
        ";
        $rsCampaignMap = $conn->query($sql);
        if ($rsCampaignMap) {
            while ($row = $rsCampaignMap->fetch_assoc()) {
                $teamId = (int)($row['team_id'] ?? 0);
                $campaignId = (int)($row['campaign_id'] ?? 0);
                if ($teamId <= 0 || $campaignId <= 0) {
                    continue;
                }
                $campaignMeta = $campaignIndex[$campaignId] ?? [
                    'name' => 'Campaign #' . $campaignId,
                    'code' => '',
                ];
                $teamCampaignsByTeam[$teamId][] = [
                    'id' => $campaignId,
                    'name' => (string)$campaignMeta['name'],
                    'code' => (string)$campaignMeta['code'],
                ];
            }
            $rsCampaignMap->close();
        }
    }
}

$totalTeams = count($teams);
$totalMembers = 0;
$totalCampaignLinks = 0;
$teamsWithoutCampaigns = 0;
$mappedTeams = 0;
foreach ($teams as $teamRow) {
    $memberCount = (int)($teamRow['member_count'] ?? 0);
    $campaignCount = (int)($teamRow['campaign_count'] ?? 0);
    $totalMembers += $memberCount;
    $totalCampaignLinks += $campaignCount;
    if ($campaignCount <= 0) {
        $teamsWithoutCampaigns++;
    } else {
        $mappedTeams++;
    }
}

$totalCampaignInventory = is_array($campaigns) ? count($campaigns) : 0;
$averageCampaignLinks = $totalTeams > 0 ? round($totalCampaignLinks / $totalTeams, 1) : 0.0;
$teamCoveragePercent = $totalTeams > 0 ? (int)round(($mappedTeams / $totalTeams) * 100) : 0;

$userRoles = [];
foreach ($users as $userRow) {
    $roleKey = trim((string)($userRow['role'] ?? ''));
    if ($roleKey !== '') {
        $userRoles[$roleKey] = true;
    }
}
$userRoles = array_keys($userRoles);
sort($userRoles, SORT_NATURAL | SORT_FLAG_CASE);

$formatRoleLabel = static function(string $role): string {
    $role = trim($role);
    if ($role === '') {
        return 'Unknown Role';
    }
    return ucwords(str_replace('_', ' ', $role));
};

$formatTeamCreatedAt = static function($value): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Recently created';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d M Y');
    } catch (Throwable $e) {
        return 'Recently created';
    }
};

$campaignBadgeClass = static function(int $campaignCount): string {
    return $campaignCount > 0 ? 'team-admin-badge team-admin-badge-success' : 'team-admin-badge team-admin-badge-warning';
};

$memberBadgeClass = static function(int $memberCount): string {
    return $memberCount >= 5 ? 'team-admin-badge team-admin-badge-primary' : 'team-admin-badge team-admin-badge-muted';
};

$campaignCoverageBadgeClass = static function(int $campaignCount): string {
    if ($campaignCount >= 3) {
        return 'team-admin-badge team-admin-badge-success';
    }
    if ($campaignCount >= 1) {
        return 'team-admin-badge team-admin-badge-primary';
    }
    return 'team-admin-badge team-admin-badge-warning';
};

$topMappedTeams = $teams;
usort($topMappedTeams, static function(array $left, array $right): int {
    return ((int)($right['campaign_count'] ?? 0) <=> (int)($left['campaign_count'] ?? 0))
        ?: ((int)($right['member_count'] ?? 0) <=> (int)($left['member_count'] ?? 0));
});
$topMappedTeams = array_values(array_filter($topMappedTeams, static function(array $team): bool {
    return (int)($team['campaign_count'] ?? 0) > 0;
}));
$topMappedTeams = array_slice($topMappedTeams, 0, 3);

$unmappedTeams = array_values(array_filter($teams, static function(array $team): bool {
    return (int)($team['campaign_count'] ?? 0) <= 0;
}));
$unmappedTeams = array_slice($unmappedTeams, 0, 4);

$loadEdit();

?>
<?php $pageTitle = 'Team Management'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0 team-admin-workspace">
    <div class="team-admin-page-header mb-4">
        <div class="team-admin-hero flex-grow-1">
            <div class="team-admin-eyebrow"><i class="bi bi-diagram-3"></i> Admin Workspace</div>
            <div class="team-admin-title-row mt-3">
                <div>
                    <h1 class="team-admin-page-title mb-1">Team Management</h1>
                    <p class="team-admin-page-subtitle mb-0">Create team structures, assign managers, organize members, and review campaign readiness in one CRM-aligned workspace.</p>
                </div>
                <div class="team-admin-page-actions">
                    <a class="btn btn-light border" href="<?php echo htmlspecialchars(appBackUrl('../dashboard/admin-dashboard.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
                    <?php if ($editTeam): ?>
                        <a class="btn btn-outline-primary" href="team-management.php"><i class="bi bi-plus-circle me-1"></i>New Team</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 team-admin-kpi">
                        <div class="card-body">
                            <div class="team-admin-kpi-label">Total Teams</div>
                            <div class="team-admin-kpi-value"><?php echo number_format($totalTeams); ?></div>
                            <div class="team-admin-kpi-note">Configured team units</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 team-admin-kpi kpi-info">
                        <div class="card-body">
                            <div class="team-admin-kpi-label">Active Members</div>
                            <div class="team-admin-kpi-value"><?php echo number_format($totalMembers); ?></div>
                            <div class="team-admin-kpi-note">Across all saved teams</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 team-admin-kpi kpi-success">
                        <div class="card-body">
                            <div class="team-admin-kpi-label">Campaign Links</div>
                            <div class="team-admin-kpi-value"><?php echo number_format($totalCampaignLinks); ?></div>
                            <div class="team-admin-kpi-note">Mapped campaign assignments</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card border-0 team-admin-kpi kpi-warning">
                        <div class="card-body">
                            <div class="team-admin-kpi-label">Need Mapping</div>
                            <div class="team-admin-kpi-value"><?php echo number_format($teamsWithoutCampaigns); ?></div>
                            <div class="team-admin-kpi-note">Teams without campaigns</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm team-admin-alert"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm team-admin-panel">
                <div class="card-header d-flex align-items-start justify-content-between gap-3">
                    <div>
                        <div class="team-admin-section-title"><?php echo $editTeam ? 'Edit Team' : 'Create Team'; ?></div>
                        <div class="team-admin-section-note"><?php echo $editTeam ? 'Update team ownership and membership from a single form.' : 'Set the team name, choose the manager, and include the required members.'; ?></div>
                    </div>
                    <?php if ($editTeam): ?>
                        <form method="post" class="m-0" onsubmit="return confirm('Delete this team?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="team_id" value="<?php echo (int)$editTeam['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="save_team">
                        <input type="hidden" name="team_id" value="<?php echo (int)($editTeam['id'] ?? 0); ?>">

                        <div class="team-admin-form-grid">
                            <div class="team-admin-field">
                                <label class="form-label">Team Name</label>
                                <input class="form-control" name="team_name" value="<?php echo htmlspecialchars((string)($editTeam['team_name'] ?? '')); ?>" placeholder="e.g. Operations Team A" required>
                            </div>

                            <div class="team-admin-field">
                                <label class="form-label">Team Manager</label>
                                <select class="form-select" name="manager_user_id" required>
                                    <option value="">Select manager</option>
                                    <?php foreach ($users as $u): ?>
                                        <?php $uid2 = (int)($u['id'] ?? 0); ?>
                                        <option value="<?php echo $uid2; ?>" <?php echo ($uid2 > 0 && (int)($editTeam['manager_user_id'] ?? 0) === $uid2) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(formatUserNameWithRole((string)($u['full_name'] ?? ''), (string)($u['role'] ?? ''), (string)($u['job_title'] ?? ''))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="team-admin-input-note">Only active internal CRM users are shown here.</div>
                            </div>
                        </div>

                        <div class="team-admin-member-card mt-3">
                            <div class="team-admin-member-head">
                                <div>
                                    <div class="team-admin-section-title">Team Members</div>
                                    <div class="team-admin-section-note">Pick the users who should belong to this team. The selected manager is included automatically.</div>
                                </div>
                                <div class="team-admin-inline-meta">
                                    <span class="team-admin-chip" id="teamAdminVisibleUsersChip"><?php echo number_format(count($users)); ?> visible</span>
                                    <span class="team-admin-chip"><?php echo number_format(count($users)); ?> available users</span>
                                </div>
                            </div>
                            <div class="team-admin-member-filters">
                                <div class="team-admin-member-search">
                                    <i class="bi bi-search"></i>
                                    <input type="search" class="form-control" id="teamMemberSearch" placeholder="Search by name or role">
                                </div>
                                <select class="form-select" id="teamMemberRoleFilter" aria-label="Filter team members by role">
                                    <option value="">All roles</option>
                                    <?php foreach ($userRoles as $roleKey): ?>
                                        <option value="<?php echo htmlspecialchars($roleKey); ?>"><?php echo htmlspecialchars($formatRoleLabel($roleKey)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="team-admin-member-picker">
                                <?php if (empty($users)): ?>
                                    <div class="team-admin-empty-state">No users found.</div>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <?php $uid2 = (int)($u['id'] ?? 0); ?>
                                        <?php $memberName = (string)($u['full_name'] ?? ''); ?>
                                        <?php $memberRole = (string)($u['role'] ?? ''); ?>
                                        <label class="team-admin-member-option" for="mem_<?php echo $uid2; ?>" data-member-name="<?php echo htmlspecialchars(strtolower($memberName)); ?>" data-member-role="<?php echo htmlspecialchars($memberRole); ?>">
                                            <div class="form-check m-0">
                                                <input class="form-check-input" type="checkbox" name="member_user_ids[]" value="<?php echo $uid2; ?>" id="mem_<?php echo $uid2; ?>" <?php echo isset($editMembers[$uid2]) ? 'checked' : ''; ?>>
                                            </div>
                                            <div class="team-admin-member-copy">
                                                <span class="team-admin-member-name"><?php echo htmlspecialchars($memberName); ?></span>
                                                <span class="team-admin-member-role"><?php echo htmlspecialchars($formatRoleLabel($memberRole)); ?></span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="team-admin-empty-state d-none" id="teamAdminMemberEmpty">No users match the current search or role filter.</div>
                            <div class="team-admin-input-note mt-2">Manager selection stays in sync with team membership automatically.</div>
                        </div>

                        <div class="team-admin-footer-bar">
                            <div class="team-admin-inline-meta">
                                <span class="team-admin-chip"><i class="bi bi-person-badge"></i> Manager-led team</span>
                                <span class="team-admin-chip"><i class="bi bi-people"></i> Member mapping</span>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <a class="btn btn-light border" href="team-management.php">Clear</a>
                                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save Team</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm team-admin-panel mb-4">
                <div class="card-header">
                    <div class="team-admin-table-head">
                        <div>
                            <div class="team-admin-section-title">Campaign Mapping Summary</div>
                            <div class="team-admin-section-note">Track how well teams are connected to campaigns and quickly spot operational gaps before they affect routing.</div>
                        </div>
                        <div class="team-admin-inline-meta">
                            <span class="team-admin-chip"><?php echo $teamCoveragePercent; ?>% team coverage</span>
                            <span class="team-admin-chip"><?php echo number_format($totalCampaignInventory); ?> campaigns in CRM</span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6 col-xl-3">
                            <div class="team-admin-summary-card">
                                <div class="team-admin-summary-label">Mapped Teams</div>
                                <div class="team-admin-summary-value"><?php echo number_format($mappedTeams); ?></div>
                                <div class="team-admin-summary-note">Teams linked to at least one campaign</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="team-admin-summary-card">
                                <div class="team-admin-summary-label">Unmapped Teams</div>
                                <div class="team-admin-summary-value"><?php echo number_format($teamsWithoutCampaigns); ?></div>
                                <div class="team-admin-summary-note">Teams that still need campaign allocation</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="team-admin-summary-card">
                                <div class="team-admin-summary-label">Campaign Inventory</div>
                                <div class="team-admin-summary-value"><?php echo number_format($totalCampaignInventory); ?></div>
                                <div class="team-admin-summary-note">Available campaigns in the system</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-xl-3">
                            <div class="team-admin-summary-card">
                                <div class="team-admin-summary-label">Avg Links Per Team</div>
                                <div class="team-admin-summary-value"><?php echo number_format($averageCampaignLinks, 1); ?></div>
                                <div class="team-admin-summary-note">Average mapped campaign relationships</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="team-admin-insight-card">
                                <div class="team-admin-section-title">Top Mapped Teams</div>
                                <div class="team-admin-section-note mb-3">Teams with the strongest current campaign coverage.</div>
                                <?php if (empty($topMappedTeams)): ?>
                                    <div class="team-admin-empty-state">No campaign mappings available yet.</div>
                                <?php else: ?>
                                    <div class="team-admin-insight-list">
                                        <?php foreach ($topMappedTeams as $teamRow): ?>
                                            <div class="team-admin-insight-item">
                                                <div>
                                                    <div class="team-admin-row-primary"><?php echo htmlspecialchars((string)($teamRow['team_name'] ?? '')); ?></div>
                                                    <div class="team-admin-row-secondary"><?php echo htmlspecialchars((string)($teamRow['manager_name'] ?? 'Unassigned')); ?> · <?php echo number_format((int)($teamRow['member_count'] ?? 0)); ?> members</div>
                                                </div>
                                                <span class="team-admin-badge team-admin-badge-success"><?php echo number_format((int)($teamRow['campaign_count'] ?? 0)); ?> linked</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="team-admin-insight-card">
                                <div class="team-admin-section-title">Needs Attention</div>
                                <div class="team-admin-section-note mb-3">Teams created without active campaign allocation.</div>
                                <?php if (empty($unmappedTeams)): ?>
                                    <div class="team-admin-empty-state">All teams currently have at least one campaign link.</div>
                                <?php else: ?>
                                    <div class="team-admin-insight-list">
                                        <?php foreach ($unmappedTeams as $teamRow): ?>
                                            <div class="team-admin-insight-item">
                                                <div>
                                                    <div class="team-admin-row-primary"><?php echo htmlspecialchars((string)($teamRow['team_name'] ?? '')); ?></div>
                                                    <div class="team-admin-row-secondary"><?php echo htmlspecialchars((string)($teamRow['manager_name'] ?? 'Unassigned')); ?> · created <?php echo htmlspecialchars($formatTeamCreatedAt($teamRow['created_at'] ?? '')); ?></div>
                                                </div>
                                                <span class="team-admin-badge team-admin-badge-warning">Needs mapping</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm team-admin-panel">
                <div class="card-header">
                    <div class="team-admin-table-head">
                        <div>
                            <div class="team-admin-section-title">Teams</div>
                            <div class="team-admin-section-note">Review ownership, member load, live campaign assignments, and mapping coverage with a cleaner CRM-aligned table layout.</div>
                        </div>
                        <div class="team-admin-inline-meta">
                            <span class="team-admin-chip"><?php echo number_format($totalTeams); ?> total</span>
                            <span class="team-admin-chip"><?php echo number_format($teamsWithoutCampaigns); ?> need campaign mapping</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive team-admin-table-wrap">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Manager</th>
                                    <th>Linked Campaigns</th>
                                    <th class="text-end">Members</th>
                                    <th class="text-end">Coverage</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $t): ?>
                                    <?php $memberCount = (int)($t['member_count'] ?? 0); ?>
                                    <?php $campaignCount = (int)($t['campaign_count'] ?? 0); ?>
                                    <?php $teamId = (int)($t['id'] ?? 0); ?>
                                    <?php $linkedCampaigns = $teamCampaignsByTeam[$teamId] ?? []; ?>
                                    <?php $campaignPreview = array_slice($linkedCampaigns, 0, 3); ?>
                                    <?php $campaignOverflow = max(0, count($linkedCampaigns) - count($campaignPreview)); ?>
                                    <tr>
                                        <td class="team-admin-col-team">
                                            <div class="team-admin-row-primary"><?php echo htmlspecialchars((string)($t['team_name'] ?? '')); ?></div>
                                            <div class="team-admin-row-secondary">Created <?php echo htmlspecialchars($formatTeamCreatedAt($t['created_at'] ?? '')); ?></div>
                                        </td>
                                        <td class="team-admin-col-manager">
                                            <div class="team-admin-row-primary"><?php echo htmlspecialchars((string)($t['manager_name'] ?? 'Unassigned')); ?></div>
                                            <div class="team-admin-row-secondary">Team owner</div>
                                        </td>
                                        <td class="team-admin-col-campaigns">
                                            <?php if (empty($campaignPreview)): ?>
                                                <span class="team-admin-inline-empty">No campaigns linked</span>
                                            <?php else: ?>
                                                <div class="team-admin-campaign-list">
                                                    <?php foreach ($campaignPreview as $campaignRow): ?>
                                                        <?php $campaignLabel = trim(($campaignRow['code'] !== '' ? $campaignRow['code'] . ' · ' : '') . $campaignRow['name']); ?>
                                                        <span class="team-admin-campaign-chip" title="<?php echo htmlspecialchars($campaignRow['name']); ?>"><?php echo htmlspecialchars($campaignLabel); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if ($campaignOverflow > 0): ?>
                                                        <span class="team-admin-campaign-chip team-admin-campaign-chip-more">+<?php echo number_format($campaignOverflow); ?> more</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end team-admin-col-metrics">
                                            <span class="<?php echo $memberBadgeClass($memberCount); ?>"><?php echo number_format($memberCount); ?> members</span>
                                        </td>
                                        <td class="text-end team-admin-col-metrics">
                                            <span class="<?php echo $campaignCoverageBadgeClass($campaignCount); ?>"><?php echo number_format($campaignCount); ?> linked</span>
                                        </td>
                                        <td class="text-end team-admin-col-action">
                                            <a class="btn btn-sm btn-light border" href="team-management.php?edit_id=<?php echo (int)($t['id'] ?? 0); ?>"><i class="bi bi-pencil-square me-1"></i>Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($teams)): ?>
                                    <tr><td colspan="6" class="team-admin-empty-state">No teams created yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('teamMemberSearch');
    var roleFilter = document.getElementById('teamMemberRoleFilter');
    var visibleChip = document.getElementById('teamAdminVisibleUsersChip');
    var emptyState = document.getElementById('teamAdminMemberEmpty');
    var options = Array.prototype.slice.call(document.querySelectorAll('.team-admin-member-option'));

    if (!searchInput || !roleFilter || options.length === 0) {
        return;
    }

    var applyFilters = function () {
        var searchValue = String(searchInput.value || '').toLowerCase().trim();
        var roleValue = String(roleFilter.value || '').toLowerCase().trim();
        var visibleCount = 0;

        options.forEach(function (option) {
            var name = String(option.getAttribute('data-member-name') || '');
            var role = String(option.getAttribute('data-member-role') || '').toLowerCase();
            var matchesSearch = searchValue === '' || name.indexOf(searchValue) !== -1 || role.indexOf(searchValue) !== -1;
            var matchesRole = roleValue === '' || role === roleValue;
            var visible = matchesSearch && matchesRole;
            option.classList.toggle('d-none', !visible);
            if (visible) {
                visibleCount += 1;
            }
        });

        if (visibleChip) {
            visibleChip.textContent = visibleCount + ' visible';
        }
        if (emptyState) {
            emptyState.classList.toggle('d-none', visibleCount > 0);
        }
    };

    searchInput.addEventListener('input', applyFilters);
    roleFilter.addEventListener('change', applyFilters);
    applyFilters();
});
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
