<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessageLookupTable extends Migration
{
    public function up(): void
    {
        Schema::create('sendportal_message_lookup', function (Blueprint $table) {
            $table->string('message_id')->primary();
            $table->unsignedInteger('source_id')->index();
            $table->char('hash', 36)->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sendportal_message_lookup');
    }
}
