<?php
/**
 * DemandFlow Bridge CRM - Incentive API Standardized JSON Response Helper
 */

class IncentiveResponse {
    public static function json(array $data, int $statusCode = 200): void {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Webhook-Signature, X-Idempotency-Key');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(mixed $data = null, ?string $message = null, int $statusCode = 200, array $meta = []): void {
        $payload = [
            'success' => true,
            'status' => $statusCode,
            'message' => $message ?? 'Operation completed successfully.',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $statusCode);
    }

    public static function error(string $message, ?string $errorCode = 'BAD_REQUEST', int $statusCode = 400, mixed $details = null): void {
        $payload = [
            'success' => false,
            'status' => $statusCode,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
            ],
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($details !== null) {
            $payload['error']['details'] = $details;
        }
        self::json($payload, $statusCode);
    }

    public static function unauthorized(string $message = 'Authentication required.'): void {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): void {
        self::error($message, 'FORBIDDEN', 403);
    }

    public static function notFound(string $message = 'Resource not found.'): void {
        self::error($message, 'NOT_FOUND', 404);
    }
}
