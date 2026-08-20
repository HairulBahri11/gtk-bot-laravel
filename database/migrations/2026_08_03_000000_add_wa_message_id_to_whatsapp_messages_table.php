<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WAHA kadang mengirim webhook event yang sama lebih dari sekali (mis.
     * retry karena respons lambat) - kolom unik ini dipakai
     * WhatsappWebhookController untuk menolak event duplikat sebelum
     * memicu job pemrosesan AI/booking dua kali untuk pesan yang sama.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('wa_message_id')->nullable()->unique()->after('chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('wa_message_id');
        });
    }
};
