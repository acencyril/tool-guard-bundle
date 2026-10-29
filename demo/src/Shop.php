<?php

declare(strict_types=1);

namespace ToolGuardDemo;

/** A tiny shop kept in a JSON file: two customers, their orders, the refunds really made. */
final class Shop
{
    public function __construct(private readonly string $file)
    {
    }

    /** @return array{orders: array<string, array{customer: string, total: float, refunded: float}>, customers: array<string, string>} */
    public function data(): array
    {
        $d = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        return \is_array($d) ? $d : [
            'orders' => [
                'A-1' => ['customer' => 'alice', 'total' => 89.0, 'refunded' => 0.0],
                'A-2' => ['customer' => 'alice', 'total' => 640.0, 'refunded' => 0.0],
                'B-7' => ['customer' => 'bob', 'total' => 240.0, 'refunded' => 0.0],
            ],
            'customers' => ['alice' => 'alice@example.test, 12 rue des Lilas', 'bob' => 'bob@example.test, 3 place du Marché'],
        ];
    }

    public function save(array $d): void
    {
        if (!is_dir(\dirname($this->file))) {
            mkdir(\dirname($this->file), 0775, true);
        }
        file_put_contents($this->file, json_encode($d, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
}
