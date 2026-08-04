<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded documents.
 *
 * Polymorphic, so the same table and the same verification workflow serve
 * merchant identity documents, business registration, loan agreements,
 * guarantor papers, collateral evidence and payment proof.
 *
 * Files themselves live on a private disk and are never publicly addressable;
 * they are served through an authorised, audited controller action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();

            $table->string('documentable_type', 160);
            $table->unsignedBigInteger('documentable_id');

            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();

            // Path on the configured disk. Never a URL: the disk is private,
            // and switching from local to S3 must not invalidate stored rows.
            $table->string('file_path', 500);

            // The name the uploader saw, kept for display and download.
            $table->string('file_name', 255);

            // Sniffed from the file contents, not taken from the request —
            // a browser-supplied content type is attacker-controlled.
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            // Detects a re-upload of a byte-identical file.
            $table->char('checksum', 64)->nullable();

            $table->string('document_number', 100)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->string('verification_status', 30)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            /*
             * Version chain.
             *
             * Replacing a document supersedes the previous one rather than
             * overwriting it: the file that compliance actually looked at when
             * they approved a merchant has to remain retrievable.
             */
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->boolean('is_current')->default(true);

            $table->foreignId('uploaded_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();

            // Documents are evidence. They are superseded, never removed.
            $table->softDeletes();

            $table->index(['documentable_type', 'documentable_id'], 'documents_documentable_index');
            $table->index(['document_type_id', 'verification_status']);
            $table->index(['expires_at', 'is_current']);
            $table->index('is_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
