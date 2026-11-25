<?php

declare(strict_types=1);

use PeopleSuite\Accounts;
use PeopleSuite\BusinessError;
use PeopleSuite\Database;
use PeopleSuite\Demo;
use PeopleSuite\Schema;

require __DIR__ . '/../src/bootstrap.php';

if ($argc < 2 || in_array($argv[1], ['help', '--help'], true)) {
    fwrite(STDOUT, "Usage:\n  php bin/hcm.php migrate\n  php bin/hcm.php health\n"
        . "  php bin/hcm.php create-group GROUP GROUP_NAME ADMIN_EMAIL ADMIN_NAME\n"
        . "  php bin/hcm.php demo-seed|demo-finish|demo-check\n"
        . "create-group and demo commands read the supplied password from standard input.\n");
    exit(0);
}

try {
    $db = Database::connect();
    $schema = new Schema($db);
    $result = match ($argv[1]) {
        'migrate' => ['applied' => $schema->migrate()],
        'health' => ['ready' => $schema->ready()],
        'create-group' => createGroup($db, array_slice($argv, 2)),
        'demo-seed', 'demo-finish', 'demo-check' => (new Demo($db))->run($argv[1], inputPassword()),
        default => throw new BusinessError(422, 'unknown_command'),
    };
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(($result['ready'] ?? true) ? 0 : 1);
} catch (BusinessError $error) {
    fwrite(STDERR, json_encode(['error' => $error->reason, 'details' => $error->details], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

function createGroup(Database $db, array $arguments): array
{
    if (count($arguments) !== 4) {
        throw new BusinessError(422, 'group_arguments_required');
    }
    return (new Accounts($db))->createGroup(...[...$arguments, inputPassword()]);
}

function inputPassword(): string
{
    if (stream_isatty(STDIN)) {
        fwrite(STDERR, "Password (input is visible): ");
    }
    $line = fgets(STDIN, 1024);
    if ($line === false) {
        throw new BusinessError(422, 'password_required');
    }
    return rtrim($line, "\r\n");
}
