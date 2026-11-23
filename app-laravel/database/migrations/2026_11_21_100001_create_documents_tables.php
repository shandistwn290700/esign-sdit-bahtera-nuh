<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            // 128-bit random id used in the QR URL (ADR-0006); never the sequential id.
            $table->string('public_id', 32)->unique();
            $table->string('title', 200);
            $table->string('number', 100)->nullable();
            $table->string('subject', 255)->nullable();
            $table->boolean('show_metadata_publicly')->default(true);
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->unsignedTinyInteger('current_step')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->unsignedTinyInteger('step_order')->nullable();
            $table->string('path');
            $table->char('sha256', 64)->index();
            $table->unsignedInteger('size');
            $table->timestamp('created_at');
        });

        Schema::create('document_signers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('order');
            $table->string('field_name', 8);
            $table->unsignedSmallInteger('page');
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('width', 8, 2);
            $table->decimal('height', 8, 2);
            $table->string('status', 20);
            // Snapshot of how the signer appears on the stamp at signing time.
            $table->string('signer_name', 128)->nullable();
            $table->string('signer_position', 128)->nullable();
            $table->string('certificate_serial', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'order']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signers');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
