<?php

namespace App\Services\Gtk;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien REST API SIM Klinik / EMR GTK (Bridging AI GTK x SIMRS Khanza).
 * Membungkus seluruh 12 endpoint yang terdokumentasi pada §5 & §7 PRD.
 *
 * Catatan implementasi: server GTK memakai pola routing lama
 * "index.php?url=<endpoint>" (bukan path REST /endpoint biasa). Karena itu
 * endpoint TIDAK bisa ditempel sebagai path setelah baseUrl() - untuk GET,
 * "url" dikirim sebagai query param bersama param lain (digabung oleh Http
 * client); untuk POST/PATCH, "url" ditempel langsung ke string URL supaya
 * tidak bentrok dengan body JSON.
 */
class GtkApiService
{
    /**
     * Base script URL tanpa suffix "?url=" - dinormalisasi supaya nilai
     * GTK_API_BASE_URL boleh diisi dengan atau tanpa suffix tersebut.
     */
    protected function scriptUrl(): string
    {
        $base = rtrim((string) config('services.gtk.base_url'), '/');

        return preg_replace('/\?url=?$/i', '', $base);
    }

    protected function endpointUrl(string $endpoint): string
    {
        return $this->scriptUrl().'?url='.$endpoint;
    }

    public function auth(): string
    {
        $response = Http::withHeaders([
                'x-username' => config('services.gtk.username'),
                'x-password' => config('services.gtk.password'),
            ])
            ->get($this->scriptUrl(), ['url' => 'auth']);

        $body = $response->json() ?? [];
        $code = (int) ($body['metadata']['code'] ?? $response->status());

        if ($code !== 200) {
            throw new GtkApiException($body['metadata']['message'] ?? 'Token salah/expired..!!', $code);
        }

        $token = $body['response']['token']
            ?? $body['response']['x-token']
            ?? $body['response']['access_token']
            ?? null;

        if (! $token) {
            throw new GtkApiException('Token tidak ditemukan pada response /auth', 401);
        }

        return $token;
    }

    protected function token(): string
    {
        return Cache::remember(
            config('gtk.token_cache_key'),
            config('gtk.token_cache_ttl'),
            fn () => $this->auth(),
        );
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'x-username' => config('services.gtk.username'),
            'x-token' => $this->token(),
        ]);
    }

    /**
     * Kirim request terautentikasi. Jika token kedaluwarsa (401 dengan pesan
     * token), cache token dibersihkan lalu dicoba ulang sekali.
     */
    protected function request(string $method, string $endpoint, array $params = [], bool $retry = true): array
    {
        // Http client Laravel melempar ConnectionException (bukan response
        // gagal biasa) untuk kegagalan level jaringan (timeout, connection
        // refused, dsb) - beda dari GtkApiException yang dipakai untuk error
        // level aplikasi GTK (401/404/409/dst). Bungkus di sini supaya
        // pemanggil cukup tangani satu jenis exception (GtkApiException)
        // untuk semua kegagalan GTK API, termasuk saat server GTK unreachable.
        try {
            $response = match ($method) {
                'get' => $this->client()->get($this->scriptUrl(), array_merge(['url' => $endpoint], $params)),
                'post', 'patch' => $this->client()->{$method}($this->endpointUrl($endpoint), $params),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };
        } catch (ConnectionException $e) {
            Log::error('GTK API tidak bisa dihubungi', [
                'endpoint' => $endpoint,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            throw new GtkApiException('Tidak bisa menghubungi server GTK: '.$e->getMessage(), 0);
        }

        $body = $response->json() ?? [];
        $code = (int) ($body['metadata']['code'] ?? $response->status());

        if ($code === 401 && $retry) {
            Cache::forget(config('gtk.token_cache_key'));

            return $this->request($method, $endpoint, $params, retry: false);
        }

        if ($code !== 200) {
            Log::warning('GTK API request gagal', [
                'endpoint' => $endpoint,
                'method' => $method,
                'params' => $params,
                'http_status' => $response->status(),
                'gtk_code' => $code,
                'gtk_message' => $body['metadata']['message'] ?? null,
                'raw_body' => $response->body(),
            ]);

            throw new GtkApiException($body['metadata']['message'] ?? 'GTK API error', $code);
        }

        return $body['response'] ?? [];
    }

    public function cariPasien(array $params): array
    {
        return $this->request('get', 'caripasien', $params);
    }

    public function riwayatRekamMedis(string $noRm): array
    {
        return $this->request('get', 'riwayatrekammedis', ['no_rm' => $noRm]);
    }

    public function regPasien(array $data): array
    {
        return $this->request('post', 'regpasien', $data);
    }

    public function pemeriksaanRajal(array $data): array
    {
        return $this->request('post', 'pemeriksaanrajal', $data);
    }

    public function tambahPasien(array $data): array
    {
        return $this->request('post', 'tambahpasien', $data);
    }

    public function updatePasien(array $data): array
    {
        return $this->request('patch', 'updatepasien', $data);
    }

    public function batalKunjungan(array $data): array
    {
        return $this->request('post', 'batalkunjungan', $data);
    }

    public function reminderKunjungan(array $params): array
    {
        return $this->request('get', 'reminderkunjungan', $params);
    }
}
