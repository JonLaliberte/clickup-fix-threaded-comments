<?php

namespace ClickUpCommentDethreader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AttachmentManager
{
    private string $attachmentsDir;
    private string $downloadsDir;
    private string $trackingFile;
    private Client $client;

    public function __construct()
    {
        $this->attachmentsDir = __DIR__ . '/../attachments';
        $this->downloadsDir = $this->attachmentsDir . '/downloads';
        $this->trackingFile = $this->attachmentsDir . '/tracking.json';
        
        // Create directories if they don't exist
        if (!is_dir($this->attachmentsDir)) {
            mkdir($this->attachmentsDir, 0755, true);
        }
        if (!is_dir($this->downloadsDir)) {
            mkdir($this->downloadsDir, 0755, true);
        }
        
        // Initialize tracking file if it doesn't exist
        if (!file_exists($this->trackingFile)) {
            file_put_contents($this->trackingFile, json_encode([], JSON_PRETTY_PRINT));
        }
        
        $this->client = new Client();
    }

    /**
     * Download an attachment from ClickUp URL
     * 
     * @param string $url The attachment URL
     * @param string $taskId The task ID
     * @param string $commentId The comment ID
     * @param string $originalFilename The original filename
     * @return string Local file path
     * @throws \RuntimeException If download fails
     */
    public function downloadAttachment(string $url, string $taskId, string $commentId, string $originalFilename): string
    {
        if (empty($url)) {
            throw new \RuntimeException("Empty URL provided for attachment download");
        }
        
        // Sanitize filename
        $sanitizedFilename = $this->sanitizeFilename($originalFilename);
        $localFilename = "{$taskId}-{$commentId}-{$sanitizedFilename}";
        $localPath = $this->downloadsDir . '/' . $localFilename;
        
        try {
            // Download the file
            $response = $this->client->get($url, [
                'sink' => $localPath,
                'headers' => [
                    'User-Agent' => 'ClickUp-Comment-Dethreader/1.0'
                ]
            ]);
            
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException("Failed to download attachment: HTTP {$response->getStatusCode()}");
            }
            
            return $localPath;
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to download attachment: " . $e->getMessage());
        }
    }

    /**
     * Track an attachment in the tracking file
     * 
     * @param string $taskId The task ID
     * @param string $commentId The comment ID
     * @param string $originalUrl The original URL
     * @param string $localPath The local file path
     * @return void
     */
    public function trackAttachment(string $taskId, string $commentId, string $originalUrl, string $localPath): void
    {
        $tracking = $this->loadTracking();
        
        if (!isset($tracking[$taskId])) {
            $tracking[$taskId] = [];
        }
        
        if (!isset($tracking[$taskId][$commentId])) {
            $tracking[$taskId][$commentId] = [];
        }
        
        $tracking[$taskId][$commentId][] = [
            'original_url' => $originalUrl,
            'local_path' => $localPath,
            'timestamp' => time(),
            'downloaded_at' => date('Y-m-d H:i:s')
        ];
        
        $this->saveTracking($tracking);
    }

    /**
     * Load tracking data from JSON file
     * 
     * @return array
     */
    private function loadTracking(): array
    {
        if (!file_exists($this->trackingFile)) {
            return [];
        }
        
        $content = file_get_contents($this->trackingFile);
        $data = json_decode($content, true);
        
        return $data ?? [];
    }

    /**
     * Save tracking data to JSON file
     * 
     * @param array $tracking
     * @return void
     */
    private function saveTracking(array $tracking): void
    {
        file_put_contents($this->trackingFile, json_encode($tracking, JSON_PRETTY_PRINT));
    }

    /**
     * Sanitize filename for filesystem
     * 
     * @param string $filename
     * @return string
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove path components
        $filename = basename($filename);
        
        // URL decode
        $filename = urldecode($filename);
        
        // Remove or replace invalid characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Limit length
        if (strlen($filename) > 200) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 200 - strlen($ext) - 1) . '.' . $ext;
        }
        
        return $filename;
    }

    /**
     * Get the downloads directory path
     * 
     * @return string
     */
    public function getDownloadsDir(): string
    {
        return $this->downloadsDir;
    }
}

