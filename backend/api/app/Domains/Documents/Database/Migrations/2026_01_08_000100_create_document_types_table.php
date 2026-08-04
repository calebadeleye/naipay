<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable document types.
 *
 * Which documents Naipay asks for changes with regulation and with product, so
 * the list is data rather than an enum. Compliance adds a new requirement
 * without a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();

            // Which kind of record this document attaches to: merchant,
            // business, loan, guarantor, collateral, repayment.
            $table->string('applies_to', 40);

            $table->string('category', 60)->nullable();

            // A merchant cannot be approved while a required document is
            // missing or unverified.
            $table->boolean('is_required')->default(false);

            // Whether the document carries an expiry date that must be
            // captured — a driving licence does, a shop photograph does not.
            $table->boolean('has_expiry')->default(false);

            // Whether a document number should be captured alongside the file.
            $table->boolean('requires_document_number')->default(false);

            $table->unsignedInteger('display_order')->default(0);
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // Deactivated, never deleted: documents already filed against a
            // type keep their classification.
            $table->index(['applies_to', 'status', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
