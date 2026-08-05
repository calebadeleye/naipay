<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantors offered against a loan application.
 *
 * A guarantor's identity evidence is a document, not a column here — it
 * attaches through the existing polymorphic Document system, the same as a
 * merchant's or a business's. This table holds only the bio-data an officer
 * captures directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_application_guarantors', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('loan_application_id')->constrained('loan_applications')->cascadeOnDelete();

            $table->string('full_name', 150);
            $table->string('phone', 20);
            $table->string('email', 190)->nullable();
            $table->string('relationship', 100);
            $table->string('address', 255)->nullable();

            $table->string('id_type', 40)->nullable();
            $table->string('id_number', 40)->nullable();

            $table->string('employer', 150)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->decimal('monthly_income', 20, Money::SCALE)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('loan_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_application_guarantors');
    }
};
