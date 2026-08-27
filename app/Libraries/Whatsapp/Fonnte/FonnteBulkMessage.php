<?php

namespace App\Libraries\Whatsapp\Fonnte;

use InvalidArgumentException;

class FonnteBulkMessage
{
    private array $messageWhatsapp = [];

    public function __construct(public array $messages)
    {
        foreach ($messages as $i => $message) {
            if (! is_array($message) || ! isset($message['destination'], $message['message'])) {
                throw new InvalidArgumentException(
                    "Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $this->messageWhatsapp[] = (new FonnteMessage(
                (string) $message['destination'],
                (string) $message['message'],
                (int) ($message['delay'] ?? 2)
            ))->toArray();
        }
    }

    public function toArray()
    {
        return $this->messageWhatsapp;
    }

    public function toJson()
    {
        return json_encode($this->toArray());
    }
}
