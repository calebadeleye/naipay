<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repayments', function (Blueprint $table): void {
            $table->string('sender_account_number', 34)->nullable()->after('sender_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('repayments', function (Blueprint $table): void {
            $table->dropColumn('sender_account_number');
        });
    }
};
