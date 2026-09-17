<?php
declare(strict_types=1);

// Schema 2: no shared JSON is written by participants. Catalog files are admin-only;
// attempts, access grants, login sessions and mail events have individual files.
function storage_id(string $id): string {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/D', $id)) fail('Ogiltigt id.');
    return $id;
}
function storage_mkdir(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) fail('Datamappen är inte skrivbar.', 503);
}
function storage_read(string $relative, ?array $fallback = null): array {
    $file = data_path($relative);
    if (!is_file($file)) return $fallback ?? fail('Objektet finns inte.', 404);
    // Atomic rename means readers see either the old complete file or the new one.
    $raw = @file_get_contents($file);
    if ($raw === false) return $fallback ?? fail('Objektet finns inte.', 404);
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) fail('Ogiltig datafil.', 503);
    return $value;
}
function storage_write(string $relative, array $value): void {
    $file = data_path($relative);
    storage_mkdir(dirname($file));
    $tmp = $file.'.'.uid().'.tmp';
    try {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($tmp, $json) !== strlen($json)) fail('Kunde inte spara data.', 503);
        chmod($tmp, 0600);
        if (!rename($tmp, $file)) fail('Kunde inte slutföra sparningen.', 503);
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function storage_lock(string $key, callable $fn, int $mode = LOCK_EX): mixed {
    storage_mkdir(data_path('locks'));
    // Lock a stable sidecar, never the inode replaced by rename(). Do not remove lock files.
    $handle = fopen(data_path('locks/'.hash('sha256', $key).'.lock'), 'c');
    if (!$handle || !flock($handle, $mode)) fail('Datalagringen är inte tillgänglig.', 503);
    try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
}
function storage_files(string $pattern): array {
    return array_map(fn($p) => substr($p, strlen(data_path())), glob(data_path($pattern)) ?: []);
}
function storage_rows(string $pattern): array {
    $rows = [];
    foreach (storage_files($pattern) as $path) {
        $row = storage_read($path, []);
        if ($row) $rows[] = $row;
    }
    return $rows;
}
function storage_attempt_path(string $id): string {
    $paths = storage_files('tests/*/result-'.storage_id($id).'.json');
    if (count($paths) !== 1) fail('Objektet finns inte.', 404);
    return $paths[0];
}
function storage_grant_path(string $testId, string $id): string {
    return 'tests/'.storage_id($testId).'/access/'.storage_id($id).'.json';
}
function storage_result_path(array $a): string {
    return 'tests/'.storage_id($a['test']['id']).'/result-'.storage_id($a['id']).'.json';
}
function storage_context(): array {
    if (!is_file(data_path('schema.json'))) fail(is_file(data_path('store.json'))
        ? 'Data behöver migreras till separata filer. Se installationsguiden.' : 'Startdata saknas. Installera data-mappen.', 503);
    if ((storage_read('schema.json')['schemaVersion'] ?? null) !== 2) fail('Dataformatet stöds inte.', 503);
    $db = ['settings'=>storage_read('settings.json'), 'admins'=>storage_read('admins.json'),
        'banks'=>[], 'tests'=>[], 'attempts'=>[], 'grants'=>[], 'invites'=>[],
        'preferences'=>[], 'authCodes'=>[], 'adminSessions'=>[], 'mailLog'=>[]];
    if (!empty($_SESSION['adminToken'])) {
        $token = storage_id($_SESSION['adminToken']);
        $session = storage_read('auth/sessions/'.$token.'.json', []);
        if ($session) $db['adminSessions'][$token] = $session;
    }
    return $db;
}
function storage_catalog(array &$db): void {
    $db['banks'] = storage_rows('banks/*.json');
    $db['tests'] = storage_rows('tests/*/test.json');
}
function storage_person_attempts(string $testId, string $grantId): array {
    // Only used when starting/resuming a test, never for an answer. No result index is rewritten.
    return array_values(array_filter(storage_rows('tests/'.storage_id($testId).'/result-*.json'),
        fn($a) => $a['grantId'] === $grantId));
}
function storage_attempt(array &$db, string $id): void {
    $a = storage_read(storage_attempt_path($id));
    $cutoff = time() - $db['settings']['retentionDays'] * 86400;
    if (($a['finishedAt'] ?? $a['startedAt']) < $cutoff) fail('Objektet finns inte.', 404);
    $db['attempts'] = [$a];
    $g = storage_read(storage_grant_path($a['test']['id'], $a['grantId']), []);
    if ($g) $db['grants'] = [$g];
}
function storage_collection_paths(string $table, array $rows): array {
    $mapped = [];
    foreach ($rows as $key => $row) {
        $path = match ($table) {
            'banks' => 'banks/'.storage_id($row['id']).'.json',
            'tests' => 'tests/'.storage_id($row['id']).'/test.json',
            'attempts' => storage_result_path($row),
            'grants' => storage_grant_path($row['testId'], $row['id']),
            'invites' => 'tests/'.storage_id($row['testId']).'/invitations/'.storage_id($row['hash']).'.json',
            'authCodes' => 'auth/codes/'.hash('sha256', $key).'.json',
            'adminSessions' => 'auth/sessions/'.storage_id($key).'.json',
            'preferences' => 'preferences/'.hash('sha256', $key).'.json',
            'mailLog' => 'mail-log/'.storage_id($row['id']).'.json',
        };
        $mapped[$path] = $row;
    }
    return $mapped;
}
function storage_commit(array $before, array $after, array $allowed): void {
    foreach ($after as $table => $rows) {
        if (($before[$table] ?? null) !== $rows && !in_array($table, $allowed, true)) {
            throw new LogicException('Unscoped write to '.$table);
        }
    }
    // Write the attempt before the usage counter. After interruption, start() repairs used
    // from the existing attempts under the same grant lock, so a retry never loses a slot.
    $order = array_unique(array_merge(['attempts','grants'], $allowed));
    foreach ($order as $table) {
        if (!in_array($table, $allowed, true) || ($before[$table] ?? []) === $after[$table]) continue;
        if (in_array($table, ['admins','settings'], true)) {
            storage_write($table.'.json', $after[$table]); continue;
        }
        $old = storage_collection_paths($table, $before[$table] ?? []);
        $new = storage_collection_paths($table, $after[$table]);
        foreach ($new as $path => $row) if (!isset($old[$path]) || $old[$path] !== $row) storage_write($path, $row);
        // Deleting a test removes only test.json, never its results or access records.
        foreach (array_diff_key($old, $new) as $path => $_) if (is_file(data_path($path)) && !unlink(data_path($path))) fail('Kunde inte ta bort datafilen.', 503);
    }
}
function storage_run(array $db, callable $fn, array $allowed): mixed {
    $before = $db; $error = null; $result = null;
    try { $result = $fn($db); } catch (AppError $e) { $error = $e; }
    // In particular, failed OTP attempts must persist. Unexpected failures never commit.
    storage_commit($before, $db, $allowed);
    if ($error) throw $error;
    return $result;
}
function storage_request(string $action, array $input, callable $fn): mixed {
    if ($action === 'setupLogin') return storage_lock('catalog', function () use ($fn) {
        return storage_run(storage_context(), $fn, ['settings','adminSessions']);
    });
    $catalogWrites = ['bankSave'=>['banks'], 'questionSave'=>['banks'], 'questionDelete'=>['banks'],
        'testSave'=>['tests'], 'testDelete'=>['tests'], 'settings'=>['settings'],
        'adminSave'=>['admins'], 'adminDelete'=>['admins']];
    if (isset($catalogWrites[$action])) return storage_lock('catalog', function () use ($action, $fn, $catalogWrites) {
        $db = storage_context(); require_admin($db); storage_catalog($db);
        return storage_run($db, $fn, $catalogWrites[$action]);
    });
    if (in_array($action, ['loginRequest','loginVerify'], true)) {
        $email = email_value($input['email'] ?? null);
        return storage_lock('auth-code-hash:'.hash('sha256',$email), function () use ($email, $fn) {
            $db = storage_context(); $entry = storage_read('auth/codes/'.hash('sha256',$email).'.json', []);
            if ($entry) $db['authCodes'][$email] = $entry;
            return storage_run($db, $fn, ['authCodes','adminSessions','mailLog']);
        });
    }
    if ($action === 'start') {
        $testId = storage_id(required($input['testId'] ?? null, 100));
        $grantId = storage_id($_SESSION['grants'][$testId] ?? 'missing');
        return storage_lock('grant:'.$grantId, function () use ($testId, $grantId, $fn) {
            $db = storage_lock('catalog', function () use ($testId) {
                $db = storage_context(); $t = storage_read('tests/'.$testId.'/test.json');
                $db['tests'] = [$t]; $db['banks'] = [storage_read('banks/'.storage_id($t['bankId']).'.json')];
                return $db;
            }, LOCK_SH);
            $db['grants'] = [storage_read(storage_grant_path($testId, $grantId))];
            $db['attempts'] = storage_person_attempts($testId, $grantId);
            return storage_run($db, $fn, ['attempts','grants']);
        });
    }
    if (in_array($action, ['answer','attempt','diploma','result','resend'], true)) {
        $id = storage_id(required($input['id'] ?? $_GET['id'] ?? null, 100));
        return storage_lock('attempt:'.$id, function () use ($action, $id, $fn) {
            $db = storage_context();
            if (in_array($action, ['result','resend'], true)) require_admin($db);
            elseif (!in_array($id, $_SESSION['attempts'] ?? [], true) && !admin_email($db)) fail('Testet tillhör en annan session.', 403);
            storage_attempt($db, $id);
            return storage_run($db, $fn, ['attempts','mailLog']);
        });
    }
    if ($action === 'preference') {
        $admin = require_admin(storage_context());
        return storage_lock('preference:'.$admin, function () use ($admin, $fn) {
            $db = storage_context(); storage_catalog($db);
            $db['preferences'][$admin] = storage_read('preferences/'.hash('sha256',$admin).'.json', []);
            return storage_run($db, $fn, ['preferences']);
        });
    }
    if ($action === 'logout') return storage_lock('auth-session:'.($_SESSION['adminToken'] ?? 'none'), function () use ($fn) {
        return storage_run(storage_context(), $fn, ['adminSessions']);
    });
    $db = storage_context();
    if ($action === 'admin') {
        $admin = require_admin($db);
        storage_cleanup(); // Only admin/CLI maintenance may remove other people's expired records.
        storage_catalog($db);
        $db['preferences'][$admin] = storage_read('preferences/'.hash('sha256',$admin).'.json', []);
        $db['attempts'] = storage_rows('tests/*/result-*.json');
        usort($db['attempts'], fn($a,$b) => ($a['finishedAt'] ?? $a['startedAt']) <=> ($b['finishedAt'] ?? $b['startedAt']));
        $db['mailLog'] = storage_rows('mail-log/*.json');
        usort($db['mailLog'], fn($a,$b) => $a['at'] <=> $b['at']);
        $db['mailLog'] = array_slice($db['mailLog'], -200);
    } elseif ($action === 'access') {
        $testId = storage_id(required($input['testId'] ?? null, 100));
        $db['tests'] = [storage_read('tests/'.$testId.'/test.json')];
        if (($input['method'] ?? '') === 'code') {
            $hash = hash('sha256', strtoupper(required($input['code'] ?? null, 100)));
            $invite = storage_read('tests/'.$testId.'/invitations/'.$hash.'.json', []);
            if ($invite) {
                $db['invites'] = [$invite];
                $g = storage_read(storage_grant_path($testId, $invite['grantId']), []);
                if ($g) $db['grants'] = [$g];
            }
        } elseif (isset($_SESSION['grants'][$testId])) {
            $g = storage_read(storage_grant_path($testId, $_SESSION['grants'][$testId]), []);
            if ($g) $db['grants'] = [$g];
        }
    } elseif ($action === 'image') {
        if (!admin_email($db)) foreach ($_SESSION['attempts'] ?? [] as $id) {
            try { $db['attempts'][] = storage_read(storage_attempt_path($id)); }
            catch (AppError $e) { if ($e->getCode() !== 404) throw $e; }
        }
    } elseif (in_array($action, ['bootstrap','bankPdf','invite'], true)) {
        if ($action !== 'bootstrap') require_admin($db);
        storage_catalog($db);
    }
    return storage_run($db, $fn, match ($action) {
        'access' => ['grants'], 'invite' => ['grants','invites','mailLog'],
        'mailTest' => ['mailLog'], 'logout' => ['adminSessions'], default => [],
    });
}

function storage_cleanup(): void {
    $cutoff = time() - storage_read('settings.json')['retentionDays'] * 86400;
    $groups = [
        ['tests/*/result-*.json', fn($v)=>'attempt:'.$v['id'], fn($v)=>($v['finishedAt'] ?? $v['startedAt']) < $cutoff],
        ['tests/*/access/*.json', fn($v)=>'grant:'.$v['id'], fn($v)=>$v['expires'] < $cutoff],
        ['tests/*/invitations/*.json', fn($v)=>'invite:'.$v['id'], fn($v)=>$v['expires'] < $cutoff],
        ['auth/sessions/*.json', fn($v,$p)=>'auth-session:'.basename($p,'.json'), fn($v)=>$v['expires'] < time()],
        ['mail-log/*.json', fn($v)=>'mail:'.$v['id'], fn($v)=>$v['at'] < $cutoff],
    ];
    foreach ($groups as [$pattern,$key,$expired]) foreach (storage_files($pattern) as $path) {
        $row = storage_read($path, []);
        if (!$row || !$expired($row)) continue;
        storage_lock($key($row,$path), function () use ($path,$expired) {
            $row = storage_read($path, []); // Recheck after acquiring the same lock used by writers.
            if ($row && $expired($row)) unlink(data_path($path));
        });
    }
    // Login-code locks use the email hash so cleanup can share the exact writer lock.
    foreach (storage_files('auth/codes/*.json') as $path) storage_lock('auth-code-hash:'.basename($path,'.json'), function () use ($path) {
        $row = storage_read($path, []);
        if ($row && $row['expires'] < time()) unlink(data_path($path));
    });
    foreach (glob(data_path('mail/*.eml')) ?: [] as $file) if (filemtime($file) < $cutoff) unlink($file);
    foreach (glob(data_path('rate-limits/*.counter')) ?: [] as $file) storage_lock('rate:'.basename($file,'.counter'), function () use ($file) {
        if (is_file($file) && (int)explode(' ', trim(file_get_contents($file)))[0] < time()) unlink($file);
    });
}
