"""Exercise payment HTTP boundaries on disposable data; no Stripe traffic."""
import hashlib, hmac, http.cookiejar, json, os, pathlib, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[1]
def save(base, name, value):
    p = base / name; p.parent.mkdir(parents=True, exist_ok=True); p.write_text(json.dumps(value))
with tempfile.TemporaryDirectory(prefix='niag-stripe-http-') as directory:
    data = pathlib.Path(directory) / 'data'; data.mkdir()
    settings = dict(retentionDays=30, paymentMode='stripe_sandbox', secureCookie=False,
                    mailTransport='file', mailFrom='test@example.test', stripeBaseUrl='https://example.test',
                    stripeSandbox=dict(secretKey='sk_test_testing', webhookSecret='whsec_testing'), stripeLive={})
    test = dict(id='exam', bankId='bank', active=True, title=dict(sv='Test',en='Test'), description=dict(sv='Test',en='Test'),
                questionCount=1,passCount=1,priceEur=10,paidDays=1,paidMaxAttempts=2,codeSeconds=0,paidSeconds=99)
    question = dict(id='q',text=dict(sv='Fråga',en='Question'),options=dict(sv=dict(A='A',B='B',C='C'),en=dict(A='A',B='B',C='C')),correct='A',image=None)
    for name,value in [('settings.json',settings),('admins.json',[]),('schema.json',dict(schemaVersion=2)),('banks/bank.json',dict(id='bank',questions=[question])),('tests/exam/test.json',test)]: save(data,name,value)
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    env=dict(os.environ,NIAG_DATA_DIR=str(data),NIAG_LOCAL_HTTP='1')
    with open(pathlib.Path(directory)/'server.log','w') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),.1): break
                except OSError: time.sleep(.05)
            jar=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
            def call(path, body=None, headers=None, expected=200):
                req=urllib.request.Request(f'http://127.0.0.1:{port}/'+path,data=body,headers=headers or {})
                try: response=client.open(req)
                except urllib.error.HTTPError as e: response=e
                result=response.read(); assert response.status==expected,(path,response.status,result)
                return json.loads(result)
            boot=call('api.php?action=bootstrap'); csrf=boot['csrf']
            assert boot['tests'][0]['paymentReady'] and b'sk_test' not in json.dumps(boot).encode()
            call('payment.php?action=create',expected=405)
            call('payment.php?action=create',b'{}',expected=403)
            headers={'Content-Type':'application/json','X-CSRF-Token':csrf}
            call('payment.php?action=create',b'{',headers,400)
            call('payment.php?action=create',b' '*8193,headers,413)
            call('payment.php?action=webhook',b'{}',expected=400)
            # Seed a pending order as if creation had succeeded. Add ownership to this test session.
            order_id='a'*32; grant_id='b'*32
            order=dict(id=order_id,testId='exam',email='buyer@example.test',mode='stripe_sandbox',amount=1000,currency='eur',days=1,max=2,seconds=25,createdAt=int(time.time()),grantId=grant_id,paidAt=None,sessionId='cs_test_http')
            save(data,f'payments/{order_id}.json',order)
            session=dict(id='cs_test_http',object='checkout.session',mode='payment',status='complete',payment_status='paid',livemode=False,currency='eur',amount_total=1000,client_reference_id=order_id,metadata=dict(niag_order=order_id))
            event=dict(type='checkout.session.completed',livemode=False,data=dict(object=session))
            raw=json.dumps(event).encode(); now=str(int(time.time())); signature='t='+now+',v1='+hmac.new(b'whsec_testing',now.encode()+b'.'+raw,hashlib.sha256).hexdigest()
            call('payment.php?action=webhook',raw,{'Stripe-Signature':signature})
            call('payment.php?action=webhook',raw,{'Stripe-Signature':signature})
            assert len(list(data.glob('tests/exam/access/*.json')))==1
            call('payment.php?action=status',json.dumps(dict(orderId=order_id)).encode(),headers,403)
            # Configure ownership in PHP's own session format, not via a production endpoint.
            session_id=next(c.value for c in jar if c.name=='niag_session')
            setup="session_save_path(getenv('NIAG_DATA_DIR').'/sessions');session_id($argv[1]);session_start();$_SESSION['stripeOrders']=['test'=>$argv[2]];session_write_close();"
            subprocess.run(['php','-r',setup,session_id,order_id],env=env,check=True)
            status=call('payment.php?action=status',json.dumps(dict(orderId=order_id)).encode(),headers)
            assert status['paid'] and status['email']=='buyer@example.test'
            person=dict(name='Test Person',email='buyer@example.test',address='Test 1',city='Test',country='Sweden',phone='+460000000')
            attempt=call('api.php?action=start',json.dumps(dict(testId='exam',language='sv',person=person)).encode(),headers)
            assert attempt['secondsLeft']==25
            grant=json.loads((data/f'tests/exam/access/{grant_id}.json').read_text());assert grant['used']==1
            call('payment.php?action=webhook',raw,{'Stripe-Signature':signature})
            assert json.loads((data/f'tests/exam/access/{grant_id}.json').read_text())['used']==1
            print('PASS HTTP: CSRF, methods, input limits, signed cookie-free webhook, duplicate delivery, ownership, paid start and purchased time limit')
        finally: server.terminate();server.wait(timeout=10)
