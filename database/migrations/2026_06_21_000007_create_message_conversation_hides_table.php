<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_conversation_hides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id', 36);
            $table->string('conversation_id', 64);
            $table->timestamp('hidden_at');
            $table->timestamps();

            $table->unique(['user_id', 'conversation_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_conversation_hides');
    }
};
