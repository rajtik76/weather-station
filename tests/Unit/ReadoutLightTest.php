<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\ValueObject\CarriesLight;
use App\ValueObject\LightWindow;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\Readout;

it('reads light from any entry that carries it, not from a version class', function (): void {
    $entry = new class(new LightWindow(illuminance: 112300, illuminanceMin: 98000, illuminanceMax: 131000)) implements CarriesLight
    {
        public int $temperature = 2100;

        public int $humidity = 5800;

        public int $pressure = 97390;

        public int $temperatureMin = 2000;

        public int $temperatureMax = 2200;

        public int $humidityMin = 5700;

        public int $humidityMax = 5900;

        public int $pressureMin = 97380;

        public int $pressureMax = 97395;

        public int $samples = 20;

        public ProtocolVersion $protocolVersion = ProtocolVersion::V4;

        public function __construct(public ?LightWindow $light) {}

        public static function fromArray(array $data): MeasurementDataV4
        {
            return MeasurementDataV4::fromArray($data);
        }

        public static function validationRules(): array
        {
            return [];
        }

        public function jsonSerialize(): array
        {
            return [];
        }

        public function __toString(): string
        {
            return '{}';
        }
    };

    $readout = Readout::of($entry);

    expect($readout->light())->toBe(1123.0)
        ->and($readout->toArray()['lMin'])->toBe(980.0)
        ->and($readout->toArray()['lMax'])->toBe(1310.0);
});

it('has no light for an entry that cannot carry it', function (): void {
    $readout = Readout::of(new MeasurementDataV1(temperature: 2100, humidity: 5800, pressure: 97390));

    expect($readout->light())->toBeNull();
});
