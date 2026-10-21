<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RainCommand;
use App\Enums\RainReportSource;
use App\Models\RainReport;
use App\Queries\Discord;
use App\Queries\RainHearing;
use App\Queries\Stations;
use App\ValueObject\LocalTime;
use App\ValueObject\RainPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Discord interactions endpoint: /rain, /dry and the ✅/❌ buttons. */
class DiscordInteractionController extends Controller
{
    private const int PING = 1;

    private const int APPLICATION_COMMAND = 2;

    private const int MESSAGE_COMPONENT = 3;

    private const int PONG = 1;

    private const int CHANNEL_MESSAGE = 4;

    private const int UPDATE_MESSAGE = 7;

    private const int EPHEMERAL = 64;

    public function __invoke(Request $request, Stations $stations, RainHearing $hearing, Discord $discord): JsonResponse
    {
        $type = $request->integer('type');

        if ($type === self::PING) {
            return response()->json(['type' => self::PONG]);
        }

        if (! $discord->isRainChannel($request->string('channel_id')->value())) {
            return response()->json(['type' => self::CHANNEL_MESSAGE, 'data' => [
                'content' => 'Rain reports are taken only in the rain channel.',
                'flags' => self::EPHEMERAL,
            ]]);
        }

        return match ($type) {
            self::APPLICATION_COMMAND => $this->observe($request, $stations, $hearing),
            self::MESSAGE_COMPONENT => $this->answer($request),
            default => $this->unsupported(),
        };
    }

    private function observe(Request $request, Stations $stations, RainHearing $hearing): JsonResponse
    {
        $command = RainCommand::tryFrom($request->string('data.name')->value());

        if ($command === null) {
            return $this->unsupported();
        }

        $sensor = $stations->all()->first();

        if ($sensor === null) {
            return $this->reply('No station has reported yet.');
        }

        $now = now()->getTimestamp();
        $heard = $hearing->newest($sensor->id, $now)['heard'] ?? null;

        RainReport::query()->create([
            'sensor_id' => $sensor->id,
            'source' => RainReportSource::Observer,
            'timestamp' => $now,
            'detected' => $heard,
            'raining' => $command->raining(),
        ]);

        $logged = ($command->raining() ? 'Logged: raining at ' : 'Logged: not raining at ').LocalTime::of($now)->stamp().'.';
        $detector = match ($heard) {
            true => ' The detector hears rain.',
            false => ' The detector hears none.',
            null => ' The detector has no fresh window.',
        };

        return $this->reply($logged.$detector);
    }

    private function answer(Request $request): JsonResponse
    {
        $answer = RainPrompt::answer($request->string('data.custom_id')->value());
        $report = $answer === null ? null : RainReport::query()->find($answer['id']);

        if ($answer === null || $report === null) {
            return $this->reply('This report no longer exists.');
        }

        $report->update(['raining' => $answer['raining']]);

        return response()->json(['type' => self::UPDATE_MESSAGE, 'data' => new RainPrompt($report)->message()]);
    }

    private function reply(string $content): JsonResponse
    {
        return response()->json(['type' => self::CHANNEL_MESSAGE, 'data' => ['content' => $content]]);
    }

    private function unsupported(): JsonResponse
    {
        return response()->json(['message' => 'Unsupported interaction.'], JsonResponse::HTTP_BAD_REQUEST);
    }
}
