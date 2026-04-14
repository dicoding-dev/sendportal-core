<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCompositeIndexesToTagSubscriber extends Migration
{
    public function up(): void
    {
        Schema::table('sendportal_tag_subscriber', function (Blueprint $table) {
            $table->index(['tag_id', 'subscriber_id'], 'idx_tag_subscriber');
            $table->index(['subscriber_id', 'tag_id'], 'idx_subscriber_tag');
        });
    }

    public function down(): void
    {
        Schema::table('sendportal_tag_subscriber', function (Blueprint $table) {
            $table->dropIndex('idx_tag_subscriber');
            $table->dropIndex('idx_subscriber_tag');
        });
    }
}
