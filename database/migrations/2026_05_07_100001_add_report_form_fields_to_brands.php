<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // Hostnames that should resolve to this brand on the public abuse
            // report form (e.g. ["abuse.brand-a.example", "report.brand-a.example"]).
            $table->jsonb('report_hostnames')->nullable();

            // Per-brand theming and copy for the public /report form.
            // Schema (all keys optional):
            //   - page_title: string
            //   - intro: string  (markdown / plain text shown above the form)
            //   - footer_html: string (raw HTML rendered at the bottom)
            //   - footer_links: array of {label, url}
            //   - primary_color: string (hex, e.g. "#2563eb")
            //   - support_email: string
            $table->jsonb('report_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['report_hostnames', 'report_config']);
        });
    }
};
