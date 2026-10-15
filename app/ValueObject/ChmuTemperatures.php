<?php

declare(strict_types=1);

namespace App\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final readonly class ChmuTemperatures
{
    public const string STATION = '0-20000-0-11450';

    /**
     * @return list<array{station: string, timestamp: int, temperature: ?float, quality: int}>
     */
    public static function parse(string $json): array
    {
        $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $values = $document['data']['data']['values'] ?? null;

        if (! is_array($values) || ($document['data']['data']['header'] ?? null) !== 'STATION,ELEMENT,DT,VAL,FLAG,QUALITY') {
            throw new UnexpectedValueException('Unrecognized ČHMÚ temperature document.');
        }

        $rows = [];

        foreach ($values as $value) {
            if (! is_array($value) || ($value[0] ?? null) !== self::STATION || ($value[1] ?? null) !== 'T') {
                continue;
            }

            $date = is_string($value[2] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value[2], new DateTimeZone('UTC')) : false;
            $quality = $value[5] ?? null;

            if ($date === false || $date->format('Y-m-d\\TH:i:s\\Z') !== $value[2] || ! is_numeric($quality) || (float) $quality !== (float) (int) $quality || $quality < 0 || $quality > 5) {
                throw new UnexpectedValueException('Malformed ČHMÚ temperature observation.');
            }

            $temperature = $value[3] ?? null;
            $usable = is_numeric($temperature) && is_finite((float) $temperature) && (float) $temperature >= -90 && (float) $temperature <= 60
                && in_array((int) $quality, [0, 5], true) && ($value[4] ?? '') === '';

            $rows[$date->getTimestamp()] = [
                'station' => self::STATION,
                'timestamp' => $date->getTimestamp(),
                'temperature' => $usable ? (float) $temperature : null,
                'quality' => (int) $quality,
            ];
        }

        if ($rows === []) {
            throw new UnexpectedValueException('No Mikulka air temperature observations in the document.');
        }

        ksort($rows);

        return array_values($rows);
    }
}
