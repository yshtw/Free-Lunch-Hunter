#!/usr/bin/env python3
"""Real HTTP + MariaDB regression suite. Requires an isolated *_test database.

No third-party Python packages. Never points at production or resets user data.
Spawns a four-worker PHP server; concurrent requests use distinct sessions.
"""
import concurrent.futures
import base64
from decimal import Decimal
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import select
import signal
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
ENV = dict(os.environ, FLH_TEST_RUN='1')
MARKER = 'test_' + uuid.uuid4().hex[:18]


def sql(statement, parameters=()):
    result = subprocess.run([PHP, 'tests/db.php'], cwd=ROOT, env=ENV,
        input=json.dumps({'sql': statement, 'parameters': parameters}), text=True,
        capture_output=True, check=True)
    return json.loads(result.stdout)


def scalar(statement, parameters=()):
    return next(iter(sql(statement, parameters)[0].values()))


class Browser:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(self.cookies))
        self.token = None

    def request(self, path, data=None, *, headers=None, raw=None):
        body = raw if raw is not None else None if data is None else urllib.parse.urlencode(data).encode()
        request = urllib.request.Request(self.base + path, data=body, headers=headers or {})
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            content = response.read().decode()
            return response.status, content, dict(response.headers)

    def csrf(self):
        status, body, _ = self.request('login.php')
        assert status == 200
        self.token = re.search(r'name="csrf-token" content="([a-f0-9]+)"', body)[1]
        return self.token

    def post(self, path, data, **kwargs):
        return self.request(path, dict(data, csrf_token=self.token), **kwargs)

    def login(self, account):
        self.csrf()
        status, body, _ = self.post('login.php', {'email': account['email'], 'password': account['password']})
        assert status == 200, 'Login failed'
        self.token = re.search(r'name="csrf-token" content="([a-f0-9]+)"', body)[1]

    def order(self, key):
        return self.request('api.php?resource=membership-orders', raw=json.dumps({'product_code':'premium_lifetime'}).encode(),
            headers={'Content-Type':'application/json', 'X-CSRF-Token':self.token, 'Idempotency-Key':key})

    def upload(self, content):
        boundary = uuid.uuid4().hex
        parts = []
        for key, value in {'title':MARKER+'_image', 'content':'Upload validation', 'csrf_token':self.token}.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="img"; filename="picture.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+content+b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        return self.request('post_article.php', raw=b''.join(parts), headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})


def issue_token(email, scopes='read', days='30'):
    result = subprocess.run([PHP, 'scripts/token.php', 'issue', email, f'--scopes={scopes}', f'--days={days}',
        '--label=integration'], cwd=ROOT, env=ENV, text=True, capture_output=True, timeout=20)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


class TokenClient:
    """Bearer-token client with no cookie jar: proves token requests never depend on a session."""

    def __init__(self, base, token):
        self.base = base
        self.token = token
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def call(self, method, resource, body=None, *, params=None, headers=None):
        query = urllib.parse.urlencode({'resource': resource, **(params or {})})
        request = urllib.request.Request(f'{self.base}api.php?{query}', method=method,
            data=None if body is None else json.dumps(body).encode(),
            headers={'Authorization': f'Bearer {self.token}', 'Content-Type': 'application/json', **(headers or {})})
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, json.loads(response.read().decode() or 'null'), dict(response.headers)


class BackendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not re.search(r'dbname=[A-Za-z0-9_]+_test(?:;|$)', ENV.get('DB_DSN', '')):
            raise RuntimeError('Set DB_DSN to an isolated database with a name ending in _test.')
        subprocess.run([PHP, 'scripts/migrate.php'], cwd=ROOT, env=ENV, check=True)
        # Verify re-running the migration is safe and leaves its checksum intact.
        subprocess.run([PHP, 'scripts/migrate.php'], cwd=ROOT, env=ENV, check=True)
        cls.log = tempfile.TemporaryFile()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.base = f'http://127.0.0.1:{port}/'
        cls.server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'final_project'],
            cwd=ROOT, env=dict(ENV, PHP_CLI_SERVER_WORKERS='4', ENABLE_DEMO_TOPUP='1'),
            stdout=cls.log, stderr=cls.log, start_new_session=True)
        for _ in range(100):
            try:
                if Browser(cls.base).request('api.php?resource=health')[0] == 200: break
            except (OSError, urllib.error.URLError):
                time.sleep(.05)
        else:
            cls.stop_server()
            raise RuntimeError('PHP server did not become ready')
        cls.accounts = {}
        for role in ['user', 'other', 'admin', 'buyer', 'racer', 'poor', 'auditor', 'agent']:
            account = {'email':f'{MARKER}_{role}@example.invalid', 'username':f'{MARKER}_{role}',
                'password':secrets.token_urlsafe(24), 'birth_date':'2000-01-01'}
            browser = Browser(cls.base)
            browser.csrf()
            status, _, _ = browser.post('register.php', dict(account, confirm_password=account['password'], user_role='admin'))
            assert status == 200
            account['id'] = int(scalar('SELECT user_id FROM user_account WHERE email=?', [account['email']]))
            browser.login(account)
            cls.accounts[role] = (account, browser)
        subprocess.run([PHP, 'scripts/admin.php', cls.accounts['admin'][0]['email']], cwd=ROOT, env=ENV, check=True,
            stdout=subprocess.DEVNULL)

    @classmethod
    def stop_server(cls):
        if hasattr(cls, 'server'):
            os.killpg(cls.server.pid, signal.SIGTERM)
            cls.server.wait(timeout=10)
            cls.log.close()

    @classmethod
    def tearDownClass(cls):
        try:
            for account, _ in cls.accounts.values():
                for row in sql('SELECT img FROM articles WHERE user_id=? AND img IS NOT NULL', [account['id']]):
                    if re.fullmatch(r'uploads/[a-f0-9]{32}\.(png|jpg|webp)', row['img']):
                        (ROOT/'final_project'/row['img']).unlink(missing_ok=True)
                # Canonical FK cascades remove only this run's fixtures.
                sql('DELETE FROM user_account WHERE user_id=? AND email=?', [account['id'], account['email']])
        finally:
            cls.stop_server()

    def account(self, role='user'):
        return self.accounts[role]

    def balance(self, role='user'):
        return Decimal(scalar('SELECT money FROM premium WHERE user_id=?', [self.account(role)[0]['id']]))

    def credit(self, role, amount, key=None):
        return self.account(role)[1].post('recharge.php', {'amount':str(amount), 'idempotency_key':key or uuid.uuid4().hex})

    def article(self, role='user'):
        account, browser = self.account(role)
        title = MARKER + '_' + uuid.uuid4().hex
        self.assertEqual(browser.post('post_article.php', {'title':title, 'content':'A useful event review.'})[0], 200)
        return int(scalar('SELECT article_id FROM articles WHERE user_id=? AND title=?', [account['id'], title]))

    def sessions(self, role):
        def login(_):
            browser = Browser(self.base)
            browser.login(self.account(role)[0])
            return browser
        with concurrent.futures.ThreadPoolExecutor(4) as pool:
            return list(pool.map(login, range(4)))

    def reconcile(self, role=None):
        arguments = [] if role is None else [f'--user-id={self.account(role)[0]["id"]}']
        result = subprocess.run([PHP, 'scripts/reconcile.php', *arguments], cwd=ROOT, env=ENV,
            text=True, capture_output=True, timeout=20)
        return result, json.loads(result.stdout) if result.stdout else None

    def test_01_registration_cannot_choose_admin(self):
        for role in ['user', 'other', 'buyer', 'racer', 'poor']:
            self.assertEqual(int(scalar('SELECT premium FROM premium WHERE user_id=?', [self.account(role)[0]['id']])), 0)
        browser = Browser(self.base)
        self.assertEqual(browser.request('register.php', {'username':'no_csrf'})[0], 403)
        browser.csrf()
        account = self.account()[0]
        self.assertEqual(browser.post('register.php', dict(account, confirm_password=account['password']))[0], 409)
        stored = scalar('SELECT password FROM user_account WHERE user_id=?', [account['id']])
        self.assertNotEqual(stored, account['password'])
        self.assertTrue(stored.startswith('$'))

    def test_02_authentication_and_session_rotation(self):
        browser = Browser(self.base)
        self.assertEqual(browser.request('api.php?resource=wallet')[0], 401)
        browser.csrf()
        self.assertEqual(browser.post('login.php', {'email':self.account()[0]['email'], 'password':'incorrect-password'})[0], 401)
        before = next(cookie.value for cookie in browser.cookies if cookie.name == 'flh_session')
        browser.login(self.account()[0])
        after = next(cookie.value for cookie in browser.cookies if cookie.name == 'flh_session')
        self.assertNotEqual(before, after)
        self.assertEqual(browser.request('logout.php')[0], 405)
        status, _, headers = browser.request('api.php?resource=session')
        self.assertEqual(status, 200)
        self.assertIn('nosniff', headers['X-Content-Type-Options'])
        self.assertIn('X-Request-ID', headers)
        self.assertTrue(all(cookie.has_nonstandard_attr('HttpOnly') for cookie in browser.cookies))

    def test_03_csrf_and_role_boundaries(self):
        _, browser = self.account()
        before = self.balance()
        self.assertEqual(browser.request('post_article.php', {'title':'forged', 'content':'forged'})[0], 403)
        self.assertEqual(self.balance(), before)
        self.assertEqual(browser.request('admin_dashboard.php')[0], 403)
        self.assertEqual(browser.post('create_activities.php', {})[0], 403)

    def test_04_activities_and_repeatable_calendar(self):
        from datetime import date, timedelta
        admin, browser = self.account('admin')
        data = {'title':MARKER+'_activity', 'content':'Test event', 'place':'Taipei', 'lunch':'Lunch',
            'act_date':str(date.today()+timedelta(days=7)), 'start_time':'12:00', 'end_time':'13:00'}
        self.assertEqual(browser.post('create_activities.php', dict(data, end_time='11:00'))[0], 422)
        self.assertEqual(browser.post('create_activities.php', dict(data, url='javascript:alert(1)'))[0], 422)
        self.assertEqual(browser.post('create_activities.php', data)[0], 200)
        activity = scalar('SELECT activity_id FROM activities WHERE user_id=? AND title=?', [admin['id'], data['title']])
        self.assertEqual(browser.request('admin_dashboard.php?order=desc%3BDROP+TABLE+activities')[0], 200)
        user, member = self.account()
        for _ in range(2):
            self.assertEqual(member.post('manage_calendar.php', {'activity_id':activity,'action':'add'})[0], 200)
        self.assertEqual(int(scalar('SELECT COUNT(*) FROM join_activity WHERE user_id=? AND activity_id=?', [user['id'],activity])), 1)
        events = json.loads(member.request('load_activities.php')[1])
        self.assertIn(str(activity), [str(event['id']) for event in events])
        for _ in range(2):
            self.assertEqual(member.post('manage_calendar.php', {'activity_id':activity,'action':'delete'})[0], 200)
        self.assertEqual(json.loads(member.request('load_activities.php')[1]), [])
        self.assertEqual(member.post('manage_calendar.php', {'activity_id':2147483647,'action':'add'})[0], 404)

    def test_05_article_validation_and_ownership(self):
        _, user = self.account()
        before = self.balance()
        self.assertEqual(user.post('post_article.php', {'title':' ', 'content':'bad'})[0], 422)
        self.assertEqual(self.balance(), before)
        article = self.article()
        self.assertEqual(self.balance(), before+5)
        _, attacker = self.account('other')
        self.assertEqual(attacker.post('manage_articles.php', {'article_id':article, 'title':'stolen','content':'changed','update':'1'})[0], 404)
        self.assertEqual(attacker.post('manage_articles.php', {'article_id':article,'delete':'1'})[0], 404)
        self.assertNotEqual(scalar('SELECT title FROM articles WHERE article_id=?', [article]), 'stolen')

    def test_06_comment_validation_and_html_escaping(self):
        _, browser = self.account()
        before = self.balance()
        self.assertEqual(browser.post('comments.php', {'article_id':2147483647,'content':'orphan'})[0], 404)
        self.assertEqual(self.balance(), before)
        article = self.article()
        _, body, _ = browser.post('comments.php', {'article_id':article,'content':'<script>alert("xss")</script>'})
        self.assertIn('&lt;script&gt;', body)
        self.assertNotIn('<script>alert("xss")</script>', body)
        self.assertEqual(self.balance(), before+8)

    def test_07_votes_are_repeatable_and_reward_once(self):
        article = self.article()
        account, browser = self.account()
        before = self.balance()
        self.assertEqual(browser.post('rate.php', {'article_id':article,'rate':'500'})[0], 422)
        for rate in [1,1,0,1,-1,-1,0,1]:
            self.assertEqual(browser.post('rate.php', {'article_id':article,'rate':rate})[0], 200)
        self.assertEqual(self.balance(), before+1)
        self.assertEqual(int(scalar('SELECT rate FROM articles WHERE article_id=?', [article])), 1)
        self.assertEqual(int(scalar("SELECT COUNT(*) FROM wallet_entries WHERE user_id=? AND action='vote' AND reference=?", [account['id'],str(article)])), 1)
        # Multiple voters run on separate PHP workers; score must equal vote sum.
        with concurrent.futures.ThreadPoolExecutor(4) as pool:
            results = list(pool.map(lambda item: item[1].post('rate.php', {'article_id':article,'rate':1})[0], self.accounts.values()))
        self.assertTrue(all(status == 200 for status in results))
        self.assertEqual(int(scalar('SELECT rate FROM articles WHERE article_id=?', [article])), int(scalar('SELECT SUM(rate) FROM votes WHERE article_id=?', [article])))

    def test_08_sandbox_credit_retries_and_payload_conflicts(self):
        key = uuid.uuid4().hex
        before = self.balance('buyer')
        for _ in range(2): self.assertEqual(self.credit('buyer',40,key)[0],200)
        self.assertEqual(self.balance('buyer'), before+40)
        self.assertEqual(self.credit('buyer',50,key)[0],409)
        self.assertEqual(self.credit('buyer',-1)[0],422)
        self.assertEqual(self.credit('buyer','1.5')[0],422)

    def test_09_insufficient_balance_rolls_back(self):
        account, browser = self.account('poor')
        before = self.balance('poor')
        self.assertLess(before,30)
        self.assertEqual(browser.order(uuid.uuid4().hex)[0],409)
        self.assertEqual(self.balance('poor'),before)
        self.assertEqual(int(scalar('SELECT COUNT(*) FROM membership_orders WHERE user_id=?',[account['id']])),0)

    def test_10_storage_failure_rolls_back_order_balance_and_role(self):
        account, browser = self.account('buyer')
        before = self.balance('buyer')
        trigger = MARKER+'_fail'
        try:
            sql(f"CREATE TRIGGER {trigger} BEFORE INSERT ON wallet_entries FOR EACH ROW BEGIN IF NEW.user_id={account['id']} AND NEW.action='membership' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test-only storage failure'; END IF; END")
            status, body, headers = browser.order(uuid.uuid4().hex)
            self.assertEqual(status,500)
            self.assertNotIn('test-only storage failure',body)
            self.assertEqual(json.loads(body)['request_id'],headers['X-Request-ID'])
            self.assertEqual(self.balance('buyer'),before)
            self.assertEqual(int(scalar('SELECT premium FROM premium WHERE user_id=?',[account['id']])),0)
            self.assertEqual(int(scalar('SELECT COUNT(*) FROM membership_orders WHERE user_id=?',[account['id']])),0)
        finally:
            sql(f'DROP TRIGGER IF EXISTS {trigger}')

    def test_11_concurrent_order_retry_charges_once(self):
        sessions = self.sessions('buyer')
        key = uuid.uuid4().hex
        before = self.balance('buyer')
        with concurrent.futures.ThreadPoolExecutor(4) as pool:
            responses = list(pool.map(lambda browser: browser.order(key), sessions))
        self.assertEqual(sorted(status for status,_,_ in responses),[200,200,200,201])
        self.assertEqual(len({json.loads(body)['order']['order_id'] for _,body,_ in responses}),1)
        self.assertEqual(self.balance('buyer'),before-30)
        self.assertEqual(int(scalar("SELECT COUNT(*) FROM wallet_entries WHERE user_id=? AND action='membership'",[self.account('buyer')[0]['id']])),1)
        self.assertEqual(self.account('buyer')[1].order(uuid.uuid4().hex)[0],409)

    def test_12_concurrent_distinct_orders_do_not_double_upgrade(self):
        self.credit('racer',60)
        sessions = self.sessions('racer')
        before = self.balance('racer')
        with concurrent.futures.ThreadPoolExecutor(4) as pool:
            statuses = list(pool.map(lambda browser: browser.order(uuid.uuid4().hex)[0],sessions))
        self.assertEqual(sorted(statuses),[201,409,409,409])
        self.assertEqual(self.balance('racer'),before-30)

    def test_13_order_privacy_and_ledger_reconciliation(self):
        for account, browser in self.accounts.values():
            status, body, _ = browser.request('api.php?resource=membership-orders')
            self.assertEqual(status,200)
            own = {row['order_id'] for row in sql('SELECT order_id FROM membership_orders WHERE user_id=?',[account['id']])}
            self.assertEqual({row['order_id'] for row in json.loads(body)['orders']},own)
            total = Decimal(scalar('SELECT COALESCE(SUM(amount),0) FROM wallet_entries WHERE user_id=?',[account['id']]))
            self.assertEqual(total,Decimal(scalar('SELECT money FROM premium WHERE user_id=?',[account['id']])))

    def test_14_upload_content_validation_and_relative_image_url(self):
        account, browser = self.account()
        before = self.balance()
        self.assertEqual(browser.upload(b'<?php echo "not an image"; ?>')[0],422)
        self.assertEqual(self.balance(),before)
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
        status, body, _ = browser.upload(png)
        self.assertEqual(status,200)
        path = scalar('SELECT img FROM articles WHERE user_id=? AND title=?', [account['id'],MARKER+'_image'])
        self.assertRegex(path,r'^uploads/[a-f0-9]{32}\.png$')
        self.assertIn('src="'+path+'"',body)
        with browser.opener.open(browser.base+path,timeout=10) as image:
            self.assertEqual(image.read(),png)
        self.assertEqual(self.balance(),before+5)

    def test_15_delete_cascades_content_but_retains_reward_history(self):
        article = self.article()
        account, owner = self.account()
        _, other = self.account('other')
        other.post('comments.php', {'article_id':article,'content':'Reply to deleted post'})
        other.post('rate.php', {'article_id':article,'rate':1})
        self.assertEqual(owner.post('manage_articles.php',{'article_id':article,'delete':'1'})[0],200)
        for table in ['articles','comments','votes']:
            self.assertEqual(int(scalar(f'SELECT COUNT(*) FROM {table} WHERE article_id=?',[article])),0)
        self.assertEqual(int(scalar("SELECT COUNT(*) FROM wallet_entries WHERE user_id=? AND action='post' AND reference=?",[account['id'],str(article)])),1)

    def test_16_reconciliation_checks_clean_accounts_without_personal_data(self):
        for role, (account, _) in self.accounts.items():
            result, report = self.reconcile(role)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(report['status'], 'ok')
            self.assertEqual(report['accounts_checked'], 1)
            self.assertEqual(report['issues'], [])
            self.assertNotIn(account['email'], result.stdout)
            self.assertNotIn(account['username'], result.stdout)
        result, report = self.reconcile()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertGreaterEqual(report['accounts_checked'], len(self.accounts))

    def test_17_reconciliation_detects_exact_decimal_drift_and_is_read_only(self):
        account, _ = self.account('auditor')
        before = self.balance('auditor')
        sql('UPDATE premium SET money=money+0.01 WHERE user_id=?', [account['id']])
        try:
            result, report = self.reconcile('auditor')
            self.assertEqual(result.returncode, 1)
            issue, = report['issues']
            self.assertEqual(issue['check'], 'wallet_balance')
            self.assertEqual(Decimal(issue['difference']), Decimal('0.01'))
            self.assertEqual(self.balance('auditor'), before+Decimal('0.01'))
            # A scoped report must not include another account's drift.
            self.assertEqual(self.reconcile('other')[0].returncode, 0)
        finally:
            sql('UPDATE premium SET money=money-0.01 WHERE user_id=?', [account['id']])

    def test_18_reconciliation_detects_order_debit_even_when_wallet_balances(self):
        account, browser = self.account('auditor')
        self.assertEqual(self.credit('auditor', 60)[0], 200)
        status, body, _ = browser.order(uuid.uuid4().hex)
        self.assertEqual(status, 201)
        order_id = json.loads(body)['order']['order_id']
        self.assertEqual(self.reconcile('auditor')[0].returncode, 0)
        sql("UPDATE wallet_entries SET amount=-29.99 WHERE user_id=? AND action='membership' AND reference=?", [account['id'], order_id])
        sql('UPDATE premium SET money=money+0.01 WHERE user_id=?', [account['id']])
        try:
            result, report = self.reconcile('auditor')
            self.assertEqual(result.returncode, 1)
            issue, = report['issues']
            self.assertEqual(issue['check'], 'membership_debit')
            self.assertEqual(issue['order_id'], order_id)
            self.assertEqual(Decimal(issue['ledger_amount']), Decimal('-29.99'))
        finally:
            sql("UPDATE wallet_entries SET amount=-30 WHERE user_id=? AND action='membership' AND reference=?", [account['id'], order_id])
            sql('UPDATE premium SET money=money-0.01 WHERE user_id=?', [account['id']])
        sql("UPDATE wallet_entries SET reference=? WHERE user_id=? AND action='membership' AND reference=?", ['missing_'+order_id, account['id'], order_id])
        try:
            result, report = self.reconcile('auditor')
            self.assertEqual(result.returncode, 1)
            self.assertEqual({issue['check'] for issue in report['issues']}, {'membership_debit', 'orphan_membership_debit'})
        finally:
            sql("UPDATE wallet_entries SET reference=? WHERE user_id=? AND action='membership' AND reference=?", [order_id, account['id'], 'missing_'+order_id])
        # Promoting a paying member to administrator preserves a valid historical order.
        sql('UPDATE premium SET premium=2 WHERE user_id=?', [account['id']])
        try:
            self.assertEqual(self.reconcile('auditor')[0].returncode, 0)
        finally:
            sql('UPDATE premium SET premium=1 WHERE user_id=?', [account['id']])

    def test_19_reconciliation_detects_missing_wallet_and_unearned_entitlement(self):
        account, _ = self.account('poor')
        sql('UPDATE premium SET premium=1 WHERE user_id=?', [account['id']])
        try:
            result, report = self.reconcile('poor')
            self.assertEqual(result.returncode, 1)
            self.assertEqual([issue['check'] for issue in report['issues']], ['membership_entitlement'])
        finally:
            sql('UPDATE premium SET premium=0 WHERE user_id=?', [account['id']])
        before = self.balance('poor')
        sql('DELETE FROM premium WHERE user_id=?', [account['id']])
        try:
            result, report = self.reconcile('poor')
            self.assertEqual(result.returncode, 1)
            self.assertEqual([issue['check'] for issue in report['issues']], ['missing_wallet'])
        finally:
            sql('INSERT INTO premium(user_id,premium,money) VALUES(?,0,?)', [account['id'], str(before)])

    def test_20_snapshot_remains_consistent_while_another_session_commits(self):
        account, _ = self.account('auditor')
        before = self.balance('auditor')
        # Pause between two real reads; an independent HTTP writer commits in between.
        script = '''require "app/bootstrap.php";
            db()->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $result = read_snapshot(function () use ($argv) {
                $balance = query("SELECT money FROM premium WHERE user_id=?", [$argv[1]])->fetchColumn();
                echo "snapshot-ready\\n"; fflush(STDOUT);
                if (trim(fgets(STDIN)) !== "continue") throw new RuntimeException("Barrier failed");
                return ["balance"=>$balance, "ledger_total"=>query(
                    "SELECT COALESCE(SUM(amount),0) FROM wallet_entries WHERE user_id=?", [$argv[1]])->fetchColumn()];
            });
            echo json_encode($result);'''
        with tempfile.TemporaryFile(mode='w+') as errors:
            process = subprocess.Popen([PHP, '-r', script, str(account['id'])], cwd=ROOT, env=ENV,
                text=True, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errors)
            try:
                self.assertTrue(select.select([process.stdout], [], [], 10)[0], 'Snapshot did not become ready')
                self.assertEqual(process.stdout.readline().strip(), 'snapshot-ready')
                self.assertEqual(self.credit('auditor', 1)[0], 200)
                output, _ = process.communicate('continue\n', timeout=10)
                self.assertEqual(process.returncode, 0)
                snapshot = json.loads(output)
                self.assertEqual(Decimal(snapshot['balance']), before)
                self.assertEqual(Decimal(snapshot['ledger_total']), before)
            finally:
                if process.poll() is None:
                    process.kill()
                    process.communicate()
        self.assertEqual(self.balance('auditor'), before+1)
        status, body, _ = self.account('auditor')[1].request('api.php?resource=wallet')
        self.assertEqual(status, 200)
        wallet = json.loads(body)
        self.assertEqual(Decimal(wallet['balance']), before+1)
        self.assertEqual(sum(Decimal(entry['amount']) for entry in wallet['entries']), before+1)

    def test_21_reconciliation_does_not_report_success_on_operator_errors(self):
        for argument in ['--user-id=0', '--user-id=abc', '--user-id=2147483647', '--unknown']:
            result = subprocess.run([PHP, 'scripts/reconcile.php', argument], cwd=ROOT, env=ENV,
                text=True, capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 2)
            self.assertEqual(result.stdout, '')
            self.assertTrue(result.stderr)
        result = subprocess.run([PHP, 'scripts/reconcile.php'], cwd=ROOT,
            env=dict(ENV, DB_DSN='mysql:unix_socket=/nonexistent/flh.sock;dbname=unavailable_test'),
            text=True, capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, '')
        self.assertIn('Reconciliation unavailable', result.stderr)
        self.assertNotIn('/nonexistent', result.stderr)
        self.assertEqual(self.reconcile()[0].returncode, 0)


    def upcoming_activity(self, title_suffix, days=7, start='12:00', end='13:00', content='Token test event'):
        from datetime import date, timedelta
        admin, browser = self.account('admin')
        title = f'{MARKER}_{title_suffix}'
        self.assertEqual(browser.post('create_activities.php', {'title':title, 'content':content, 'place':'Taipei',
            'act_date':str(date.today()+timedelta(days=days)), 'start_time':start, 'end_time':end})[0], 200)
        return int(scalar('SELECT activity_id FROM activities WHERE user_id=? AND title=?', [admin['id'], title]))

    def test_22_migration_v2_and_tokens_store_only_digests(self):
        self.assertEqual([row['version'] for row in sql('SELECT version FROM schema_migrations ORDER BY version')], [1, 2])
        account = self.account('user')[0]
        issued = issue_token(account['email'], 'schedule:write')
        self.assertRegex(issued['token'], r'\Aflh_[a-f0-9]{16}_[a-f0-9]{64}\Z')
        self.assertEqual(issued['scopes'], ['read', 'schedule:write'])
        secret = issued['token'].rsplit('_', 1)[1]
        stored = sql('SELECT secret_hash,scopes FROM api_tokens WHERE token_id=?', [issued['token_id']])[0]
        self.assertNotIn(secret, json.dumps(stored))
        self.assertEqual(len(stored['secret_hash']), 64)
        for arguments in [['issue', account['email'], '--scopes=admin'], ['issue', account['email'], '--days=0'],
                ['issue', account['email'], '--days=366'], ['issue', account['email'], '--bogus=1'], ['launch']]:
            result = subprocess.run([PHP, 'scripts/token.php', *arguments], cwd=ROOT, env=ENV,
                text=True, capture_output=True, timeout=20)
            self.assertEqual(result.returncode, 2, arguments)
            self.assertEqual(result.stdout, '')
        listed = subprocess.run([PHP, 'scripts/token.php', 'list', account['email']], cwd=ROOT, env=ENV,
            text=True, capture_output=True, timeout=20, check=True).stdout
        self.assertIn(issued['token_id'], listed)
        self.assertNotIn(secret, listed)

    def test_23_read_token_cannot_write_and_uses_no_session(self):
        account = self.account('user')[0]
        client = TokenClient(self.base, issue_token(account['email'])['token'])
        status, body, headers = client.call('GET', 'session')
        self.assertEqual(status, 200)
        self.assertEqual((body['auth'], body['scopes'], body['user']['user_id']), ('token', ['read'], account['id']))
        self.assertNotIn('csrf_token', body)
        self.assertNotIn('flh_session', headers.get('Set-Cookie', ''))
        for resource in ['wallet', 'membership-offer', 'membership-orders', 'activities', 'schedule']:
            self.assertEqual(client.call('GET', resource)[0], 200, resource)
        activity = self.upcoming_activity('read_only')
        before = self.balance()
        self.assertEqual(client.call('POST', 'schedule', {'activity_id':activity, 'action':'add'})[0], 403)
        self.assertEqual(client.call('POST', 'membership-orders', {'product_code':'premium_lifetime'},
            headers={'Idempotency-Key':uuid.uuid4().hex})[0], 403)
        self.assertEqual(self.balance(), before)
        self.assertEqual(int(scalar('SELECT COUNT(*) FROM join_activity WHERE user_id=? AND activity_id=?',
            [account['id'], activity])), 0)
        self.assertEqual(client.call('DELETE', 'schedule')[0], 405)
        self.assertEqual(client.call('GET', 'nonexistent')[0], 404)

    def test_24_invalid_revoked_and_expired_tokens_never_fall_back_to_session(self):
        account, browser = self.account('user')
        issued = issue_token(account['email'])
        token_id, secret = issued['token_id'], issued['token'].rsplit('_', 1)[1]
        for token in ['', 'flh_short', f'flh_{token_id}_{"0" * 64}', f'flh_{"f" * 16}_{secret}', issued['token'] + 'x']:
            self.assertEqual(TokenClient(self.base, token).call('GET', 'wallet')[0], 401, token)
        # A browser session cookie plus a bad Authorization header must not authenticate as the cookie owner.
        self.assertEqual(browser.request('api.php?resource=wallet', headers={'Authorization':'Bearer invalid'})[0], 401)
        self.assertEqual(browser.request('api.php?resource=wallet', headers={'Authorization':'Basic dXNlcjpwYXNz'})[0], 401)
        self.assertEqual(browser.request('api.php?resource=wallet')[0], 200)
        client = TokenClient(self.base, issued['token'])
        self.assertEqual(client.call('GET', 'wallet')[0], 200)
        self.assertIsNotNone(scalar('SELECT last_used_at FROM api_tokens WHERE token_id=?', [token_id]))
        revoke = subprocess.run([PHP, 'scripts/token.php', 'revoke', token_id], cwd=ROOT, env=ENV, capture_output=True, timeout=20)
        self.assertEqual(revoke.returncode, 0)
        self.assertEqual(client.call('GET', 'wallet')[0], 401)
        expired = issue_token(account['email'])
        sql('UPDATE api_tokens SET expires_at=NOW() - INTERVAL 1 SECOND WHERE token_id=?', [expired['token_id']])
        self.assertEqual(TokenClient(self.base, expired['token']).call('GET', 'wallet')[0], 401)
        sql('DELETE FROM api_tokens WHERE user_id=?', [account['id']])

    def test_25_scoped_writes_need_no_csrf_and_are_retry_safe(self):
        account = self.account('agent')[0]
        writer = TokenClient(self.base, issue_token(account['email'], 'schedule:write,membership:redeem')['token'])
        activity = self.upcoming_activity('token_write')
        for _ in range(2):
            self.assertEqual(writer.call('POST', 'schedule', {'activity_id':activity, 'action':'add'})[:2],
                (200, {'activity_id':activity, 'joined':True}))
        self.assertEqual([item['activity_id'] for item in writer.call('GET', 'schedule')[1]['activities']], [activity])
        self.assertEqual(writer.call('POST', 'schedule', {'activity_id':activity, 'action':'join'})[0], 422)
        self.assertEqual(writer.call('POST', 'schedule', {'activity_id':2147483647, 'action':'add'})[0], 404)
        for _ in range(2):
            self.assertEqual(writer.call('POST', 'schedule', {'activity_id':activity, 'action':'remove'})[0], 200)
        self.assertEqual(writer.call('GET', 'schedule')[1]['activities'], [])
        # Session-authenticated writes still require CSRF even when the same endpoint accepts tokens.
        _, browser = self.account('agent')
        self.assertEqual(browser.request('api.php?resource=schedule', raw=json.dumps({'activity_id':activity,
            'action':'add'}).encode(), headers={'Content-Type':'application/json'})[0], 403)
        offer = writer.call('GET', 'membership-offer')[1]
        balance = Decimal(offer['balance'])
        self.assertLess(balance, 30)
        self.assertEqual((offer['membership_level'], offer['eligible'], offer['reason'], offer['points_needed']),
            ('regular', False, 'insufficient_points', f'{30 - balance:.2f}'))
        self.assertEqual(writer.call('POST', 'membership-orders', {'product_code':'premium_lifetime'},
            headers={'Idempotency-Key':uuid.uuid4().hex})[0], 409)
        self.assertEqual(self.credit('agent', int(35 - balance))[0], 200)
        self.assertEqual(writer.call('GET', 'membership-offer')[1]['eligible'], True)
        key = uuid.uuid4().hex
        first = writer.call('POST', 'membership-orders', {'product_code':'premium_lifetime'}, headers={'Idempotency-Key':key})
        again = writer.call('POST', 'membership-orders', {'product_code':'premium_lifetime'}, headers={'Idempotency-Key':key})
        self.assertEqual((first[0], again[0], again[2]['Idempotency-Replayed']), (201, 200, 'true'))
        self.assertEqual(first[1]['order'], again[1]['order'])
        self.assertEqual(self.balance('agent'), 5)
        self.assertEqual(writer.call('GET', 'membership-offer')[1]['reason'], 'already_member')
        self.reconcile_clean('agent')

    def reconcile_clean(self, role):
        result, report = self.reconcile(role)
        self.assertEqual(result.returncode, 0, report)

    def test_26_activity_search_filters_literal_wildcards_and_flags_joined(self):
        account = self.account('other')[0]
        client = TokenClient(self.base, issue_token(account['email'], 'schedule:write')['token'])
        near = self.upcoming_activity('search_near', days=3, content='Robotics 100% hands-on')
        far = self.upcoming_activity('search_far', days=20)
        sql('INSERT INTO activities(title,content,act_date,place,startt,endt,user_id) VALUES(?,?,CURDATE() - INTERVAL 1 DAY,?,?,?,?)',
            [MARKER + '_search_past', 'past', 'Taipei', '12:00:00', '13:00:00', self.account('admin')[0]['id']])
        client.call('POST', 'schedule', {'activity_id':near, 'action':'add'})
        found = client.call('GET', 'activities', params={'q':MARKER + '_search'})[1]
        self.assertEqual([item['activity_id'] for item in found['activities']], [near, far])
        self.assertEqual([item['joined'] for item in found['activities']], [True, False])
        self.assertEqual(set(found['activities'][0]), {'activity_id', 'title', 'content', 'act_date', 'start_time',
            'end_time', 'place', 'lunch', 'url', 'joined'})
        self.assertFalse(found['has_more'])
        from datetime import date, timedelta
        window = client.call('GET', 'activities', params={'q':MARKER + '_search', 'to':str(date.today()+timedelta(days=10))})[1]
        self.assertEqual([item['activity_id'] for item in window['activities']], [near])
        limited = client.call('GET', 'activities', params={'q':MARKER + '_search', 'limit':'1'})[1]
        self.assertTrue(limited['has_more'])
        self.assertEqual([item['activity_id'] for item in client.call('GET', 'activities', params={'q':'100%'})[1]['activities']], [near])
        self.assertEqual(client.call('GET', 'activities', params={'q':MARKER[:5] + '%_search'})[1]['activities'], [])
        for params in [{'from':'2026-02-30'}, {'limit':'0'}, {'limit':'51'}, {'from':'2099-01-02', 'to':'2099-01-01'}]:
            self.assertEqual(client.call('GET', 'activities', params=params)[0], 422, params)

    def test_27_membership_offer_is_server_computed(self):
        account = self.account('poor')[0]
        client = TokenClient(self.base, issue_token(account['email'])['token'])
        offer = client.call('GET', 'membership-offer')[1]
        balance = Decimal(offer['balance'])
        self.assertEqual((offer['product_code'], offer['price'], offer['unit']), ('premium_lifetime', '30.00', 'community_points'))
        if offer['membership_level'] == 'regular' and balance < 30:
            self.assertEqual((offer['eligible'], offer['reason']), (False, 'insufficient_points'))
            self.assertEqual(Decimal(offer['points_needed']), 30 - balance)
            self.assertEqual(self.credit('poor', int(30 - balance))[0], 200)
            offer = client.call('GET', 'membership-offer')[1]
        self.assertEqual((offer['eligible'], offer['reason'], Decimal(offer['points_needed'])), (True, None, 0))

if __name__ == '__main__':
    unittest.main(verbosity=2)
