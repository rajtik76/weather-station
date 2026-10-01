<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Which lines the weather strip draws. Read against the defaults, so stray client keys change nothing and the last line on stays on.
 */
final readonly class ChannelSelection
{
    /** @var array<string, bool> */
    public const array DEFAULTS = ['t' => true, 'h' => true, 'd' => false];

    /**
     * @param  array<string, bool>  $on
     */
    private function __construct(private array $on) {}

    /**
     * @param  array<array-key, mixed>  $channels
     */
    public static function of(array $channels): self
    {
        $on = [];

        foreach (self::DEFAULTS as $channel => $default) {
            $on[$channel] = (bool) ($channels[$channel] ?? $default);
        }

        return new self($on);
    }

    /** An unknown channel, or the last one on, stays as it is. */
    public function toggle(string $channel): self
    {
        if (! array_key_exists($channel, $this->on) || $this->isLast($channel)) {
            return $this;
        }

        return new self([...$this->on, $channel => ! $this->on[$channel]]);
    }

    public function isLast(string $channel): bool
    {
        return ($this->on[$channel] ?? false) && count(array_filter($this->on)) === 1;
    }

    /**
     * @return list<string>
     */
    public function hidden(): array
    {
        return array_keys(array_filter($this->on, fn (bool $on): bool => ! $on));
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->on;
    }
}
