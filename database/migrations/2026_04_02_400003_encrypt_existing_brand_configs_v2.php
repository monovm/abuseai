<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $brands = DB::table('brands')->get();

        foreach ($brands as $brand) {
            $updates = [];

            foreach (['smtp_config', 'whmcs_config', 'virtualizor_config'] as $field) {
                $raw = $brand->{$field};

                if (empty($raw)) {
                    continue;
                }

                // Already encrypted? Skip
                try {
                    Crypt::decryptString($raw);
                    continue;
                } catch (\Throwable) {
                    // Not encrypted yet
                }

                // Parse as JSON
                $decoded = json_decode($raw, true);
                if ($decoded === null) {
                    continue;
                }

                // Encrypt
                $updates[$field] = Crypt::encryptString(json_encode($decoded));
            }

            if (! empty($updates)) {
                DB::table('brands')->where('id', $brand->id)->update($updates);
                Log::info("Encrypted brand configs: {$brand->name}");
            }
        }
    }

    public function down(): void
    {
    }
};
