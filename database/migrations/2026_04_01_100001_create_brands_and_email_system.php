<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Brands — each brand has its own identity, logo, email settings
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // e.g. "Brand A", "Brand B"
            $table->string('slug')->unique();                // e.g. "brand-a", "brand-b"
            $table->string('from_name');                     // e.g. "Brand A Abuse Desk"
            $table->string('from_email');                    // e.g. "abuse@brand-a.example"
            $table->string('reply_to_email')->nullable();    // optional reply-to
            $table->text('email_signature')->nullable();     // HTML signature
            $table->text('email_footer')->nullable();        // plain text footer
            $table->string('logo_url')->nullable();
            $table->string('website_url')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->jsonb('smtp_config')->nullable();        // per-brand SMTP override
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // Email connections — multiple POP3/IMAP inboxes linked to brands
        Schema::create('email_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // e.g. "abuse@brand-a.example inbox"
            $table->uuid('brand_id')->nullable();
            $table->string('protocol')->default('pop3');     // pop3, imap
            $table->string('host');
            $table->integer('port')->default(995);
            $table->string('username');
            $table->text('password');                        // encrypted
            $table->boolean('ssl')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_import')->default(false);  // auto-import on poll
            $table->integer('poll_interval_minutes')->default(5);
            $table->timestamp('last_polled_at')->nullable();
            $table->integer('last_message_count')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        // Sent emails — track all outbound emails
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->foreignId('sent_by')->nullable();        // user who sent
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('from_email');
            $table->string('from_name');
            $table->string('subject');
            $table->text('body_html');
            $table->text('body_text')->nullable();
            $table->string('message_id')->nullable();        // Mandrill message ID
            $table->string('status')->default('sent');       // sent, delivered, bounced, failed
            $table->string('in_reply_to')->nullable();       // original message-id for threading
            $table->jsonb('mandrill_response')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('abuse_cases')->nullOnDelete();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
            $table->foreign('sent_by')->references('id')->on('users')->nullOnDelete();
        });

        // Add brand_id to abuse_cases
        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->uuid('brand_id')->nullable()->after('customer_id');
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        // Add brand_id to email_templates
        Schema::table('email_templates', function (Blueprint $table) {
            $table->uuid('brand_id')->nullable()->after('abuse_type');
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
        Schema::table('abuse_cases', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
        Schema::dropIfExists('sent_emails');
        Schema::dropIfExists('email_connections');
        Schema::dropIfExists('brands');
    }
};
