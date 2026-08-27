<?php

namespace App\Libraries\Whatsapp\OpenWA;

use App\Libraries\Whatsapp\Support\HttpClient;
use App\Libraries\Whatsapp\Whatsapp;

/**
 * Provider Whatsapp untuk OpenWA (https://github.com/rmyndharis/OpenWA),
 * gateway WhatsApp self-hosted.
 *
 * Konfigurasi lewat .env:
 *   WHATSAPP_PROVIDER = OpenWA
 *   WHATSAPP_TOKEN    = API key OpenWA (dikirim sebagai header X-API-Key)
 *   WHATSAPP_URL      = base URL instance, mis. http://localhost:2785
 *   WHATSAPP_SESSION  = id session yang sudah di-start dan tersambung
 */
class OpenWA implements Whatsapp
{
    public const DEFAULT_URL = 'http://localhost:2785';

    private string $baseUrl;
    private string $sessionId;

    public function __construct(
        public ?string $token,
        ?string $baseUrl = null,
        ?string $sessionId = null
    ) {
        $this->baseUrl   = rtrim($baseUrl ?: (string) env('WHATSAPP_URL') ?: self::DEFAULT_URL, '/');
        $this->sessionId = $sessionId ?: (string) env('WHATSAPP_SESSION');
    }

    public function getProvider(): string
    {
        return 'OpenWA';
    }

    public function getToken(): string
    {
        return $this->token ?: (string) env('WHATSAPP_TOKEN');
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Kirim pesan lewat OpenWA.
     *
     * Satu pesan dikirim ke endpoint send-text (sinkron, balasannya messageId).
     * Lebih dari satu pesan dikirim ke send-bulk (asinkron, balasannya batchId).
     *
     * @param array|string $messages satu pesan ['destination'=>, 'message'=>, 'delay'=>]
     *                               atau array berisi beberapa pesan seperti itu
     * @return string pesan hasil untuk di-log oleh pemanggil
     */
    public function sendMessage(array|string $messages): string
    {
        if (is_string($messages)) {
            return 'Format pesan tidak valid: OpenWA membutuhkan array pesan';
        }

        if ($this->sessionId === '') {
            return 'WHATSAPP_SESSION belum diisi di .env';
        }

        $isBulk   = isset($messages[0]) && is_array($messages[0]);
        $messages = $isBulk ? $messages : [$messages];
        $delay    = (int) ($messages[0]['delay'] ?? 3);

        try {
            $bulk = new OpenWABulkMessage($messages, $delay);
        } catch (\Throwable $e) {
            return 'Gagal menyusun pesan: ' . $e->getMessage();
        }

        if ($bulk->count() === 0) {
            return 'Tidak ada pesan untuk dikirim';
        }

        return $bulk->count() === 1
            ? $this->sendText($bulk->first())
            : $this->sendBulk($bulk);
    }

    /** POST /api/sessions/{sessionId}/messages/send-text */
    private function sendText(OpenWAMessage $message): string
    {
        $response = $this->post('messages/send-text', $message->toArray());

        if (is_string($response)) {
            return $response;
        }

        return 'Sukses, messageId: ' . ($response['messageId'] ?? '-');
    }

    /** POST /api/sessions/{sessionId}/messages/send-bulk */
    private function sendBulk(OpenWABulkMessage $bulk): string
    {
        $response = $this->post('messages/send-bulk', $bulk->toArray());

        if (is_string($response)) {
            return $response;
        }

        $total   = $response['totalMessages'] ?? $bulk->count();
        $batchId = $response['batchId'] ?? '-';

        return "Batch diterima ({$total} pesan), batchId: {$batchId}";
    }

    /**
     * Kirim request JSON ke OpenWA.
     *
     * @return array|string body terdecode kalau sukses, atau string pesan error
     */
    private function post(string $path, array $payload): array|string
    {
        $url = "{$this->baseUrl}/api/sessions/" . rawurlencode($this->sessionId) . "/{$path}";

        $response = HttpClient::post(
            $url,
            (string) json_encode($payload),
            [
                'Content-Type: application/json',
                "X-API-Key: {$this->getToken()}",
            ]
        );

        if ($response->error !== '') {
            return 'Gagal menghubungi OpenWA: ' . $response->error;
        }

        $body = $response->json();

        if (! $response->isSuccess()) {
            return $this->errorMessage($response->status, $body);
        }

        return $body ?? [];
    }

    /**
     * OpenWA memakai bentuk error bawaan NestJS:
     * { statusCode, message, error }, dengan message bisa berupa array
     * (kegagalan validasi per-field).
     */
    private function errorMessage(int $status, ?array $body): string
    {
        $detail = $body['message'] ?? $body['error'] ?? null;

        if (is_array($detail)) {
            $detail = implode('; ', $detail);
        }

        return $detail !== null && $detail !== ''
            ? "OpenWA menolak pesan (HTTP {$status}): {$detail}"
            : "OpenWA menolak pesan (HTTP {$status})";
    }
}
