<?php

namespace App\Services\Ingestion;

use App\Support\Email\ParsesMimeEmail;
use Illuminate\Support\Facades\Log;

class EmailIngestionService
{
    use ParsesMimeEmail;

    /**
     * Connect to POP3 mailbox and return list of emails.
     * Returns array of ['id', 'from', 'subject', 'date', 'size', 'body_preview']
     */
    public function listEmails(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl = true,
        int $limit = 50,
    ): array {
        $connection = $this->connect($host, $port, $username, $password, $ssl);

        if (! $connection) {
            return [];
        }

        try {
            $stat = $this->command($connection, 'STAT');
            if (! $stat || ! str_starts_with($stat, '+OK')) {
                return [];
            }

            $parts = explode(' ', $stat);
            $totalMessages = (int) ($parts[1] ?? 0);

            if ($totalMessages === 0) {
                return [];
            }

            $emails = [];
            $start = max(1, $totalMessages - $limit + 1);

            for ($i = $totalMessages; $i >= $start; $i--) {
                $headers = $this->getHeaders($connection, $i);
                if ($headers) {
                    $headers['id'] = $i;
                    $emails[] = $headers;
                }
            }

            $this->command($connection, 'QUIT');
            fclose($connection);

            return $emails;
        } catch (\Throwable $e) {
            Log::error('POP3 list error: ' . $e->getMessage());
            $this->safeClose($connection);
            return [];
        }
    }

    /**
     * Fetch a full email by message number.
     */
    public function fetchEmail(
        string $host,
        int $port,
        string $username,
        string $password,
        int $messageId,
        bool $ssl = true,
    ): ?array {
        $connection = $this->connect($host, $port, $username, $password, $ssl);

        if (! $connection) {
            return null;
        }

        try {
            $raw = $this->retrieveMessage($connection, $messageId);

            $this->command($connection, 'QUIT');
            fclose($connection);

            if (! $raw) {
                return null;
            }

            return $this->parseEmail($raw, $messageId);
        } catch (\Throwable $e) {
            Log::error('POP3 fetch error: ' . $e->getMessage());
            $this->safeClose($connection);
            return null;
        }
    }

    /**
     * Delete an email by message number.
     */
    public function deleteEmail(
        string $host,
        int $port,
        string $username,
        string $password,
        int $messageId,
        bool $ssl = true,
    ): bool {
        $connection = $this->connect($host, $port, $username, $password, $ssl);

        if (! $connection) {
            return false;
        }

        try {
            $result = $this->command($connection, "DELE {$messageId}");
            $this->command($connection, 'QUIT');
            fclose($connection);

            return $result && str_starts_with($result, '+OK');
        } catch (\Throwable $e) {
            Log::error('POP3 delete error: ' . $e->getMessage());
            $this->safeClose($connection);
            return false;
        }
    }

    /**
     * Test POP3 connection.
     */
    public function testConnection(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl = true,
    ): array {
        $connection = $this->connect($host, $port, $username, $password, $ssl);

        if (! $connection) {
            return ['success' => false, 'error' => 'Failed to connect to POP3 server'];
        }

        try {
            $stat = $this->command($connection, 'STAT');
            $parts = explode(' ', $stat ?? '');
            $messageCount = (int) ($parts[1] ?? 0);
            $totalSize = (int) ($parts[2] ?? 0);

            $this->command($connection, 'QUIT');
            fclose($connection);

            return [
                'success' => true,
                'messages' => $messageCount,
                'size_bytes' => $totalSize,
            ];
        } catch (\Throwable $e) {
            $this->safeClose($connection);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Connect to POP3 server via socket.
     */
    protected function connect(string $host, int $port, string $username, string $password, bool $ssl): mixed
    {
        try {
            $protocol = $ssl ? 'ssl' : 'tcp';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $connection = @stream_socket_client(
                "{$protocol}://{$host}:{$port}",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if (! $connection) {
                Log::error("POP3 connect failed: {$errstr} ({$errno})");
                return null;
            }

            // Read greeting
            $greeting = fgets($connection, 1024);
            if (! $greeting || ! str_starts_with(trim($greeting), '+OK')) {
                fclose($connection);
                return null;
            }

            // Authenticate
            $userResp = $this->command($connection, "USER {$username}");
            if (! $userResp || ! str_starts_with($userResp, '+OK')) {
                fclose($connection);
                return null;
            }

            $passResp = $this->command($connection, "PASS {$password}");
            if (! $passResp || ! str_starts_with($passResp, '+OK')) {
                fclose($connection);
                return null;
            }

            return $connection;
        } catch (\Throwable $e) {
            Log::error('POP3 connection error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send a POP3 command and read response.
     */
    protected function command(mixed $connection, string $command): ?string
    {
        fwrite($connection, "{$command}\r\n");
        $response = fgets($connection, 1024);

        return $response ? trim($response) : null;
    }

    /**
     * Get headers for a message using TOP command.
     */
    protected function getHeaders(mixed $connection, int $messageId): ?array
    {
        fwrite($connection, "TOP {$messageId} 0\r\n");
        $firstLine = fgets($connection, 1024);

        if (! $firstLine || ! str_starts_with(trim($firstLine), '+OK')) {
            return null;
        }

        $headerText = '';
        while (true) {
            $line = fgets($connection, 8192);
            if ($line === false || trim($line) === '.') {
                break;
            }
            $headerText .= $line;
        }

        return $this->parseHeaders($headerText);
    }

    /**
     * Retrieve full message.
     */
    protected function retrieveMessage(mixed $connection, int $messageId): ?string
    {
        fwrite($connection, "RETR {$messageId}\r\n");
        $firstLine = fgets($connection, 1024);

        if (! $firstLine || ! str_starts_with(trim($firstLine), '+OK')) {
            return null;
        }

        $message = '';
        while (true) {
            $line = fgets($connection, 8192);
            if ($line === false || trim($line) === '.') {
                break;
            }
            // Unescape dot-stuffing
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $message .= $line;
        }

        return $message;
    }

    protected function safeClose(mixed $connection): void
    {
        try {
            if (is_resource($connection)) {
                @fwrite($connection, "QUIT\r\n");
                @fclose($connection);
            }
        } catch (\Throwable) {
        }
    }
}
