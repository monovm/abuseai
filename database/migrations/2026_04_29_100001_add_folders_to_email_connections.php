<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            // Folders to poll. Used by IMAP only (POP3 has no concept of folders
            // and always sees the inbox). Empty/null falls back to ["INBOX"] at
            // read time so existing rows keep working without a backfill.
            $table->jsonb('folders')->nullable()->after('ssl');
        });

        // Backfill existing IMAP rows with INBOX plus every common Junk/Spam
        // folder name across cPanel, Outlook, Gmail, and Yahoo. Folders the
        // server doesn't actually expose are silently skipped at poll time, so
        // listing more than the inbox is safe and means BKA/CERT mail that
        // gets misfiled by the receiving server is still picked up. POP3 rows
        // are left null — the protocol dispatcher ignores the column for them.
        $defaultFolders = json_encode([
            'INBOX',
            'INBOX.Junk',
            'INBOX.Spam',
            'INBOX.spam',
            'Junk',
            'Spam',
            'Junk Email',
            '[Gmail]/Spam',
            'Bulk Mail',
        ]);
        DB::table('email_connections')
            ->where('protocol', 'imap')
            ->whereNull('folders')
            ->update(['folders' => $defaultFolders]);
    }

    public function down(): void
    {
        Schema::table('email_connections', function (Blueprint $table) {
            $table->dropColumn('folders');
        });
    }
};
