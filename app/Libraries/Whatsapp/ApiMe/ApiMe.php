<?php

namespace App\Libraries\Whatsapp\ApiMe;

use App\Libraries\Whatsapp\Support\HttpClient;
use App\Libraries\Whatsapp\Whatsapp;
use InvalidArgumentException;

/**
 * Provider Whatsapp untuk ApiMe (https://github.com/open-apime/apime),
 * gateway WhatsApp self-hosted berbasis Go + WhatsMeow.
 *
 * Konfigurasi lewat .env:
 *   WHATSAPP_PROVIDER = ApiMe
 *   WHATSAPP_TOKEN    = instance token (header Authorization: Bearer ...)
 *   WHATSAPP_URL      = base URL instance, mis. http://localhost:8080
 *   WHATSAPP_INSTANCE = UUID instance yang sudah tersambung
 *
 * Catatan: ApiMe menuntut token BER-SCOPE INSTANCE. Token user (JWT login)
 * maupun API token global ditolak dengan HTTP 403 oleh endpoint pengiriman.
 * Ambil tokennya saat membuat instance, atau putar lewat
 * POST /api/instances/{id}/token/rotate.
 */
class ApiMe implements Whatsapp
{
    public const DEFAULT_URL = 'http://localhost:8080';

    private string $baseUrl;
    private string $instanceId;

    public function __construct(
        public ?string $token,
        ?string $baseUrl = null,
        ?string $instanceId = null
    ) {
        $url = rtrim($baseUrl ?: (string) env('WHATSAPP_URL') ?: self::DEFAULT_URL, '/');

        // Terima "http://host:8080" maupun "http://host:8080/api" tanpa jadi "/api/api".
        $this->baseUrl    = preg_replace('#/api$#', '', $url) ?? $url;
        $this->instanceId = $instanceId ?: (string) env('WHATSAPP_INSTANCE');
    }

    public function getProvider(): string
    {
        return 'ApiMe';
    }

    public function getToken(): string
    {
        return $this->token ?: (string) env('WHATSAPP_TOKEN');
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * Kirim pesan lewat ApiMe.
     *
     * ApiMe tidak punya endpoint batch, jadi beberapa pesan dikirim satu per
     * satu secara berurutan. Jeda antar pesan dihormati lewat kunci 'delay',
     * sehingga mengirim banyak pesan akan MEMBLOKIR pemanggil selama total
     * jeda tersebut. Pemakaian di halaman scan hanya mengirim satu pesan.
     *
     * @param array|string $messages satu pesan ['destination'=>, 'message'=>, 'delay'=>]
     *                               atau array berisi beberapa pesan seperti itu
     * @return string pesan hasil untuk di-log oleh pemanggil
     */
    public function sendMessage(array|string $messages): string
    {
        if (is_string($messages)) {
            return 'Format pesan tidak valid: ApiMe membutuhkan array pesan';
        }

        if ($this->instanceId === '') {
            return 'WHATSAPP_INSTANCE belum diisi di .env';
        }

        $isBulk   = isset($messages[0]) && is_array($messages[0]);
        $messages = $isBulk ? $messages : [$messages];

        try {
            $items = $this->parse($messages);
        } catch (InvalidArgumentException $e) {
            return 'Gagal menyusun pesan: ' . $e->getMessage();
        }

        if ($items === []) {
            return 'Tidak ada pesan untuk dikirim';
        }

        if (count($items) === 1) {
            return $this->sendText($items[0]['message']);
        }

        return $this->sendSequentially($items);
    }

    /**
     * @return array<int,array{message:ApiMeMessage,delay:int}>
     * @throws InvalidArgumentException
     */
    private function parse(array $messages): array
    {
        $items = [];

        foreach ($messages as $i => $message) {
            if (! is_array($message) || ! isset($message['destination'], $message['message'])) {
                throw new InvalidArgumentException(
                    "Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $item = new ApiMeMessage(
                (string) $message['destination'],
                (string) $message['message']
            );

            if ($item->to === '') {
                throw new InvalidArgumentException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            $items[] = ['message' => $item, 'delay' => max(0, (int) ($message['delay'] ?? 0))];
        }

        return $items;
    }

    /** @param array<int,array{message:ApiMeMessage,delay:int}> $items */
    private function sendSequentially(array $items): string
    {
        $sukses = 0;
        $gagal  = [];

        foreach ($items as $i => $item) {
            if ($i > 0 && $item['delay'] > 0) {
                sleep($item['delay']);
            }

            $result = $this->sendText($item['message']);

            if (str_starts_with($result, 'Sukses')) {
                $sukses++;
            } else {
                $gagal[] = $item['message']->to . ': ' . $result;
            }
        }

        $total = count($items);

        if ($gagal === []) {
            return "Sukses, {$sukses}/{$total} pesan terkirim";
        }

        return "{$sukses}/{$total} pesan terkirim. Gagal: " . implode(' | ', $gagal);
    }

    /** POST /api/instances/{instanceId}/messages/text */
    private function sendText(ApiMeMessage $message): string
    {
        $url = "{$this->baseUrl}/api/instances/" . rawurlencode($this->instanceId) . '/messages/text';

        $response = HttpClient::post(
            $url,
            (string) json_encode($message->toArray()),
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getToken(),
                'Idempotency-Key: ' . $message->idempotencyKey($this->instanceId),
            ]
        );

        if ($response->error !== '') {
            return 'Gagal menghubungi ApiMe: ' . $response->error;
        }

        $body = $response->json();

        if (! $response->isSuccess()) {
            return $this->errorMessage($response->status, $body);
        }

        // Sukses dibungkus sebagai {"data": {...Message}}.
        $data = $body['data'] ?? [];

        return 'Sukses, messageId: ' . ($data['whatsappId'] ?? $data['id'] ?? '-');
    }

    /**
     * ApiMe selalu memakai amplop error {"error": "..."} (internal/pkg/response).
     * Beberapa status punya arti khusus yang layak dijelaskan di log.
     */
    private function errorMessage(int $status, ?array $body): string
    {
        $detail = is_string($body['error'] ?? null) ? $body['error'] : '';

        $konteks = match ($status) {
            403 => 'token harus instance token, bukan JWT user atau API token global',
            404 => 'instance tidak ditemukan, cek WHATSAPP_INSTANCE',
            409 => 'pengiriman dengan Idempotency-Key yang sama masih berjalan',
            422 => 'Idempotency-Key sudah dipakai untuk isi pesan yang berbeda',
            503 => 'sesi WhatsApp belum siap, tidak ada pesan yang terkirim',
            default => '',
        };

        $pesan = "ApiMe menolak pesan (HTTP {$status})";

        if ($detail !== '') {
            $pesan .= ": {$detail}";
        }

        if ($konteks !== '') {
            $pesan .= " [{$konteks}]";
        }

        return $pesan;
    }
}
