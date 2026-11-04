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

        // Attachment URLs come from comment content, which any workspace member
        // can control. Only fetch from ClickUp's own hosts so a crafted URL can't
        // make this tool read internal network resources and re-upload them.
        self::assertAllowedUrl($url);

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
                ],
                'allow_redirects' => [
                    'max' => 5,
                    'protocols' => ['https'],
                    'on_redirect' => function ($request, $response, $uri) {
                        self::assertAllowedUrl((string) $uri);
                    },
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException("Failed to download attachment: HTTP {$response->getStatusCode()}");
            }

            return $localPath;
        } catch (GuzzleException $e) {
            if (file_exists($localPath)) {
                @unlink($localPath);
            }
            throw new \RuntimeException("Failed to download attachment: " . self::redactUrls($e->getMessage()));
        }
    }

    /** Host suffixes ClickUp serves attachments from. */
    private const ALLOWED_HOST_SUFFIXES = ['clickup.com', 'clickup-attachments.com'];

    /**
     * Reject anything that isn't an https URL on a ClickUp host resolving to a public IP.
     *
     * @throws \RuntimeException
     */
    public static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));

        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['port'])) {
            throw new \RuntimeException("Refusing to download attachment from non-ClickUp URL");
        }

        $allowed = false;
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \RuntimeException("Refusing to download attachment from non-ClickUp host: {$host}");
        }

        $ips = gethostbynamel($host) ?: [];
        if (empty($ips)) {
            throw new \RuntimeException("Could not resolve attachment host: {$host}");
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException("Refusing to download attachment from private address for host: {$host}");
            }
        }
    }

    /**
     * Strip query strings (which can carry signed-URL tokens) from any URLs in a message.
     */
    public static function redactUrls(string $message): string
    {
        return preg_replace('~(https?://[^\s?#"\']+)[?#][^\s"\']*~i', '$1?…', $message);
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

