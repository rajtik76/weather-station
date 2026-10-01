<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * A packet as indented JSON, with a list of numbers kept on one line: 26 noise bands would otherwise take 26 rows.
 */
final readonly class PacketJson
{
    /**
     * @param  array<string, mixed>  $packet
     */
    public static function format(array $packet): string
    {
        $json = json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return (string) preg_replace_callback(
            '/\[\s*(-?\d+(?:\s*,\s*-?\d+)*)\s*\]/',
            static fn (array $list): string => '['.preg_replace('/\s*,\s*/', ', ', $list[1]).']',
            $json,
        );
    }
}
