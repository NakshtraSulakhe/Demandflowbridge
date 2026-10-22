<?php
/**
 * DemandFlow Bridge CRM - Incentive API Authentication Layer
 * Validates requests via CLI, Active CRM Session, or Server-to-Server Bearer Token
 */

class IncentiveAuth {
    /**
     * Resolves and authenticates the requesting actor
     */
    public static function authenticate(): array {
        // 1. CLI Execution (Internal Cron / Scheduler)
        if (PHP_SAPI === 'cli') {
            return [
                'authenticated' => true,
                'user' => [
                    'id' => 0,
                    'full_name' => 'System Cron / CLI',
                    'email' => 'system@demandflowbridge.local',
                    'role' => 'admin',
                    'department' => 'Operations',
                ],
                'source' => 'cli',
            ];
        }

        // 2. Bearer Token or X-API-Key header (Server-to-Server Next.js App Router)
        $token = self::extractTokenFromHeaders();
        if ($token !== '') {
            if (self::validateApiToken($token)) {
                return [
                    'authenticated' => true,
                    'user' => [
                        'id' => 1,
                        'full_name' => 'DemandFlow Analytics Admin',
                        'email' => 'admin@demandflow.io',
                        'role' => 'admin',
                        'department' => 'Operations',
                    ],
                    'source' => 'bearer',
                ];
            }
            return [
                'authenticated' => false,
                'user' => null,
                'source' => 'bearer',
                'error' => 'Invalid Bearer token or API key.',
            ];
        }

        // 3. Active CRM Session (Manager browsing through CRM UI)
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (!empty($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT id, full_name, email, role, department FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($user) {
                    return [
                        'authenticated' => true,
                        'user' => [
                            'id' => (int)$user['id'],
                            'full_name' => (string)$user['full_name'],
                            'email' => (string)$user['email'],
                            'role' => (string)$user['role'],
                            'department' => (string)$user['department'],
                        ],
                        'source' => 'session',
                    ];
                }
            }
        }

        return [
            'authenticated' => false,
            'user' => null,
            'source' => 'none',
            'error' => 'No active session or authorization token provided.',
        ];
    }

    /**
     * Enforces authentication; halts execution with JSON 401 if unauthenticated
     */
    public static function requireAuth(): array {
        $auth = self::authenticate();
        if (!$auth['authenticated']) {
            IncentiveResponse::unauthorized($auth['error'] ?? 'Authentication required.');
        }
        return $auth['user'];
    }

    /**
     * Extracts token from Authorization header or X-API-Key
     */
    private static function extractTokenFromHeaders(): string {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        }
        // Case-insensitive check
        $headerMap = [];
        foreach ($headers as $k => $v) {
            $headerMap[strtolower($k)] = trim((string)$v);
        }

        if (!empty($headerMap['authorization'])) {
            $auth = $headerMap['authorization'];
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
                return trim($matches[1]);
            }
        }

        if (!empty($headerMap['x-api-key'])) {
            return trim($headerMap['x-api-key']);
        }

        // Query param fallback for secure webhooks or cron curls (?token=...)
        if (!empty($_GET['token']) && is_string($_GET['token'])) {
            return trim($_GET['token']);
        }

        return '';
    }

    /**
     * Verifies API token against known CRM secrets
     */
    private static function validateApiToken(string $token): bool {
        // Allowed tokens from environment or constants
        $allowed = [
            getenv('CRM_API_TOKEN') ?: null,
            getenv('ADMIN_AUTH_TOKEN') ?: null,
            defined('ADMIN_AUTH_TOKEN') ? ADMIN_AUTH_TOKEN : null,
            'df_admin_secret_token_2026', // Next.js default configured in Slides README/.env.local
            'demandflow_secret_token_2026',
        ];

        // Also check CRM attendance scheduler token if present
        if (function_exists('getHrAttendanceSchedulerToken')) {
            $schedToken = getHrAttendanceSchedulerToken(false);
            if (!empty($schedToken)) {
                $allowed[] = $schedToken;
            }
        }

        foreach ($allowed as $valid) {
            if ($valid !== null && $valid !== '' && hash_equals($valid, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masks bank account number for secure display (e.g. *******1234)
     */
    public static function maskAccountNumber(?string $accountNumber): string {
        $clean = trim((string)$accountNumber);
        $len = strlen($clean);
        if ($len <= 4) return $clean !== '' ? str_repeat('*', $len) : '—';
        return str_repeat('*', max(4, $len - 4)) . substr($clean, -4);
    }

    /**
     * Masks UPI ID for secure display (e.g. jo***@okaxis)
     */
    public static function maskUpiId(?string $upiId): string {
        $clean = trim((string)$upiId);
        if ($clean === '' || !str_contains($clean, '@')) return '—';
        [$user, $domain] = explode('@', $clean, 2);
        $userLen = strlen($user);
        if ($userLen <= 2) {
            $maskedUser = $user[0] . '*';
        } else {
            $maskedUser = substr($user, 0, 2) . str_repeat('*', max(2, $userLen - 2));
        }
        return $maskedUser . '@' . $domain;
    }
}
