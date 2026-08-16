<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_store', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->string('stream', 255);
            $table->integer('playhead')->nullable();
            $table->string('event_id', 255);
            $table->string('event_name', 255);
            $table->json('event_payload');
            $table->dateTime('recorded_on');
            $table->boolean('archived')->default(false);
            $table->json('custom_headers');

            $table->unique('event_id');
            $table->unique(['stream', 'playhead']);
            $table->index(['stream', 'playhead', 'archived']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->string('id', 255);
            $table->string('group_name', 32);
            $table->string('run_mode', 16);
            $table->string('status', 32);
            $table->integer('position');
            $table->longText('error_message')->nullable();
            $table->string('error_previous_status', 32)->nullable();
            $table->json('error_context')->nullable();
            $table->integer('retry_attempt');
            $table->dateTime('last_saved_at');
            $table->text('cleanup_tasks')->nullable();
            $table->index('group_name');
            $table->index('status');
            $table->primary('id');
        });

        Schema::create('crypto_keys', function (Blueprint $table) {
            $table->string('subject_id', 255);
            $table->string('crypto_key', 255);
            $table->string('crypto_method', 255);
            $table->string('crypto_iv', 255);
            $table->primary('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_store');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('crypto_keys');
    }
};
