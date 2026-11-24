<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/stripe.php';
try {
    headers_private();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST krävs.', 405);
    if (($_GET['action'] ?? '') === 'webhook') {
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        stripe_webhook($body, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        $result = ['received'=>true];
    } else {
        start_session();
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Sessionen har ändrats. Ladda om sidan.', 403);
        $body = file_get_contents('php://input', false, null, 0, 8193);
        if (strlen($body) > 8192) fail('För stort anrop.', 413);
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) fail('Ogiltigt anrop.');
        $result = match ($_GET['action'] ?? '') {
            'create' => stripe_create($input),
            'status' => stripe_status(required($input['orderId'] ?? null, 32)),
            default => throw new AppError('Okänd funktion.', 404),
        };
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status = $e instanceof AppError ? $e->getCode() : ($e instanceof JsonException ? 400 : 500);
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    header('Content-Type: application/json; charset=utf-8');
    if (!($e instanceof AppError) && !($e instanceof JsonException)) error_log('NIAG Stripe internal failure: '.get_class($e));
    echo json_encode(['error'=>$e instanceof AppError ? $e->getMessage() : 'Betalningen kunde inte behandlas. Försök igen eller kontakta NIAG.']);
}
