<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ClickUpCommentDethreader\Config;
use ClickUpCommentDethreader\ClickUpApi;
use ClickUpCommentDethreader\CommentAnalyzer;
use ClickUpCommentDethreader\TaskScanner;
use ClickUpCommentDethreader\DatabaseManager;

/**
 * ClickUp Comment De-threading Tool
 * 
 * This tool finds comments with threaded replies in a ClickUp workspace.
 * Backup mode stores threaded comments (and their attachments) locally;
 * unthread mode re-posts each reply as a top-level comment.
 */

class ClickUpCommentDethreader
{
    private Config $config;
    private ClickUpApi $api;
    private CommentAnalyzer $analyzer;
    private TaskScanner $scanner;
    private DatabaseManager $db;
    private string $mode;
    private ?string $listId = null;
    private ?string $taskId = null;
    private ?string $commentId = null;

    public function __construct()
    {
        try {
            $this->config = Config::getInstance();
            $this->api = new ClickUpApi();
            $this->db = new DatabaseManager();
            $this->analyzer = new CommentAnalyzer($this->api, $this->db);
            $this->scanner = new TaskScanner($this->api, $this->analyzer, $this->db);
            $this->parseCommandLineArgs();
        } catch (Exception $e) {
            $this->displayError("Configuration error: " . $e->getMessage());
            exit(1);
        }
    }

    public function run(): void
    {
        $this->displayHeader();
        
        try {
            if ($this->mode === 'backup') {
                $this->runBackupMode();
            } elseif ($this->mode === 'unthread') {
                $this->runUnthreadMode();
            } else {
                throw new Exception("Unknown mode: {$this->mode}");
            }
        } catch (Exception $e) {
            $this->displayError("Error during execution: " . $e->getMessage());
            exit(1);
        }
    }

    private function runBackupMode(): void
    {
        // Test API connection
        $this->testApiConnection();
        
        echo "=== BACKUP MODE ===\n";
        echo "Scanning workspace and downloading all attachments...\n\n";
        
        // Scan for threaded comments
        if ($this->listId) {
            echo "Scanning only list ID {$this->listId}\n\n";
            $threadedComments = $this->scanner->scanSingleList($this->listId);
        } else {
            $threadedComments = $this->scanner->scanAllTasks();
        }
        
        // Display results
        $this->displayResults($threadedComments);
        
        echo "\nBackup complete! All data stored in database.\n";
        echo "Database: " . $this->db->getDbPath() . "\n\n";
    }

    private function runUnthreadMode(): void
    {
        echo "=== UNTHREADING MODE ===\n";
        echo "Reading from database...\n\n";
        
        // Read threaded comments from database
        $threadedComments = $this->scanner->unthreadFromDatabase($this->taskId, $this->commentId, $this->listId);
        
        if (empty($threadedComments)) {
            echo "No threaded comments found in database";
            if ($this->taskId) {
                echo " for task ID: {$this->taskId}";
            }
            if ($this->commentId) {
                echo " for comment ID: {$this->commentId}";
            }
            if ($this->listId) {
                echo " for list ID: {$this->listId}";
            }
            echo "\n";
            return;
        }
        
        echo "Found " . count($threadedComments) . " threaded comment(s) to process.\n\n";
        
        // Process unthreading
        $this->scanner->processUnthreading($threadedComments);
    }

    private function displayHeader(): void
    {
        echo "==========================================\n";
        echo "ClickUp Comment De-threading Tool v1.0\n";
        echo "==========================================\n\n";
        echo "Mode: " . strtoupper($this->mode) . "\n";
        echo "Team ID: " . $this->config->get('CLICKUP_TEAM_ID') . "\n";
        echo "Database: " . $this->db->getDbPath() . "\n";
        if ($this->listId) {
            echo "List ID: {$this->listId}\n";
        }
        if ($this->taskId) {
            echo "Task ID: {$this->taskId}\n";
        }
        if ($this->commentId) {
            echo "Comment ID: {$this->commentId}\n";
        }
        echo "\n";
        
        echo "Usage: php src/main.php [mode] [options]\n";
        echo "Modes:\n";
        echo "  --backup (default): Scan workspace, download attachments, store in database\n";
        echo "  --unthread: Read from database and create unthreaded comments\n";
        echo "Options:\n";
        echo "  --list-id <id>: Limit to specific list (backup mode and unthread mode)\n";
        echo "  --task-id <id>: Limit to specific task (unthread mode)\n";
        echo "  --comment-id <id>: Limit to specific comment (unthread mode)\n\n";
    }

    private function testApiConnection(): void
    {
        echo "Testing API connection...\n";
        
        try {
            $spaces = $this->api->getSpaces();
            if (isset($spaces['spaces'])) {
                echo "✓ Connected successfully! Found " . count($spaces['spaces']) . " spaces.\n\n";
            } else {
                throw new Exception("No spaces found in workspace");
            }
        } catch (Exception $e) {
            throw new Exception("Failed to connect to ClickUp API: " . $e->getMessage());
        }
    }

    private function displayResults(array $threadedComments): void
    {
        if (empty($threadedComments)) {
            echo "\n🎉 No threaded comments found in your workspace!\n";
            return;
        }

        // Display formatted results
        $this->analyzer->displayThreadedComments($threadedComments);
        
        // Display summary
        $summary = $this->scanner->getSummary($threadedComments);
        $this->displaySummary($summary);
    }

    private function displaySummary(array $summary): void
    {
        echo "=== SUMMARY ===\n";
        echo "Total threaded comments: {$summary['total_threaded_comments']}\n";
        echo "Total replies: {$summary['total_replies']}\n";
        echo "Tasks affected: {$summary['unique_tasks']}\n";
        echo "Authors involved: {$summary['unique_authors']}\n\n";
    }

    private function parseCommandLineArgs(): void
    {
        $args = $_SERVER['argv'] ?? [];
        $this->mode = 'backup'; // Default to backup mode
        $this->listId = null;
        $this->taskId = null;
        $this->commentId = null;
        
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            
            if ($arg === '--backup') {
                $this->mode = 'backup';
            } elseif ($arg === '--unthread') {
                $this->mode = 'unthread';
            } elseif ($arg === '--list-id' && isset($args[$i + 1])) {
                $this->listId = $args[$i + 1];
                $i++; // Skip next argument as it's the list ID
            } elseif ($arg === '--task-id' && isset($args[$i + 1])) {
                $this->taskId = $args[$i + 1];
                $i++; // Skip next argument as it's the task ID
            } elseif ($arg === '--comment-id' && isset($args[$i + 1])) {
                $this->commentId = $args[$i + 1];
                $i++; // Skip next argument as it's the comment ID
            }
        }
    }

    private function displayError(string $message): void
    {
        echo "\n❌ ERROR: {$message}\n\n";
        echo "Please check your .env file and ensure:\n";
        echo "1. CLICKUP_API_KEY is set correctly\n";
        echo "2. CLICKUP_TEAM_ID is set correctly\n";
        echo "3. Your API key has the necessary permissions\n\n";
    }
}

// Run the application
try {
    $app = new ClickUpCommentDethreader();
    $app->run();
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
