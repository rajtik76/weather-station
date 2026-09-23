<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use UnexpectedValueException;

/**
 * Protocol V3: a V2 window plus an optional "noise" object, present only
 * when the microphone produced data for that ten minutes.
 */
final readonly class MeasurementDataV3 implements MeasurementData
{
    public ProtocolVersion $protocolVersion;

    public function __construct(
        public int $temperature,
        public int $humidity,
        public int $pressure,
        public int $temperatureMin,
        public int $temperatureMax,
        public int $humidityMin,
        public int $humidityMax,
        public int $pressureMin,
        public int $pressureMax,
        public int $samples,
        public ?NoiseWindow $noise = null,
    ) {
        $this->protocolVersion = ProtocolVersion::V3;
    }

    public static function fromArray(array $data): self
    {
        foreach (array_keys(MeasurementDataV2::validationRules()) as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw new UnexpectedValueException("Missing or invalid field [{$field}] for protocol version 3.");
            }
        }

        $noise = null;

        if (isset($data['noise'])) {
            if (! is_array($data['noise'])) {
                throw new UnexpectedValueException('Missing or invalid field [noise] for protocol version 3.');
            }

            $noise = NoiseWindow::fromArray($data['noise']);
        }

        return new self(
            temperature: (int) $data['temperature'],
            humidity: (int) $data['humidity'],
            pressure: (int) $data['pressure'],
            temperatureMin: (int) $data['temperature_min'],
            temperatureMax: (int) $data['temperature_max'],
            humidityMin: (int) $data['humidity_min'],
            humidityMax: (int) $data['humidity_max'],
            pressureMin: (int) $data['pressure_min'],
            pressureMax: (int) $data['pressure_max'],
            samples: (int) $data['samples'],
            noise: $noise,
        );
    }

    /**
     * The V2 rules plus the noise object, optional as a whole and complete
     * once present - the same shape as the "station" report.
     */
    public static function validationRules(): array
    {
        return [
            ...MeasurementDataV2::validationRules(),
            'noise' => ['sometimes', 'array'],
            'noise.seconds' => ['required_with:measurements.*.noise', 'integer', 'min:1', 'max:600'],
            'noise.laeq' => ['required_with:measurements.*.noise', 'integer', 'min:0', 'max:15000', 'lte:measurements.*.noise.lamax'],
            'noise.lamax' => ['required_with:measurements.*.noise', 'integer', 'min:0', 'max:15000'],
            'noise.la10' => ['required_with:measurements.*.noise', 'integer', 'min:0', 'max:15000', 'lte:measurements.*.noise.lamax'],
            'noise.la90' => ['required_with:measurements.*.noise', 'integer', 'min:0', 'max:15000', 'lte:measurements.*.noise.la10'],
            // A list: 26 keyed entries would pass "array" and fail only on hydration, as a 500.
            'noise.bands' => ['required_with:measurements.*.noise', 'list', 'size:'.NoiseWindow::BANDS_COUNT],
            'noise.bands.*' => ['integer', 'min:0', 'max:15000'],
        ];
    }

    public function jsonSerialize(): array
    {
        $data = [
            'temperature' => $this->temperature,
            'humidity' => $this->humidity,
            'pressure' => $this->pressure,
            'temperature_min' => $this->temperatureMin,
            'temperature_max' => $this->temperatureMax,
            'humidity_min' => $this->humidityMin,
            'humidity_max' => $this->humidityMax,
            'pressure_min' => $this->pressureMin,
            'pressure_max' => $this->pressureMax,
            'samples' => $this->samples,
        ];

        if ($this->noise instanceof NoiseWindow) {
            $data['noise'] = $this->noise->jsonSerialize();
        }

        return $data;
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), flags: JSON_THROW_ON_ERROR);
    }
}
