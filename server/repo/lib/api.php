<?php
/*
 * Publishing API used by tools/repo.py.
 *
 * Every request is a POST to publish.php?action=...&path=...&t=...&n=...&sig=...
 * with the file (if any) as the raw body. sig is
 *   HMAC-SHA256(secret, "cyrepo1\n{action}\n{path}\n{t}\n{n}\n{sha256(body)}")
 * so the secret never travels over the network, a captured request can't be
 * altered, and a nonce + 5 minute window stops replays. Only known file types
 * can be written, and never PHP: the API can't change the server code.
 */
if (!defined('CYREPO')) {
    http_response_code(404);
    exit;
}

class CyApiError extends Exception
{
    public $status;

    public function __construct($status, $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function cy_api($root)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // PHP notices must not leak into the JSON reply: collect them instead.
    @ini_set('display_errors', '0');
    $notes = [];
    set_error_handler(function ($level, $message, $file, $line) use (&$notes) {
        if (!(error_reporting() & $level)) {
            return false;
        }
        $notes[] = basename($file) . ":$line $message";
        return true;
    });
    ob_start();
    try {
        $result = cy_api_handle($root);
        $status = 200;
        $result = ['ok' => true] + $result;
    } catch (CyApiError $e) {
        $status = $e->status;
        $result = ['ok' => false, 'error' => $e->getMessage()];
    } catch (CyError $e) {
        $status = 422;
        $result = ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        $status = 500;
        $result = ['ok' => false, 'error' => 'server error: ' . $e->getMessage()];
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    if ($notes) {
        $result['php_warnings'] = array_slice(array_values(array_unique($notes)), 0, 20);
    }
    http_response_code($status);
    echo cy_json($result);
}

function cy_api_handle($root)
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new CyApiError(405, 'this address is for tools/repo.py');
    }
    $secret = cy_api_secret($root);
    $action = (string)($_GET['action'] ?? '');
    $path = (string)($_GET['path'] ?? '');
    $t = (string)($_GET['t'] ?? '');
    $nonce = (string)($_GET['n'] ?? '');
    $sig = (string)($_GET['sig'] ?? '');
    $body = (string)file_get_contents('php://input');

    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > 0 && strlen($body) < $declared) {
        throw new CyApiError(413, 'file is larger than this hosting accepts (post_max_size = '
            . ini_get('post_max_size') . ')');
    }
    if (!preg_match('/^[0-9a-f]{32}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
        throw new CyApiError(400, 'malformed request');
    }
    if (!preg_match('/^\d{1,12}$/', $t) || abs(time() - (int)$t) > 300) {
        throw new CyApiError(401, 'request expired: the clocks of this computer and the hosting differ by more than 5 minutes');
    }
    $expected = hash_hmac('sha256', implode("\n", ['cyrepo1', $action, $path, $t, $nonce, hash('sha256', $body)]), $secret);
    if (!hash_equals($expected, $sig)) {
        throw new CyApiError(401, 'bad signature: publish.json and repo/secret.php hold different keys');
    }
    cy_api_nonce($root, $nonce);

    switch ($action) {
        case 'status':
            return cy_api_status($root);
        case 'manifest':
            return ['files' => (object)cy_api_manifest($root, $path)];  // {} even when empty
        case 'put':
            return cy_api_put($root, $path, $body);
        case 'delete':
            return cy_api_delete($root, $path);
        case 'build':
            return cy_build($root);
    }
    throw new CyApiError(400, 'unknown action');
}

function cy_api_secret($root)
{
    $file = "$root/repo/secret.php";
    $secret = is_file($file) ? include $file : null;
    if (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
        throw new CyApiError(503, 'publishing is not set up: upload repo/secret.php (created by "repo.py setup")');
    }
    return $secret;
}

/** Remember used nonces for 15 minutes; a second use is a replay. */
function cy_api_nonce($root, $nonce)
{
    cy_mkdir("$root/repo/data");
    $f = fopen("$root/repo/data/nonces.json", 'c+');
    if (!$f || !flock($f, LOCK_EX)) {
        throw new CyError('cannot write repo/data/nonces.json: check folder permissions');
    }
    try {
        $seen = json_decode((string)stream_get_contents($f), true) ?: [];
        $now = time();
        foreach ($seen as $key => $time) {
            if ($time < $now - 900) {
                unset($seen[$key]);
            }
        }
        if (isset($seen[$nonce])) {
            throw new CyApiError(409, 'this request was already used');
        }
        $seen[$nonce] = $now;
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, json_encode($seen));
        fflush($f);
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/**
 * Map an API path to a file. Allowed:
 *   debs/<name>.deb           public packages (uploads must use the canonical name)
 *   config.json, assets/CydiaIcon.png, theme/...       site settings and design
 *   packages/<id>/...         depiction files: description, changelog, meta, images
 */
function cy_api_target($root, $path, $write)
{
    if ($path === '' || strlen($path) > 255 || preg_match('#(^|/)\.|\\\\|\x00|//|/$#', $path)) {
        throw new CyApiError(400, "path not allowed: $path");
    }
    $name = '[A-Za-z0-9_][A-Za-z0-9._+~-]*';
    if (preg_match($write ? "#^debs/$name\\.deb$#" : "#^debs/($name/)*$name\\.deb$#", $path)) {
        return ["$root/$path", 'deb'];
    }
    if ($path === 'config.json') {
        return ["$root/repo/config.json", 'config'];
    }
    if ($path === 'assets/CydiaIcon.png') {
        return ["$root/repo/assets/CydiaIcon.png", 'image'];
    }
    if (preg_match('#^theme/([A-Za-z0-9_-]+/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(css|js|png|jpe?g|gif)$#', $path)) {
        return ["$root/repo/$path", in_array(cy_ext($path), ['css', 'js'], true) ? 'text' : 'image'];
    }
    if (preg_match('#^packages/[A-Za-z0-9][A-Za-z0-9.+-]*/(screenshots/)?[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(md|html|json|png|jpe?g|gif)$#', $path)) {
        $ext = cy_ext($path);
        return ["$root/repo/$path", in_array($ext, CY_IMAGE_EXTS, true) ? 'image' : ($ext === 'json' ? 'json' : 'text')];
    }
    throw new CyApiError(400, "path not allowed: $path");
}

function cy_api_status($root)
{
    $debs = array_filter(cy_list_files("$root/debs"), function ($f) {
        return cy_ext($f) === 'deb';
    });
    return [
        'version' => CYREPO_VERSION,
        'php' => PHP_VERSION,
        'built' => is_file("$root/Release") ? filemtime("$root/Release") : null,
        'debs' => count($debs),
        'writable' => is_writable($root),
        'bz2' => function_exists('bzcompress'),
        'limits' => [
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ],
    ];
}

/** sha256 of every file under one of the API areas (+ package fields for debs). */
function cy_api_manifest($root, $prefix)
{
    if ($prefix === 'config.json' || $prefix === 'assets/CydiaIcon.png') {
        list($file) = cy_api_target($root, $prefix, false);
        return is_file($file) ? [$prefix => ['sha256' => hash_file('sha256', $file)]] : [];
    }
    $areas = ['debs/' => "$root/debs", 'theme/' => "$root/repo/theme", 'assets/' => "$root/repo/assets"];
    if (isset($areas[$prefix])) {
        $dir = $areas[$prefix];
    } elseif (preg_match('#^packages/[A-Za-z0-9][A-Za-z0-9.+-]*/$#', $prefix)) {
        $dir = "$root/repo/$prefix";
    } else {
        throw new CyApiError(400, "unknown manifest area: $prefix");
    }
    $cacheFile = "$root/repo/data/cache.json";
    $cache = is_file($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
    $files = [];
    foreach (cy_list_files($dir) as $rel) {
        $entry = ['sha256' => hash_file('sha256', "$dir/$rel")];
        if ($prefix === 'debs/') {
            if (cy_ext($rel) !== 'deb') {
                continue;
            }
            try {
                $deb = CyDeb::load("$dir/$rel", "debs/$rel", $cache);
                $entry += ['package' => $deb->package, 'version' => $deb->version, 'arch' => $deb->arch];
            } catch (CyError $e) {
                $entry['error'] = $e->getMessage();
            }
        }
        $files[$prefix . $rel] = $entry;
    }
    return $files;
}

function cy_api_put($root, $path, $body)
{
    list($file, $kind) = cy_api_target($root, $path, true);
    cy_mkdir(dirname($file));
    if ($kind === 'deb') {
        $tmp = dirname($file) . '/.upload-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $body) === false) {
            throw new CyError('cannot save the upload: check permissions of debs/');
        }
        try {
            $info = CyDeb::parse($tmp);
            $c = new CyControl($info['control']);
            $name = cy_canonical_name($c->get('Package'), $c->get('Version'), $c->get('Architecture'));
            if ("debs/$name" !== $path) {
                throw new CyError("this package must be uploaded as debs/$name");
            }
            if (is_file($file)) {
                $old = new CyControl(CyDeb::parse($file)['control']);
                if ($old->get('Version') !== $c->get('Version')) {
                    throw new CyError("debs/$name already holds version " . $old->get('Version'));
                }
            }
            if (!rename($tmp, $file)) {
                throw new CyError("cannot save debs/$name");
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
        return ['stored' => $path, 'package' => $c->get('Package'), 'version' => $c->get('Version')];
    }
    if ($kind === 'config' || $kind === 'json') {
        if (!is_array(json_decode($body, true))) {
            throw new CyError("$path is not valid JSON: " . json_last_error_msg());
        }
    }
    if ($kind === 'image' && !@getimagesizefromstring($body)) {
        throw new CyError("$path is not an image");
    }
    cy_write($file, $body);
    return ['stored' => $path];
}

function cy_api_delete($root, $path)
{
    list($file) = cy_api_target($root, $path, false);
    if (!is_file($file)) {
        return ['deleted' => false];
    }
    unlink($file);
    // drop folders left empty inside repo/packages/<id>/
    $stop = realpath("$root/repo/packages");
    $dir = dirname($file);
    while ($stop && strpos((string)realpath($dir), $stop . '/') === 0 && count(scandir($dir)) === 2) {
        rmdir($dir);
        $dir = dirname($dir);
    }
    return ['deleted' => true];
}
