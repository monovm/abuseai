<?php

namespace App\Services\Ingestion;

use App\Models\EmailConnection;

/**
 * Protocol-aware façade for mailbox access. Hides POP3 vs IMAP from
 * callers (PollAbuseMailbox, the Livewire inbox UI, etc.) — they pass an
 * EmailConnection plus an optional folder and the dispatcher routes to
 * the right backend.
 *
 * For POP3, $folder is ignored: POP3 has no folder concept and always
 * exposes a single inbox.
 */
class MailboxClient
{
    public function __construct(
        protected EmailIngestionService $pop3,
        protected ImapIngestionService $imap,
    ) {}

    public function listEmails(EmailConnection $conn, ?string $folder = null, int $limit = 50): array
    {
        if ($conn->protocol === 'imap') {
            return $this->imap->listEmails(
                $conn->host, $conn->port, $conn->username, $conn->password,
                $conn->ssl, $limit, $folder ?: 'INBOX',
            );
        }

        return $this->pop3->listEmails(
            $conn->host, $conn->port, $conn->username, $conn->password,
            $conn->ssl, $limit,
        );
    }

    public function fetchEmail(EmailConnection $conn, ?string $folder, int|string $messageId): ?array
    {
        if ($conn->protocol === 'imap') {
            return $this->imap->fetchEmail(
                $conn->host, $conn->port, $conn->username, $conn->password,
                (int) $messageId, $conn->ssl, $folder ?: 'INBOX',
            );
        }

        return $this->pop3->fetchEmail(
            $conn->host, $conn->port, $conn->username, $conn->password,
            (int) $messageId, $conn->ssl,
        );
    }

    public function deleteEmail(EmailConnection $conn, ?string $folder, int|string $messageId): bool
    {
        if ($conn->protocol === 'imap') {
            return $this->imap->deleteEmail(
                $conn->host, $conn->port, $conn->username, $conn->password,
                (int) $messageId, $conn->ssl, $folder ?: 'INBOX',
            );
        }

        return $this->pop3->deleteEmail(
            $conn->host, $conn->port, $conn->username, $conn->password,
            (int) $messageId, $conn->ssl,
        );
    }

    public function testConnection(EmailConnection $conn, ?string $folder = null): array
    {
        if ($conn->protocol === 'imap') {
            return $this->imap->testConnection(
                $conn->host, $conn->port, $conn->username, $conn->password,
                $conn->ssl, $folder ?: 'INBOX',
            );
        }

        return $this->pop3->testConnection(
            $conn->host, $conn->port, $conn->username, $conn->password, $conn->ssl,
        );
    }

    /**
     * Process every message in a folder through one persistent session.
     *
     * The handler is invoked once per message with the parsed email and a
     * `$delete` callable; the handler decides whether to call $delete()
     * after processing (e.g. skip vs imported vs follow-up). For IMAP this
     * holds a single SSL+LOGIN+SELECT session for the whole folder, so a
     * 50-message Junk folder costs 1 connect instead of 1 + 50 + 50, which
     * is the difference between ~5 seconds and ~100 seconds per folder.
     *
     * For POP3 there's no folder concept, so the call is just a thin
     * wrapper around the per-call API; the perf characteristics there
     * are unchanged (and POP3 round-trips are cheap anyway).
     *
     * The handler signature is:
     *     fn (array $email, callable $delete): void
     *
     * Returns the number of messages the handler saw.
     */
    public function processFolder(
        EmailConnection $conn,
        ?string $folder,
        int $limit,
        callable $handler,
    ): int {
        if ($conn->protocol === 'imap') {
            return $this->processImapFolder($conn, $folder ?: 'INBOX', $limit, $handler);
        }

        return $this->processPop3Folder($conn, $limit, $handler);
    }

    protected function processImapFolder(
        EmailConnection $conn,
        string $folder,
        int $limit,
        callable $handler,
    ): int {
        $session = $this->imap->openSession(
            $conn->host, $conn->port, $conn->username, $conn->password, $conn->ssl,
        );
        if (! $session) {
            return 0;
        }

        try {
            if (! $this->imap->selectFolderInSession($session, $folder)) {
                return 0;
            }

            $uids = $this->imap->listUidsInSession($session, $limit);
            if (empty($uids)) {
                return 0;
            }

            $count = 0;
            foreach ($uids as $uid) {
                $email = $this->imap->fetchEmailInSession($session, $uid);
                if (! $email) {
                    continue;
                }

                $delete = function () use ($session, $uid): bool {
                    return $this->imap->deleteEmailInSession($session, $uid);
                };

                $handler($email, $delete);
                $count++;
            }

            return $count;
        } finally {
            $this->imap->closeSession($session);
        }
    }

    protected function processPop3Folder(
        EmailConnection $conn,
        int $limit,
        callable $handler,
    ): int {
        $headers = $this->pop3->listEmails(
            $conn->host, $conn->port, $conn->username, $conn->password, $conn->ssl, $limit,
        );
        if (empty($headers)) {
            return 0;
        }

        $count = 0;
        foreach ($headers as $h) {
            $email = $this->pop3->fetchEmail(
                $conn->host, $conn->port, $conn->username, $conn->password,
                (int) $h['id'], $conn->ssl,
            );
            if (! $email) {
                continue;
            }

            $delete = function () use ($conn, $h): bool {
                return $this->pop3->deleteEmail(
                    $conn->host, $conn->port, $conn->username, $conn->password,
                    (int) $h['id'], $conn->ssl,
                );
            };

            $handler($email, $delete);
            $count++;
        }

        return $count;
    }

    /**
     * Discover folders on the server (IMAP only). POP3 returns a single
     * synthetic INBOX entry so UI code doesn't need to special-case it.
     *
     * @return array<int, string>
     */
    public function listFolders(EmailConnection $conn): array
    {
        if ($conn->protocol !== 'imap') {
            return ['INBOX'];
        }

        return $this->imap->listFolders(
            $conn->host, $conn->port, $conn->username, $conn->password, $conn->ssl,
        );
    }
}
