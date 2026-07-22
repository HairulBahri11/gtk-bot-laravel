<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Setiap nomor WhatsApp (chat_id) diikat dengan state aktif -
     * §6.A PRD (Conversational Form / State Management).
     */
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->unique();
            $table->enum('state', [
                'STATE_1_PENGUMPULAN_DATA',
                'STATE_2_KONFIRMASI',
                'STATE_3_DONE',
            ])->default('STATE_1_PENGUMPULAN_DATA');
            $table->string('step')->nullable();
            $table->json('context')->nullable();
            $table->string('no_rm')->nullable()->index();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
