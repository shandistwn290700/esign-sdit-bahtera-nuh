<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('role', 20)->default('staff')->after('email');
            $table->string('position', 128)->nullable()->after('role');
            $table->string('nip', 18)->nullable()->after('position');
            $table->string('phone', 20)->nullable()->after('nip');
            $table->boolean('is_active')->default(true)->after('phone');
            // Signing Service references (ADR-0002/0003). The private key never leaves the HSM.
            $table->string('signer_key_id', 32)->nullable()->unique();
            $table->string('signer_certificate_serial', 64)->nullable();
            $table->timestamp('signer_totp_confirmed_at')->nullable();
            $table->timestamp('signer_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'uuid', 'role', 'position', 'nip', 'phone', 'is_active', 'signer_key_id',
                'signer_certificate_serial', 'signer_totp_confirmed_at', 'signer_revoked_at',
            ]);
        });
    }
};
