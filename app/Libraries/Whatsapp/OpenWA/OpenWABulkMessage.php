<?php

namespace App\Libraries\Whatsapp\OpenWA;

use InvalidArgumentException;

/**
 * Kumpulan pesan untuk endpoint send-bulk OpenWA.
 */
class OpenWABulkMessage
{
    /** Batas item per batch sesuai spesifikasi OpenWA. */
    public const MAX_ITEMS = 100;

    /** @var OpenWAMessage[] */
    private array $items = [];

    public function __construct(public array $messages, private int $delaySeconds = 3)
    {
        foreach ($messages as $i => $message) {
            if (! is_array($message) || ! isset($message['destination'], $message['message'])) {
                throw new InvalidArgumentException(
                    "Pesan ke-{$i} harus berupa array dengan kunci 'destination' dan 'message'"
                );
            }

            $item = new OpenWAMessage(
                (string) $message['destination'],
                (string) $message['message']
            );

            if ($item->chatId === '' || str_starts_with($item->chatId, '@')) {
                throw new InvalidArgumentException("Pesan ke-{$i} tidak punya nomor tujuan yang valid");
            }

            $this->items[] = $item;
        }

        if (count($this->items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(
                'OpenWA membatasi ' . self::MAX_ITEMS . ' pesan per batch, diberikan ' . count($this->items)
            );
        }
    }

    /** @return OpenWAMessage[] */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function first(): ?OpenWAMessage
    {
        return $this->items[0] ?? null;
    }

    public function toArray(): array
    {
        // delayBetweenMessages dalam milidetik, dibatasi 1000-60000 oleh OpenWA
        $delayMs = min(60000, max(1000, $this->delaySeconds * 1000));

        return [
            'messages' => array_map(static fn(OpenWAMessage $m) => $m->toBulkItem(), $this->items),
            'options'  => [
                'delayBetweenMessages' => $delayMs,
                'randomizeDelay'       => true,
                'stopOnError'          => false,
            ],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
