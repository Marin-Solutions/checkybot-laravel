<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Illuminate\Console\Command;

class CheckybotCommand extends Command
{
    public $signature = 'checkybot-laravel-temp';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
