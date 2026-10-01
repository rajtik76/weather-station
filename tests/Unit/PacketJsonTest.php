<?php

declare(strict_types=1);

use App\ValueObject\PacketJson;

it('indents the packet and keeps a list of numbers on one line', function (): void {
    $json = PacketJson::format(['temperature' => 2134, 'noise' => ['laeq' => 5562, 'bands' => [3120, -5, 7]]]);

    expect($json)->toBe(<<<'JSON'
    {
        "temperature": 2134,
        "noise": {
            "laeq": 5562,
            "bands": [3120, -5, 7]
        }
    }
    JSON);
});
