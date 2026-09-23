<?php
/**
 * DemandFlow Bridge - Public Call Recording Audio Stream API
 * 
 * Streams call recording files safely from uploads/ without 403 Forbidden
 * or session authentication restrictions. Supports Range requests for HTML5 audio seeking.
 */

// Enable CORS for cross-origin audio streaming
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);

try {
    require_once __DIR__ . '/../config/database.php';

    $filePath = '';
    $fileId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0);
    
    if (isset($_GET['file']) && trim((string)$_GET['file']) !== '') {
        $filePath = trim((string)$_GET['file']);
    } elseif (isset($_GET['path']) && trim((string)$_GET['path']) !== '') {
        $filePath = trim((string)$_GET['path']);
    }

    $conn = getDbConnection();

    // If file ID is provided, fetch file path from lead_files table
    if ($fileId > 0 && $filePath === '') {
        $stmt = $conn->prepare("SELECT file_path FROM lead_files WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $fileId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['file_path'])) {
                $filePath = trim((string)$row['file_path']);
            }
        }
    }

    if ($filePath === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Missing recording file parameter ("file" or "id").']);
        exit;
    }

    // Normalize and sanitize relative path
    $filePath = str_replace('\\', '/', $filePath);
    $filePath = ltrim($filePath, '/');
    while (str_starts_with($filePath, './')) {
        $filePath = substr($filePath, 2);
    }

    // Security check: must reside inside uploads directory and contain no directory traversal
    if (!str_starts_with($filePath, 'uploads/') || str_contains($filePath, '..') || str_contains($filePath, "\0")) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Access denied: Invalid file path.']);
        exit;
    }

    $baseDir = realpath(__DIR__ . '/../');
    if ($baseDir === false) {
        http_response_code(500);
        exit;
    }

    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filePath);

    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Recording file not found on server.']);
        exit;
    }

    $fileSize = filesize($fullPath);
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

    $mimeType = 'application/octet-stream';
    switch ($ext) {
        case 'wav':
            $mimeType = 'audio/wav';
            break;
        case 'mp3':
            $mimeType = 'audio/mpeg';
            break;
        case 'ogg':
            $mimeType = 'audio/ogg';
            break;
        case 'm4a':
            $mimeType = 'audio/mp4';
            break;
        case 'aac':
            $mimeType = 'audio/aac';
            break;
    }

    $filename = basename($fullPath);
    $isDownload = !empty($_GET['download']);

    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');

    if ($isDownload) {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Disposition: inline; filename="' . $filename . '"');
    }

    // Handle HTTP Range Requests for HTML5 Audio Seek/Scrub
    if (isset($_SERVER['HTTP_RANGE'])) {
        list($param, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strtolower($param) === 'bytes') {
            list($rangeStart, $rangeEnd) = explode('-', $range, 2);
            $start = intval($rangeStart);
            $end = ($rangeEnd === '') ? ($fileSize - 1) : intval($rangeEnd);
            
            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                header("Content-Range: bytes */$fileSize");
                exit;
            }
            
            $length = ($end - $start) + 1;
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$fileSize");
            header("Content-Length: $length");

            $fp = fopen($fullPath, 'rb');
            fseek($fp, $start);
            $bufferSize = 8192;
            while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
                if ($pos + $bufferSize > $end) {
                    $bufferSize = ($end - $pos) + 1;
                }
                echo fread($fp, $bufferSize);
                flush();
            }
            fclose($fp);
            exit;
        }
    }

    header('Content-Length: ' . $fileSize);
    readfile($fullPath);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
}
