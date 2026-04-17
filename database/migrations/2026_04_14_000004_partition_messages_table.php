<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartitionMessagesTable extends Migration
{
    public function up(): void
    {
        // Only run automatically on empty tables (fresh install / local dev).
        // For production with existing data, use pt-archiver as described in
        // docs/partition-messages-plan.md and mark this migration as ran:
        //   php artisan migrate:mark 2026_04_14_000004_partition_messages_table
        if (DB::table('sendportal_messages')->exists()) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        Schema::drop('sendportal_messages');

        Schema::create('sendportal_messages', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->uuid('hash');
            $table->unsignedInteger('workspace_id')->index();
            $table->unsignedInteger('subscriber_id')->index();
            $table->string('source_type')->index();
            $table->unsignedInteger('source_id')->index();
            $table->string('recipient_email');
            $table->string('subject');
            $table->string('from_name');
            $table->string('from_email');
            $table->string('message_id')->index()->nullable();
            $table->string('ip')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('queued_at')->nullable()->default(null)->index();
            $table->timestamp('sent_at')->nullable()->default(null)->index();
            $table->timestamp('delivered_at')->nullable()->default(null)->index();
            $table->timestamp('bounced_at')->nullable()->default(null)->index();
            $table->timestamp('unsubscribed_at')->nullable()->default(null)->index();
            $table->timestamp('complained_at')->nullable()->default(null)->index();
            $table->timestamp('opened_at')->nullable()->default(null)->index();
            $table->timestamp('clicked_at')->nullable()->default(null)->index();
            $table->timestamps();
        });

        // Adjust PK and unique index for partition key.
        // Laravel Schema builder does not support PARTITION BY.
        DB::statement('ALTER TABLE sendportal_messages
            ADD UNIQUE INDEX sendportal_messages_hash_unique (hash, source_id),
            DROP PRIMARY KEY,
            ADD PRIMARY KEY (id, source_id)');

        Schema::table('sendportal_message_failures', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
        });

        DB::statement('ALTER TABLE sendportal_messages
            PARTITION BY HASH(source_id) PARTITIONS 50');

        Schema::table('sendportal_message_failures', function (Blueprint $table) {
            $table->foreign('message_id')->references('id')->on('sendportal_messages');
        });

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        Schema::drop('sendportal_messages');

        Schema::create('sendportal_messages', function ($table) {
            $table->increments('id');
            $table->uuid('hash')->unique();
            $table->unsignedInteger('workspace_id')->index();
            $table->unsignedInteger('subscriber_id')->index();
            $table->string('source_type')->index();
            $table->unsignedInteger('source_id')->index();
            $table->string('recipient_email');
            $table->string('subject');
            $table->string('from_name');
            $table->string('from_email');
            $table->string('message_id')->index()->nullable();
            $table->string('ip')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('queued_at')->nullable()->default(null)->index();
            $table->timestamp('sent_at')->nullable()->default(null)->index();
            $table->timestamp('delivered_at')->nullable()->default(null)->index();
            $table->timestamp('bounced_at')->nullable()->default(null)->index();
            $table->timestamp('unsubscribed_at')->nullable()->default(null)->index();
            $table->timestamp('complained_at')->nullable()->default(null)->index();
            $table->timestamp('opened_at')->nullable()->default(null)->index();
            $table->timestamp('clicked_at')->nullable()->default(null)->index();
            $table->timestamps();
        });

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
