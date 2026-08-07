<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Models\RegisteredServer;
use MarinSolutions\CheckybotLaravel\Models\ProjectApiToken;

if ($argc !== 5) {
    fwrite(STDERR, "Usage: seed.php <project-uuid> <opted-out-server-uuid> <enabled-server-uuid> <state-path>\n");
    exit(64);
}

$app = require dirname(__DIR__, 4).'/scripts/harness/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$project, $optedOutServer, $enabledServer, $statePath] = array_slice($argv, 1);
foreach ([$optedOutServer, $enabledServer] as $serverUuid) {
    $server = RegisteredServer::register(strtolower($project), strtolower($serverUuid));
    $server->forceFill(['share_redacted_logs' => true])->save();
}
$issued = ProjectApiToken::issue(strtolower($project), 'ai-annotation-runtime', ['agent:report']);

$state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
$state['agent_token'] = $issued->plainTextToken();
file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

fwrite(STDOUT, "Seeded two opted-in log-sharing runtime servers for project {$project}.\n");
