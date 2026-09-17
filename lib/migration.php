<?php
declare(strict_types=1);

function migration_copy_directory(string $source, string $target): void {
    if (!is_dir($source)) return;
    storage_mkdir($target);
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isLink()) throw new RuntimeException('Migrering stöder inte symboliska länkar: '.$entry->getPathname());
        $dest = $target.'/'.$entry->getFilename();
        if ($entry->isDir()) migration_copy_directory($entry->getPathname(), $dest);
        elseif (!copy($entry->getPathname(), $dest)) throw new RuntimeException('Kunde inte kopiera '.$entry->getPathname());
    }
}
function migrate_v1(string $source, string $target): array {
    $source = rtrim($source, '/'); $target = rtrim($target, '/');
    if (file_exists($target)) throw new RuntimeException('Målmappen får inte finnas. Befintlig data skrivs aldrig över.');
    if (!is_file($source.'/store.json')) throw new RuntimeException('Källmappen saknar store.json.');
    $sourceReal=realpath($source); $targetParent=realpath(dirname($target));
    if ($targetParent === false || $targetParent === $sourceReal || str_starts_with($targetParent, $sourceReal.'/')) {
        throw new RuntimeException('Målmappens förälder måste finnas och ligga utanför källmappen.');
    }
    $lock = fopen($source.'/store.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Kunde inte låsa den gamla datalagringen.');
    $stage = $target.'.migrating-'.uid();
    try {
        $db = json_decode(file_get_contents($source.'/store.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($db['schemaVersion'] ?? null) !== 1) throw new RuntimeException('Förväntade schemaVersion 1.');
        $files = ['schema.json'=>['schemaVersion'=>2], 'settings.json'=>$db['settings'], 'admins.json'=>$db['admins']];
        foreach ($db['mailLog'] as $i => &$entry) $entry['id'] ??= substr(hash('sha256', $i.json_encode($entry)), 0, 32);
        unset($entry);
        foreach (['banks','tests','attempts','grants','invites','authCodes','adminSessions','preferences','mailLog'] as $table) {
            $rows = storage_collection_paths($table, $db[$table]);
            if (count($rows) !== count($db[$table])) throw new RuntimeException('Dubbletter i '.$table.'. Migreringen avbryts.');
            $files = array_merge($files, $rows);
        }
        storage_mkdir($stage);
        foreach ($files as $relative => $row) {
            $file = $stage.'/'.$relative; storage_mkdir(dirname($file));
            $json = json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($file, $json) !== strlen($json)) throw new RuntimeException('Kunde inte skriva '.$relative);
            chmod($file,0600);
            if (json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR) !== $row) throw new RuntimeException('Kontrolläsningen misslyckades: '.$relative);
        }
        foreach (['uploads','sessions','mail'] as $directory) migration_copy_directory($source.'/'.$directory, $stage.'/'.$directory);
        if (is_file($source.'/import-report.json') && !copy($source.'/import-report.json',$stage.'/import-report.json')) throw new RuntimeException('Kunde inte kopiera importrapporten.');
        if (file_put_contents($stage.'/.htaccess', "Require all denied\n") === false) throw new RuntimeException('Kunde inte skapa åtkomstskyddet.');
        if ($db['rateLimits']) {
            storage_mkdir($stage.'/rate-limits');
            foreach ($db['rateLimits'] as $key=>$row) {
                $path=$stage.'/rate-limits/'.storage_id($key).'.counter';
                if (file_put_contents($path, $row['until'].' '.$row['count']) === false) throw new RuntimeException('Kunde inte migrera åtkomstbegränsningen.');
            }
        }
        if (!rename($stage,$target)) throw new RuntimeException('Kunde inte färdigställa målmappen.');
        return ['banks'=>count($db['banks']), 'tests'=>count($db['tests']), 'results'=>count($db['attempts']), 'jsonFiles'=>count($files)];
    } catch (Throwable $e) {
        // Remove only our unique staging directory. The source and an existing target are untouched.
        if (is_dir($stage)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
            rmdir($stage);
        }
        throw $e;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
