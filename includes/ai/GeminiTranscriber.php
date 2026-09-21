<?php
/**
 * GeminiTranscriber.php
 * Handles Speech-to-Text audio transcription using Google Gemini API.
 */

if (!defined('GEMINI_TRANSCRIBER_LOADED')) {
    define('GEMINI_TRANSCRIBER_LOADED', true);

    require_once __DIR__ . '/GeminiClient.php';

    class GeminiTranscriber {
        private GeminiClient $client;
        private string $model;
        private bool $speakerLabels;

        public function __construct(GeminiClient $client, string $model = 'gemini-1.5-flash', bool $speakerLabels = true) {
            $this->client = $client;
            $this->model = trim($model) !== '' ? trim($model) : 'gemini-1.5-flash';
            $this->speakerLabels = $speakerLabels;
        }

        /**
         * Transcribe audio file path to raw text
         */
        public function transcribe(string $audioPath): array {
            if (!file_exists($audioPath)) {
                return [
                    'success' => false,
                    'error' => 'Audio file does not exist at path: ' . $audioPath
                ];
            }

            $fileSize = filesize($audioPath);
            if ($fileSize <= 0) {
                return [
                    'success' => false,
                    'error' => 'Audio file is empty (0 bytes).'
                ];
            }

            // Max size for inline base64 (20MB)
            if ($fileSize > 25 * 1024 * 1024) {
                return [
                    'success' => false,
                    'error' => 'Audio file size exceeds maximum 25MB threshold for transcription.'
                ];
            }

            $mimeType = $this->detectAudioMimeType($audioPath);
            $audioData = file_get_contents($audioPath);
            if ($audioData === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to read audio file contents.'
                ];
            }

            $base64Data = base64_encode($audioData);

            $speakerInstruction = $this->speakerLabels 
                ? "Use speaker labels (e.g. 'Agent:', 'Prospect:') to separate distinct speakers in the conversation wherever identifiable." 
                : "Do not include speaker labels.";

            $prompt = "You are an expert verbatim speech-to-text transcription engine for call center QA. " .
                      "Transcribe the provided audio recording verbatim into clear English. " .
                      "Do not summarize, do not omit words, and do not paraphrase. " .
                      $speakerInstruction . " " .
                      "Return only the transcribed English text.";

            $contents = [
                [
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => $base64Data
                            ]
                        ],
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ];

            $res = $this->client->generateContent($this->model, $contents, [
                'temperature' => 0.1,
                'topP' => 0.95
            ]);

            if (!$res['success']) {
                return $res;
            }

            $rawText = trim((string)($res['text'] ?? ''));
            if ($rawText === '') {
                return [
                    'success' => false,
                    'error' => 'Gemini API returned an empty transcription.'
                ];
            }

            return [
                'success' => true,
                'text' => $rawText,
                'language' => 'English',
                'model' => $this->model
            ];
        }

        /**
         * Detect mime type for audio formats
         */
        private function detectAudioMimeType(string $path): string {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'mp3':
                    return 'audio/mp3';
                case 'wav':
                    return 'audio/wav';
                case 'm4a':
                    return 'audio/m4a';
                case 'ogg':
                case 'oga':
                    return 'audio/ogg';
                case 'webm':
                    return 'audio/webm';
                case 'aac':
                    return 'audio/aac';
                case 'flac':
                    return 'audio/flac';
                case 'mp4':
                    return 'audio/mp4';
                default:
                    if (function_exists('mime_content_type')) {
                        $mime = mime_content_type($path);
                        if (!empty($mime) && str_starts_with($mime, 'audio/')) return $mime;
                    }
                    return 'audio/mp3';
            }
        }
    }
}
