<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RainCommand;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/** Bot REST client: rain channel messages and slash command registration. */
final readonly class Discord
{
    public const string BASE_URL = 'https://discord.com/api/v10';

    public function isConfigured(): bool
    {
        return $this->setting('bot_token') !== null && $this->setting('channel_id') !== null;
    }

    public function isRainChannel(string $channelId): bool
    {
        return $channelId !== '' && $channelId === $this->setting('channel_id');
    }

    /**
     * @param  array<string, mixed>  $message
     * @return string the message id
     */
    public function post(array $message): string
    {
        $id = $this->client()->post('/channels/'.$this->required('channel_id').'/messages', $message)->throw()->json('id');

        if (! is_string($id)) {
            throw new UnexpectedValueException('Discord returned no message id.');
        }

        return $id;
    }

    public function registerCommands(): void
    {
        $commands = array_map(
            fn (RainCommand $command): array => ['name' => $command->value, 'description' => $command->description(), 'type' => 1],
            RainCommand::cases(),
        );

        $this->client()->put('/applications/'.$this->required('application_id').'/commands', $commands)->throw();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->required('bot_token'), 'Bot')
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function required(string $key): string
    {
        return $this->setting($key) ?? throw new UnexpectedValueException("Missing services.discord.{$key}.");
    }

    private function setting(string $key): ?string
    {
        $value = config('services.discord.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
