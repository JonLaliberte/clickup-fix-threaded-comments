<?php

namespace ClickUpCommentDethreader;

use Dotenv\Dotenv;

class Config
{
    private static ?Config $instance = null;
    private array $config;

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();

        $this->config = [
            'CLICKUP_API_KEY' => $_ENV['CLICKUP_API_KEY'] ?? '',
            'CLICKUP_TEAM_ID' => $_ENV['CLICKUP_TEAM_ID'] ?? '',
            'CLICKUP_API_BASE_URL' => $_ENV['CLICKUP_API_BASE_URL'] ?? 'https://api.clickup.com/api/v2',
            'CLICKUP_RATE_LIMIT_DELAY' => (int)($_ENV['CLICKUP_RATE_LIMIT_DELAY'] ?? 600000), // microseconds, default 0.6 seconds
        ];

        $this->validateConfig();
    }

    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key): string
    {
        return $this->config[$key] ?? '';
    }

    public function getAll(): array
    {
        return $this->config;
    }

    private function validateConfig(): void
    {
        if (empty($this->config['CLICKUP_API_KEY'])) {
            throw new \RuntimeException('CLICKUP_API_KEY is required in .env file');
        }

        if (empty($this->config['CLICKUP_TEAM_ID'])) {
            throw new \RuntimeException('CLICKUP_TEAM_ID is required in .env file');
        }
    }
}
