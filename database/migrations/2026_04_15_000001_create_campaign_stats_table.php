<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCampaignStatsTable extends Migration
{
    public function up(): void
    {
        Schema::create('sendportal_campaign_stats', function (Blueprint $table) {
            $table->unsignedInteger('campaign_id')->primary();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('opened')->default(0);
            $table->unsignedInteger('clicked')->default(0);
            $table->unsignedInteger('bounced')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->timestamp('stats_frozen_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sendportal_campaign_stats');
    }
}
