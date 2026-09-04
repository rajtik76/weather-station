<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProtocolVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|\Stringable|string>>
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
