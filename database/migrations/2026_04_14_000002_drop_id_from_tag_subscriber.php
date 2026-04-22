<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropIdFromTagSubscriber extends Migration
{
    public function up(): void
    {
        Schema::table('sendportal_tag_subscriber', function (Blueprint $table) {
            $foreignKeys = $this->listTableForeignKeys('sendportal_tag_subscriber');
            if (in_array('sendportal_segment_subscriber_tag_id_foreign', $foreignKeys)) {
                $table->dropForeign('sendportal_segment_subscriber_tag_id_foreign');
            } elseif(in_array('sendportal_tag_subscriber_tag_id_foreign', $foreignKeys)) {
                $table->dropForeign(['tag_id']);
            }

            $table->dropColumn('id');
            $table->dropIndex('idx_tag_subscriber');
            $table->primary(['tag_id', 'subscriber_id']);
            $table->foreign('tag_id')->references('id')->on('sendportal_tags');
        });
    }

    public function down(): void
    {
        Schema::table('sendportal_tag_subscriber', function (Blueprint $table) {
            $table->dropForeign(['tag_id']);
            $table->dropPrimary(['tag_id', 'subscriber_id']);
            $table->increments('id')->first();
            $table->index(['tag_id', 'subscriber_id'], 'idx_tag_subscriber');
            $table->foreign('tag_id')->references('id')->on('sendportal_tags');
        });
    }

    protected function listTableForeignKeys(string $table): array
    {
        return collect(Schema::getForeignKeys($table))
            ->map(fn ($fk) => $fk['name'])
            ->toArray();
    }
}
