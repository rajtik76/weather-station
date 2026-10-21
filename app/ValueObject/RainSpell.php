<?php

declare(strict_types=1);

namespace App\ValueObject;

/** The rain still going on at the newest window, from its first to its latest rainy window. */
final readonly class RainSpell
{
    private function __construct(public int $start, public int $end) {}

    /**
     * Null once $dryWindows windows in a row heard no rain; shorter pauses belong to the same rain.
     *
     * @param  list<array{timestamp: int, rain: ?bool}>  $windows  oldest first, stamped at the window end
     */
    public static function current(array $windows, int $dryWindows): ?self
    {
        $first = null;
        $last = null;
        $dryInRow = 0;

        for ($index = count($windows) - 1; $index >= 0; $index--) {
            if ($windows[$index]['rain'] === true) {
                $last ??= $index;
                $first = $index;
                $dryInRow = 0;

                continue;
            }

            if (++$dryInRow >= $dryWindows) {
                break;
            }
        }

        if ($first === null || $last === null) {
            return null;
        }

        return new self($windows[$first]['timestamp'] - ChartWindow::STEP_SECONDS, $windows[$last]['timestamp']);
    }

    public function span(): string
    {
        return LocalTime::of($this->start)->clock().'-'.LocalTime::of($this->end)->clock();
    }
}
