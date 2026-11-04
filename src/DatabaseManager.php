<?php

namespace ClickUpCommentDethreader;

use PDO;
use PDOException;

class DatabaseManager
{
    private PDO $db;
    private string $dbPath;

    public function __construct()
    {
        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        $this->dbPath = $dataDir . '/comments.db';
        $this->connect();
        $this->initializeSchema();
    }

    /**
     * Connect to SQLite database
     */
    private function connect(): void
    {
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to connect to database: " . $e->getMessage());
        }
    }

    /**
     * Initialize database schema
     */
    private function initializeSchema(): void
    {
        // Table for threaded (parent) comments
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS threaded_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT NOT NULL,
                comment_id TEXT NOT NULL UNIQUE,
                list_id TEXT,
                comment_text TEXT,
                author TEXT,
                author_id TEXT,
                date_created INTEGER,
                date_created_formatted TEXT,
                view_url TEXT,
                reply_count INTEGER DEFAULT 0,
                scanned_at INTEGER DEFAULT (strftime('%s', 'now')),
                created_at TEXT DEFAULT (datetime('now')),
                UNIQUE(task_id, comment_id)
            )
        ");

        // Table for comment replies
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS comment_replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_comment_id TEXT NOT NULL,
                reply_id TEXT NOT NULL UNIQUE,
                task_id TEXT NOT NULL,
                reply_text TEXT,
                author TEXT,
                author_id TEXT,
                date_created INTEGER,
                date_created_formatted TEXT,
                scanned_at INTEGER DEFAULT (strftime('%s', 'now')),
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (parent_comment_id) REFERENCES threaded_comments(comment_id),
                UNIQUE(parent_comment_id, reply_id)
            )
        ");

        // Table for attachments
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT NOT NULL,
                comment_id TEXT,
                reply_id TEXT,
                attachment_type TEXT,
                original_url TEXT NOT NULL,
                original_filename TEXT,
                local_path TEXT,
                file_size INTEGER,
                downloaded_at INTEGER,
                downloaded_at_formatted TEXT,
                scanned_at INTEGER DEFAULT (strftime('%s', 'now')),
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (comment_id) REFERENCES threaded_comments(comment_id),
                FOREIGN KEY (reply_id) REFERENCES comment_replies(reply_id)
            )
        ");

        // Table for unthread operations (audit log)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS unthread_operations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT NOT NULL,
                parent_comment_id TEXT NOT NULL,
                new_comment_id TEXT,
                unthreaded_text TEXT,
                attachment_count INTEGER DEFAULT 0,
                operation_status TEXT,
                error_message TEXT,
                performed_at INTEGER DEFAULT (strftime('%s', 'now')),
                performed_at_formatted TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (parent_comment_id) REFERENCES threaded_comments(comment_id)
            )
        ");

        // Create indexes for better query performance
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_threaded_comments_task_id ON threaded_comments(task_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_threaded_comments_comment_id ON threaded_comments(comment_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_threaded_comments_list_id ON threaded_comments(list_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_replies_parent_id ON comment_replies(parent_comment_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_replies_task_id ON comment_replies(task_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_attachments_comment_id ON attachments(comment_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_attachments_reply_id ON attachments(reply_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_attachments_task_id ON attachments(task_id)");
    }

    /**
     * Store a threaded comment
     */
    public function storeThreadedComment(array $threadedComment): void
    {
        $comment = $threadedComment['comment'];
        $taskId = $threadedComment['task_id'];
        $commentId = $comment['id'];
        
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO threaded_comments 
            (task_id, comment_id, list_id, comment_text, author, author_id, date_created, date_created_formatted, view_url, reply_count, scanned_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, strftime('%s', 'now'), datetime('now'))
        ");
        
        $stmt->execute([
            $taskId,
            $commentId,
            $threadedComment['list_id'] ?? null,
            $comment['comment_text'] ?? '',
            $comment['user']['username'] ?? 'Unknown',
            $comment['user']['id'] ?? null,
            $comment['date_created'] ?? $comment['date'] ?? null,
            $this->formatTimestamp($comment['date_created'] ?? $comment['date'] ?? null),
            "https://app.clickup.com/t/{$taskId}",
            $threadedComment['reply_count'] ?? 0
        ]);
    }

    /**
     * Store a comment reply
     */
    public function storeCommentReply(string $parentCommentId, array $reply, string $taskId): void
    {
        $replyId = $reply['id'];
        
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO comment_replies 
            (parent_comment_id, reply_id, task_id, reply_text, author, author_id, date_created, date_created_formatted, scanned_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, strftime('%s', 'now'), datetime('now'))
        ");
        
        $stmt->execute([
            $parentCommentId,
            $replyId,
            $taskId,
            $reply['comment_text'] ?? '',
            $reply['user']['username'] ?? 'Unknown',
            $reply['user']['id'] ?? null,
            $reply['date_created'] ?? $reply['date'] ?? null,
            $this->formatTimestamp($reply['date_created'] ?? $reply['date'] ?? null)
        ]);
    }

    /**
     * Store an attachment
     */
    public function storeAttachment(array $attachmentData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attachments 
            (task_id, comment_id, reply_id, attachment_type, original_url, original_filename, local_path, file_size, downloaded_at, downloaded_at_formatted, scanned_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, strftime('%s', 'now'), datetime('now'))
        ");
        
        $fileSize = null;
        if (isset($attachmentData['local_path']) && file_exists($attachmentData['local_path'])) {
            $fileSize = filesize($attachmentData['local_path']);
        }
        
        $stmt->execute([
            $attachmentData['task_id'],
            $attachmentData['comment_id'] ?? null,
            $attachmentData['reply_id'] ?? null,
            $attachmentData['type'] ?? 'unknown',
            $attachmentData['url'],
            $attachmentData['filename'],
            $attachmentData['local_path'] ?? null,
            $fileSize,
            isset($attachmentData['local_path']) ? time() : null,
            isset($attachmentData['local_path']) ? date('Y-m-d H:i:s') : null
        ]);
    }

    /**
     * Store an unthread operation
     */
    public function storeUnthreadOperation(array $operationData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO unthread_operations 
            (task_id, parent_comment_id, new_comment_id, unthreaded_text, attachment_count, operation_status, error_message, performed_at, performed_at_formatted)
            VALUES (?, ?, ?, ?, ?, ?, ?, strftime('%s', 'now'), datetime('now'))
        ");
        
        $stmt->execute([
            $operationData['task_id'],
            $operationData['parent_comment_id'],
            $operationData['new_comment_id'] ?? null,
            $operationData['unthreaded_text'] ?? null,
            $operationData['attachment_count'] ?? 0,
            $operationData['status'] ?? 'unknown',
            $operationData['error_message'] ?? null
        ]);
    }

    /**
     * Format timestamp to yyyy-mm-dd hh:mm am/pm Eastern time
     */
    private function formatTimestamp($timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        
        try {
            $seconds = intval($timestamp / 1000);
            $utc = new \DateTime();
            $utc->setTimestamp($seconds);
            $eastern = new \DateTimeZone('America/New_York');
            $utc->setTimezone($eastern);
            return $utc->format('Y-m-d h:i A');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get database path
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * Get threaded comments from database
     * 
     * @param string|null $taskId Filter by task ID
     * @param string|null $commentId Filter by comment ID
     * @param string|null $listId Filter by list ID (requires joining with tasks)
     * @return array Array of threaded comment data structures
     */
    public function getThreadedComments(?string $taskId = null, ?string $commentId = null, ?string $listId = null): array
    {
        $query = "
            SELECT 
                tc.task_id,
                tc.comment_id,
                tc.list_id,
                tc.comment_text,
                tc.author,
                tc.author_id,
                tc.date_created,
                tc.date_created_formatted,
                tc.view_url,
                tc.reply_count
            FROM threaded_comments tc
            LEFT JOIN unthread_operations uo ON tc.comment_id = uo.parent_comment_id 
                AND uo.operation_status = 'success'
            WHERE 1=1
                AND uo.id IS NULL
        ";
        
        $params = [];
        
        if ($taskId) {
            $query .= " AND tc.task_id = ?";
            $params[] = $taskId;
        }
        
        if ($commentId) {
            $query .= " AND tc.comment_id = ?";
            $params[] = $commentId;
        }
        
        if ($listId) {
            $query .= " AND tc.list_id = ?";
            $params[] = $listId;
        }
        
        $query .= " ORDER BY tc.date_created ASC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $comments = $stmt->fetchAll();
        
        // Build full threaded comment structures with replies and attachments
        $result = [];
        foreach ($comments as $comment) {
            $replies = $this->getCommentReplies($comment['comment_id']);
            $attachments = $this->getAttachmentsForComment($comment['comment_id']);
            
            // Build comment structure similar to API response
            $commentData = [
                'id' => $comment['comment_id'],
                'comment_text' => $comment['comment_text'],
                'user' => [
                    'id' => $comment['author_id'],
                    'username' => $comment['author']
                ],
                'date_created' => $comment['date_created'],
                'date' => $comment['date_created']
            ];
            
            // Build reply structures
            $replyData = [];
            foreach ($replies as $reply) {
                $replyAttachments = $this->getAttachmentsForReply($reply['reply_id']);
                $replyData[] = [
                    'id' => $reply['reply_id'],
                    'comment_text' => $reply['reply_text'],
                    'user' => [
                        'id' => $reply['author_id'],
                        'username' => $reply['author']
                    ],
                    'date_created' => $reply['date_created'],
                    'date' => $reply['date_created'],
                    'attachments' => $replyAttachments,
                    // Store database attachment data for easy access
                    '_db_attachments' => $replyAttachments
                ];
            }
            
            $result[] = [
                'task_id' => $comment['task_id'],
                'comment' => $commentData,
                'reply_count' => $comment['reply_count'],
                'replies' => $replyData
            ];
        }
        
        return $result;
    }

    /**
     * Get replies for a comment
     */
    private function getCommentReplies(string $parentCommentId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                reply_id,
                task_id,
                reply_text,
                author,
                author_id,
                date_created,
                date_created_formatted
            FROM comment_replies
            WHERE parent_comment_id = ?
            ORDER BY date_created ASC
        ");
        
        $stmt->execute([$parentCommentId]);
        return $stmt->fetchAll();
    }

    /**
     * Get attachments for a comment (parent comment attachments)
     */
    private function getAttachmentsForComment(string $commentId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                attachment_type,
                original_url,
                original_filename,
                local_path,
                file_size
            FROM attachments
            WHERE comment_id = ? AND reply_id IS NULL
        ");
        
        $stmt->execute([$commentId]);
        return $stmt->fetchAll();
    }

    /**
     * Get attachments for a reply
     */
    private function getAttachmentsForReply(string $replyId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                attachment_type,
                original_url,
                original_filename,
                local_path,
                file_size
            FROM attachments
            WHERE reply_id = ?
        ");
        
        $stmt->execute([$replyId]);
        return $stmt->fetchAll();
    }
}

