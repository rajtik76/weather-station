<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProtocolVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Stringable;

class StoreMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|Stringable|string>>
     */
    public function rules(): array
    {
        return [
            'sensor_name' => ['required', 'string', 'min:3', 'max:50'],
            'protocol_version' => ['required', Rule::enum(ProtocolVersion::class)],
            'measurements' => ['required', 'array', 'min:1', 'max:500'],
            // Bounded by the unsignedInteger column, so an out-of-range value is a 422 and not a database error.
            'measurements.*.timestamp' => ['required', 'integer', 'date_format:U', 'min:1', 'max:4294967295'],
            ...$this->protocolSpecificRules(),
            ...$this->stationRules(),
        ];
    }

    /**
     * The `station` object with only the fields the rules name: `validated()`
     * hands the object back as sent, extra keys included, because the object
     * itself is under a rule.
     *
     * @return array<string, mixed>|null
     */
    public function stationReport(): ?array
    {
        $station = $this->validated('station');

        if (! is_array($station)) {
            return null;
        }

        $fields = array_map(
            fn (string $key): string => substr($key, strlen('station.')),
            array_keys(Arr::except($this->stationRules(), 'station')),
        );

        return Arr::only($station, $fields);
    }

    /**
     * The board's own state, sent beside the readings. Optional as a whole -
     * a batch without it is still a batch - but once present every field
     * has to be there, so a firmware that reports is held to the shape the
     * dashboard reads.
     *
     * @return array<string, array<int, string>>
     */
    protected function stationRules(): array
    {
        return [
            'station' => ['sometimes', 'array'],
            'station.firmware' => ['required_with:station', 'string', 'max:32'],
            'station.reset_reason' => ['required_with:station', 'string', 'max:40'],
            'station.uptime' => ['required_with:station', 'integer', 'min:0'],
            'station.heap_free' => ['required_with:station', 'integer', 'min:0'],
            'station.heap_min' => ['required_with:station', 'integer', 'min:0'],
            // Empty while the station is offline, which it never is when a batch arrives - but the firmware sends what it has.
            'station.ssid' => ['present_with:station', 'nullable', 'string', 'max:32'],
            'station.ip' => ['present_with:station', 'nullable', 'string', 'max:15'],
            'station.rssi' => ['required_with:station', 'integer', 'min:-120', 'max:0'],
            'station.wifi_network' => ['required_with:station', 'integer', 'min:0', 'max:1'],
            'station.wifi_switches' => ['required_with:station', 'integer', 'min:0'],
            'station.buffered' => ['required_with:station', 'integer', 'min:0'],
            'station.upload_failures' => ['required_with:station', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function protocolSpecificRules(): array
    {
        $version = $this->enum('protocol_version', ProtocolVersion::class);

        // Unknown version, let the protocol_version rule report it instead of demanding fields for a version nobody claimed.
        if ($version === null) {
            return [];
        }

        $rules = [];

        foreach ($version->validationRules() as $field => $fieldRules) {
            $rules["measurements.*.{$field}"] = $fieldRules;
        }

        return $rules;
    }
}
