#!/usr/bin/env python3
"""Local, isolated Nextcloud development container. No production credentials."""
import argparse
import io
import json
from pathlib import Path
import secrets
import subprocess
import tarfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parent
CONTAINER = 'sharemanager-dev'
IMAGE = 'nextcloud:33-apache@sha256:6df481c0861b87366086df72a63a8f041e3680c715edb12f8444902223b51c09'

def run(*args, capture=False):
    return subprocess.run(args, check=True, text=True, stdout=subprocess.PIPE if capture else None).stdout

def occ(*args, capture=False):
    return run('docker', 'exec', '-u', 'www-data', CONTAINER, 'php', 'occ', *args, capture=capture)

def sync():
    archive = io.BytesIO()
    with tarfile.open(fileobj=archive, mode='w') as tar:
        for name in ['appinfo', 'lib', 'templates', 'js', 'css', 'img', 'tests']:
            tar.add(ROOT / name, arcname=name)
    run('docker', 'exec', CONTAINER, 'mkdir', '-p', '/var/www/html/custom_apps/sharemanager')
    subprocess.run(['docker', 'exec', '-i', CONTAINER, 'tar', '-xf', '-', '-C',
                    '/var/www/html/custom_apps/sharemanager'], input=archive.getvalue(), check=True)
    run('docker', 'exec', CONTAINER, 'chown', '-R', 'www-data:www-data', '/var/www/html/custom_apps/sharemanager')

def up():
    existing = run('docker', 'ps', '-a', '--format', '{{.Names}}', capture=True).splitlines()
    if CONTAINER not in existing:
        run('docker', 'pull', IMAGE)
        run('docker', 'run', '-d', '--name', CONTAINER, '-p', '127.0.0.1:8080:80', IMAGE)
    else:
        run('docker', 'start', CONTAINER)
    for attempt in range(90):
        result = subprocess.run(['docker', 'exec', CONTAINER, 'test', '-f', '/var/www/html/occ'], capture_output=True)
        if result.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Nextcloud files did not initialize')
    status = json.loads(occ('status', '--output=json', capture=True))
    if not status['installed']:
        occ('maintenance:install', '--database=sqlite', '--admin-user=devadmin',
            '--admin-pass=' + secrets.token_urlsafe(32))
    sync()
    occ('upgrade', '--no-interaction')
    occ('app:enable', 'sharemanager')
    with urllib.request.urlopen('http://127.0.0.1:8080/status.php', timeout=10) as response:
        status = json.load(response)
    if not status['installed'] or status['maintenance']:
        raise RuntimeError('Nextcloud is not ready')
    print('Nextcloud is ready; Share Manager enabled.')

def test():
    sync()
    for directory in ['appinfo', 'lib', 'templates', 'tests']:
        for file in sorted((ROOT / directory).rglob('*.php')):
            run('docker', 'exec', CONTAINER, 'php', '-l',
                '/var/www/html/custom_apps/sharemanager/' + file.relative_to(ROOT).as_posix())
    run('node', '--check', str(ROOT / 'js/main.js'))
    run('node', str(ROOT / 'tests/frontend.cjs'))
    run('docker', 'exec', '-u', 'www-data', CONTAINER, 'php',
        '/var/www/html/custom_apps/sharemanager/tests/integration.php')
    run('python3', str(ROOT / 'tests/http_smoke.py'))

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['up', 'sync', 'test', 'stop'])
    command = parser.parse_args().command
    if command == 'stop':
        run('docker', 'stop', CONTAINER)
    else:
        globals()[command]()
