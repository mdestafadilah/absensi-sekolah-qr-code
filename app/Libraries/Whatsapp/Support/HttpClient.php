<?php

namespace App\Libraries\Whatsapp\Support;

/**
 * Pembungkus cURL minimal untuk provider Whatsapp.
 *
 * Semua provider mengirim request lewat sini supaya timeout, penanganan error
 * koneksi, dan bentuk hasilnya seragam. Method post() tidak pernah melempar
 * exception; kegagalan koneksi dilaporkan lewat HttpResponse::$error.
 */
class HttpClient
{
    /** Batas waktu handshake koneksi (detik). */
    public const CONNECT_TIMEOUT = 5;

    /** Batas waktu total request (detik). Notifikasi berjalan di request user
     *  yang sedang scan absen, jadi tidak boleh menggantung.
     *  Bisa ditimpa lewat WHATSAPP_TIMEOUT di .env. */
    public const TIMEOUT = 10;

    /** Batas waktu efektif: WHATSAPP_TIMEOUT bila diisi angka 1-60, selain itu TIMEOUT. */
    public static function timeout(): int
    {
        $configured = (int) env('WHATSAPP_TIMEOUT');

        return ($configured >= 1 && $configured <= 60) ? $configured : self::TIMEOUT;
    }

    /**
     * @param string          $url
     * @param array|string    $body    array => form-encoded, string => body mentah
     * @param array<int,string> $headers
     */
    public static function post(string $url, array|string $body, array $headers = []): HttpResponse
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::timeout(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($curl);
        $errno    = curl_errno($curl);
        $error    = $errno !== 0 ? curl_error($curl) : '';
        $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        if ($errno !== 0) {
            return new HttpResponse($status, null, $error !== '' ? $error : "cURL error {$errno}");
        }

        return new HttpResponse($status, is_string($response) ? $response : null);
    }
}
