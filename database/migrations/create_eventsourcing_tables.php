<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventstore', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('aggregate', 255);
            $table->char('aggregate_id', 36);
            $table->integer('playhead');
            $table->string('event', 255);
            $table->json('payload');
            $table->dateTime('recorded_on');
            $table->tinyInteger('new_stream_start')->default(0);
            $table->tinyInteger('archived')->default(0);
            $table->json('custom_headers');
            $table->unique(['aggregate', 'aggregate_id', 'playhead']);
            $table->index(['aggregate', 'aggregate_id', 'playhead', 'archived']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->string('id', 255);
            $table->string('group_name', 32);
            $table->string('run_mode', 16);
            $table->integer('position');
            $table->string('status', 32);
            $table->longText('error_message')->nullable();
            $table->string('error_previous_status', 32)->nullable();
            $table->json('error_context')->nullable();
            $table->integer('retry_attempt');
            $table->dateTime('last_saved_at');
            $table->index('group_name');
            $table->index('status');
            $table->primary('id');
        });

        Schema::create('eventstore_cipher_keys', function (Blueprint $table) {
            $table->string('subject_id', 255);
            $table->string('crypto_key', 255);
            $table->string('crypto_method', 255);
            $table->string('crypto_iv', 255);
            $table->primary('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventstore');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('eventstore_cipher_keys');
    }
};
