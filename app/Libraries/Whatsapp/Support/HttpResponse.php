<?php

namespace App\Libraries\Whatsapp\Support;

/**
 * Hasil satu request HTTP dari HttpClient.
 *
 * $error hanya terisi bila request gagal di level koneksi (DNS, timeout, TLS).
 * Respons HTTP 4xx/5xx tetap dianggap "sampai", jadi $error kosong dan
 * statusnya ada di $status.
 */
class HttpResponse
{
    public function __construct(
        public int $status,
        public ?string $body,
        public string $error = ''
    ) {}

    public function isSuccess(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode body sebagai array. Mengembalikan null kalau body bukan JSON valid.
     */
    public function json(): ?array
    {
        if ($this->body === null || $this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
