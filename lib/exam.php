<?php
declare(strict_types=1);
function public_test(array $test, array $db): array {
    $bank = $db['banks'][index_of($db['banks'], $test['bankId'])];
    return array_intersect_key($test, array_flip(['id','title','description','questionCount','priceEur','paidDays','paidMaxAttempts','codeSeconds','paidSeconds'])) +
        ['available' => $test['active'] && count($bank['questions']) >= $test['questionCount'], 'paymentMode' => $db['settings']['paymentMode']];
}
function my_attempt_index(array $db, string $id): int {
    if (!in_array($id, $_SESSION['attempts'] ?? [], true) && !admin_email($db)) fail('Testet tillhör en annan session.', 403);
    return index_of($db['attempts'], $id);
}
function finish_attempt(array &$db, array &$a): void {
    if ($a['finishedAt'] !== null) return;
    $a['finishedAt'] = time();
    $a['score'] = count(array_filter($a['answers'], fn($v) => $v['correct']));
    $a['passed'] = $a['score'] >= $a['test']['passCount'];
    if ($a['passed']) {
        $a['diplomaMailSent'] = send_email($db, $a['person']['email'], 'NIAG – diplom / certificate',
            'Ditt diplom från NIAG bifogas. / Your NIAG certificate is attached.', diploma_pdf($a));
    }
}
function expire_question(array &$db, array &$a): void {
    if ($a['finishedAt'] !== null || !$a['seconds'] || time() < $a['questionStartedAt'] + $a['seconds']) return;
    $a['answers'][] = ['selected' => null, 'correct' => false, 'timedOut' => true, 'answeredAt' => time()];
    $a['questionStartedAt'] = time();
    if (count($a['answers']) === count($a['questions'])) finish_attempt($db, $a);
}
function attempt_view(array $a): array {
    if ($a['finishedAt'] !== null) return ['id' => $a['id'], 'finished' => true, 'passed' => $a['passed'],
        'diplomaMailSent' => $a['diplomaMailSent'] ?? false, 'language' => $a['language']];
    $i = count($a['answers']); $q = $a['questions'][$i];
    return ['id' => $a['id'], 'finished' => false, 'index' => $i, 'total' => count($a['questions']),
        'question' => ['text' => $q['text'][$a['language']], 'options' => $q['options'][$a['language']], 'image' => $q['image']],
        'secondsLeft' => $a['seconds'] ? max(0, $a['seconds'] - (time() - $a['questionStartedAt'])) : null,
        'language' => $a['language']];
}
function validate_test(array $input, array $db): array {
    $bankId = required($input['bankId'] ?? null, 100);
    $bank = $db['banks'][index_of($db['banks'], $bankId)];
    $count = integer($input['questionCount'] ?? null, 1, count($bank['questions']));
    $price = $input['priceEur'] ?? null;
    if (!is_numeric($price) || $price < 0 || $price > 100000) fail('Ogiltigt pris.');
    return ['bankId' => $bankId, 'name' => required($input['name'] ?? null, 200),
        'title' => translated($input['title'] ?? null, 200), 'description' => translated($input['description'] ?? null),
        'questionCount' => $count, 'passCount' => integer($input['passCount'] ?? null, 1, $count),
        'codeSeconds' => integer($input['codeSeconds'] ?? null, 0, 3600),
        'paidSeconds' => integer($input['paidSeconds'] ?? null, 0, 3600), 'priceEur' => round((float)$price, 2),
        'paidDays' => integer($input['paidDays'] ?? null, 1, 365),
        'paidMaxAttempts' => integer($input['paidMaxAttempts'] ?? null, 1, 100), 'active' => (bool)($input['active'] ?? false)];
}
function valid_image(mixed $v): ?string {
    if (!$v) return null;
    $v = required($v, 100);
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $v) || !is_file(data_path('uploads/'.$v))) fail('Bilden finns inte.');
    return $v;
}
