<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\RainReport;

/** Discord prompt with ✅/❌ buttons; ✅ = raining for both detector states. */
final readonly class RainPrompt
{
    private const string BUTTON_PREFIX = 'rain-report';

    private const int ACTION_ROW = 1;

    private const int BUTTON = 2;

    private const int SUCCESS_STYLE = 3;

    private const int DANGER_STYLE = 4;

    public function __construct(private RainReport $report) {}

    /**
     * @return array{content: string, components: list<array{type: int, components: list<array<string, mixed>>}>}
     */
    public function message(): array
    {
        return [
            'content' => $this->content(),
            'components' => [[
                'type' => self::ACTION_ROW,
                'components' => [$this->button(raining: true), $this->button(raining: false)],
            ]],
        ];
    }

    /**
     * @return ?array{id: int, raining: bool}
     */
    public static function answer(string $customId): ?array
    {
        if (preg_match('/^'.self::BUTTON_PREFIX.':(\d+):(yes|no)$/', $customId, $matches) !== 1) {
            return null;
        }

        return ['id' => (int) $matches[1], 'raining' => $matches[2] === 'yes'];
    }

    private function content(): string
    {
        $at = LocalTime::of($this->report->timestamp)->stamp();
        $question = $this->report->detected === true
            ? "🌧️ Rain heard at {$at}. Is it raining?"
            : "🌤️ Rain no longer heard at {$at}. Is it raining?";

        return match ($this->report->raining) {
            true => $question."\nAnswer: ✅ raining",
            false => $question."\nAnswer: ❌ not raining",
            null => $question,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function button(bool $raining): array
    {
        return [
            'type' => self::BUTTON,
            'style' => $raining ? self::SUCCESS_STYLE : self::DANGER_STYLE,
            'label' => $raining ? 'Raining' : 'Not raining',
            'emoji' => ['name' => $raining ? '✅' : '❌'],
            'custom_id' => self::BUTTON_PREFIX.':'.$this->report->id.':'.($raining ? 'yes' : 'no'),
        ];
    }
}
