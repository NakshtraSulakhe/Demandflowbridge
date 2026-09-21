<?php
/**
 * GeminiClient.php
 * Handles direct HTTP requests to Google Gemini REST API.
 */

if (!defined('GEMINI_CLIENT_LOADED')) {
    define('GEMINI_CLIENT_LOADED', true);

    class GeminiClient {
        private string $apiKey;
        private int $timeout;
        private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

        public function __construct(string $apiKey, int $timeout = 120) {
            $this->apiKey = trim($apiKey);
            $this->timeout = max(10, $timeout);
        }

        /**
         * Generate content from text and optional inline data (audio/image)
         */
        public function generateContent(string $model, array $contents, array $generationConfig = []): array {
            if (empty($this->apiKey)) {
                return [
                    'success' => false,
                    'error' => 'Gemini API key is not configured.'
                ];
            }

            $model = trim($model);
            if ($model === '') {
                $model = 'gemini-1.5-flash';
            }

            $url = $this->baseUrl . '/models/' . urlencode($model) . ':generateContent?key=' . urlencode($this->apiKey);

            $body = [
                'contents' => $contents
            ];
            if (!empty($generationConfig)) {
                $body['generationConfig'] = $generationConfig;
            }

            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlErrno !== 0) {
                return [
                    'success' => false,
                    'error' => 'cURL request failed: ' . $this->sanitizeErrorMessage($curlError)
                ];
            }

            $data = json_decode((string)$response, true);
            if ($httpCode < 200 || $httpCode >= 300) {
                $err = $data['error']['message'] ?? ('HTTP ' . $httpCode . ' response from Gemini API');
                return [
                    'success' => false,
                    'error' => $this->sanitizeErrorMessage($err),
                    'http_code' => $httpCode
                ];
            }

            if (!is_array($data)) {
                return [
                    'success' => false,
                    'error' => 'Invalid JSON response from Gemini API.'
                ];
            }

            // Extract text from candidates
            $candidates = $data['candidates'] ?? [];
            if (empty($candidates)) {
                return [
                    'success' => false,
                    'error' => 'No content returned from Gemini API.'
                ];
            }

            $parts = $candidates[0]['content']['parts'] ?? [];
            $textParts = [];
            foreach ($parts as $p) {
                if (isset($p['text'])) {
                    $textParts[] = $p['text'];
                }
            }

            $resultText = trim(implode("\n", $textParts));

            return [
                'success' => true,
                'text' => $resultText,
                'raw_response' => $data
            ];
        }

        /**
         * Upload a file using Gemini File API (for large files > 20MB)
         */
        public function uploadFile(string $filePath, string $mimeType): array {
            if (empty($this->apiKey)) {
                return ['success' => false, 'error' => 'API Key missing'];
            }
            if (!file_exists($filePath)) {
                return ['success' => false, 'error' => 'File does not exist: ' . $filePath];
            }

            $fileSize = filesize($filePath);
            $uploadUrl = 'https://generativelanguage.googleapis.com/upload/v1beta/files?key=' . urlencode($this->apiKey);

            $headers = [
                'X-Goog-Upload-Protocol: multipart',
                'Content-Type: multipart/related'
            ];

            // For simplicity and high performance under 20MB, inlineData is used by default.
            // If file upload is needed, we stream file chunks.
            return ['success' => false, 'error' => 'File API fallback'];
        }

        /**
         * Remove any exposed credentials from error messages before logging or displaying
         */
        private function sanitizeErrorMessage(string $msg): string {
            if ($this->apiKey !== '') {
                $msg = str_replace($this->apiKey, '[REDACTED_API_KEY]', $msg);
            }
            // Strip any key query param pattern
            $msg = preg_replace('/key=[a-zA-Z0-9_-]+/', 'key=[REDACTED]', $msg);
            return trim($msg);
        }
    }
}
