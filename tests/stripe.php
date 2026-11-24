<?php
// Isolated tests. No requests are sent to Stripe and no real keys are used.
declare(strict_types=1);
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/niag-stripe-'.bin2hex(random_bytes(6));
mkdir($tmp, 0700); putenv('NIAG_DATA_DIR='.$tmp);
require $root.'/lib/bootstrap.php';
require $root.'/lib/stripe.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function rejects(callable $fn, int $status, string $message): void {
    try { $fn(); } catch (AppError $e) { check($e->getCode() === $status, $message); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
function signed(array $event, string $secret = 'whsec_testing', ?int $at = null): array {
    $raw = json_encode($event); $at ??= time();
    return [$raw, 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$raw, $secret)];
}
try {
    storage_write('schema.json',['schemaVersion'=>2]);
    storage_write('admins.json',[]);
    $settings = ['retentionDays'=>30];
    $settings['paymentMode']='stripe_sandbox'; $settings['stripeBaseUrl']='https://example.test/system';
    $settings['stripeSandbox']=['secretKey'=>'sk_test_testing','webhookSecret'=>'whsec_testing','publishableKey'=>'pk_test_testing'];
    $settings['stripeLive']=['secretKey'=>'sk_live_testing','webhookSecret'=>'whsec_live','publishableKey'=>'pk_live_testing'];
    storage_write('settings.json',$settings);
    $test=['id'=>'atex-verifiering','bankId'=>'atex','title'=>['sv'=>'Test','en'=>'Test'],'active'=>true,'questionCount'=>1,'paidDays'=>1,'paidSeconds'=>90];
    $test['priceEur']=12.34; $test['paidMaxAttempts']=2;
    storage_write('tests/atex-verifiering/test.json',$test);
    storage_write('banks/atex.json',['id'=>'atex','questions'=>[['id'=>'q1']]]);
    $_SESSION=[]; $_SERVER['REMOTE_ADDR']='test';
    $calls=[]; $sessions=[];
    $request=function($method,$path,$params,$secret,$key=null) use (&$calls,&$sessions) {
        $calls[]=[$method,$path,$params,$secret,$key];
        if ($method==='GET') return $sessions[basename($path)];
        $id='cs_test_'.count($calls);
        return $sessions[$id]=['id'=>$id,'object'=>'checkout.session','mode'=>'payment','status'=>'open','payment_status'=>'unpaid',
            'livemode'=>false,'currency'=>'eur','amount_total'=>$params['line_items'][0]['price_data']['unit_amount'],
            'metadata'=>$params['metadata'],'client_reference_id'=>$params['client_reference_id'],'url'=>'https://checkout.stripe.com/c/pay/'.$id];
    };
    $input=['testId'=>'atex-verifiering','email'=>'buyer@example.test','amount'=>1];
    $created=stripe_create($input,$request); $id=$created['orderId'];
    check($calls[0][2]['line_items'][0]['price_data']['unit_amount']===1234,'amount comes from server, not client');
    check($calls[0][2]['success_url']==='https://example.test/system/index.php?test=atex-verifiering&payment='.$id,'return URL uses configured origin');
    check($calls[0][3]==='sk_test_testing' && $calls[0][4]==='niag-'.$id,'test key and persistent idempotency key');
    check(stripe_create($input,$request)===$created && count($calls)===1,'duplicate checkout reuses session');
    check(stripe_status($id,$request)===['paid'=>false] && !storage_files('tests/*/access/*.json'),'unpaid session grants no access');
    $owner=$_SESSION; $_SESSION=[];
    rejects(fn()=>stripe_status($id,$request),403,'another browser cannot claim the order'); $_SESSION=$owner;
    $order=storage_read(stripe_order_path($id));$session=$sessions[$order['sessionId']];
    $session['status']='complete';$session['payment_status']='paid';
    foreach (['amount_total'=>1,'currency'=>'usd','livemode'=>true,'client_reference_id'=>'wrong','id'=>'cs_test_wrong'] as $field=>$value) {
        $bad=$session;$bad[$field]=$value;
        rejects(function()use($order,$bad){$o=$order;stripe_fulfill($o,$bad);},409,'reject mismatched '.$field);
    }
    $event=['type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>$session]];
    [$raw,$signature]=signed($event);
    rejects(fn()=>stripe_webhook($raw,$signature.'broken'),400,'reject invalid signature');
    [$oldRaw,$oldSig]=signed($event,'whsec_testing',time()-301);
    rejects(fn()=>stripe_webhook($oldRaw,$oldSig),400,'reject expired signature');
    [$wrongRaw,$wrongSig]=signed($event,'whsec_live');
    rejects(fn()=>stripe_webhook($wrongRaw,$wrongSig),400,'reject wrong environment signature');
    // Webhooks require neither a browser cookie nor the currently active payment mode.
    $settings['paymentMode']='fake';storage_write('settings.json',$settings);$_SESSION=[];
    stripe_webhook($raw,$signature);$after=storage_read(stripe_order_path($id));
    $grantPath=storage_grant_path($after['testId'],$after['grantId']);$grant=storage_read($grantPath);
    check($grant['max']===2 && $grant['used']===0 && $grant['simulated']===true,'signed webhook creates the purchased access once');
    $grant['used']=1;storage_write($grantPath,$grant);stripe_webhook($raw,$signature);
    check(storage_read($grantPath)===$grant,'duplicate webhook does not reset attempts or extend expiry');
    if (function_exists('pcntl_fork')) {
        $children=[];
        for($i=0;$i<6;$i++) { $pid=pcntl_fork(); if($pid===0){stripe_webhook($raw,$signature);exit(0);} $children[]=$pid; }
        foreach($children as $pid){pcntl_waitpid($pid,$exit);check(pcntl_wexitstatus($exit)===0,'parallel webhook worker succeeds');}
        check(storage_read($grantPath)===$grant && count(storage_files('tests/*/access/*.json'))===1,'parallel deliveries preserve one grant and its usage');
    }
    $_SESSION=$owner;$status=stripe_status($id,$request);
    check($status['paid'] && $_SESSION['grants']['atex-verifiering']===$grant['id'],'owner recovers webhook-confirmed access');
    // Recovery after a process stops between writing the grant and writing the order.
    $after['paidAt']=null;storage_write(stripe_order_path($id),$after);stripe_webhook($raw,$signature);
    check(storage_read($grantPath)===$grant && storage_read(stripe_order_path($id))['paidAt']!==null,'interrupted fulfillment recovers without new access');
    unlink(data_path($grantPath));stripe_webhook($raw,$signature);
    check(!is_file(data_path($grantPath)),'replay does not recreate purged access');
    $settings['paymentMode']='stripe_sandbox';storage_write('settings.json',$settings);
    $created2=stripe_create($input,$request);$order2=storage_read(stripe_order_path($created2['orderId']));
    check($created2['orderId']!==$id,'new purchase after expired/purged access');
    $sessions[$order2['sessionId']]['status']='complete';$sessions[$order2['sessionId']]['payment_status']='paid';
    check(stripe_status($created2['orderId'],$request)['paid'],'server-side reconciliation works before webhook delivery');
    $input['email']='retry@example.test';$failedKey=null;
    $failRequest=function($m,$p,$params,$secret,$key)use(&$failedKey){$failedKey=$key;fail('Timeout',502);};
    rejects(fn()=>stripe_create($input,$failRequest),502,'network failure preserves pending order');
    $retry=stripe_create($input,$request);check(end($calls)[4]===$failedKey,'network retry retains idempotency key');
    $test['priceEur']=0;storage_write('tests/atex-verifiering/test.json',$test);
    rejects(fn()=>stripe_create(['testId'=>$test['id'],'email'=>'zero@example.test'],$request),400,'reject zero-priced Stripe checkout');
    check(stripe_ready($settings),'complete sandbox configuration is ready');
    $settings['stripeSandbox']['secretKey']='sk_live_wrong';check(!stripe_ready($settings),'live key cannot activate sandbox');
    rejects(fn()=>stripe_base_url('http://example.test/system'),400,'return origin requires HTTPS');
    echo "Stripe checks passed. No real payments or email sent.\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file) { if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname()); } rmdir($tmp);
}
