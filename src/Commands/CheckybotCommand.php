<?php

namespace MarinSolutions\CheckybotLaravel\Commands;

use Illuminate\Console\Command;

class CheckybotCommand extends Command
{
    public $signature = 'checkybot:sync';

    public $description = 'Sync monitoring checks with CheckyBot platform';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
