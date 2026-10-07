"""Authenticated HTTP routing, template and CSRF checks against dev.py's instance."""
import html
import http.cookiejar
import json
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request

base = 'http://127.0.0.1:8080'
uid = 'sm_http_' + secrets.token_hex(5)
password = secrets.token_urlsafe(32)
subprocess.run(['docker', 'exec', '-u', 'www-data', '-e', 'OC_PASS=' + password,
                'sharemanager-dev', 'php', 'occ', 'user:add', '--password-from-env', uid], check=True)
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def token(page):
    return html.unescape(re.search(r'data-requesttoken="([^"]+)"', page).group(1))

try:
    login = opener.open(base + '/index.php/login').read().decode()
    body = urllib.parse.urlencode({'user': uid, 'password': password, 'requesttoken': token(login)}).encode()
    opener.open(urllib.request.Request(base + '/index.php/login', body, headers={'Origin': base})).read()
    page = opener.open(base + '/index.php/apps/sharemanager/').read().decode()
    assert 'id="share-tree"' in page, 'App template did not load after login'
    assert '/sharemanager/js/main.js' in page, 'App JavaScript not registered'
    print('PASS: Authenticated app page and JavaScript registration')
    headers = {'requesttoken': token(page), 'Accept': 'application/json'}
    request = urllib.request.Request(base + '/index.php/apps/sharemanager/shares', headers=headers)
    assert json.load(opener.open(request)) == {'currentUser': uid, 'trees': []}
    print('PASS: Authenticated share API routing')
    for route in ['/revoke/preview', '/revoke']:
        payload = json.dumps({'rootId': 999999, 'targetUser': uid, 'fingerprint': 'fake', 'includeSharedAccess': False}).encode()
        request = urllib.request.Request(base + '/index.php/apps/sharemanager' + route, payload,
            headers={'Content-Type': 'application/json', 'Accept': 'application/json'}, method='POST')
        try:
            opener.open(request)
            raise AssertionError('Bulk endpoint accepted missing CSRF token')
        except urllib.error.HTTPError as error:
            assert error.code == 412, error.code
        request = urllib.request.Request(base + '/index.php/apps/sharemanager' + route, payload,
            headers={**headers, 'Content-Type': 'application/json'}, method='POST')
        try:
            opener.open(request)
            raise AssertionError('Bulk endpoint accepted unauthorized tree')
        except urllib.error.HTTPError as error:
            assert error.code == 403, error.code
        print('PASS: ' + route + ' HTTP routing, CSRF and ownership guard')
    request = urllib.request.Request(base + '/index.php/apps/sharemanager/shares/ocinternal%3A999999',
                                     headers={'Accept': 'application/json'}, method='DELETE')
    try:
        opener.open(request)
        raise AssertionError('Deletion accepted without CSRF token')
    except urllib.error.HTTPError as error:
        assert error.code == 412, error.code
    print('PASS: HTTP deletion without CSRF token rejected')
    request = urllib.request.Request(base + '/index.php/apps/sharemanager/shares/ocinternal%3A999999',
                                     headers=headers, method='DELETE')
    try:
        opener.open(request)
        raise AssertionError('Missing share deletion succeeded')
    except urllib.error.HTTPError as error:
        assert error.code == 404, error.code
    print('PASS: Encoded provider ID reaches deletion controller')
finally:
    subprocess.run(['docker', 'exec', '-u', 'www-data', 'sharemanager-dev', 'php', 'occ', 'user:delete', uid], check=True)
