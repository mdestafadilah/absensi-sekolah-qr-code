<?php

namespace App\Libraries\Whatsapp\Fonnte;

use App\Libraries\Whatsapp\Support\HttpClient;
use App\Libraries\Whatsapp\Whatsapp;

class Fonnte implements Whatsapp
{
    private string $urlApi = 'https://api.fonnte.com/send';

    public function __construct(public ?string $token) {}

    public function getProvider(): string
    {
        return 'Fonnte';
    }

    public function getToken(): string
    {
        return $this->token ?: (string) env('WHATSAPP_TOKEN');
    }

    /**
     * Kirim pesan ke Fonnte API.
     *
     * @param array|string $messages satu pesan ['destination'=>, 'message'=>, 'delay'=>]
     *                               atau array berisi beberapa pesan seperti itu
     * @return string pesan hasil untuk di-log oleh pemanggil
     */
    public function sendMessage(array|string $messages): string
    {
        if (is_string($messages)) {
            return 'Format pesan tidak valid: Fonnte membutuhkan array pesan';
        }

        // Bulk kalau elemen pertama sendiri berupa array pesan.
        $isBulk   = isset($messages[0]) && is_array($messages[0]);
        $messages = $isBulk ? $messages : [$messages];

        try {
            $payload = (new FonnteBulkMessage($messages))->toJson();
        } catch (\Throwable $e) {
            return 'Gagal menyusun pesan: ' . $e->getMessage();
        }

        $response = HttpClient::post(
            $this->urlApi,
            ['data' => $payload],
            ["Authorization: {$this->getToken()}"]
        );

        if ($response->error !== '') {
            return 'Gagal menghubungi Fonnte: ' . $response->error;
        }

        $body = $response->json();

        if ($body === null) {
            return "Respons Fonnte tidak valid (HTTP {$response->status})";
        }

        if (empty($body['status'])) {
            return $body['reason'] ?? "Fonnte menolak pesan (HTTP {$response->status})";
        }

        $detail = $body['detail'] ?? '';
        $detail = is_array($detail) ? implode('; ', $detail) : (string) $detail;

        // Awalan "Sukses" adalah penanda seragam antar provider, dipakai
        // pemanggil untuk memilih level log.
        return $detail !== '' ? "Sukses: {$detail}" : 'Sukses';
    }
}
