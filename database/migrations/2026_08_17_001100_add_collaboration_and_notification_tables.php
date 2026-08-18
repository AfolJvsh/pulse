<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('incident_event_id')->nullable()->unique();
            $table->string('kind')->default('broadcast')->index();
            $table->string('dedupe_key')->nullable()->unique();
        });

        Schema::create('incident_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('incident_id')->unique();
            $table->longText('body')->default('');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('edited_by_user_id')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->timestamps();
            $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
            $table->foreign('edited_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('organization_id')->index();
            $table->foreignId('user_id');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('webhook_enabled')->default(false);
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->jsonb('event_types')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('incident_event_id')->index();
            $table->foreignId('user_id');
            $table->string('channel');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable()->index();
            $table->timestampTz('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['incident_event_id', 'user_id', 'channel']);
            $table->foreign('incident_event_id')->references('id')->on('incident_events')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('incident_notes');
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropUnique(['incident_event_id']);
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn(['incident_event_id', 'kind', 'dedupe_key']);
        });
    }
};
