<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ip_address')->unique();       // single IP (e.g. 192.168.1.1)
            $table->string('server_name')->nullable();     // optional tag
            $table->uuid('customer_id')->nullable();       // link to customer
            $table->string('range_source')->nullable();    // original range notation (e.g. 192.168.1.0/24)
            $table->string('status')->default('active');   // active, reserved, decommissioned
            $table->jsonb('tags')->nullable();             // flexible tags: datacenter, rack, vlan, etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
