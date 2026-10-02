#!/usr/bin/env python3
"""
Publishing client for the Cydia repo. "deploy" in config.json picks the way:

  "github"  (default)  packages are committed to this git repository and pushed;
                       GitHub Actions builds the repo with server/ and serves it on GitHub Pages.
  "hosting"            packages are uploaded to publish.php on a PHP hosting, which builds
                       the repo itself; requests are signed with the key made by "setup".

Python 3.8+, standard library only.

    python3 tools/repo.py setup                    one time: prepare git (or the hosting key)
    python3 tools/repo.py publish DEB|THEOS_DIR    publish a package (+ its depiction/)
    python3 tools/repo.py sync                     publish config.json, icon and theme changes
    python3 tools/repo.py remove ID [--version V]  delete a package or one of its versions
    python3 tools/repo.py rebuild                  build the site again
    python3 tools/repo.py status                   where things stand
"""

import argparse
import bz2
import datetime
import gzip
import hashlib
import hmac
import io
import json
import lzma
import os
import re
import secrets
import shutil
import subprocess
import sys
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CONFIG_FILE = ROOT / 'config.json'
CLIENT_FILE = ROOT / 'publish.json'
SECRET_FILE = ROOT / 'server' / 'repo' / 'secret.php'
THEME_DIR = ROOT / 'theme'
ICON_FILE = ROOT / 'assets' / 'CydiaIcon.png'
DEBS_DIR = ROOT / 'debs'
PACKAGES_DIR = ROOT / 'packages'

# What the builder accepts (see server/repo/lib/api.php).
DEPICTION_FILE = re.compile(r'^(description\.(md|html)|changelog\.md|meta\.json|(icon|banner)\.(png|jpe?g|gif)'
                            r'|screenshots/[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(png|jpe?g|gif))$')
THEME_FILE = re.compile(r'^([A-Za-z0-9_-]+/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(css|js|png|jpe?g|gif)$')


class DebError(Exception):
    pass


class ApiError(Exception):
    pass


def say(message=''):
    print(message, flush=True)


def die(message):
    print('Ошибка: ' + message, file=sys.stderr, flush=True)
    sys.exit(1)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def human_size(n):
    if n < 1024:
        return f'{n} Б'
    if n < 1024 * 1024:
        return f'{n / 1024:.1f} КБ'.replace('.', ',')
    return f'{n / 1024 / 1024:.1f} МБ'.replace('.', ',')


def load_config():
    if not CONFIG_FILE.is_file():
        die(f'нет файла {CONFIG_FILE}')
    try:
        cfg = json.loads(CONFIG_FILE.read_text(encoding='utf-8-sig'))
    except ValueError as e:
        die(f'config.json: {e}')
    url = str(cfg.get('url', '')).strip()
    if not re.match(r'^https?://[^/]+', url):
        die('укажите в config.json "url", например http://repo.example.com/')
    cfg['url'] = url if url.endswith('/') else url + '/'
    cfg['deploy'] = str(cfg.get('deploy', 'github')).strip().lower()
    if cfg['deploy'] not in ('github', 'hosting'):
        die('"deploy" в config.json: "github" или "hosting"')
    return cfg


# ----------------------------------------------------------------- .deb ---

def read_ar(data):
    """[(name, mtime, bytes)] of an ar archive held in memory."""
    if data[:8] != b'!<arch>\n':
        raise DebError('это не .deb (не ar-архив)')
    members, pos = [], 8
    while pos < len(data):
        header = data[pos:pos + 60]
        if not header.strip():
            break
        if len(header) < 60 or header[58:60] != b'`\n':
            raise DebError('повреждённый заголовок ar')
        try:
            mtime = int(header[16:28].strip() or 0)
            size = int(header[48:58].strip())
        except ValueError:
            raise DebError('повреждённый заголовок ar')
        body = data[pos + 60:pos + 60 + size]
        if len(body) != size:
            raise DebError('файл обрезан (не до конца скачан?)')
        members.append((header[:16].decode('ascii', 'replace').strip().rstrip('/'), mtime, body))
        pos += 60 + size + size % 2
    return members


def write_ar(members):
    out = [b'!<arch>\n']
    for name, mtime, body in members:  # same header layout as dpkg-deb
        out.append(f'{name:<16}{mtime:<12}{0:<6}{0:<6}{"100644":<8}{len(body):<10}`\n'.encode('ascii'))
        out.append(body)
        if len(body) % 2:
            out.append(b'\n')
    return b''.join(out)


def zstd_decompress(data):
    try:
        from compression import zstd  # Python 3.14+
        return zstd.decompress(data)
    except ImportError:
        pass
    try:
        import zstandard
        return zstandard.ZstdDecompressor().decompressobj().decompress(data)
    except ImportError:
        pass
    if shutil.which('zstd'):
        return subprocess.run(['zstd', '-dcq'], input=data, stdout=subprocess.PIPE, check=True).stdout
    raise DebError('для zstd-пакетов нужен Python 3.14+, "pip install zstandard" или утилита zstd')


def unpack(name, data):
    ext = name.split('.tar', 1)[1] if '.tar' in name else ''
    try:
        if ext == '':
            return data
        if ext == '.gz':
            return gzip.decompress(data)
        if ext in ('.xz', '.lzma'):
            return lzma.decompress(data)
        if ext == '.bz2':
            return bz2.decompress(data)
        if ext == '.zst':
            return zstd_decompress(data)
    except DebError:
        raise
    except Exception as e:
        raise DebError(f'не удалось распаковать {name}: {e}')
    raise DebError(f'неизвестное сжатие: {name}')


def read_deb(data):
    """(ar members, {lowercase field: value}) of a .deb."""
    members = read_ar(data)
    names = [m[0] for m in members]
    control = next((m for m in members if m[0].startswith('control.tar')), None)
    if 'debian-binary' not in names or control is None or not any(n.startswith('data.tar') for n in names):
        raise DebError('это не .deb (нет debian-binary, control.tar или data.tar)')
    text = None
    try:
        with tarfile.open(fileobj=io.BytesIO(unpack(control[0], control[2])), mode='r:') as tar:
            for member in tar:
                if member.isfile() and os.path.normpath(member.name) == 'control':
                    text = tar.extractfile(member).read().decode('utf-8', 'replace')
                    break
    except tarfile.TarError as e:
        raise DebError(f'повреждён control.tar: {e}')
    if text is None:
        raise DebError('в пакете нет control-файла')
    fields, last = {}, None
    for line in text.splitlines():
        if not line.strip():
            if fields:
                break
        elif line[0] in ' \t' and last:
            fields[last] += '\n' + line
        elif ':' in line:
            key, value = line.split(':', 1)
            last = key.strip().lower()
            fields[last] = value.strip()
    for key in ('package', 'version', 'architecture'):
        if not fields.get(key):
            raise DebError(f'в control нет поля {key.capitalize()}')
    return members, fields


def prepare_for_builder(members, arch):
    """gzip the control archive (the PHP builder reads only gzip) and, for
    iphoneos-arm packages, an xz/zstd data archive (old dpkg on iOS 6 can't unpack it)."""
    out, notes = [], []
    for name, mtime, body in members:
        control = name.startswith('control.tar') and name not in ('control.tar', 'control.tar.gz')
        legacy_data = name in ('data.tar.xz', 'data.tar.zst') and arch == 'iphoneos-arm'
        if control or legacy_data:
            new_name = name.split('.tar', 1)[0] + '.tar.gz'
            out.append((new_name, mtime, gzip.compress(unpack(name, body), 9, mtime=0)))
            notes.append(f'{name} → {new_name}')
        else:
            out.append((name, mtime, body))
    return out, notes


def canonical_name(package, version, arch):
    """dpkg's file name for a package: package_version_arch.deb (epoch dropped)."""
    version = re.sub(r'^\d+:', '', version)
    if not (re.match(r'^[A-Za-z0-9][A-Za-z0-9.+-]*$', package)
            and re.match(r'^[A-Za-z0-9][A-Za-z0-9.+~-]*$', version)
            and re.match(r'^[A-Za-z0-9-]+$', arch)):
        raise DebError(f'недопустимые символы в Package/Version/Architecture: {package} {version} {arch}')
    return f'{package}_{version}_{arch}.deb'


# ---------------------------------------------------------------- Theos ---

def resolve_deb(path):
    """A .deb file, or the newest package in a project folder: the one `make package` just
    built (.theos/last_package of Theos, or packages/*.deb of a custom Makefile)."""
    if path.is_file():
        return path
    if not path.is_dir():
        die(f'{path}: нет такого файла или папки')
    candidates = list(path.glob('packages/*.deb')) + list(path.glob('*.deb'))
    last = path / '.theos' / 'last_package'
    if last.is_file():
        ref = Path(last.read_text().strip())
        candidates += [ref] if ref.is_absolute() else [path / ref, path / 'packages' / ref]
    candidates = [c for c in candidates if c.is_file()]
    if not candidates:
        die(f'{path}: не найден .deb (сначала make package)')
    return max(candidates, key=lambda c: c.stat().st_mtime)


def theos_package_id(folder):
    control = folder / 'control'
    if control.is_file():
        for line in control.read_text(encoding='utf-8', errors='replace').splitlines():
            if line.lower().startswith('package:'):
                return line.split(':', 1)[1].strip()
    return None


def add_changelog(folder, version, messages):
    folder.mkdir(parents=True, exist_ok=True)
    changelog = folder / 'changelog.md'
    existing = changelog.read_text(encoding='utf-8-sig') if changelog.exists() else ''
    if re.search(r'^\s{0,3}#{1,3}\s+v?' + re.escape(version) + r'(\s|$)', existing, re.M):
        say(f'  ! в {changelog} уже есть версия {version}, оставил как есть')
        return
    entry = f'## {version} ({datetime.date.today().isoformat()})\n' + ''.join(f'- {m}\n' for m in messages) + '\n'
    changelog.write_bytes((entry + existing).encode('utf-8'))
    say(f'  changelog: добавлена версия {version}')


def collect(folder, pattern, what):
    """{relative path: Path} of the files in folder that match pattern."""
    files = {}
    for file in sorted(folder.rglob('*')):
        rel = file.relative_to(folder).as_posix()
        if not file.is_file() or any(part.startswith('.') for part in rel.split('/')) or rel.startswith('README'):
            continue
        if pattern.match(rel):
            files[rel] = file
        else:
            say(f'  ! пропущен {folder.name}/{rel}: {what}')
    return files


# ------------------------------------------------------- GitHub Pages ---

def git(*args, capture=False, check=True):
    try:
        return subprocess.run(['git', *args], cwd=ROOT, text=True, check=check, capture_output=capture)
    except FileNotFoundError:
        die('не найден git: установите его (sudo apt install git)')
    except subprocess.CalledProcessError as e:
        die(f'git {args[0]}: ' + ((e.stderr or '').strip() or f'код ошибки {e.returncode}'))


def git_ready():
    if not (ROOT / '.git').exists():
        die('проект ещё не git-репозиторий: выполните python3 tools/repo.py setup')


def require_identity():
    if git('var', 'GIT_COMMITTER_IDENT', capture=True, check=False).returncode != 0:
        die('git не знает автора коммитов. Выполните один раз:\n'
            '  git config --global user.name "Ваше имя"\n'
            '  git config --global user.email "you@example.com"')


def git_commit(message, paths):
    """Commit the changes under paths; True if a commit was made."""
    git_ready()
    paths = [p for p in paths if (ROOT / p).exists() or git('ls-files', '--', p, capture=True).stdout.strip()]
    if not paths:
        return False
    git('add', '-A', '--', *paths)
    if git('diff', '--cached', '--quiet', '--', *paths, check=False).returncode == 0:
        return False
    require_identity()
    git('commit', '-q', '-m', message, '--', *paths)
    return True


def git_upstream_state():
    """(has upstream, commits not pushed yet)."""
    if git('rev-parse', '--abbrev-ref', '@{u}', capture=True, check=False).returncode != 0:
        return False, 0
    return True, int(git('rev-list', '--count', '@{u}..HEAD', capture=True).stdout.strip() or 0)


def git_push(cfg):
    if 'origin' not in git('remote', capture=True).stdout.split():
        die('не подключён репозиторий на GitHub: '
            'python3 tools/repo.py setup --remote https://github.com/ЛОГИН/РЕПОЗИТОРИЙ.git')
    upstream, ahead = git_upstream_state()
    if upstream and not ahead:
        say('На GitHub уже всё актуально.')
        return
    say('Отправляю на GitHub...')
    if git('push', *([] if upstream else ['-u', 'origin', 'HEAD']), check=False).returncode != 0:
        die('git push не прошёл. Проверьте доступ к GitHub (логин и токен с правами repo и workflow, '
            'или SSH-ключ). Коммит сохранён: после исправления повторите команду.')
    say(f'Готово. GitHub Actions соберёт репозиторий за 1–2 минуты → {cfg["url"]}')


class GitTarget:
    """GitHub Pages: files go into this repository; a push makes GitHub Actions rebuild the site."""
    where = 'в репозитории'
    stored = 'добавлен'

    def __init__(self, cfg):
        self.cfg = cfg

    def debs(self):
        found = {}
        for file in sorted(DEBS_DIR.rglob('*.deb')) if DEBS_DIR.is_dir() else []:
            data = file.read_bytes()
            entry = {'sha256': sha256(data)}
            try:
                fields = read_deb(data)[1]
                entry.update(package=fields['package'], version=fields['version'], arch=fields['architecture'])
            except DebError as e:
                entry['error'] = str(e)
            found[file.relative_to(ROOT).as_posix()] = entry
        return found

    def files(self, area):
        base = ROOT / area
        if not base.is_dir():
            return {}
        return {area + f.relative_to(base).as_posix(): {'sha256': sha256(f.read_bytes())}
                for f in sorted(base.rglob('*')) if f.is_file()}

    def put(self, path, data):
        target = ROOT / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)

    def delete(self, path):
        target = ROOT / path
        if target.is_file():
            target.unlink()
        parent = target.parent  # drop folders left empty inside packages/<id>/
        while parent not in (ROOT, DEBS_DIR, PACKAGES_DIR) and ROOT in parent.parents and not any(parent.iterdir()):
            parent.rmdir()
            parent = parent.parent

    def finish(self, message, paths, build=True):
        committed = git_commit(message, paths)
        if build:
            git_push(self.cfg)
        elif committed:
            say('Сохранено в git. Отправить на GitHub: python3 tools/repo.py rebuild')


# ------------------------------------------------------------ PHP hosting ---

class Api:
    """Signed requests to publish.php (protocol: server/repo/lib/api.php)."""

    def __init__(self, endpoint, secret):
        self.endpoint = endpoint
        self.secret = secret.encode('ascii')

    def call(self, action, path='', body=b''):
        t = str(int(time.time()))
        nonce = secrets.token_hex(16)
        message = '\n'.join(['cyrepo1', action, path, t, nonce, sha256(body)]).encode('utf-8')
        signature = hmac.new(self.secret, message, hashlib.sha256).hexdigest()
        query = urllib.parse.urlencode({'action': action, 'path': path, 't': t, 'n': nonce, 'sig': signature})
        request = urllib.request.Request(
            self.endpoint + ('&' if '?' in self.endpoint else '?') + query, data=body, method='POST',
            headers={'Content-Type': 'application/octet-stream', 'User-Agent': 'cydia-repo-publish/2.0'})
        try:
            with urllib.request.urlopen(request, timeout=300) as response:
                status, payload = response.status, response.read()
        except urllib.error.HTTPError as e:
            status, payload = e.code, e.read()
        except (urllib.error.URLError, OSError) as e:
            die(f'нет связи с {self.endpoint}: {getattr(e, "reason", e)}')
        try:
            data = json.loads(payload.decode('utf-8'))
        except ValueError:
            if b'aes.js' in payload and b'__test' in payload:
                die('хостинг вместо ответа показывает JavaScript-проверку на робота (aes.js, cookie __test). '
                    'Так работают бесплатные хостинги на платформе iFastNet (InfinityFree, ByetHost и др.). '
                    'Эту проверку не проходит ни repo.py, ни сама Cydia, и на бесплатном тарифе она не отключается: '
                    'нужен хостинг без неё.')
            text = re.sub(r'\s+', ' ', re.sub(r'<[^>]*>', ' ', payload[:600].decode('utf-8', 'replace'))).strip()
            die(f'{self.endpoint} ответил не так, как ожидалось (HTTP {status}). '
                f'Папка server/ загружена на хостинг? "url" в config.json верный?\n  Ответ сервера: {text[:300]}')
        for warning in data.get('php_warnings', []):
            say('  ! PHP на хостинге: ' + warning)
        if not data.get('ok'):
            raise ApiError(data.get('error') or f'HTTP {status}')
        return data


def connect(cfg):
    """publish.php of the repo from config.json (or the "endpoint" saved by setup --endpoint)."""
    if not CLIENT_FILE.is_file():
        die('сначала выполните: python3 tools/repo.py setup')
    try:
        client = json.loads(CLIENT_FILE.read_text(encoding='utf-8'))
        return Api(client.get('endpoint') or cfg['url'] + 'publish.php', client['secret'])
    except (ValueError, KeyError, TypeError, AttributeError) as e:
        die(f'publish.json повреждён ({e}); удалите его и выполните setup заново')


def manifest(api, area):
    files = api.call('manifest', area).get('files')
    return files if isinstance(files, dict) else {}


def rebuild_hosting(api):
    result = api.call('build')
    for warning in result.get('warnings', []):
        say('  ! ' + warning)
    seconds = f'{float(result["seconds"]):.2f}'.replace('.', ',')
    say(f'Собрано на хостинге за {seconds} с: пакетов {result["packages"]}, '
        f'файлов .deb {result["debs"]} → {result["url"]}')


class HostingTarget:
    """PHP hosting: signed uploads to publish.php; the hosting rebuilds the site."""
    where = 'на сервере'
    stored = 'загружен'

    def __init__(self, cfg):
        self.cfg = cfg
        self.api = connect(cfg)

    def debs(self):
        return manifest(self.api, 'debs/')

    def files(self, area):
        return manifest(self.api, area)

    def put(self, path, data):
        self.api.call('put', path, data)

    def delete(self, path):
        self.api.call('delete', path)

    def finish(self, message, paths, build=True):
        if build:
            rebuild_hosting(self.api)


def open_target(cfg):
    return GitTarget(cfg) if cfg['deploy'] == 'github' else HostingTarget(cfg)


def sync_files(target, area, local, delete=True):
    """Make target's area hold exactly local ({path: Path}); returns (written, deleted)."""
    remote = target.files(area)
    written = removed = 0
    for path, file in sorted(local.items()):
        data = file.read_bytes()
        if remote.get(path, {}).get('sha256') != sha256(data):
            target.put(path, data)
            written += 1
    if delete:
        for path in sorted(remote):
            if path not in local:
                target.delete(path)
                removed += 1
    return written, removed


def check_site(cfg):
    """Fetch Release the way Cydia does and say what the site answers."""
    url = cfg['url'] + 'Release'
    try:
        request = urllib.request.Request(url, headers={'User-Agent': 'Telesphoreo APT-HTTP/1.0.592'})
        with urllib.request.urlopen(request, timeout=20) as response:
            status, body, final = response.status, response.read(4096), response.geturl()
    except urllib.error.HTTPError as e:
        hint = ': репозиторий ещё не собран или домен не привязан' if e.code == 404 else ''
        say(f'  сайт {cfg["url"]}: HTTP {e.code}{hint}')
        return
    except (urllib.error.URLError, OSError) as e:
        say(f'  сайт {cfg["url"]} недоступен: {getattr(e, "reason", e)}')
        return
    if b'aes.js' in body:
        say(f'  ! {cfg["url"]} всё ещё отвечает проверкой InfinityFree: DNS ещё не переключён (или не обновился)')
    elif body.startswith(b'Origin:') or b'\nLabel:' in body:
        date = next((l for l in body.decode('utf-8', 'replace').splitlines() if l.startswith('Date:')), '')
        say(f'  сайт работает: {cfg["url"]} ({date})')
        if url.startswith('http:') and final.startswith('https:'):
            say('  ! http перенаправляется на https: iOS 6 так не подключится. Выключите «Enforce HTTPS» в Settings → Pages.')
    else:
        say(f'  ! {url} ответил неожиданно (HTTP {status})')


# ------------------------------------------------------------- commands ---

def cmd_setup(args):
    cfg = load_config()
    if cfg['deploy'] == 'hosting':
        setup_hosting(args, cfg)
        return
    if not (ROOT / '.git').exists():
        git('init', '-q', '-b', 'main')
        say('Создан git-репозиторий (ветка main).')
    if args.remote:
        verb = 'set-url' if 'origin' in git('remote', capture=True).stdout.split() else 'add'
        git('remote', verb, 'origin', args.remote)
        say(f'Подключён GitHub: {args.remote}')
    if git('rev-parse', '-q', '--verify', 'HEAD', capture=True, check=False).returncode != 0:
        if git_commit('Cydia repo', ['.']):
            say('Сделан первый коммит.')
    remote = git('remote', 'get-url', 'origin', capture=True, check=False).stdout.strip()
    upstream, _ = git_upstream_state()
    host = urllib.parse.urlparse(cfg['url']).hostname
    owner = re.search(r'github\.com[/:]([^/]+)/', remote).group(1) if re.search(r'github\.com[/:]([^/]+)/', remote) else 'ЛОГИН'
    say()
    say('Дальше:')
    step = 1
    if not remote:
        say(f'  {step}. Создайте на github.com пустой публичный репозиторий (без README) и подключите его:')
        say('       python3 tools/repo.py setup --remote https://github.com/ЛОГИН/ИМЯ.git')
        step += 1
    if not upstream:
        say(f'  {step}. Отправьте проект: git push -u origin main')
        say('       GitHub попросит логин и вместо пароля токен с правами repo и workflow.')
        step += 1
    say(f'  {step}. В репозитории на GitHub: Settings → Pages → Source: GitHub Actions;')
    say(f'       Custom domain: {host}; галочку «Enforce HTTPS» снимите (для iOS 6).')
    say(f'  {step + 1}. В DNS домена (Cloudflare): CNAME {host.split(".")[0]} → {owner}.github.io, '
        'режим «DNS only» (серое облако).')


def setup_hosting(args, cfg):
    client = {}
    if CLIENT_FILE.is_file():
        try:
            client = json.loads(CLIENT_FILE.read_text(encoding='utf-8'))
        except ValueError:
            client = {}
    secret = str(client.get('secret', ''))
    new_key = args.new_key or not re.fullmatch(r'[0-9a-f]{64}', secret)
    if new_key:
        secret = secrets.token_hex(32)
    default = cfg['url'] + 'publish.php'
    custom = args.endpoint or client.get('endpoint')
    stored = {'secret': secret}
    if custom and custom != default:  # otherwise follow "url" from config.json
        stored['endpoint'] = custom
    CLIENT_FILE.write_text(json.dumps(stored, indent=2) + '\n', encoding='utf-8')
    os.chmod(CLIENT_FILE, 0o600)
    SECRET_FILE.write_text("<?php\n// Publishing key created by tools/repo.py setup. Keep it private.\n"
                           f"return '{secret}';\n", encoding='utf-8')
    say(f'Ключ публикации {"создан" if new_key else "сохранён"}: publish.json и server/repo/secret.php')
    say(f'Адрес публикации: {stored.get("endpoint", default)}')
    say()
    if new_key:
        say('Дальше:')
        say('  1. Загрузите содержимое папки server/ в корень сайта на хостинге (по FTP).')
        say('  2. python3 tools/repo.py sync     зальёт оформление и соберёт сайт')
    else:
        say('Ключ не менялся. Новый ключ: setup --new-key (потом перезалейте server/repo/secret.php).')


def cmd_status(args):
    cfg = load_config()
    if cfg['deploy'] == 'hosting':
        api = connect(cfg)
        info = api.call('status')
        built = (datetime.datetime.fromtimestamp(info['built']).strftime('%d.%m.%Y %H:%M')
                 if info.get('built') else 'ещё не было')
        say(f'Связь есть: {api.endpoint}')
        say(f'  PHP {info["php"]}, файлов .deb: {info["debs"]}, последняя сборка: {built}')
        say(f'  максимальный размер загрузки: {info["limits"]["post_max_size"]}')
        if not info.get('writable'):
            say('  ! PHP не может писать в папку сайта: поставьте на неё права 755')
    else:
        if not (ROOT / '.git').exists():
            say('git-репозиторий ещё не создан: python3 tools/repo.py setup')
        else:
            remote = git('remote', 'get-url', 'origin', capture=True, check=False).stdout.strip()
            upstream, ahead = git_upstream_state()
            state = 'не отправлялся' if not upstream else (f'не отправлено коммитов: {ahead}' if ahead else 'всё отправлено')
            say(f'GitHub: {remote or "не подключён (setup --remote URL)"}, {state}')
        say(f'  пакетов в debs/: {len(GitTarget(cfg).debs())}')
    check_site(cfg)


def cmd_sync(args):
    cfg = load_config()
    if cfg['deploy'] == 'github':
        GitTarget(cfg).finish('Update repo settings', ['.'])
        return
    target = HostingTarget(cfg)
    counts = [sync_files(target, 'config.json', {'config.json': CONFIG_FILE}, delete=False),
              sync_files(target, 'assets/', {'assets/CydiaIcon.png': ICON_FILE} if ICON_FILE.is_file() else {}),
              sync_files(target, 'theme/', {'theme/' + rel: f for rel, f in collect(
                  THEME_DIR, THEME_FILE, 'в theme/ можно только .css, .js и картинки').items()})]
    say(f'Настройки и оформление: загружено файлов {sum(c[0] for c in counts)}, удалено {sum(c[1] for c in counts)}')
    target.finish('', [])


def cmd_publish(args):
    cfg = load_config()
    target = open_target(cfg)
    source = Path(args.source).expanduser()
    theos = source if source.is_dir() else None
    depiction = Path(args.depiction).expanduser() if args.depiction else (theos / 'depiction' if theos else None)
    messages = [m.strip() for m in args.message if m.strip()]

    if args.depiction_only:
        package = theos_package_id(theos) if theos else None
        if not package:
            package = read_deb(resolve_deb(source).read_bytes())[1]['package']
        if not depiction or not depiction.is_dir():
            die(f'нет папки {depiction or "depiction/"} с описанием пакета')
        say(f'{package}: обновляю описание → {cfg["url"]}')
        commit = f'Update depiction of {package}'
    else:
        data = resolve_deb(source).read_bytes()
        members, fields = read_deb(data)
        package, version, arch = fields['package'], fields['version'], fields['architecture']
        if '+debug' in version and not args.allow_debug:
            die(f'{version} — отладочная сборка. Соберите с FINALPACKAGE=1 (или добавьте --allow-debug)')
        say(f'{fields.get("name") or package} {version} ({arch}) → {cfg["url"]}')
        members, notes = prepare_for_builder(members, arch)
        if notes:
            data = write_ar(members)
            say('  перепакован в gzip: ' + ', '.join(notes))
        path = 'debs/' + canonical_name(package, version, arch)
        if messages:
            if depiction is None:
                die('changelog некуда записать: укажите --depiction ПАПКА')
            add_changelog(depiction, version, messages)
        existing = target.debs()
        if existing.get(path, {}).get('sha256') == sha256(data):
            say(f'  этот пакет уже {target.where}')
        else:
            target.put(path, data)
            say(f'  {target.stored} {path} ({human_size(len(data))})')
        if args.replace:
            for other, info in sorted(existing.items()):
                if other != path and info.get('package') == package and info.get('arch') == arch:
                    target.delete(other)
                    say(f'  удалена старая версия {other}')
        commit = f'Publish {package} {version}'

    if depiction and depiction.is_dir():
        files = {f'packages/{package}/{rel}': f for rel, f in collect(
            depiction, DEPICTION_FILE, 'см. список разрешённых файлов в README (раздел «Описание пакета»)').items()}
        written, removed = sync_files(target, f'packages/{package}/', files)
        say(f'  описание: обновлено файлов {written}, удалено {removed}' if written or removed else '  описание без изменений')
    target.finish(commit, ['debs', 'packages'], build=not args.no_build)


def cmd_remove(args):
    cfg = load_config()
    target = open_target(cfg)
    targets = [p for p, info in sorted(target.debs().items())
               if info.get('package') == args.id and (args.version is None or info.get('version') == args.version)]
    if not args.version:
        targets += sorted(target.files(f'packages/{args.id}/'))
    if not targets:
        die(f'{target.where} нет пакета {args.id}' + (f' версии {args.version}' if args.version else ''))
    say(f'Будет удалено ({target.where}):')
    for path in targets:
        say('  ' + path)
    if not args.yes:
        try:
            answer = input('Удалить? [y/N] ').strip().lower()
        except EOFError:
            answer = ''
        if answer not in ('y', 'yes', 'д', 'да'):
            say('Отменено.')
            return
    for path in targets:
        target.delete(path)
    target.finish(f'Remove {args.id}' + (f' {args.version}' if args.version else ''), ['debs', 'packages'])


def cmd_rebuild(args):
    cfg = load_config()
    if cfg['deploy'] == 'hosting':
        rebuild_hosting(connect(cfg))
        return
    git_ready()
    committed = git_commit('Update repo', ['.'])
    upstream, ahead = git_upstream_state()
    if not committed and upstream and not ahead:  # nothing new: an empty commit still triggers a build
        require_identity()
        git('commit', '-q', '--allow-empty', '-m', 'Rebuild repo')
    git_push(cfg)


def main():
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, 'reconfigure'):
            stream.reconfigure(errors='replace')
    parser = argparse.ArgumentParser(prog='repo.py', description='Публикация в Cydia-репозиторий')
    sub = parser.add_subparsers(dest='command', required=True)

    p = sub.add_parser('setup', help='один раз: подготовить git (или ключ для PHP-хостинга)')
    p.add_argument('--remote', metavar='URL', help='адрес репозитория на GitHub (https://github.com/ЛОГИН/ИМЯ.git)')
    p.add_argument('--endpoint', help='PHP-хостинг: адрес publish.php, если он не совпадает с url из config.json')
    p.add_argument('--new-key', action='store_true', help='PHP-хостинг: сменить ключ (потом перезалить server/repo/secret.php)')
    p.set_defaults(func=cmd_setup)

    p = sub.add_parser('status', help='что опубликовано и отвечает ли сайт')
    p.set_defaults(func=cmd_status)

    p = sub.add_parser('sync', help='опубликовать изменения config.json, иконки и оформления')
    p.set_defaults(func=cmd_sync)

    p = sub.add_parser('publish', help='опубликовать пакет (файл .deb или папку Theos-проекта)')
    p.add_argument('source', metavar='DEB_ИЛИ_ПАПКА', nargs='?', default='.',
                   help='файл .deb или папка проекта (по умолчанию текущая)')
    p.add_argument('-m', '--message', action='append', default=[], help='строка changelog для этой версии (можно несколько)')
    p.add_argument('--depiction', metavar='ПАПКА', help='папка с описанием (по умолчанию <Theos-проект>/depiction)')
    p.add_argument('--depiction-only', action='store_true', help='обновить только описание, без пакета')
    p.add_argument('--replace', action='store_true', help='удалить старые версии этого пакета')
    p.add_argument('--allow-debug', action='store_true', help='разрешить публиковать debug-сборки')
    p.add_argument('--no-build', action='store_true', help='не пересобирать сайт (для GitHub: не отправлять)')
    p.set_defaults(func=cmd_publish)

    p = sub.add_parser('remove', help='удалить пакет (или одну его версию)')
    p.add_argument('id', metavar='ID_ПАКЕТА')
    p.add_argument('-V', '--version', help='удалить только эту версию')
    p.add_argument('-y', '--yes', action='store_true', help='не спрашивать подтверждение')
    p.set_defaults(func=cmd_remove)

    p = sub.add_parser('rebuild', help='пересобрать сайт (для GitHub: отправить и пересобрать)')
    p.set_defaults(func=cmd_rebuild)

    args = parser.parse_args()
    try:
        args.func(args)
    except DebError as e:
        die(str(e))
    except ApiError as e:
        die('хостинг ответил: ' + str(e))
    except KeyboardInterrupt:
        sys.exit(130)


if __name__ == '__main__':
    main()
