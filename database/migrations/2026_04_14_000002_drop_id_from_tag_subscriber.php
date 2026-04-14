<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropIdFromTagSubscriber extends Migration
{
    public function up(): void
    {
        Schema::table('sendportal_tag_subscriber', function (Blueprint $table) {
            $table->dropColumn('id');
            $table->primary(['tag_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sendportal_tag_subscriber', function (Blueprint $table) {
            $table->dropPrimary(['tag_id', 'subscriber_id']);
            $table->increments('id')->first();
        });
    }
}
