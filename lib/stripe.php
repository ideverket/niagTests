<?php
declare(strict_types=1);

function stripe_keys(array $settings, string $mode): array {
    if (!in_array($mode, ['stripe_sandbox', 'stripe_live'], true)) fail('Stripe är inte aktiverat.', 503);
    return $settings[$mode === 'stripe_live' ? 'stripeLive' : 'stripeSandbox'] ?? [];
}
function stripe_base_url(string $url): string {
    $url = rtrim(trim($url), '/');
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[\s<>"\\\\]/', $url)) fail('Ange systemets fullständiga HTTPS-adress, utan frågetecken eller fragment.');
    return $url;
}
function stripe_ready(array $settings): bool {
    try {
        $mode = $settings['paymentMode'] ?? '';
        $keys = stripe_keys($settings, $mode);
        $prefix = $mode === 'stripe_live' ? 'live' : 'test';
        stripe_base_url($settings['stripeBaseUrl'] ?? '');
        return (bool)preg_match('/^(sk|rk)_'.$prefix.'_[A-Za-z0-9]+$/D', $keys['secretKey'] ?? '') && str_starts_with($keys['webhookSecret'] ?? '', 'whsec_');
    } catch (AppError $e) { return false; }
}
function stripe_request(string $method, string $path, array $params, string $secret, ?string $idempotency = null): array {
    if (!function_exists('curl_init')) fail('Servern behöver PHP-tillägget cURL för Stripe.', 503);
    $curl = curl_init('https://api.stripe.com/v1/'.$path);
    $headers = ['Authorization: Bearer '.$secret, 'Stripe-Version: 2025-02-24.acacia'];
    if ($idempotency !== null) $headers[] = 'Idempotency-Key: '.$idempotency;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($params)]);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        // Never log the request, key, response body or customer details.
        error_log('NIAG Stripe request failed; HTTP '.(int)$status);
        fail('Stripe kunde inte nås eller avvisade anropet. Kontrollera inställningarna eller försök igen.', 502);
    }
    return $data;
}
function stripe_order_path(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) fail('Ogiltig betalning.', 400);
    return 'payments/'.$id.'.json';
}
function stripe_create(array $input, ?callable $request = null): array {
    $request ??= 'stripe_request';
    $db = storage_context();
    if (!stripe_ready($db['settings'])) fail('Stripe-inställningarna är inte klara.', 503);
    $mode = $db['settings']['paymentMode'];
    $testId = storage_id(required($input['testId'] ?? null, 100));
    $email = email_value($input['email'] ?? null);
    $lang = ($input['language'] ?? '') === 'en' ? 'en' : 'sv';
    rate_limit($db, 'checkout:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 3600);
    $t = storage_read('tests/'.$testId.'/test.json');
    $bank = storage_read('banks/'.storage_id($t['bankId']).'.json');
    if (!$t['active'] || count($bank['questions']) < $t['questionCount']) fail('Testet är inte tillgängligt.', 409);
    $amount = (int)round($t['priceEur'] * 100);
    if ($amount < 50) fail('Priset måste vara minst 0,50 EUR för kortbetalning.', 400);
    $slot = hash('sha256', $mode.'|'.$testId.'|'.$email);
    $id = $_SESSION['stripeOrders'][$slot] ?? uid();
    $old = storage_read(stripe_order_path($id), []);
    // Never reuse an identifier from a purged order: old Stripe events can be resent.
    if (!$old) $id = uid();
    if (!empty($old['paidAt'])) {
        $grant = storage_read(storage_grant_path($old['testId'], $old['grantId']), []);
        if (!$grant || $grant['expires'] <= time() || $grant['used'] >= $grant['max']) { $id = uid(); $old = []; }
    }
    if ($old && ($old['createdAt'] < time()-1800) && empty($old['paidAt'])) $id = uid();
    $_SESSION['stripeOrders'][$slot] = $id;
    return storage_lock('payment:'.$id, function () use ($id,$testId,$email,$mode,$lang,$amount,$t,$db,$request) {
        $path = stripe_order_path($id); $order = storage_read($path, []);
        if (!$order) {
            $base = stripe_base_url($db['settings']['stripeBaseUrl']);
            $order = ['id'=>$id,'testId'=>$testId,'email'=>$email,'mode'=>$mode,'amount'=>$amount,'currency'=>'eur',
                'days'=>$t['paidDays'],'max'=>$t['paidMaxAttempts'],'seconds'=>$t['paidSeconds'],'createdAt'=>time(),
                'grantId'=>uid(),'paidAt'=>null,'sessionId'=>null];
            $return = $base.'/index.php?test='.rawurlencode($testId);
            $order['params'] = ['mode'=>'payment','payment_method_types'=>['card'],'customer_email'=>$email,
                'client_reference_id'=>$id,'metadata'=>['niag_order'=>$id], 'locale'=>$lang,
                'success_url'=>$return.'&payment='.$id, 'cancel_url'=>$return.'&payment='.$id.'&cancelled=1',
                'expires_at'=>$order['createdAt']+1800,
                'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>'eur','unit_amount'=>$amount,'product_data'=>['name'=>$t['title'][$lang]]]]]];
            storage_write($path, $order); // Persist before network I/O; retries use the same payload and key.
        }
        if ($order['paidAt']) return ['orderId'=>$id,'paid'=>true];
        if (!empty($order['url'])) return ['orderId'=>$id,'url'=>$order['url']];
        $keys = stripe_keys($db['settings'], $mode);
        $session = $request('POST', 'checkout/sessions', $order['params'], $keys['secretKey'], 'niag-'.$id);
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/D', $session['id'] ?? '') || !str_starts_with($session['url'] ?? '', 'https://checkout.stripe.com/')) fail('Stripe gav ett oväntat svar.', 502);
        $order['sessionId'] = $session['id']; $order['url'] = $session['url'];
        storage_write($path, $order);
        return ['orderId'=>$id,'url'=>$order['url']];
    });
}
// Caller holds the payment lock. Both webhook and browser reconciliation use this path.
function stripe_fulfill(array &$order, array $session): bool {
    if (($session['object'] ?? '') !== 'checkout.session' || ($session['mode'] ?? '') !== 'payment' ||
        ($session['client_reference_id'] ?? '') !== $order['id'] || ($session['metadata']['niag_order'] ?? '') !== $order['id'] ||
        ($session['livemode'] ?? null) !== ($order['mode'] === 'stripe_live') ||
        ($session['amount_total'] ?? null) !== $order['amount'] || ($session['currency'] ?? '') !== $order['currency'] ||
        (!empty($order['sessionId']) && $session['id'] !== $order['sessionId']) ||
        !preg_match('/^cs_[A-Za-z0-9_]+$/D', $session['id'] ?? '')) fail('Betalningen stämmer inte med beställningen.', 409);
    if (($session['payment_status'] ?? '') !== 'paid' || ($session['status'] ?? '') !== 'complete') return false;
    $order['sessionId'] = $session['id'];
    storage_lock('grant:'.$order['grantId'], function () use (&$order) {
        $path = storage_grant_path($order['testId'], $order['grantId']);
        $grant = storage_read($path, []);
        if (!$grant) {
            // A completed order must never recreate expired/purged access on a replay.
            if ($order['paidAt']) return;
            $grant = ['id'=>$order['grantId'],'testId'=>$order['testId'],'email'=>$order['email'],'kind'=>'paid',
                'simulated'=>$order['mode'] !== 'stripe_live','expires'=>time()+$order['days']*86400,
                'max'=>$order['max'],'used'=>0,'paidSeconds'=>$order['seconds'],'stripeOrderId'=>$order['id']];
            storage_write($path, $grant);
        }
        $order['paidAt'] ??= $grant['expires']-$order['days']*86400;
        $order['expires'] = $grant['expires'];
    });
    storage_write(stripe_order_path($order['id']), $order);
    return true;
}
function stripe_status(string $id, ?callable $request = null): array {
    $request ??= 'stripe_request';
    $path = stripe_order_path($id);
    if (!in_array($id, $_SESSION['stripeOrders'] ?? [], true)) fail('Betalningen tillhör en annan webbläsarsession. Kontakta NIAG om du har betalat.', 403);
    return storage_lock('payment:'.$id, function () use ($path,$request) {
        $order = storage_read($path); $settings = storage_read('settings.json');
        if (!$order['paidAt'] && !empty($order['sessionId'])) {
            $keys = stripe_keys($settings, $order['mode']);
            $session = $request('GET', 'checkout/sessions/'.$order['sessionId'], [], $keys['secretKey']);
            stripe_fulfill($order, $session);
        }
        if (!$order['paidAt']) return ['paid'=>false];
        $grant = storage_read(storage_grant_path($order['testId'], $order['grantId']), []);
        if (!$grant || $grant['expires'] <= time() || $grant['used'] >= $grant['max']) fail('Betalningen är registrerad men åtkomsten har gått ut eller försöken är använda. Kontakta NIAG.', 409);
        $_SESSION['grants'][$order['testId']] = $grant['id'];
        return ['paid'=>true,'testId'=>$order['testId'],'email'=>$order['email'],'expires'=>$grant['expires']];
    });
}
function stripe_event(string $body, string $signature, array $settings): array {
    if (strlen($body) > 1048576) fail('För stort anrop.', 413);
    $timestamp = null; $signatures = [];
    foreach (explode(',', $signature) as $part) {
        [$k,$v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't' && ctype_digit($v)) $timestamp = (int)$v;
        if ($k === 'v1') $signatures[] = $v;
    }
    if (!$timestamp || abs(time()-$timestamp) > 300) fail('Ogiltig Stripe-signatur.', 400);
    $mode = null;
    foreach (['stripe_sandbox','stripe_live'] as $candidate) {
        $secret = stripe_keys($settings, $candidate)['webhookSecret'] ?? '';
        if (!$secret) continue;
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($signatures as $sig) if (hash_equals($expected, $sig)) $mode = $candidate;
    }
    if (!$mode) fail('Ogiltig Stripe-signatur.', 400);
    $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (($event['livemode'] ?? null) !== ($mode === 'stripe_live')) fail('Fel Stripe-miljö.', 400);
    return [$event, $mode];
}
function stripe_webhook(string $body, string $signature): void {
    [$event,$mode] = stripe_event($body, $signature, storage_read('settings.json'));
    if (!in_array($event['type'] ?? '', ['checkout.session.completed','checkout.session.async_payment_succeeded'], true)) return;
    $session = $event['data']['object'] ?? [];
    $id = $session['metadata']['niag_order'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return; // Other applications may use the same Stripe account.
    storage_lock('payment:'.$id, function () use ($id,$mode,$session) {
        $order = storage_read(stripe_order_path($id), []);
        if (!$order) return;
        if ($order['mode'] !== $mode) fail('Fel Stripe-miljö.', 400);
        stripe_fulfill($order, $session);
    });
}
