<?php

namespace ClickUpCommentDethreader;

use DateTime;
use DateTimeZone;
use ClickUpCommentDethreader\AttachmentManager;
use ClickUpCommentDethreader\DatabaseManager;

class CommentAnalyzer
{
    private ClickUpApi $api;
    private ?DatabaseManager $db;

    public function __construct(ClickUpApi $api, ?DatabaseManager $db = null)
    {
        $this->api = $api;
        $this->db = $db;
    }

    /**
     * Analyze comments for a task and find threaded comments
     */
    public function findThreadedComments(string $taskId, ?string $listId = null): array
    {
        $comments = $this->api->getTaskComments($taskId);
        $threadedComments = [];

        if (!isset($comments['comments'])) {
            return $threadedComments;
        }

        foreach ($comments['comments'] as $comment) {
            // Check if this comment has replies by making a separate API call
            try {
                $replies = $this->api->getCommentReplies($comment['id']);
                
                // ClickUp API returns replies in a 'comments' array, not 'replies'
                if (isset($replies['comments']) && !empty($replies['comments'])) {
                    $threadedComment = [
                        'task_id' => $taskId,
                        'list_id' => $listId,
                        'comment' => $comment,
                        'reply_count' => count($replies['comments']),
                        'replies' => $replies['comments']
                    ];
                    
                    $threadedComments[] = $threadedComment;
                    
                    // Store in database if available
                    if ($this->db) {
                        $this->db->storeThreadedComment($threadedComment);
                        
                        // Initialize attachment manager for backup mode
                        $attachmentManager = new AttachmentManager();
                        
                        // Store each reply and download attachments
                        foreach ($replies['comments'] as $reply) {
                            $this->db->storeCommentReply($comment['id'], $reply, $taskId);
                            
                            // Extract and download attachments during backup
                            $attachments = $this->extractAttachments($reply);
                            foreach ($attachments as $attachment) {
                                $localPath = null;
                                
                                // Download attachment during backup
                                try {
                                    $localPath = $attachmentManager->downloadAttachment(
                                        $attachment['url'],
                                        $taskId,
                                        $reply['id'],
                                        $attachment['filename']
                                    );
                                    
                                    $attachmentManager->trackAttachment(
                                        $taskId,
                                        $reply['id'],
                                        $attachment['url'],
                                        $localPath
                                    );
                                } catch (\Exception $e) {
                                    echo "        Warning: Failed to download attachment {$attachment['filename']}: " . $e->getMessage() . "\n";
                                }
                                
                                // Store attachment in database
                                $this->db->storeAttachment([
                                    'task_id' => $taskId,
                                    'reply_id' => $reply['id'],
                                    'type' => $attachment['type'],
                                    'url' => $attachment['url'],
                                    'filename' => $attachment['filename'],
                                    'local_path' => $localPath
                                ]);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // If we can't get replies, skip this comment
                continue;
            }
        }

        return $threadedComments;
    }

    /**
     * Get a formatted list of threaded comments for display
     */
    public function formatThreadedComments(array $threadedComments): array
    {
        $formatted = [];

        foreach ($threadedComments as $thread) {
            $comment = $thread['comment'];
            $formatted[] = [
                'task_id' => $thread['task_id'],
                'comment_id' => $comment['id'],
                'comment_text' => $this->truncateText($comment['comment_text'], 100),
                'author' => $comment['user']['username'] ?? 'Unknown',
                'date' => $comment['date_created'] ?? $comment['date'] ?? 'Unknown',
                'reply_count' => $thread['reply_count'],
                'view_url' => $this->generateTaskUrl($thread['task_id']),
                'replies' => $thread['replies']
            ];
        }

        return $formatted;
    }

    /**
     * Truncate text to specified length
     */
    private function truncateText(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }

    /**
     * Generate ClickUp task URL
     */
    private function generateTaskUrl(string $taskId): string
    {
        return "https://app.clickup.com/t/{$taskId}";
    }

    /**
     * Display threaded comments in a formatted way
     */
    public function displayThreadedComments(array $threadedComments): void
    {
        if (empty($threadedComments)) {
            echo "No threaded comments found.\n";
            return;
        }

        echo "\n=== THREADED COMMENTS FOUND ===\n\n";
        
        foreach ($threadedComments as $index => $thread) {
            $num = $index + 1;
            echo "{$num}. Task: {$thread['task_id']}\n";
            echo "   Comment: {$thread['comment_text']}\n";
            echo "   Author: {$thread['author']}\n";
            echo "   Replies: {$thread['reply_count']}\n";
            echo "   View: {$thread['view_url']}\n";
            echo "   Date: {$thread['date']}\n";
            echo "\n";
        }

        echo "Total threaded comments found: " . count($threadedComments) . "\n\n";
    }

    /**
     * Unthread a comment by creating a new top-level comment with the reply content
     * 
     * @param array $threadedComment The threaded comment data
     * @param AttachmentManager|null $attachmentManager Optional attachment manager
     * @return array ['text' => string, 'attachments' => array] Text and list of downloaded attachment paths
     */
    public function unthreadComment(array $threadedComment, ?AttachmentManager $attachmentManager = null): array
    {
        $comment = $threadedComment['comment'];
        $replies = $threadedComment['replies'];
        $taskId = $threadedComment['task_id'];
        $allAttachments = [];
        
        // Sort replies by date ascending
        usort($replies, function($a, $b) {
            $dateA = $a['date_created'] ?? $a['date'] ?? 0;
            $dateB = $b['date_created'] ?? $b['date'] ?? 0;
            return $dateA <=> $dateB;
        });
        
        $unthreadText = "Unthreading Comments\n\n";
        
        foreach ($replies as $reply) {
            $replyId = $reply['id'];
            $unthreadText .= "Commenter: " . ($reply['user']['username'] ?? 'Unknown') . "\n";
            $unthreadText .= "Timestamp: " . $this->formatTimestamp($reply['date_created'] ?? $reply['date'] ?? null) . "\n\n";
            $unthreadText .= "In reply to: " . $this->truncateText($comment['comment_text'], 200) . "\n\n";
            
            // Extract and handle attachments
            $attachments = $this->extractAttachments($reply);
            if (!empty($attachments) && $attachmentManager) {
                foreach ($attachments as $attachment) {
                    try {
                        $localPath = $attachmentManager->downloadAttachment(
                            $attachment['url'],
                            $taskId,
                            $replyId,
                            $attachment['filename']
                        );
                        
                        $attachmentManager->trackAttachment(
                            $taskId,
                            $replyId,
                            $attachment['url'],
                            $localPath
                        );
                        
                        // Store attachment in database
                        if ($this->db) {
                            $this->db->storeAttachment([
                                'task_id' => $taskId,
                                'reply_id' => $replyId,
                                'type' => $attachment['type'],
                                'url' => $attachment['url'],
                                'filename' => $attachment['filename'],
                                'local_path' => $localPath
                            ]);
                        }
                        
                        $allAttachments[] = $localPath;
                        $unthreadText .= "📎 Attachment: {$attachment['filename']} (downloaded as: " . basename($localPath) . ")\n";
                    } catch (\Exception $e) {
                        $unthreadText .= "⚠️ Failed to download attachment {$attachment['filename']}: " . $e->getMessage() . "\n";
                    }
                }
            } elseif (!empty($attachments)) {
                // No attachment manager, just list them
                foreach ($attachments as $attachment) {
                    $unthreadText .= "📎 Attachment: {$attachment['filename']} (URL: {$attachment['url']})\n";
                    
                    // Store attachment metadata in database even if not downloaded
                    if ($this->db) {
                        $this->db->storeAttachment([
                            'task_id' => $taskId,
                            'reply_id' => $replyId,
                            'type' => $attachment['type'],
                            'url' => $attachment['url'],
                            'filename' => $attachment['filename']
                        ]);
                    }
                }
            }
            
            $unthreadText .= $reply['comment_text'] . "\n\n";
            $unthreadText .= "---\n\n";
        }
        
        return [
            'text' => trim($unthreadText),
            'attachments' => $allAttachments
        ];
    }

    /**
     * Format timestamp to yyyy-mm-dd hh:mm am/pm Eastern time
     */
    private function formatTimestamp($timestamp): string
    {
        if (!$timestamp) {
            return 'Unknown';
        }
        
        // ClickUp timestamps appear to be in milliseconds since epoch
        $seconds = intval($timestamp / 1000);
        
        // Create DateTime object in UTC
        $utc = new DateTime();
        $utc->setTimestamp($seconds);
        
        // Convert to Eastern time
        $eastern = new DateTimeZone('America/New_York');
        $utc->setTimezone($eastern);
        
        // Format as yyyy-mm-dd hh:mm am/pm
        return $utc->format('Y-m-d h:i A');
    }

    /**
     * Extract attachments from a reply comment structure
     * 
     * @param array $reply The reply comment array
     * @return array Array of attachment data with url, filename, type, etc.
     */
    public function extractAttachments(array $reply): array
    {
        $attachments = [];
        
        // Check if reply has a 'comment' array structure
        if (!isset($reply['comment']) || !is_array($reply['comment'])) {
            return $attachments;
        }
        
        foreach ($reply['comment'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            // Check for image attachments
            if (isset($item['type']) && $item['type'] === 'image' && isset($item['image'])) {
                $image = $item['image'];
                $attachments[] = [
                    'type' => 'image',
                    'url' => $image['url'] ?? '',
                    'filename' => urldecode($image['name'] ?? $item['text'] ?? 'unknown.jpg'),
                    'original_name' => $image['name'] ?? $item['text'] ?? 'unknown.jpg',
                    'extension' => $image['extension'] ?? pathinfo($image['name'] ?? '', PATHINFO_EXTENSION),
                ];
            }
            // Check for file attachments (PDFs, documents, etc.)
            elseif (isset($item['type']) && $item['type'] === 'attachment' && isset($item['attachment'])) {
                $attachment = $item['attachment'];
                $filename = urldecode($attachment['title'] ?? $item['text'] ?? 'unknown');
                $attachments[] = [
                    'type' => 'attachment',
                    'url' => $attachment['url'] ?? '',
                    'filename' => $filename,
                    'original_name' => $attachment['title'] ?? $item['text'] ?? 'unknown',
                    'extension' => $attachment['extension'] ?? pathinfo($filename, PATHINFO_EXTENSION),
                    'mimetype' => $attachment['mimetype'] ?? null,
                    'size' => $attachment['size'] ?? null,
                ];
            }
        }
        
        return $attachments;
    }
}
