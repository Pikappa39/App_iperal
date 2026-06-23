#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Crea una release coerente: aggiorna APP_VERSION, esegue il commit e crea
 * il tag Git. Per includere le modifiche desiderate, prepararle prima con
 * `git add <file>`; il comando rifiuta modifiche non preparate.
 */

function releaseFail(string $message): never
{
    fwrite(STDERR, "Errore: {$message}\n");
    exit(1);
}

/** @return array{0:int, 1:string} */
function releaseCommand(array $command, string $workingDirectory): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory
    );
    if (!is_resource($process)) {
        releaseFail('Impossibile eseguire il comando Git.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    return [$exitCode, trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''))];
}

function releaseRunOrFail(array $command, string $workingDirectory): string
{
    [$exitCode, $output] = releaseCommand($command, $workingDirectory);
    if ($exitCode !== 0) {
        releaseFail($output !== '' ? $output : 'Comando Git non riuscito.');
    }

    return $output;
}

$arguments = array_slice($argv, 1);
$push = false;
if (($key = array_search('--push', $arguments, true)) !== false) {
    $push = true;
    unset($arguments[$key]);
    $arguments = array_values($arguments);
}

if (count($arguments) !== 2 || !in_array($arguments[0], ['patch', 'minor', 'major'], true)) {
    fwrite(STDERR, "Uso: php scripts/release.php <patch|minor|major> \"descrizione\" [--push]\n");
    exit(1);
}

[$increment, $description] = $arguments;
$description = trim($description);
if ($description === '') {
    releaseFail('La descrizione della release è obbligatoria.');
}

$projectRoot = dirname(__DIR__);
releaseRunOrFail(['git', 'rev-parse', '--is-inside-work-tree'], $projectRoot);

[$statusCode, $status] = releaseCommand(['git', 'status', '--porcelain'], $projectRoot);
if ($statusCode !== 0) {
    releaseFail('Impossibile leggere lo stato Git.');
}
foreach (preg_split('/\R/', $status) ?: [] as $line) {
    if ($line === '') {
        continue;
    }
    // La seconda colonna rappresenta modifiche non preparate; ?? sono file
    // non tracciati. Entrambi devono essere valutati esplicitamente prima
    // di creare una release.
    if (str_starts_with($line, '??') || (strlen($line) > 1 && $line[1] !== ' ')) {
        releaseFail('Ci sono modifiche non preparate. Usa git status e poi git add per scegliere cosa includere.');
    }
}

$configPath = $projectRoot . '/app_config.php';
$config = file_get_contents($configPath);
if (!is_string($config)) {
    releaseFail('Impossibile leggere app_config.php.');
}

$pattern = "/define\('APP_VERSION',\s*'([0-9]+)\.([0-9]+)\.([0-9]+)'\);/";
if (preg_match($pattern, $config, $matches) !== 1) {
    releaseFail('APP_VERSION non ha il formato x.y.z previsto.');
}

[$major, $minor, $patch] = array_map('intval', array_slice($matches, 1));
if ($increment === 'major') {
    $major++;
    $minor = 0;
    $patch = 0;
} elseif ($increment === 'minor') {
    $minor++;
    $patch = 0;
} else {
    $patch++;
}

$version = "{$major}.{$minor}.{$patch}";
$tag = "v{$version}";
[$tagExists] = releaseCommand(['git', 'rev-parse', '--verify', '--quiet', "refs/tags/{$tag}"], $projectRoot);
if ($tagExists === 0) {
    releaseFail("Il tag {$tag} esiste già.");
}

$updatedConfig = preg_replace($pattern, "define('APP_VERSION', '{$version}');", $config, 1);
if (!is_string($updatedConfig) || file_put_contents($configPath, $updatedConfig) === false) {
    releaseFail('Impossibile aggiornare APP_VERSION.');
}

releaseRunOrFail(['git', 'add', 'app_config.php'], $projectRoot);
releaseRunOrFail(['git', 'commit', '-m', "Versione {$tag} - {$description}"], $projectRoot);
releaseRunOrFail(['git', 'tag', '-a', $tag, '-m', "Versione {$tag} - {$description}"], $projectRoot);

if ($push) {
    $branch = releaseRunOrFail(['git', 'branch', '--show-current'], $projectRoot);
    if ($branch === '') {
        releaseFail('Non è possibile pubblicare una release da un commit scollegato da un ramo.');
    }
    releaseRunOrFail(['git', 'push', 'origin', $branch], $projectRoot);
    releaseRunOrFail(['git', 'push', 'origin', $tag], $projectRoot);
}

echo "Release {$tag} creata" . ($push ? ' e pubblicata' : ' localmente') . ".\n";
