<?php

namespace App\Console\Commands;

use App\Enums\ReminderType;
use App\Models\Booking;
use App\Models\ReminderLog;
use App\Services\Gtk\GtkApiException;
use App\Services\Gtk\GtkApiService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pengingat Otomatis (§6.B PRD): H-1 hari, 3 jam, dan 1 jam sebelum
 * pemeriksaan, berbasis GET /reminderkunjungan. Idempotent via reminder_logs.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'gtk:send-reminders {--tolerance=8 : Toleransi menit di sekitar window reminder}';

    protected $description = 'Kirim pengingat WhatsApp H-1, 3 jam, dan 1 jam sebelum jadwal kunjungan';

    /** @var array<string, int> */
    protected array $windowsMinutes = [
        'h1' => 1440,
        '3jam' => 180,
        '1jam' => 60,
    ];

    public function handle(GtkApiService $gtk, WhatsAppServiceInterface $wa): int
    {
        $tolerance = (int) $this->option('tolerance');
        $dates = collect([now()->toDateString(), now()->copy()->addDay()->toDateString()])->unique();

        $entries = collect();

        foreach ($dates as $date) {
            try {
                $result = $gtk->reminderKunjungan(['tanggal' => $date]);
            } catch (GtkApiException $e) {
                $this->warn("Gagal ambil reminderkunjungan tanggal {$date}: {$e->getMessage()}");

                continue;
            }

            foreach ($result['list'] ?? [] as $item) {
                $item['_tanggal'] = $date;
                $entries->push($item);
            }
        }

        $sent = 0;

        foreach ($entries as $entry) {
            if (empty($entry['no_rawat']) || empty($entry['jam'])) {
                continue;
            }

            $booking = Booking::query()->where('no_rawat', $entry['no_rawat'])->first();

            if (! $booking || ! $booking->chatSession) {
                continue;
            }

            $apptAt = Carbon::parse($entry['_tanggal'].' '.$entry['jam']);
            $diffMinutes = now()->diffInMinutes($apptAt, false);

            foreach ($this->windowsMinutes as $type => $targetMinutes) {
                if (abs($diffMinutes - $targetMinutes) > $tolerance) {
                    continue;
                }

                $alreadySent = ReminderLog::query()
                    ->where('booking_id', $booking->id)
                    ->where('type', $type)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                $wa->sendText($booking->chatSession->chat_id, $this->buildMessage($type, $entry));

                ReminderLog::create([
                    'booking_id' => $booking->id,
                    'type' => $type,
                    'sent_at' => now(),
                ]);

                $sent++;
            }
        }

        $this->info("Reminder terkirim: {$sent}");

        return self::SUCCESS;
    }

    protected function buildMessage(string $type, array $entry): string
    {
        $label = match ($type) {
            ReminderType::H1->value => 'besok',
            ReminderType::ThreeHours->value => 'dalam 3 jam',
            ReminderType::OneHour->value => 'dalam 1 jam',
        };

        return "Pengingat: kunjungan {$entry['nama_pasien']} ke {$entry['poli']} ({$entry['dokter']}) "
            ."akan berlangsung {$label}, pukul {$entry['jam']}. Mohon datang tepat waktu.";
    }
}
