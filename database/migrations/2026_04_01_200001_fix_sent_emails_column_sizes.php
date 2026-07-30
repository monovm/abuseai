<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->text('in_reply_to')->nullable()->change();
            $table->text('message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->string('in_reply_to')->nullable()->change();
            $table->string('message_id')->nullable()->change();
        });
    }
};
