<?php

namespace ClickUpCommentDethreader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ClickUpApi
{
    private Client $client;
    private Config $config;
    private string $baseUrl;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->baseUrl = $this->config->get('CLICKUP_API_BASE_URL');
        $this->client = new Client([
            'headers' => [
                'Authorization' => $this->config->get('CLICKUP_API_KEY'),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Rate limiting helper - pause between API calls
     */
    private function rateLimit(): void
    {
        // ClickUp API allows 100 requests per minute
        // So we'll wait 0.6 seconds between calls to be safe
        $delay = $this->config->get('CLICKUP_RATE_LIMIT_DELAY');
        usleep($delay);
    }

    /**
     * Get all spaces in the workspace
     */
    public function getSpaces(): array
    {
        try {
            $this->rateLimit();
            $response = $this->client->get($this->baseUrl . "/team/{$this->config->get('CLICKUP_TEAM_ID')}/space?archived=false");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get spaces: " . $e->getMessage());
        }
    }

    /**
     * Get all folders in a space
     */
    public function getFolders(string $spaceId): array
    {
        try {
            $this->rateLimit();
            $response = $this->client->get($this->baseUrl . "/space/{$spaceId}/folder");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get folders: " . $e->getMessage());
        }
    }

    /**
     * Get all lists in a space
     */
    public function getLists(string $spaceId): array
    {
        try {
            $this->rateLimit();
            $response = $this->client->get($this->baseUrl . "/space/{$spaceId}/list");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get lists: " . $e->getMessage());
        }
    }

    /**
     * Get all lists in a folder
     */
    public function getFolderLists(string $folderId): array
    {
        try {
            $this->rateLimit();
            $response = $this->client->get($this->baseUrl . "/folder/{$folderId}/list");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get folder lists: " . $e->getMessage());
        }
    }

    /**
     * Get tasks from a list (excluding closed tasks)
     */
    public function getTasks(string $listId, int $page = 0): array
    {
        try {
            $this->rateLimit();
            $params = [
                'page' => $page,
                'include_closed' => false,
                'subtasks' => true,
            ];
            
            $response = $this->client->get($this->baseUrl . "/list/{$listId}/task", [
                'query' => $params
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get tasks: " . $e->getMessage());
        }
    }

    /**
     * Get comments for a task
     */
    public function getTaskComments(string $taskId): array
    {
        try {
            $this->rateLimit();
            $response = $this->client->get($this->baseUrl . "/task/{$taskId}/comment");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get task comments: " . $e->getMessage());
        }
    }

    /**
     * Get a specific comment
     */
    public function getComment(string $commentId): array
    {
        try {
            $response = $this->client->get($this->baseUrl . "/comment/{$commentId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get comment: " . $e->getMessage());
        }
    }

    /**
     * Get replies for a specific comment
     */
    public function getCommentReplies(string $commentId): array
    {
        try {
            $this->rateLimit();
            $response = $this->client->get($this->baseUrl . "/comment/{$commentId}/reply");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to get comment replies: " . $e->getMessage());
        }
    }

    /**
     * Create a new comment on a task
     * 
     * @param string $taskId The task ID
     * @param string|array $comment Either a plain text string or a rich text comment array
     *                              Format: [{ "text": "...", "attributes": {...} }]
     * @return array API response
     */
    public function createTaskComment(string $taskId, $comment): array
    {
        try {
            $this->rateLimit();
            
            // Determine if it's rich text format (array) or plain text (string)
            $payload = [];
            if (is_array($comment)) {
                // Rich text format with comment array
                $payload['comment'] = $comment;
            } else {
                // Plain text format
                $payload['comment_text'] = $comment;
            }
            
            $response = $this->client->post($this->baseUrl . "/task/{$taskId}/comment", [
                'json' => $payload
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to create task comment: " . $e->getMessage());
        }
    }

    /**
     * Create a new comment on a task with file attachments
     * 
     * ClickUp API approach: Create comment first, then attach files to the comment
     * 
     * @param string $taskId The task ID
     * @param string|array $comment Either a plain text string or a rich text comment array
     *                              Format: [{ "text": "...", "attributes": {...} }]
     * @param array $attachmentPaths Array of local file paths to attach
     * @return array API response
     */
    public function createTaskCommentWithAttachments(string $taskId, $comment, array $attachmentPaths = []): array
    {
        try {
            $this->rateLimit();
            
            // If no attachments, use the simple method
            if (empty($attachmentPaths)) {
                return $this->createTaskComment($taskId, $comment);
            }
            
            // Step 1: Create the comment first (without attachments)
            $commentResponse = $this->createTaskComment($taskId, $comment);
            
            // ClickUp API returns comment in different possible structures
            $commentId = $commentResponse['comment']['id'] 
                      ?? $commentResponse['id'] 
                      ?? $commentResponse['data']['comment']['id'] 
                      ?? null;
            
            if (!$commentId) {
                throw new \RuntimeException("Failed to create comment: No comment ID returned. Response: " . json_encode($commentResponse));
            }
            
            // Step 2: Attach files to the comment
            $multipartClient = new Client([
                'headers' => [
                    'Authorization' => $this->config->get('CLICKUP_API_KEY'),
                ]
            ]);
            
            $attachedFiles = [];
            foreach ($attachmentPaths as $filePath) {
                if (!file_exists($filePath)) {
                    throw new \RuntimeException("Attachment file not found: {$filePath}");
                }
                
                $this->rateLimit();
                
                try {
                    $fileHandle = fopen($filePath, 'r');
                    if ($fileHandle === false) {
                        throw new \RuntimeException("Failed to open file: {$filePath}");
                    }
                    
                    // ClickUp API: Attach file to task (ClickUp doesn't support attaching files directly to comments)
                    // Files uploaded to tasks can be referenced in comments but appear in task attachments
                    // Note: Guzzle will automatically close the file handle
                    $response = $multipartClient->post($this->baseUrl . "/task/{$taskId}/attachment", [
                        'multipart' => [
                            [
                                'name' => 'attachment',
                                'contents' => $fileHandle,
                                'filename' => basename($filePath)
                            ]
                        ]
                    ]);
                    
                    // Don't close $fileHandle - Guzzle handles it
                    
                    if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
                        $responseData = json_decode($response->getBody()->getContents(), true);
                        $attachmentUrl = $responseData['url'] 
                                      ?? $responseData['attachment']['url'] 
                                      ?? $responseData['data']['url'] 
                                      ?? null;
                        
                        $attachedFiles[] = [
                            'filename' => basename($filePath),
                            'url' => $attachmentUrl,
                            'path' => $filePath
                        ];
                    }
                } catch (\Exception $e) {
                    // Log warning but continue with other attachments
                    echo "  Warning: Failed to attach file " . basename($filePath) . ": " . $e->getMessage() . "\n";
                }
            }
            
            // Return the comment response with attachment info
            $commentResponse['attachments_added'] = count($attachedFiles);
            $commentResponse['attached_files'] = $attachedFiles;
            
            return $commentResponse;
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Failed to create task comment with attachments: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to create task comment with attachments: " . $e->getMessage());
        }
    }
}
