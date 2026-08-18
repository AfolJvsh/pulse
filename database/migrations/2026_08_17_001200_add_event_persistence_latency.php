<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('incident_events', function (Blueprint $table) {
            $table->unsignedInteger('persistence_latency_ms')->nullable()->after('client_command_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_events', fn (Blueprint $table) => $table->dropColumn('persistence_latency_ms'));
    }
};
