<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/migration.php';
if ($argc !== 3) { fwrite(STDERR,"Användning: php system/migrate.php GAMMAL_DATA NY_DATA\nStäng först webbplatsen för skrivningar. Målmappen får inte finnas.\n"); exit(1); }
try {
    $summary = migrate_v1($argv[1], $argv[2]);
    echo "Migrering klar: ".json_encode($summary,JSON_UNESCAPED_UNICODE)."\nKälldata har behållits oförändrad. Byt datamapp innan webbplatsen öppnas igen.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
