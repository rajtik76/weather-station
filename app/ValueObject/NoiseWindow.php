<?php

declare(strict_types=1);

namespace App\ValueObject;

use UnexpectedValueException;

/**
 * Protocol V3's optional "noise" object: one third-octave spectrum and four
 * summary levels over the same ten-minute window as the V2 fields beside it.
 */
final readonly class NoiseWindow
{
    public const int BANDS_COUNT = 26;

    /**
     * @param  list<int>  $bands
     */
    public function __construct(
        public int $seconds,
        public int $laeq,
        public int $lamax,
        public int $la10,
        public int $la90,
        public array $bands,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['seconds', 'laeq', 'lamax', 'la10', 'la90'] as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw new UnexpectedValueException("Missing or invalid field [noise.{$field}] for protocol version 3.");
            }
        }

        if (! isset($data['bands']) || ! is_array($data['bands']) || ! array_is_list($data['bands']) || count($data['bands']) !== self::BANDS_COUNT) {
            throw new UnexpectedValueException('Missing or invalid field [noise.bands] for protocol version 3.');
        }

        $bands = [];

        foreach ($data['bands'] as $band) {
            if (! is_numeric($band)) {
                throw new UnexpectedValueException('Missing or invalid field [noise.bands] for protocol version 3.');
            }

            $bands[] = (int) $band;
        }

        return new self(
            seconds: (int) $data['seconds'],
            laeq: (int) $data['laeq'],
            lamax: (int) $data['lamax'],
            la10: (int) $data['la10'],
            la90: (int) $data['la90'],
            bands: $bands,
        );
    }

    /**
     * @return array<string, int|list<int>>
     */
    public function jsonSerialize(): array
    {
        return [
            'seconds' => $this->seconds,
            'laeq' => $this->laeq,
            'lamax' => $this->lamax,
            'la10' => $this->la10,
            'la90' => $this->la90,
            'bands' => $this->bands,
        ];
    }
}
