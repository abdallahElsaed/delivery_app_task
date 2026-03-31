<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batch_rows', function (Blueprint $table) {
            $table->string('status', 20)->default('failed')->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('import_batch_rows', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
