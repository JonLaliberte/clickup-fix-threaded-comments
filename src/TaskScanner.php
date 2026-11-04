<?php

namespace ClickUpCommentDethreader;

use ClickUpCommentDethreader\AttachmentManager;
use ClickUpCommentDethreader\DatabaseManager;

class TaskScanner
{
    private ClickUpApi $api;
    private CommentAnalyzer $analyzer;
    private ?DatabaseManager $db;

    public function __construct(ClickUpApi $api, CommentAnalyzer $analyzer, ?DatabaseManager $db = null)
    {
        $this->api = $api;
        $this->analyzer = $analyzer;
        $this->db = $db;
    }

    /**
     * Scan a single list for threaded comments
     */
    public function scanSingleList(string $listId): array
    {
        echo "Starting scan of list ID: {$listId}...\n\n";
        return $this->scanList($listId);
    }

    /**
     * Scan all tasks in the workspace for threaded comments
     */
    public function scanAllTasks(): array
    {
        echo "Starting scan of all tasks in workspace...\n\n";
        
        $allThreadedComments = [];
        $spaces = $this->api->getSpaces();
        
        if (!isset($spaces['spaces'])) {
            echo "No spaces found in workspace.\n";
            return $allThreadedComments;
        }

        foreach ($spaces['spaces'] as $space) {
            echo "Scanning space: {$space['name']} (ID: {$space['id']})\n";
            $spaceThreadedComments = $this->scanSpace($space['id']);
            $allThreadedComments = array_merge($allThreadedComments, $spaceThreadedComments);
        }

        return $allThreadedComments;
    }

    /**
     * Scan all tasks in a specific space
     */
    private function scanSpace(string $spaceId): array
    {
        $spaceThreadedComments = [];
        
        // Get folders in the space
        $folders = $this->api->getFolders($spaceId);
        if (isset($folders['folders'])) {
            foreach ($folders['folders'] as $folder) {
                echo "  Scanning folder: {$folder['name']}\n";
                $folderThreadedComments = $this->scanFolder($folder['id']);
                $spaceThreadedComments = array_merge($spaceThreadedComments, $folderThreadedComments);
            }
        }

        // Get lists directly in the space (not in folders)
        $lists = $this->api->getLists($spaceId);
        if (isset($lists['lists'])) {
            foreach ($lists['lists'] as $list) {
                echo "  Scanning list: {$list['name']}\n";
                $listThreadedComments = $this->scanList($list['id']);
                $spaceThreadedComments = array_merge($spaceThreadedComments, $listThreadedComments);
            }
        }

        return $spaceThreadedComments;
    }

    /**
     * Scan all tasks in a specific folder
     */
    private function scanFolder(string $folderId): array
    {
        $folderThreadedComments = [];
        $lists = $this->api->getFolderLists($folderId);
        
        if (isset($lists['lists'])) {
            foreach ($lists['lists'] as $list) {
                echo "    Scanning list: {$list['name']}\n";
                $listThreadedComments = $this->scanList($list['id']);
                $folderThreadedComments = array_merge($folderThreadedComments, $listThreadedComments);
            }
        }

        return $folderThreadedComments;
    }

    /**
     * Scan all tasks in a specific list
     */
    private function scanList(string $listId): array
    {
        $listThreadedComments = [];
        $page = 0;
        $hasMoreTasks = true;

        while ($hasMoreTasks) {
            $tasks = $this->api->getTasks($listId, $page);
            
            if (!isset($tasks['tasks']) || empty($tasks['tasks'])) {
                $hasMoreTasks = false;
                continue;
            }

            foreach ($tasks['tasks'] as $task) {
                echo "      Checking task: {$task['name']} (ID: {$task['id']})\n";
                
                try {
                    $taskThreadedComments = $this->analyzer->findThreadedComments($task['id'], $listId);
                    
                    if (!empty($taskThreadedComments)) {
                        $formattedComments = $this->analyzer->formatThreadedComments($taskThreadedComments);
                        $listThreadedComments = array_merge($listThreadedComments, $formattedComments);
                        echo "        Found " . count($taskThreadedComments) . " threaded comments\n";
                    }
                } catch (\Exception $e) {
                    echo "        Error checking task {$task['id']}: " . $e->getMessage() . "\n";
                    continue;
                }
            }

            // Check if there are more pages
            if (count($tasks['tasks']) < 100) { // ClickUp typically returns 100 tasks per page
                $hasMoreTasks = false;
            } else {
                $page++;
            }
        }

        return $listThreadedComments;
    }

    /**
     * Get summary statistics
     */
    public function getSummary(array $threadedComments): array
    {
        $totalComments = count($threadedComments);
        $totalReplies = 0;
        $uniqueTasks = [];
        $authors = [];

        foreach ($threadedComments as $comment) {
            $totalReplies += $comment['reply_count'];
            $uniqueTasks[$comment['task_id']] = true;
            $authors[$comment['author']] = true;
        }

        return [
            'total_threaded_comments' => $totalComments,
            'total_replies' => $totalReplies,
            'unique_tasks' => count($uniqueTasks),
            'unique_authors' => count($authors)
        ];
    }


    /**
     * Read threaded comments from database for unthreading
     */
    public function unthreadFromDatabase(?string $taskId = null, ?string $commentId = null, ?string $listId = null): array
    {
        if (!$this->db) {
            throw new \RuntimeException("Database not available for unthreading mode");
        }
        
        return $this->db->getThreadedComments($taskId, $commentId, $listId);
    }

    /**
     * Process unthreading from database data
     * Creates one comment per reply (not per parent)
     */
    public function processUnthreading(array $threadedComments): void
    {
        if (empty($threadedComments)) {
            echo "No threaded comments to process.\n";
            return;
        }
        
        $totalReplies = 0;
        foreach ($threadedComments as $threadedComment) {
            $totalReplies += count($threadedComment['replies'] ?? []);
        }
        
        echo "Processing " . count($threadedComments) . " threaded comment(s) with {$totalReplies} total reply(ies)...\n\n";
        
        foreach ($threadedComments as $index => $threadedComment) {
            $comment = $threadedComment['comment'];
            $taskId = $threadedComment['task_id'];
            $replies = $threadedComment['replies'] ?? [];
            $replyCount = count($replies);
            
            echo "Processing parent comment {$comment['id']} in task {$taskId} ({$replyCount} replies)...\n";
            
            // Generate unthread comments (one per reply)
            $replyComments = $this->unthreadFromDatabaseData($threadedComment);
            
            foreach ($replyComments as $replyIndex => $replyData) {
                $replyCommentArray = $replyData['comment'];
                $attachments = $replyData['attachments'];
                $reply = $replies[$replyIndex] ?? [];
                $replyId = $reply['id'] ?? $reply['reply_id'] ?? 'unknown';
                $replyNumber = $replyIndex + 1;
                
                echo "\n  Reply {$replyNumber}/" . count($replyComments) . " (ID: {$replyId}):\n";
                
                // Display preview of comment (simplified)
                $previewText = '';
                foreach ($replyCommentArray as $part) {
                    $previewText .= $part['text'];
                }
                echo "  Preview: " . substr(strip_tags($previewText), 0, 100) . "...\n";
                
                if (!empty($attachments)) {
                    echo "  Attachments: " . count($attachments) . " file(s)\n";
                    foreach ($attachments as $attachment) {
                        echo "    - " . basename($attachment) . "\n";
                    }
                }
                
                // Create the new comment with attachments
                try {
                    if (!empty($attachments)) {
                        $result = $this->api->createTaskCommentWithAttachments($taskId, $replyCommentArray, $attachments);
                        $newCommentId = $result['comment']['id'] ?? null;
                        echo "  ✓ Successfully created unthreaded comment with " . count($attachments) . " attachment(s)\n";
                        
                        // Store unthread operation in database
                        if ($this->db) {
                            $this->db->storeUnthreadOperation([
                                'task_id' => $taskId,
                                'parent_comment_id' => $comment['id'] ?? $comment['comment_id'] ?? '',
                                'new_comment_id' => $newCommentId,
                                'unthreaded_text' => $previewText,
                                'attachment_count' => count($attachments),
                                'status' => 'success'
                            ]);
                        }
                    } else {
                        $result = $this->api->createTaskComment($taskId, $replyCommentArray);
                        $newCommentId = $result['comment']['id'] ?? null;
                        echo "  ✓ Successfully created unthreaded comment\n";
                        
                        // Store unthread operation in database
                        if ($this->db) {
                            $this->db->storeUnthreadOperation([
                                'task_id' => $taskId,
                                'parent_comment_id' => $comment['id'] ?? $comment['comment_id'] ?? '',
                                'new_comment_id' => $newCommentId,
                                'unthreaded_text' => $previewText,
                                'attachment_count' => 0,
                                'status' => 'success'
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    echo "  ✗ Error creating comment: " . $e->getMessage() . "\n";
                    
                    // Store failed unthread operation in database
                    if ($this->db) {
                        $this->db->storeUnthreadOperation([
                            'task_id' => $taskId,
                            'parent_comment_id' => $comment['id'] ?? $comment['comment_id'] ?? '',
                            'unthreaded_text' => $previewText,
                            'attachment_count' => count($attachments ?? []),
                            'status' => 'failed',
                            'error_message' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            echo "\n";
        }
        
        echo "\nUnthreading complete!\n";
    }

    /**
     * Generate unthread comments from database data - returns array of comments (one per reply)
     */
    private function unthreadFromDatabaseData(array $threadedComment): array
    {
        $comment = $threadedComment['comment'];
        $replies = $threadedComment['replies'];
        $taskId = $threadedComment['task_id'];
        
        // Sort replies by date ascending
        usort($replies, function($a, $b) {
            $dateA = $a['date_created'] ?? $a['date'] ?? 0;
            $dateB = $b['date_created'] ?? $b['date'] ?? 0;
            return $dateA <=> $dateB;
        });
        
        $comments = [];
        foreach ($replies as $reply) {
            $comments[] = $this->buildRichTextCommentForReply($comment, $reply, $taskId);
        }
        
        return $comments;
    }

    /**
     * Build rich text comment array for a single reply
     * 
     * @param array $parentComment The parent comment data
     * @param array $reply The reply comment data
     * @param string $taskId The task ID
     * @return array Contains 'comment' (rich text array) and 'attachments' (file paths)
     */
    private function buildRichTextCommentForReply(array $parentComment, array $reply, string $taskId): array
    {
        $commentArray = [];
        $attachments = [];
        
        // "Unthreading Comments" - gray text
        $commentArray[] = [
            'text' => "Unthreading Comments\n\n",
            'attributes' => ['color' => '#808080']
        ];
        
        // "Timestamp: " - gray (not bold)
        $timestamp = $this->formatTimestampFromDb($reply['date_created'] ?? $reply['date'] ?? null);
        $commentArray[] = [
            'text' => "Timestamp: ",
            'attributes' => ['color' => '#808080']
        ];
        
        // Timestamp value - gray
        $commentArray[] = [
            'text' => $timestamp . "\n",
            'attributes' => ['color' => '#808080']
        ];
        
        // "From: " - bold
        $commentArray[] = [
            'text' => "From: ",
            'attributes' => ['bold' => true]
        ];
        
        // Author name - plain
        $authorName = $reply['user']['username'] ?? $reply['author'] ?? 'Unknown';
        $commentArray[] = [
            'text' => $authorName . "\n"
        ];
        
        
        // "In reply to: " - bold
        $commentArray[] = [
            'text' => "In reply to: ",
            'attributes' => ['bold' => true]
        ];
        
        // Parent comment text - bold + link
        $parentCommentText = $this->truncateText($parentComment['comment_text'] ?? '', 200);
        $parentCommentId = $parentComment['id'] ?? $parentComment['comment_id'] ?? '';
        $parentCommentUrl = "https://app.clickup.com/t/{$taskId}?comment={$parentCommentId}";
        $commentArray[] = [
            'text' => $parentCommentText,
            'attributes' => [
                'bold' => true,
                'link' => $parentCommentUrl
            ]
        ];
        
        $commentArray[] = ['text' => "\n\n"];
        
        // Handle attachments from database - moved below comment text
        $replyAttachments = $reply['attachments'] ?? $reply['_db_attachments'] ?? [];
        
        // Quote indicator and reply text - use quote attribute
        // Remove attachment references from the quoted text
        $replyText = $this->removeAttachmentReferencesFromText(
            $reply['comment_text'] ?? '',
            $replyAttachments
        );
        
        if (!empty($replyText)) {
            // Format the reply text as a code block
            // Remove trailing line breaks from the text
            $replyText = rtrim($replyText, "\n\r");
            
            // The code-block attribute should be on the text segment itself
            $commentArray[] = [
                'text' => $replyText,
                'attributes' => [
                    'code-block' => [
                        'code-block' => 'plain'
                    ]
                ]
            ];
            $commentArray[] = ['text' => "\n\n"];
        }
        
        // List attachments below the quoted text
        if (!empty($replyAttachments)) {
            foreach ($replyAttachments as $attachment) {
                $localPath = $attachment['local_path'] ?? null;
                $filename = $attachment['original_filename'] ?? 'Unknown';
                $attachmentUrl = $attachment['url'] ?? $attachment['original_url'] ?? null;
                
                if (!empty($localPath) && file_exists($localPath)) {
                    $attachments[] = $localPath;
                }
                
                // Create link to attachment if URL is available
                if ($attachmentUrl) {
                    $commentArray[] = [
                        'text' => "📎 ",
                    ];
                    $commentArray[] = [
                        'text' => $filename,
                        'attributes' => ['link' => $attachmentUrl]
                    ];
                    $commentArray[] = ['text' => "\n"];
                } else {
                    // No URL available, just show filename
                    $commentArray[] = ['text' => "📎 Attachment: {$filename}\n"];
                }
            }
        }
        
        return [
            'comment' => $commentArray,
            'attachments' => $attachments
        ];
    }

    /**
     * Format timestamp from database (already formatted or timestamp)
     */
    private function formatTimestampFromDb($timestamp): string
    {
        if (!$timestamp) {
            return 'Unknown';
        }
        
        // If it's already a formatted string, return it
        if (is_string($timestamp) && preg_match('/\d{4}-\d{2}-\d{2}/', $timestamp)) {
            return $timestamp;
        }
        
        // Otherwise format it
        try {
            $seconds = intval($timestamp / 1000);
            $utc = new \DateTime();
            $utc->setTimestamp($seconds);
            $eastern = new \DateTimeZone('America/New_York');
            $utc->setTimezone($eastern);
            return $utc->format('Y-m-d h:i A');
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Truncate text helper
     */
    private function truncateText(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }

    /**
     * Remove attachment references from comment text
     * Removes lines that contain attachment filenames or attachment indicators
     * 
     * @param string $text The original comment text
     * @param array $attachments Array of attachment data with filenames
     * @return string Cleaned text without attachment references
     */
    private function removeAttachmentReferencesFromText(string $text, array $attachments): string
    {
        if (empty($text) || empty($attachments)) {
            return $text;
        }
        
        // Extract all attachment filenames
        $attachmentFilenames = [];
        foreach ($attachments as $attachment) {
            $filename = $attachment['original_filename'] ?? $attachment['filename'] ?? null;
            if ($filename) {
                $attachmentFilenames[] = preg_quote($filename, '/');
            }
        }
        
        if (empty($attachmentFilenames)) {
            return $text;
        }
        
        // Split text into lines
        $lines = explode("\n", $text);
        $cleanedLines = [];
        
        foreach ($lines as $line) {
            $shouldRemove = false;
            
            // Check if line contains any attachment filename
            foreach ($attachmentFilenames as $filename) {
                if (preg_match('/' . $filename . '/i', $line)) {
                    $shouldRemove = true;
                    break;
                }
            }
            
            // Also check for common attachment indicators
            if (preg_match('/📎\s*(Attachment|File|Document)/i', $line)) {
                $shouldRemove = true;
            }
            
            // Check for attachment URL patterns
            if (preg_match('/https?:\/\/.*\.(pdf|jpg|jpeg|png|gif|doc|docx|xls|xlsx|zip|rar)/i', $line)) {
                $shouldRemove = true;
            }
            
            if (!$shouldRemove) {
                $cleanedLines[] = $line;
            }
        }
        
        return implode("\n", $cleanedLines);
    }

    /**
     * Handle unthreading comments - create new top-level comments (legacy method, kept for compatibility)
     */
    private function handleUnthreadComments(array $threadedComments, string $contextName): void
    {
        echo "\n=== UNTHREADING COMMENTS ===\n\n";
        
        // Check if the comments are already formatted or need formatting
        if (isset($threadedComments[0]['comment_text'])) {
            // Already formatted - we need the original structure for unthreading
            echo "Error: Cannot unthread already formatted comments.\n";
            return;
        }
        
        // Initialize attachment manager
        $attachmentManager = new AttachmentManager();
        
        foreach ($threadedComments as $index => $threadedComment) {
            $comment = $threadedComment['comment'];
            $taskId = $threadedComment['task_id'];
            
            echo "Processing comment {$comment['id']} in task {$taskId}...\n";
            
            // Generate the unthread text and download attachments
            $result = $this->analyzer->unthreadComment($threadedComment, $attachmentManager);
            $unthreadText = $result['text'];
            $attachments = $result['attachments'];
            
            if (!empty($attachments)) {
                echo "  Downloaded " . count($attachments) . " attachment(s)\n";
            }
            
            echo "\nGenerated comment text:\n";
            echo "---\n";
            echo $unthreadText;
            echo "\n---\n\n";
            
            if (!empty($attachments)) {
                echo "Attachments to be uploaded:\n";
                foreach ($attachments as $attachment) {
                    echo "  - " . basename($attachment) . "\n";
                }
                echo "\n";
            }
            
            // Ask for confirmation
            do {
                echo "Does this look good? (y/n): ";
                $input = trim(strtolower(fgets(STDIN)));
                
                if ($input === 'n' || $input === 'no') {
                    echo "\nExiting without making changes.\n";
                    exit(0);
                } elseif ($input === 'y' || $input === 'yes' || $input === '') {
                    break;
                } else {
                    echo "Please enter 'y' for yes or 'n' for no.\n";
                }
            } while (true);
            
            // Create the new comment with attachments
            try {
                if (!empty($attachments)) {
                    $result = $this->api->createTaskCommentWithAttachments($taskId, $unthreadText, $attachments);
                    $newCommentId = $result['comment']['id'] ?? null;
                    echo "✓ Successfully created unthreaded comment with " . count($attachments) . " attachment(s) for task {$taskId}\n\n";
                    
                    // Store unthread operation in database
                    if ($this->db) {
                        $this->db->storeUnthreadOperation([
                            'task_id' => $taskId,
                            'parent_comment_id' => $comment['id'],
                            'new_comment_id' => $newCommentId,
                            'unthreaded_text' => $unthreadText,
                            'attachment_count' => count($attachments),
                            'status' => 'success'
                        ]);
                    }
                } else {
                    $result = $this->api->createTaskComment($taskId, $unthreadText);
                    $newCommentId = $result['comment']['id'] ?? null;
                    echo "✓ Successfully created unthreaded comment for task {$taskId}\n\n";
                    
                    // Store unthread operation in database
                    if ($this->db) {
                        $this->db->storeUnthreadOperation([
                            'task_id' => $taskId,
                            'parent_comment_id' => $comment['id'],
                            'new_comment_id' => $newCommentId,
                            'unthreaded_text' => $unthreadText,
                            'attachment_count' => 0,
                            'status' => 'success'
                        ]);
                    }
                }
            } catch (\Exception $e) {
                echo "✗ Error creating comment: " . $e->getMessage() . "\n\n";
                
                // Store failed unthread operation in database
                if ($this->db) {
                    $this->db->storeUnthreadOperation([
                        'task_id' => $taskId,
                        'parent_comment_id' => $comment['id'],
                        'unthreaded_text' => $unthreadText,
                        'attachment_count' => count($attachments ?? []),
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ]);
                }
            }
        }
        
        echo "Unthreading complete!\n";
        exit(0);
    }
}
