<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameSegmentsToTags extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('sendportal_segments', 'sendportal_tags');

        Schema::table('sendportal_tags', function (Blueprint $table) {
            $table->renameIndex('sendportal_segments_workspace_id_index', 'sendportal_tags_workspace_id_index');
        });

        Schema::table('sendportal_segment_subscriber', function (Blueprint $table) {
            $foreignKeys = $this->listTableForeignKeys('sendportal_segment_subscriber');

            if (in_array('sendportal_segment_subscriber_segment_id_foreign', $foreignKeys, true)) {
                $table->dropForeign('sendportal_segment_subscriber_segment_id_foreign');
            } elseif (in_array('segment_subscriber_segment_id_foreign', $foreignKeys, true)) {
                $table->dropForeign('segment_subscriber_segment_id_foreign');
            }

            if (in_array('sendportal_segment_subscriber_subscriber_id_foreign', $foreignKeys, true)) {
                $table->dropForeign('sendportal_segment_subscriber_subscriber_id_foreign');
            }

            $table->renameColumn('segment_id', 'tag_id');

            $table->foreign('tag_id', 'sendportal_tag_subscriber_tag_id_index')
                ->references('id')->on('sendportal_tags');
            $table->foreign('subscriber_id', 'sendportal_tag_subscriber_subscriber_id_foreign')
                ->references('id')->on('sendportal_subscribers');
        });

        Schema::rename("sendportal_segment_subscriber", "sendportal_tag_subscriber");

        Schema::table('sendportal_campaign_segment', function (Blueprint $table) {
            $foreignKeys = $this->listTableForeignKeys('sendportal_campaign_segment');

            if (in_array('sendportal_campaign_segment_segment_id_foreign', $foreignKeys, true)) {
                $table->dropForeign('sendportal_campaign_segment_segment_id_foreign');
            } elseif (in_array('campaign_segment_segment_id_foreign', $foreignKeys, true)) {
                $table->dropForeign('campaign_segment_segment_id_foreign');
            }

            if (in_array('sendportal_campaign_segment_campaign_id_foreign', $foreignKeys, true)) {
                $table->dropForeign('sendportal_campaign_segment_campaign_id_foreign');
            }

            $table->renameColumn('segment_id', 'tag_id');

            $table->foreign('tag_id', 'sendportal_campaign_tag_tag_id_index')
                ->references('id')->on('sendportal_tags');
            $table->foreign('campaign_id', 'sendportal_campaign_tag_campaign_id_foreign')
                ->references('id')->on('sendportal_campaigns');
        });

        Schema::rename("sendportal_campaign_segment", "sendportal_campaign_tag");
    }

    protected function listTableForeignKeys(string $table): array
    {
        return collect(Schema::getForeignKeys($table))
            ->map(fn ($fk) => $fk['name'])
            ->toArray();
    }
}
