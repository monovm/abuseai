<?php

namespace App\Services\Ingestion;

use App\Support\Email\ParsesMimeEmail;
use Illuminate\Support\Facades\Log;

/**
 * Minimal pure-PHP IMAP client speaking the wire protocol over a TCP/SSL
 * socket — no ext-imap, no Composer dependency. Designed to mirror
 * EmailIngestionService's public API plus an extra $folder argument so
 * callers can target Junk/Spam (or any custom folder) the same way.
 *
 * Only the commands we actually need are implemented: LOGIN, LIST, SELECT,
 * UID SEARCH, UID FETCH (headers + body), UID STORE \Deleted, EXPUNGE,
 * LOGOUT. Everything goes through the same tagged-command reader so a server
 * tracing extra continuation lines, untagged responses, or BAD/NO replies
 * can't desync the parser.
 */
class ImapIngestionService
{
    use ParsesMimeEmail;

    /** Per-connection command tag counter. Reset on each connect(). */
    protected int $tagCounter = 0;

    /**
     * List recent emails in a folder. Returns array of
     * ['id' (uid), 'from', 'subject', 'date', 'to', 'message_id'].
     * `$limit` slices the most recent N UIDs (server-side ordering).
     */
    public function listEmails(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl = true,
        int $limit = 50,
        string $folder = 'INBOX',
    ): array {
        $conn = $this->connect($host, $port, $username, $password, $ssl);
        if (! $conn) {
            return [];
        }

        try {
            if (! $this->selectFolder($conn, $folder)) {
                $this->logout($conn);
                return [];
            }

            $uids = $this->searchAllUids($conn);
            if (empty($uids)) {
                $this->logout($conn);
                return [];
            }

            // Most-recent first, capped to $limit.
            sort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, -$limit);
            $uids = array_reverse($uids);

            $emails = [];
            foreach ($uids as $uid) {
                $headerText = $this->fetchHeaderText($conn, $uid);
                if ($headerText === null) {
                    continue;
                }
                $headers = $this->parseHeaders($headerText);
                $headers['id'] = $uid;
                $emails[] = $headers;
            }

            $this->logout($conn);
            return $emails;
        } catch (\Throwable $e) {
            Log::error('IMAP list error: ' . $e->getMessage(), ['folder' => $folder]);
            $this->safeClose($conn);
            return [];
        }
    }

    /**
     * Fetch a full message by UID.
     */
    public function fetchEmail(
        string $host,
        int $port,
        string $username,
        string $password,
        int $messageId,
        bool $ssl = true,
        string $folder = 'INBOX',
    ): ?array {
        $conn = $this->connect($host, $port, $username, $password, $ssl);
        if (! $conn) {
            return null;
        }

        try {
            if (! $this->selectFolder($conn, $folder)) {
                $this->logout($conn);
                return null;
            }

            $raw = $this->fetchRawMessage($conn, $messageId);

            $this->logout($conn);

            if (! $raw) {
                return null;
            }

            return $this->parseEmail($raw, $messageId);
        } catch (\Throwable $e) {
            Log::error('IMAP fetch error: ' . $e->getMessage(), ['folder' => $folder, 'uid' => $messageId]);
            $this->safeClose($conn);
            return null;
        }
    }

    /**
     * Mark a message \Deleted and EXPUNGE. IMAP needs both — STORE alone
     * just sets the flag, EXPUNGE actually removes it from the mailbox.
     */
    public function deleteEmail(
        string $host,
        int $port,
        string $username,
        string $password,
        int $messageId,
        bool $ssl = true,
        string $folder = 'INBOX',
    ): bool {
        $conn = $this->connect($host, $port, $username, $password, $ssl);
        if (! $conn) {
            return false;
        }

        try {
            if (! $this->selectFolder($conn, $folder)) {
                $this->logout($conn);
                return false;
            }

            $store = $this->command($conn, "UID STORE {$messageId} +FLAGS (\\Deleted)");
            $expunge = $this->command($conn, 'EXPUNGE');

            $this->logout($conn);

            return ($store['ok'] ?? false) && ($expunge['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::error('IMAP delete error: ' . $e->getMessage(), ['folder' => $folder, 'uid' => $messageId]);
            $this->safeClose($conn);
            return false;
        }
    }

    /**
     * Probe a connection: log in, SELECT the folder, return EXISTS count.
     */
    public function testConnection(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl = true,
        string $folder = 'INBOX',
    ): array {
        $conn = $this->connect($host, $port, $username, $password, $ssl);
        if (! $conn) {
            return ['success' => false, 'error' => 'Failed to connect to IMAP server'];
        }

        try {
            // escapeAstring() already wraps the folder in IMAP quotes — adding
            // outer "..." here would send `SELECT ""INBOX""` and the server
            // would reject it as "Invalid characters in atom".
            $select = $this->command($conn, 'SELECT ' . $this->escapeAstring($folder));
            if (! ($select['ok'] ?? false)) {
                $this->logout($conn);
                return ['success' => false, 'error' => "SELECT {$folder} failed: " . ($select['text'] ?? 'unknown')];
            }

            $exists = 0;
            foreach ($select['untagged'] ?? [] as $line) {
                if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                    $exists = (int) $m[1];
                    break;
                }
            }

            $this->logout($conn);

            return [
                'success' => true,
                'messages' => $exists,
                'size_bytes' => 0, // IMAP has no cheap STAT equivalent
                'folder' => $folder,
            ];
        } catch (\Throwable $e) {
            $this->safeClose($conn);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List all selectable folders on the server (LIST "" "*"). Useful for
     * the admin UI's folder picker.
     *
     * @return array<int, string>
     */
    public function listFolders(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $ssl = true,
    ): array {
        $conn = $this->connect($host, $port, $username, $password, $ssl);
        if (! $conn) {
            return [];
        }

        try {
            $resp = $this->command($conn, 'LIST "" "*"');
            $this->logout($conn);

            if (! ($resp['ok'] ?? false)) {
                return [];
            }

            $folders = [];
            foreach ($resp['untagged'] ?? [] as $line) {
                // * LIST (\HasNoChildren) "/" "INBOX.Junk"
                if (preg_match('/^\* LIST\s+\([^)]*\)\s+(?:"[^"]*"|NIL)\s+(?:"([^"]+)"|(\S+))/i', $line, $m)) {
                    $folders[] = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                }
            }

            return array_values(array_filter(array_unique($folders)));
        } catch (\Throwable $e) {
            Log::error('IMAP listFolders error: ' . $e->getMessage());
            $this->safeClose($conn);
            return [];
        }
    }

    // ---------- session API (caller manages the lifecycle) ----------

    /**
     * Open a long-lived IMAP session: SSL connect + LOGIN. Caller passes
     * the returned socket to selectFolderInSession / listUidsInSession /
     * fetchEmailInSession / deleteEmailInSession, then closes it with
     * closeSession. Lets the auto-poller process a whole folder in one
     * round-trip per message instead of three (connect+login+select per
     * fetch + per delete), which is a ~3× perf win on busy folders.
     *
     * Returns null on auth/connect failure.
     *
     * @return resource|null
     */
    public function openSession(string $host, int $port, string $username, string $password, bool $ssl): mixed
    {
        return $this->connect($host, $port, $username, $password, $ssl);
    }

    public function selectFolderInSession(mixed $conn, string $folder): bool
    {
        return $this->selectFolder($conn, $folder);
    }

    /**
     * UID SEARCH ALL on the currently selected folder, sorted newest-first
     * and capped to $limit. Returns an array of UIDs (integers).
     *
     * @return array<int, int>
     */
    public function listUidsInSession(mixed $conn, int $limit): array
    {
        $uids = $this->searchAllUids($conn);
        if (empty($uids)) {
            return [];
        }
        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, -$limit);
        return array_reverse($uids);
    }

    /**
     * Fetch the parsed full message for $uid in the currently selected
     * folder. Returns null on FETCH failure.
     */
    public function fetchEmailInSession(mixed $conn, int $uid): ?array
    {
        $raw = $this->fetchRawMessage($conn, $uid);
        if (! $raw) {
            return null;
        }
        return $this->parseEmail($raw, $uid);
    }

    /**
     * Mark $uid \Deleted and EXPUNGE in the active folder.
     */
    public function deleteEmailInSession(mixed $conn, int $uid): bool
    {
        $store = $this->command($conn, "UID STORE {$uid} +FLAGS (\\Deleted)");
        $expunge = $this->command($conn, 'EXPUNGE');
        return ($store['ok'] ?? false) && ($expunge['ok'] ?? false);
    }

    public function closeSession(mixed $conn): void
    {
        $this->logout($conn);
    }

    // ---------- protocol internals ----------

    protected function connect(string $host, int $port, string $username, string $password, bool $ssl): mixed
    {
        try {
            $this->tagCounter = 0;
            $protocol = $ssl ? 'ssl' : 'tcp';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $conn = @stream_socket_client(
                "{$protocol}://{$host}:{$port}",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if (! $conn) {
                Log::error("IMAP connect failed: {$errstr} ({$errno})");
                return null;
            }

            stream_set_timeout($conn, 30);

            // Read greeting (untagged) — must be * OK
            $greeting = $this->readLine($conn);
            if (! $greeting || ! preg_match('/^\* OK/i', $greeting)) {
                fclose($conn);
                return null;
            }

            $login = $this->command($conn, 'LOGIN ' . $this->escapeAstring($username) . ' ' . $this->escapeAstring($password));
            if (! ($login['ok'] ?? false)) {
                fclose($conn);
                Log::error('IMAP LOGIN failed: ' . ($login['text'] ?? 'unknown'));
                return null;
            }

            return $conn;
        } catch (\Throwable $e) {
            Log::error('IMAP connection error: ' . $e->getMessage());
            return null;
        }
    }

    protected function selectFolder(mixed $conn, string $folder): bool
    {
        $resp = $this->command($conn, 'SELECT ' . $this->escapeAstring($folder));
        if (! ($resp['ok'] ?? false)) {
            Log::warning('IMAP SELECT failed', ['folder' => $folder, 'text' => $resp['text'] ?? null]);
            return false;
        }
        return true;
    }

    /**
     * UID SEARCH ALL → array of UIDs.
     *
     * @return array<int, int>
     */
    protected function searchAllUids(mixed $conn): array
    {
        $resp = $this->command($conn, 'UID SEARCH ALL');
        if (! ($resp['ok'] ?? false)) {
            return [];
        }

        $uids = [];
        foreach ($resp['untagged'] ?? [] as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $line, $m)) {
                $idsStr = trim($m[1]);
                if ($idsStr !== '') {
                    foreach (preg_split('/\s+/', $idsStr) as $id) {
                        if (ctype_digit($id)) {
                            $uids[] = (int) $id;
                        }
                    }
                }
            }
        }

        return $uids;
    }

    /**
     * Fetch only the header block for a UID. Returns the raw header text
     * (RFC822-style) ready for parseHeaders(), or null on failure.
     */
    protected function fetchHeaderText(mixed $conn, int $uid): ?string
    {
        $resp = $this->command($conn, "UID FETCH {$uid} (BODY.PEEK[HEADER])");
        if (! ($resp['ok'] ?? false)) {
            return null;
        }
        return $resp['literal'] ?? null;
    }

    /**
     * Fetch the full RFC822 message body for a UID.
     */
    protected function fetchRawMessage(mixed $conn, int $uid): ?string
    {
        $resp = $this->command($conn, "UID FETCH {$uid} (BODY.PEEK[])");
        if (! ($resp['ok'] ?? false)) {
            return null;
        }
        return $resp['literal'] ?? null;
    }

    /**
     * Send a tagged IMAP command and read until the matching tagged reply.
     * Handles `{N}` octet-count literals inline.
     *
     * Returns:
     *   ok       — tagged response was OK
     *   text     — final tag's status text (for diagnostics)
     *   untagged — array of untagged response lines (lines starting with `*`)
     *   literal  — concatenated literal payload(s) from any FETCH responses
     */
    protected function command(mixed $conn, string $command): array
    {
        $tag = $this->nextTag();
        @fwrite($conn, "{$tag} {$command}\r\n");

        $untagged = [];
        $literal = '';
        $ok = false;
        $text = '';

        while (true) {
            $line = $this->readLine($conn);
            if ($line === null) {
                break;
            }

            // Inline literal: { ... <CRLF> N bytes ... <CRLF>
            if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $needed = (int) $m[1];
                $payload = $this->readBytes($conn, $needed);
                $literal .= $payload;
                // The literal is followed by more response lines (typically `)` and the tagged OK).
                // Append the line that prefixed the literal as untagged for context.
                if (str_starts_with($line, '*')) {
                    $untagged[] = $line;
                }
                continue;
            }

            if (str_starts_with($line, "{$tag} ")) {
                // Tagged completion line: "<tag> OK ..." | "NO ..." | "BAD ..."
                $rest = substr($line, strlen($tag) + 1);
                if (preg_match('/^(OK|NO|BAD)\b\s*(.*)$/i', $rest, $mm)) {
                    $status = strtoupper($mm[1]);
                    $text = $mm[2];
                    $ok = ($status === 'OK');
                }
                break;
            }

            if (str_starts_with($line, '*')) {
                $untagged[] = $line;
                continue;
            }

            // Stray continuation/extra line (e.g. closing `)` after a literal)
            // — we don't need it but mustn't desync.
        }

        return [
            'ok' => $ok,
            'text' => $text,
            'untagged' => $untagged,
            'literal' => $literal !== '' ? $literal : null,
        ];
    }

    protected function readLine(mixed $conn): ?string
    {
        $line = @fgets($conn, 65536);
        if ($line === false) {
            return null;
        }
        return rtrim($line, "\r\n");
    }

    /**
     * Read exactly N bytes from the socket. fgets()-style loops can short-read
     * over slow links; fread loop guarantees we consume the whole literal.
     */
    protected function readBytes(mixed $conn, int $bytes): string
    {
        $buf = '';
        $remaining = $bytes;
        while ($remaining > 0) {
            $chunk = @fread($conn, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
            $remaining = $bytes - strlen($buf);
        }
        return $buf;
    }

    protected function nextTag(): string
    {
        $this->tagCounter++;
        return 'A' . str_pad((string) $this->tagCounter, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Wrap a value in IMAP astring quoting. Used for usernames, passwords,
     * and folder names so spaces and special chars survive the wire.
     */
    protected function escapeAstring(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function logout(mixed $conn): void
    {
        try {
            $this->command($conn, 'LOGOUT');
        } catch (\Throwable) {
        }
        try {
            @fclose($conn);
        } catch (\Throwable) {
        }
    }

    protected function safeClose(mixed $conn): void
    {
        try {
            if (is_resource($conn)) {
                @fwrite($conn, "X999 LOGOUT\r\n");
                @fclose($conn);
            }
        } catch (\Throwable) {
        }
    }
}
