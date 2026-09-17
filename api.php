<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/pdf.php';
require __DIR__.'/lib/exam.php';

try {
    start_session();
    $action = $_GET['action'] ?? 'bootstrap';
    $method = $_SERVER['REQUEST_METHOD'];
    $reads = ['bootstrap','admin','attempt','diploma','bankPdf','image'];
    if (!in_array($action, $reads, true) && $method !== 'POST') fail('POST krävs.', 405);
    $input = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Sessionen har ändrats. Ladda om sidan.', 403);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 6*1024*1024) fail('Filen eller anropet är för stort.', 413);
        if ($action !== 'upload') {
            try { $input = json_decode(file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { fail('Ogiltigt JSON-anrop.'); }
            if (!is_array($input)) fail('Ogiltigt anrop.');
        }
    }
    $result = storage_request($action, $input, function (array &$db) use ($action, $input) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($action === 'bootstrap') {
            $tests = [];
            foreach ($db['tests'] as $t) if ($t['active']) $tests[] = public_test($t, $db);
            return ['csrf' => $_SESSION['csrf'], 'admin' => admin_email($db), 'tests' => $tests,
                'mailConfigured' => mail_configured($db['settings']),
                'setupAvailable' => !empty($db['settings']['setupCodeHash']),
                'retentionDays' => $db['settings']['retentionDays']];
        }
        if ($action === 'setupLogin') {
            rate_limit($db, 'setup-'.$ip, 5, 900);
            $hash = $db['settings']['setupCodeHash'] ?? '';
            $code = required($input['code'] ?? null, 128);
            if (!$hash || !hash_equals($hash, hash('sha256', $code))) fail('Installationskoden är felaktig eller redan använd.', 401);
            if (empty($db['admins'])) fail('Administratör saknas.', 503);
            session_regenerate_id(true);
            $token = uid(); $_SESSION['adminToken'] = $token;
            $db['adminSessions'][$token] = ['email'=>$db['admins'][0], 'expires'=>time()+2592000];
            $db['settings']['setupCodeHash'] = '';
            return ['ok'=>true];
        }
        if ($action === 'loginRequest') {
            $email = email_value($input['email'] ?? null);
            rate_limit($db, 'login-ip-'.$ip, 10, 900); rate_limit($db, 'login-email-'.$email, 3, 900);
            if (in_array($email, $db['admins'], true)) {
                $code = (string)random_int(100000, 999999);
                $db['authCodes'][$email] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time()+600, 'tries' => 0];
                send_email($db, $email, 'NIAG – din inloggningskod', "Din kod är $code. Den gäller i 10 minuter och kan användas en gång.");
            }
            return ['message' => 'Om adressen är behörig skickas en kod.'];
        }
        if ($action === 'loginVerify') {
            $email = email_value($input['email'] ?? null); $code = required($input['code'] ?? null, 6);
            rate_limit($db, 'verify-'.$ip, 20, 900);
            $entry = $db['authCodes'][$email] ?? null;
            if (!$entry || $entry['expires'] <= time() || $entry['tries'] >= 5 || !in_array($email, $db['admins'], true)) fail('Koden är felaktig eller har gått ut.', 401);
            $db['authCodes'][$email]['tries']++;
            if (!password_verify($code, $entry['hash'])) fail('Koden är felaktig eller har gått ut.', 401);
            unset($db['authCodes'][$email]); session_regenerate_id(true);
            $token = uid(); $_SESSION['adminToken'] = $token;
            $db['adminSessions'][$token] = ['email' => $email, 'expires' => time()+2592000];
            return ['ok' => true];
        }
        if ($action === 'logout') {
            unset($db['adminSessions'][$_SESSION['adminToken'] ?? '']);
            unset($_SESSION['adminToken']); session_regenerate_id(true); return ['ok' => true];
        }
        if ($action === 'access') {
            $t = $db['tests'][index_of($db['tests'], required($input['testId'] ?? null, 100))];
            if (!$t['active']) fail('Testet är inte aktivt.');
            rate_limit($db, 'access-'.$ip, 20, 900);
            $email = email_value($input['email'] ?? null);
            if (($input['method'] ?? '') === 'code') {
                $hash = hash('sha256', strtoupper(required($input['code'] ?? null, 100)));
                foreach ($db['invites'] as $invite) {
                    if ($invite['testId'] === $t['id'] && $invite['email'] === $email && hash_equals($invite['hash'], $hash) && $invite['expires'] > time()) {
                        $g = $db['grants'][index_of($db['grants'], $invite['grantId'])];
                        if ($g['used'] >= $g['max']) fail('Alla testförsök är använda.');
                        $_SESSION['grants'][$t['id']] = $g['id']; return ['ok' => true, 'expires' => $g['expires']];
                    }
                }
                fail('Koden är felaktig, förbrukad eller har gått ut. Kontrollera även e-postadressen.', 403);
            }
            if (($input['method'] ?? '') !== 'fake' || $db['settings']['paymentMode'] !== 'fake') fail('Stripe-betalning är inte aktiverad i denna version.', 503);
            // A retry must not create an extra payment/access grant.
            $old = $_SESSION['grants'][$t['id']] ?? null;
            foreach ($db['grants'] as $g) if ($g['id'] === $old && $g['email'] === $email && $g['expires'] > time() && $g['used'] < $g['max']) return ['ok'=>true, 'expires'=>$g['expires']];
            $id = uid();
            $db['grants'][] = ['id'=>$id,'testId'=>$t['id'],'email'=>$email,'kind'=>'paid','simulated'=>true,
                'expires'=>time()+$t['paidDays']*86400,'max'=>$t['paidMaxAttempts'],'used'=>0];
            $_SESSION['grants'][$t['id']] = $id; return ['ok'=>true,'expires'=>time()+$t['paidDays']*86400];
        }
        if ($action === 'start') {
            $testId = required($input['testId'] ?? null, 100);
            $t = $db['tests'][index_of($db['tests'], $testId)];
            $gIndex = index_of($db['grants'], $_SESSION['grants'][$testId] ?? '');
            $g = &$db['grants'][$gIndex];
            // Recover a completed result-file write if the preceding request stopped before updating access usage.
            $g['used'] = max($g['used'], count($db['attempts']));
            if (!$t['active'] || $g['expires'] <= time()) fail('Åtkomsten har gått ut.', 403);
            foreach ($db['attempts'] as $a) if ($a['grantId'] === $g['id'] && $a['finishedAt'] === null && $a['startedAt'] >= time()-$db['settings']['retentionDays']*86400) {
                $_SESSION['attempts'][] = $a['id']; return attempt_view($a);
            }
            if ($g['used'] >= $g['max']) fail('Alla testförsök är använda.', 403);
            $p = $input['person'] ?? [];
            $person = ['name'=>required($p['name'] ?? null, 150), 'email'=>email_value($p['email'] ?? null),
                'address'=>required($p['address'] ?? null, 300), 'address2'=>text_value($p['address2'] ?? '', 300),
                'postalCode'=>text_value($p['postalCode'] ?? '', 30), 'city'=>required($p['city'] ?? null, 150),
                'region'=>text_value($p['region'] ?? '', 150), 'country'=>required($p['country'] ?? null, 100),
                'phone'=>required($p['phone'] ?? null, 60)];
            if ($person['email'] !== $g['email']) fail('E-postadressen måste vara densamma som vid åtkomst.');
            $lang = $input['language'] ?? 'sv'; if (!in_array($lang, ['sv','en'], true)) fail('Ogiltigt språk.');
            $questions = $db['banks'][index_of($db['banks'], $t['bankId'])]['questions'];
            if (count($questions) < $t['questionCount']) fail('Testets frågebank har för få frågor.');
            // Fisher-Yates with cryptographic randomness, without replacement.
            for ($i=count($questions)-1; $i>0; $i--) { $j=random_int(0,$i); [$questions[$i],$questions[$j]]=[$questions[$j],$questions[$i]]; }
            $a = ['id'=>uid(),'grantId'=>$g['id'],'test'=>$t,'person'=>$person,'language'=>$lang,
                'kind'=>$g['kind'],'simulated'=>$g['simulated'] ?? false,'questions'=>array_slice($questions,0,$t['questionCount']),
                'answers'=>[],'seconds'=>$g['kind']==='code' ? $t['codeSeconds'] : $t['paidSeconds'],
                'startedAt'=>time(),'questionStartedAt'=>time(),'finishedAt'=>null];
            $g['used']++; $db['attempts'][]=$a; $_SESSION['attempts'][]=$a['id']; return attempt_view($a);
        }
        if ($action === 'attempt' || $action === 'answer') {
            $id = required($input['id'] ?? $_GET['id'] ?? null, 100);
            $i = my_attempt_index($db, $id); $a = &$db['attempts'][$i];
            $g = $db['grants'][index_of($db['grants'], $a['grantId'])];
            if ($a['finishedAt'] === null && $g['expires'] <= time()) fail('Åtkomsten har gått ut.', 403);
            $previous = count($a['answers']); expire_question($db, $a);
            $expired = count($a['answers']) !== $previous;
            if ($action === 'answer' && !$expired && $a['finishedAt'] === null) {
                $index = integer($input['index'] ?? null, 0, count($a['questions'])-1);
                // Repeated submissions never answer the next question.
                if ($index === count($a['answers'])) {
                    $selected = $input['selected'] ?? null;
                    if (!in_array($selected, ['A','B','C',null], true)) fail('Ogiltigt svar.');
                    $q = $a['questions'][$index];
                    $a['answers'][] = ['selected'=>$selected,'correct'=>$selected===$q['correct'],'timedOut'=>false,'answeredAt'=>time()];
                    $a['questionStartedAt']=time();
                    if (count($a['answers']) === count($a['questions'])) finish_attempt($db, $a);
                }
            }
            return attempt_view($a)+['timedOut'=>$expired];
        }
        if ($action === 'diploma') {
            $a = $db['attempts'][my_attempt_index($db, required($_GET['id'] ?? null, 100))];
            if (!$a['finishedAt'] || !$a['passed']) fail('Diplom finns bara för godkända test.', 403);
            return ['binary'=>diploma_pdf($a),'mime'=>'application/pdf','filename'=>'niag-diplom.pdf'];
        }
        if ($action === 'image') {
            $name = valid_image($_GET['name'] ?? null); if (!$name) fail('Bild saknas.',404);
            $allowed = (bool)admin_email($db);
            foreach ($db['attempts'] as $a) if (in_array($a['id'], $_SESSION['attempts'] ?? [], true)) foreach ($a['questions'] as $q) if ($q['image'] === $name) $allowed = true;
            if (!$allowed) fail('Bilden är inte tillgänglig.',403);
            return ['binary'=>file_get_contents(data_path('uploads/'.$name)), 'mime'=>mime_content_type(data_path('uploads/'.$name))];
        }
        $admin = require_admin($db);
        if ($action === 'admin') {
            $settings=mail_settings($db['settings']);
            $settings['smtpPasswordSet'] = $settings['smtpPassword'] !== '';
            unset($settings['smtpPassword']);
            unset($settings['setupCodeHash']);
            $settings += ['mailTransport'=>'mail', 'secureCookie'=>true];
            foreach (['stripeSandbox','stripeLive'] as $key) foreach (['secretKey','webhookSecret'] as $field) {
                $settings[$key][$field.'Set'] = $settings[$key][$field] !== ''; unset($settings[$key][$field]);
            }
            $attempts=[];
            foreach (array_reverse($db['attempts']) as $a) if ($a['finishedAt']) $attempts[] = array_intersect_key($a,array_flip(['id','test','person','kind','simulated','startedAt','finishedAt','score','passed','diplomaMailSent']));
            return ['admin'=>$admin,'banks'=>$db['banks'],'tests'=>array_map(fn($t)=>$t + test_text_defaults(), $db['tests']),'testTextDefaults'=>test_text_defaults(),'admins'=>$db['admins'],'settings'=>$settings,
                'preference'=>$db['preferences'][$admin] ?? [],'attempts'=>$attempts,'mailLog'=>array_reverse($db['mailLog']),
                'mailTransport'=>config()['mail_transport']];
        }
        if ($action === 'preference') {
            $bankId=required($input['bankId'] ?? null,100); index_of($db['banks'],$bankId);
            $db['preferences'][$admin]=['bankId'=>$bankId]; return ['ok'=>true];
        }
        if ($action === 'bankSave') {
            $name=required($input['name'] ?? null,200); $id=uid(); $questions=[];
            if (!empty($input['copyId'])) { $questions=$db['banks'][index_of($db['banks'],$input['copyId'])]['questions']; foreach ($questions as &$q) $q['id']=uid(); unset($q); }
            $db['banks'][]=['id'=>$id,'name'=>$name,'questions'=>$questions]; return ['id'=>$id];
        }
        if ($action === 'questionSave' || $action === 'questionDelete') {
            $bi=index_of($db['banks'],required($input['bankId'] ?? null,100)); $questions=&$db['banks'][$bi]['questions'];
            $id=$input['id'] ?? ''; $qi=$id ? index_of($questions,$id) : null;
            if ($action === 'questionDelete') {
                if ($qi === null) fail('Fråga saknas.');
                foreach ($db['tests'] as $t) if ($t['bankId']===$input['bankId'] && $t['questionCount'] >= count($questions)) fail('Minska först antalet frågor i de tester som använder databasen.');
                array_splice($questions,$qi,1); return ['ok'=>true];
            }
            $options=[];
            foreach (['sv','en'] as $lang) foreach (['A','B','C'] as $letter) $options[$lang][$letter]=required($input['options'][$lang][$letter] ?? null,2000);
            $correct=$input['correct'] ?? ''; if (!in_array($correct,['A','B','C'],true)) fail('Välj rätt svar.');
            $q=['id'=>$id ?: uid(),'text'=>translated($input['text'] ?? null),'options'=>$options,'correct'=>$correct,'image'=>valid_image($input['image'] ?? null)];
            if ($qi!==null) { if (isset($questions[$qi]['source'])) $q['source']=$questions[$qi]['source']; $questions[$qi]=$q; } else $questions[]=$q;
            return ['ok'=>true];
        }
        if ($action === 'upload') {
            $f=$_FILES['image'] ?? null;
            if (!$f || $f['error']!==UPLOAD_ERR_OK || $f['size']>5*1024*1024) fail('Välj en JPG- eller PNG-bild, högst 5 MB.');
            $info=@getimagesize($f['tmp_name']);
            if (!$info || !in_array($info['mime'],['image/jpeg','image/png'],true) || $info[0]*$info[1]>20000000) fail('Bilden måste vara JPG eller PNG och högst 20 megapixlar.');
            if (!extension_loaded('gd')) fail('PHP-tillägget GD krävs för bilder.',503);
            $image=$info['mime']==='image/png' ? imagecreatefrompng($f['tmp_name']) : imagecreatefromjpeg($f['tmp_name']);
            if (!$image) fail('Bilden kunde inte läsas.');
            $width=min(1600,imagesx($image)); $height=(int)round(imagesy($image)*$width/imagesx($image));
            $clean=imagecreatetruecolor($width,$height); $white=imagecolorallocate($clean,255,255,255); imagefill($clean,0,0,$white);
            imagecopyresampled($clean,$image,0,0,0,0,$width,$height,imagesx($image),imagesy($image));
            if (!is_dir(data_path('uploads'))) mkdir(data_path('uploads'),0700,true);
            $name=uid().'.jpg'; if (!imagejpeg($clean,data_path('uploads/'.$name),90)) fail('Bilden kunde inte sparas.',503);
            imagedestroy($image); imagedestroy($clean); return ['image'=>$name];
        }
        if ($action === 'testSave') {
            $t=validate_test($input,$db); $id=$input['id'] ?? '';
            if ($id) $db['tests'][index_of($db['tests'],$id)]=['id'=>$id]+$t;
            else { $id=uid(); $db['tests'][]=['id'=>$id]+$t; }
            return ['id'=>$id];
        }
        if ($action === 'testDelete') {
            $id=required($input['id'] ?? null,100); $i=index_of($db['tests'],$id);
            if (($input['confirmName'] ?? '')!==$db['tests'][$i]['name'] || ($input['confirmDelete'] ?? false)!==true) fail('Båda bekräftelserna krävs.');
            array_splice($db['tests'],$i,1); return ['ok'=>true];
        }
        if ($action === 'invite') {
            $testId=required($input['testId'] ?? null,100); $t=$db['tests'][index_of($db['tests'],$testId)];
            $email=email_value($input['email'] ?? null); $days=integer($input['days'] ?? 5,1,365); $max=integer($input['max'] ?? 10,1,100);
            $language=$input['language'] ?? 'sv';
            if (!in_array($language,['sv','en'],true)) fail('Ogiltigt språk.');
            $link=test_link($testId);
            $code=strtoupper(bin2hex(random_bytes(6))); $id=uid(); $expires=time()+$days*86400;
            $db['grants'][]=['id'=>$id,'testId'=>$testId,'email'=>$email,'kind'=>'code','expires'=>$expires,'max'=>$max,'used'=>0];
            $db['invites'][]=['id'=>uid(),'grantId'=>$id,'testId'=>$testId,'email'=>$email,'hash'=>hash('sha256',$code),'expires'=>$expires];
            $t += test_text_defaults();
            $values=['{test}'=>$t['title'][$language],'{code}'=>$code,'{email}'=>$email,
                '{expires}'=>date('Y-m-d H:i',$expires),'{attempts}'=>(string)$max,'{link}'=>$link];
            $ok=send_email($db,$email,strtr($t['inviteSubject'][$language],$values),strtr($t['inviteBody'][$language],$values));
            return ['ok'=>true,'mailSent'=>$ok,'code'=>$code,'expires'=>$expires];
        }
        if ($action === 'result') { $a=$db['attempts'][index_of($db['attempts'],required($input['id'] ?? null,100))]; return $a; }
        if ($action === 'resend') {
            $i=index_of($db['attempts'],required($input['id'] ?? null,100)); $a=&$db['attempts'][$i];
            if (!$a['finishedAt'] || !$a['passed']) fail('Testet är inte godkänt.');
            $a['diplomaMailSent']=send_email($db,$a['person']['email'],'NIAG – diplom','Här kommer ditt diplom igen.',diploma_pdf($a));
            return ['mailSent'=>$a['diplomaMailSent']];
        }
        if ($action === 'bankPdf') { $b=$db['banks'][index_of($db['banks'],required($_GET['id'] ?? null,100))]; return ['binary'=>bank_pdf($b),'mime'=>'application/pdf','filename'=>'niag-fragor.pdf']; }
        if ($action === 'adminSave') {
            $email=email_value($input['email'] ?? null); $old=$input['oldEmail'] ?? '';
            if ($old && $old!==$email) { $i=array_search($old,$db['admins'],true); if ($i===false) fail('Administratören saknas.'); array_splice($db['admins'],$i,1); }
            if (!in_array($email,$db['admins'],true)) $db['admins'][]=$email;
            return ['ok'=>true];
        }
        if ($action === 'adminDelete') {
            $email=email_value($input['email'] ?? null);
            if (count($db['admins'])<=1) fail('Minst en administratör måste finnas kvar.');
            $db['admins']=array_values(array_filter($db['admins'],fn($v)=>$v!==$email)); return ['ok'=>true];
        }
        if ($action === 'mailTest') {
            rate_limit($db, 'mail-test-'.$admin, 5, 900);
            $ok=send_email($db, $admin, 'NIAG – provmejl', 'Detta är ett provmejl från NIAG Tests. Dina sparade e-postinställningar används.');
            if (!$ok) fail(end($db['mailLog'])['error'] ?? 'Kunde inte skicka provmejlet.', 502);
            return ['ok'=>true, 'local'=>config()['mail_transport']==='file', 'to'=>$admin];
        }
        if ($action === 'settings') {
            $mode=$input['paymentMode'] ?? ''; if (!in_array($mode,['fake','stripe_sandbox','stripe_live'],true)) fail('Ogiltigt betalsystem.');
            if ($mode!==$db['settings']['paymentMode'] && (($input['confirmMode'] ?? '')!==$mode || ($input['confirmChange'] ?? false)!==true)) fail('Bekräfta bytet två gånger.');
            $from=text_value($input['mailFrom'] ?? '',254); if ($from) $from=email_value($from);
            $settings=$db['settings'];
            $transport=$input['mailTransport'] ?? ($settings['mailTransport'] ?? 'mail');
            if (!in_array($transport, ['smtp','mail','file'], true)) fail('Ogiltig e-posttransport.');
            $secure=$input['secureCookie'] ?? ($settings['secureCookie'] ?? true);
            if (!is_bool($secure)) fail('Ogiltig inställning för sessionskakor.');
            if ($transport !== 'file' && !$from) fail('Ange en avsändaradress för e-post.');
            $settings['mailTransport']=$transport; $settings['secureCookie']=$secure;
            $settings['mailFrom']=$from; $settings['paymentMode']=$mode;
            $settings['retentionDays']=integer($input['retentionDays'] ?? 30,1,3650);
            foreach (['stripeSandbox','stripeLive'] as $key) foreach (['publishableKey','secretKey','webhookSecret'] as $field) {
                $value=text_value($input[$key][$field] ?? '',500);
                if ($field==='publishableKey' || $value!=='') $settings[$key][$field]=$value;
                if (($input[$key]['clearSecrets'] ?? false) && $field!=='publishableKey') $settings[$key][$field]='';
            }
            $db['settings']=validate_mail_settings($input, $settings);
            return ['ok'=>true];
        }
        fail('Okänd funktion.',404);
    });
    if (isset($result['binary'])) {
        header('Content-Type: '.$result['mime']);
        if (isset($result['filename'])) header('Content-Disposition: attachment; filename="'.$result['filename'].'"');
        echo $result['binary'];
    } else { header('Content-Type: application/json; charset=utf-8'); echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
} catch (Throwable $e) {
    $status=$e instanceof AppError ? $e->getCode() : 500;
    http_response_code($status >= 400 && $status <= 599 ? $status : 500);
    header('Content-Type: application/json; charset=utf-8');
    if (!($e instanceof AppError)) error_log((string)$e);
    echo json_encode(['error'=>$e instanceof AppError ? $e->getMessage() : 'Ett serverfel uppstod. Kontakta administratören.'],JSON_UNESCAPED_UNICODE);
}
