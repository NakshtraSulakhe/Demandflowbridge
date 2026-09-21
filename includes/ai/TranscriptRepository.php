<?php
/**
 * TranscriptRepository.php
 * Handles database operations for lead_transcripts table using MySQLi prepared statements.
 */

if (!defined('TRANSCRIPT_REPOSITORY_LOADED')) {
    define('TRANSCRIPT_REPOSITORY_LOADED', true);

    require_once __DIR__ . '/../functions.php';

    class TranscriptRepository {

        public function __construct() {
            ensureLeadTranscriptsSchema();
        }

        /**
         * Create or return existing job for lead/file/path
         */
        public function createJob(int $leadId, ?int $fileId, string $audioPath): int {
            $conn = getDbConnection();
            $audioPath = trim($audioPath);

            // Check if job already exists for this exact audio_path / file_id
            if ($fileId !== null && $fileId > 0) {
                $stmt = $conn->prepare("SELECT id FROM lead_transcripts WHERE lead_id = ? AND file_id = ? LIMIT 1");
                $stmt->bind_param('ii', $leadId, $fileId);
            } else {
                $stmt = $conn->prepare("SELECT id FROM lead_transcripts WHERE lead_id = ? AND audio_path = ? LIMIT 1");
                $stmt->bind_param('is', $leadId, $audioPath);
            }

            if ($stmt) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return (int)$row['id'];
                }
            }

            // Create new job
            $stmt = $conn->prepare("INSERT INTO lead_transcripts (lead_id, file_id, audio_path, status, created_at, updated_at) VALUES (?, ?, ?, 'pending', NOW(), NOW())");
            if (!$stmt) return 0;

            $fileVal = ($fileId !== null && $fileId > 0) ? $fileId : null;
            $stmt->bind_param('iis', $leadId, $fileVal, $audioPath);
            $ok = $stmt->execute();
            $id = $ok ? (int)$conn->insert_id : 0;
            $stmt->close();

            return $id;
        }

        /**
         * Find all transcripts for a lead
         */
        public function findByLead(int $leadId): array {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT * FROM lead_transcripts WHERE lead_id = ? ORDER BY id ASC");
            if (!$stmt) return [];
            $stmt->bind_param('i', $leadId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            $stmt->close();
            return $res;
        }

        /**
         * Find transcript by ID
         */
        public function findById(int $id): ?array {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT * FROM lead_transcripts WHERE id = ? LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            return $row;
        }

        /**
         * Atomically claim one pending job for processing
         */
        public function claimPendingJob(int $maxRetries = 3): ?array {
            $conn = getDbConnection();

            // Stale job timeout: reset jobs stuck in transcribing or formatting for > 10 minutes
            $staleCutoff = date('Y-m-d H:i:s', time() - 600);
            $conn->query("UPDATE lead_transcripts SET status = 'pending', processing_started_at = NULL WHERE status IN ('transcribing','formatting') AND processing_started_at < '{$staleCutoff}' AND retry_count < {$maxRetries}");

            // Find eligible pending job
            $stmt = $conn->prepare("SELECT id FROM lead_transcripts WHERE status = 'pending' AND retry_count < ? ORDER BY id ASC LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('i', $maxRetries);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) return null;
            $jobId = (int)$row['id'];

            // Atomically update status to transcribing
            $stmt = $conn->prepare("UPDATE lead_transcripts SET status = 'transcribing', processing_started_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'pending' LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                return $this->findById($jobId);
            }

            return null; // Claim collision, another worker claimed it
        }

        /**
         * Update status
         */
        public function updateStatus(int $id, string $status, ?string $errorMessage = null): bool {
            $conn = getDbConnection();
            $stmt = $conn->prepare("UPDATE lead_transcripts SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('ssi', $status, $errorMessage, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        /**
         * Save raw transcript
         */
        public function saveRawTranscript(int $id, string $rawTranscript, string $detectedLanguage, string $model): bool {
            $conn = getDbConnection();
            $stmt = $conn->prepare("UPDATE lead_transcripts SET raw_transcript = ?, detected_language = ?, transcription_model = ?, status = 'transcribed', updated_at = NOW() WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('sssi', $rawTranscript, $detectedLanguage, $model, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        /**
         * Save filtered transcript
         */
        public function saveFilteredTranscript(int $id, string $filteredTranscript): bool {
            $conn = getDbConnection();
            $stmt = $conn->prepare("UPDATE lead_transcripts SET filtered_transcript = ?, status = 'formatting', updated_at = NOW() WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('si', $filteredTranscript, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        /**
         * Save formatted transcript & mark completed
         */
        public function saveFormattedTranscript(int $id, string $formattedTranscript, string $promptSnapshot, string $rulesSnapshot, string $model): bool {
            $conn = getDbConnection();
            $stmt = $conn->prepare("UPDATE lead_transcripts SET formatted_transcript = ?, prompt_snapshot = ?, rules_snapshot = ?, formatting_model = ?, status = 'completed', processing_completed_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('ssssi', $formattedTranscript, $promptSnapshot, $rulesSnapshot, $model, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        /**
         * Increment retry on failure
         */
        public function incrementRetry(int $id, string $errorMessage, int $maxRetries = 3): bool {
            $conn = getDbConnection();
            $job = $this->findById($id);
            if (!$job) return false;

            $retry = (int)($job['retry_count'] ?? 0) + 1;
            $nextStatus = $retry >= $maxRetries ? 'failed' : 'pending';

            $stmt = $conn->prepare("UPDATE lead_transcripts SET retry_count = ?, error_message = ?, status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('issi', $retry, $errorMessage, $nextStatus, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        /**
         * Save human-reviewed final transcript
         */
        public function saveFinalTranscript(int $id, string $finalTranscript, int $reviewedBy): bool {
            $conn = getDbConnection();
            $stmt = $conn->prepare("UPDATE lead_transcripts SET final_transcript = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('sii', $finalTranscript, $reviewedBy, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }
    }
}
