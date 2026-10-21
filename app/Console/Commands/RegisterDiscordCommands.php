<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RainCommand;
use App\Queries\Discord;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('discord:register-commands')]
#[Description('Register the /rain and /dry slash commands with Discord')]
class RegisterDiscordCommands extends Command
{
    public function handle(Discord $discord): int
    {
        $discord->registerCommands();

        $this->info('Registered: '.implode(', ', array_map(fn (RainCommand $command): string => '/'.$command->value, RainCommand::cases())).'.');

        return self::SUCCESS;
    }
}
