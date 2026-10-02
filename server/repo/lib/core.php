<?php
/*
 * Cydia repo builder: .deb reading, dpkg version ordering, Markdown.
 * Plain PHP 7.4+, only the zlib extension is required (bz2 is used when present).
 */
if (!defined('CYREPO')) {
    http_response_code(404);
    exit;
}

const CYREPO_VERSION = '2.0';
const CY_IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif'];

class CyError extends Exception
{
}

function cy_esc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cy_lower($text)
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function cy_len($text)
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./su', $text);
}

/** Write a file atomically (readers never see half a file). */
function cy_write($path, $data)
{
    $tmp = $path . '.tmp-' . getmypid();
    if (file_put_contents($tmp, $data) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new CyError('cannot write ' . basename($path) . ': check folder permissions');
    }
}

function cy_mkdir($dir)
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new CyError('cannot create folder ' . basename($dir) . ': check folder permissions');
    }
}

function cy_rmtree($dir)
{
    if (is_link($dir) || is_file($dir)) {
        unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $name) {
        if ($name !== '.' && $name !== '..') {
            cy_rmtree($dir . '/' . $name);
        }
    }
    rmdir($dir);
}

function cy_copytree($src, $dst)
{
    cy_mkdir($dst);
    foreach (scandir($src) as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }
        if (is_dir("$src/$name")) {
            cy_copytree("$src/$name", "$dst/$name");
        } elseif (!copy("$src/$name", "$dst/$name")) {
            throw new CyError("cannot copy $name");
        }
    }
}

/** Files below $dir as relative paths, skipping dot-files. */
function cy_list_files($dir, $prefix = '')
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $names = scandir($dir);
    foreach ($names as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }
        if (is_dir("$dir/$name")) {
            $out = array_merge($out, cy_list_files("$dir/$name", "$prefix$name/"));
        } elseif (is_file("$dir/$name")) {
            $out[] = $prefix . $name;
        }
    }
    return $out;
}

/** Read a UTF-8 text file (BOM and broken bytes tolerated). */
function cy_read($path)
{
    $text = (string)file_get_contents($path);
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
        $text = substr($text, 3);
    }
    return cy_utf8($text);
}

function cy_utf8($text)
{
    if (preg_match('//u', $text)) {
        return $text;
    }
    return htmlspecialchars_decode(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
}

// --------------------------------------------------------------- .deb ---

/** [[member name, bytes or null], ...]; only control.tar* is read into memory. */
function cy_read_ar($path)
{
    $f = @fopen($path, 'rb');
    if (!$f) {
        throw new CyError('cannot open file');
    }
    try {
        if (fread($f, 8) !== "!<arch>\n") {
            throw new CyError('not a .deb (ar archive)');
        }
        $end = filesize($path);
        $members = [];
        while (true) {
            $header = fread($f, 60);
            if ($header === false || trim($header) === '') {
                break;
            }
            $size = trim(substr($header, 48, 10));
            if (strlen($header) < 60 || substr($header, 58, 2) !== "`\n" || !preg_match('/^\d+$/', $size)) {
                throw new CyError('corrupted ar header');
            }
            $name = rtrim(trim(substr($header, 0, 16)), '/');
            $size = (int)$size;
            $data = null;
            if (strpos($name, 'control.tar') === 0) {
                $data = $size > 0 ? fread($f, $size) : '';
                if (strlen($data) !== $size) {
                    throw new CyError('file is truncated (incomplete upload?)');
                }
            } else {
                fseek($f, $size, SEEK_CUR);
            }
            if (ftell($f) > $end) {
                throw new CyError('file is truncated (incomplete upload?)');
            }
            if ($size % 2) {
                fseek($f, 1, SEEK_CUR);
            }
            $members[] = [$name, $data];
        }
        return $members;
    } finally {
        fclose($f);
    }
}

function cy_decompress_member($name, $data)
{
    $ext = (string)substr($name, strlen('control.tar'));
    if ($ext === '') {
        return $data;
    }
    if ($ext === '.gz') {
        $out = function_exists('gzdecode') ? @gzdecode($data) : false;
        if ($out === false) {
            throw new CyError("cannot unpack $name");
        }
        return $out;
    }
    if ($ext === '.bz2' && function_exists('bzdecompress')) {
        $out = bzdecompress($data);
        if (!is_string($out)) {
            throw new CyError("cannot unpack $name");
        }
        return $out;
    }
    if (in_array($ext, ['.bz2', '.xz', '.lzma', '.zst'], true)) {
        throw new CyError("$name can't be unpacked with PHP on this hosting; publish with tools/repo.py "
            . '(it repacks to gzip) or build with THEOS_PLATFORM_DEB_COMPRESSION_TYPE = gzip');
    }
    throw new CyError("unsupported control archive $name");
}

/** Contents of a regular file inside an uncompressed tar, or null. */
function cy_tar_find($tar, $wanted)
{
    $pos = 0;
    $len = strlen($tar);
    $long = null;
    while ($pos + 512 <= $len) {
        $h = substr($tar, $pos, 512);
        if (trim($h, "\0") === '') {
            break;
        }
        $name = rtrim(substr($h, 0, 100), "\0");
        $sizeField = trim(substr($h, 124, 12), " \0");
        $size = preg_match('/^[0-7]+$/', $sizeField) ? octdec($sizeField) : 0;
        $type = $h[156];
        if (substr($h, 257, 5) === 'ustar') {
            $prefix = rtrim(substr($h, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
        }
        $pos += 512;
        $data = (string)substr($tar, $pos, $size);
        $pos += (int)ceil($size / 512) * 512;
        if ($type === 'L') {
            $long = rtrim($data, "\0");
            continue;
        }
        if ($type === 'x') {
            if (preg_match('/\d+ path=([^\n]*)\n/', $data, $m)) {
                $long = $m[1];
            }
            continue;
        }
        if ($type === 'g') {
            continue;
        }
        if ($long !== null) {
            $name = $long;
            $long = null;
        }
        $clean = preg_replace('#^(\./)+#', '', $name);
        if (($type === '0' || $type === "\0") && $clean === $wanted) {
            return $data;
        }
    }
    return null;
}

function cy_parse_control($text)
{
    $fields = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') {
            if ($fields) {
                break;
            }
            continue;
        }
        if ($line[0] === ' ' || $line[0] === "\t") {
            if (!$fields) {
                throw new CyError('control file starts with a continuation line');
            }
            $fields[count($fields) - 1][1] .= "\n" . rtrim($line);
        } elseif (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $fields[] = [trim($key), trim($value)];
        } else {
            throw new CyError('malformed control line: ' . substr(trim($line), 0, 60));
        }
    }
    return $fields;
}

/** Ordered, case-insensitive view of a control stanza. */
class CyControl
{
    public $fields;

    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    public function get($key, $default = '')
    {
        foreach ($this->fields as $field) {
            if (strcasecmp($field[0], $key) === 0) {
                return $field[1];
            }
        }
        return $default;
    }

    public function set($key, $value)
    {
        foreach ($this->fields as $i => $field) {
            if (strcasecmp($field[0], $key) === 0) {
                $this->fields[$i][1] = $value;
                return;
            }
        }
        $this->fields[] = [$key, $value];
    }

    public function remove($key)
    {
        $this->fields = array_values(array_filter($this->fields, function ($f) use ($key) {
            return strcasecmp($f[0], $key) !== 0;
        }));
    }

    public function dump()
    {
        $out = '';
        foreach ($this->fields as $f) {  // Package first
            if (strcasecmp($f[0], 'Package') === 0) {
                $out .= "$f[0]: $f[1]\n";
            }
        }
        foreach ($this->fields as $f) {
            if (strcasecmp($f[0], 'Package') !== 0 && $f[1] !== '') {
                $out .= "$f[0]: $f[1]\n";
            }
        }
        return $out;
    }
}

class CyDeb
{
    public $path;
    public $rel;
    public $control;
    public $dataCompression;
    public $size;
    public $md5;
    public $sha1;
    public $sha256;
    public $date;

    public $package;
    public $version;
    public $arch;

    /** @param array $cache parsed-file cache, updated in place */
    public static function load($path, $rel, array &$cache)
    {
        clearstatcache(true, $path);
        $key = $rel . '|' . filesize($path) . '|' . filemtime($path);
        if (!isset($cache[$key])) {
            $cache[$key] = self::parse($path);
        }
        $info = $cache[$key];
        $deb = new self();
        $deb->path = $path;
        $deb->rel = $rel;
        $deb->control = new CyControl($info['control']);
        $deb->dataCompression = $info['data'];
        $deb->size = $info['size'];
        $deb->md5 = $info['md5'];
        $deb->sha1 = $info['sha1'];
        $deb->sha256 = $info['sha256'];
        $deb->date = filemtime($path);
        $deb->package = $deb->control->get('Package');
        $deb->version = $deb->control->get('Version');
        $deb->arch = $deb->control->get('Architecture');
        return $deb;
    }

    public static function parse($path)
    {
        $members = cy_read_ar($path);
        $names = array_column($members, 0);
        $control = null;
        $data = null;
        foreach ($members as $m) {
            if ($control === null && strpos($m[0], 'control.tar') === 0) {
                $control = $m;
            }
            if ($data === null && strpos($m[0], 'data.tar') === 0) {
                $data = $m[0];
            }
        }
        if (!in_array('debian-binary', $names, true) || $control === null || $data === null) {
            throw new CyError('not a valid .deb (debian-binary, control.tar or data.tar missing)');
        }
        $text = cy_tar_find(cy_decompress_member($control[0], $control[1]), 'control');
        if ($text === null) {
            throw new CyError('no control file inside control.tar');
        }
        $fields = cy_parse_control(cy_utf8($text));
        $c = new CyControl($fields);
        foreach (['Package', 'Version', 'Architecture'] as $required) {
            if ($c->get($required) === '') {
                throw new CyError("control file has no $required field");
            }
        }
        $parts = explode('.', $data, 3);
        return [
            'control' => $fields,
            'data' => isset($parts[2]) ? $parts[2] : 'none',
            'size' => filesize($path),
            'md5' => md5_file($path),
            'sha1' => sha1_file($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }
}

/** dpkg's canonical file name: package_version_arch.deb (epoch dropped). */
function cy_canonical_name($package, $version, $arch)
{
    $version = preg_replace('/^\d+:/', '', $version);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.+-]*$/', $package)
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.+~-]*$/', $version)
        || !preg_match('/^[A-Za-z0-9-]+$/', $arch)) {
        throw new CyError("unsupported characters in package/version/architecture: $package $version $arch");
    }
    return "{$package}_{$version}_{$arch}.deb";
}

// ------------------------------------------------- dpkg version ordering ---

function cy_char_order($c)
{
    if ($c === '') {
        return 0;
    }
    $o = ord($c);
    if ($o >= 48 && $o <= 57) {
        return 0;
    }
    if (($o >= 97 && $o <= 122) || ($o >= 65 && $o <= 90)) {
        return $o;
    }
    if ($c === '~') {
        return -1;
    }
    return $o + 256;
}

function cy_is_digit($s, $i)
{
    return $i < strlen($s) && ord($s[$i]) >= 48 && ord($s[$i]) <= 57;
}

/** Port of dpkg's verrevcmp(): '~' sorts before anything, letters before symbols. */
function cy_verrevcmp($a, $b)
{
    $i = $j = 0;
    $la = strlen($a);
    $lb = strlen($b);
    while ($i < $la || $j < $lb) {
        $firstDiff = 0;
        while (($i < $la && !cy_is_digit($a, $i)) || ($j < $lb && !cy_is_digit($b, $j))) {
            $ac = cy_char_order($i < $la ? $a[$i] : '');
            $bc = cy_char_order($j < $lb ? $b[$j] : '');
            if ($ac !== $bc) {
                return $ac - $bc;
            }
            $i++;
            $j++;
        }
        while ($i < $la && $a[$i] === '0') {
            $i++;
        }
        while ($j < $lb && $b[$j] === '0') {
            $j++;
        }
        while (cy_is_digit($a, $i) && cy_is_digit($b, $j)) {
            if (!$firstDiff) {
                $firstDiff = ord($a[$i]) - ord($b[$j]);
            }
            $i++;
            $j++;
        }
        if (cy_is_digit($a, $i)) {
            return 1;
        }
        if (cy_is_digit($b, $j)) {
            return -1;
        }
        if ($firstDiff) {
            return $firstDiff;
        }
    }
    return 0;
}

function cy_split_version($version)
{
    $version = trim($version);
    $epoch = 0;
    if (strpos($version, ':') !== false) {
        list($e, $version) = explode(':', $version, 2);
        $epoch = preg_match('/^\d+$/', $e) ? (int)$e : 0;
    }
    $dash = strrpos($version, '-');
    if ($dash === false) {
        return [$epoch, $version, ''];
    }
    return [$epoch, substr($version, 0, $dash), (string)substr($version, $dash + 1)];
}

function cy_version_compare($a, $b)
{
    list($ea, $ua, $ra) = cy_split_version($a);
    list($eb, $ub, $rb) = cy_split_version($b);
    if ($ea !== $eb) {
        return $ea < $eb ? -1 : 1;
    }
    $r = cy_verrevcmp($ua, $ub);
    return $r !== 0 ? $r : cy_verrevcmp($ra, $rb);
}

// ---------------------------------------------------- text and Markdown ---

function cy_md_inline($text)
{
    $stash = [];
    $keep = function ($html) use (&$stash) {
        $stash[] = $html;
        return "\x00" . (count($stash) - 1) . "\x00";
    };
    $text = str_replace("\x00", '', $text);
    $text = preg_replace_callback('/`([^`]+)`/u', function ($m) use ($keep) {
        return $keep('<code>' . cy_esc($m[1]) . '</code>');
    }, $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/u', function ($m) use ($keep) {
        if (preg_match('/^\s*(javascript|vbscript|data):/i', $m[2])) {
            return $m[1];
        }
        return $keep('<a href="' . cy_esc($m[2]) . '">' . cy_esc($m[1]) . '</a>');
    }, $text);
    $text = preg_replace_callback('/(?<![\w\/"=])(https?:\/\/[^\s<>()\[\]]*[^\s<>()\[\].,;:!?\'"])/u', function ($m) use ($keep) {
        return $keep('<a href="' . cy_esc($m[1]) . '">' . cy_esc($m[1]) . '</a>');
    }, $text);
    $text = cy_esc($text);
    $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![_\w])_(?=\S)(.+?)(?<=\S)_(?![_\w])/u', '<em>$1</em>', $text);
    $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $text);
    // \x00 as a PCRE escape: PHP < 8.2 rejects a raw NUL byte inside a pattern
    return preg_replace_callback('/\x00(\d+)\x00/', function ($m) use (&$stash) {
        return $stash[(int)$m[1]];
    }, $text);
}

/** Small Markdown subset: paragraphs, headings, lists, rules, bold/italic, code, links. */
function cy_markdown($text)
{
    $out = [];
    $para = [];
    $items = [];
    $listTag = null;
    $flush = function () use (&$out, &$para, &$items, &$listTag) {
        if ($para) {
            $out[] = '<p>' . implode('<br>', array_map('cy_md_inline', $para)) . '</p>';
            $para = [];
        }
        if ($items) {
            $html = '';
            foreach ($items as $item) {
                $html .= '<li>' . cy_md_inline($item) . '</li>';
            }
            $out[] = "<$listTag>$html</$listTag>";
            $items = [];
        }
        $listTag = null;
    };
    foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
        $line = trim($raw);
        if ($line === '') {
            $flush();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/u', $line, $m)) {
            $flush();
            $tag = strlen($m[1]) <= 2 ? 'h3' : 'h4';
            $out[] = "<$tag>" . cy_md_inline($m[2]) . "</$tag>";
            continue;
        }
        if (preg_match('/^([-*_])(\s*\1){2,}$/', $line)) {
            $flush();
            $out[] = '<hr>';
            continue;
        }
        $tag = null;
        if (preg_match('/^[-*+]\s+(.*)$/u', $line, $m)) {
            $tag = 'ul';
        } elseif (preg_match('/^\d{1,3}[.)]\s+(.*)$/u', $line, $m)) {
            $tag = 'ol';
        }
        if ($tag !== null) {
            if ($para || ($items && $listTag !== $tag)) {
                $flush();
            }
            $listTag = $tag;
            $items[] = $m[1];
            continue;
        }
        if ($items && ($raw[0] === ' ' || $raw[0] === "\t")) {
            $items[count($items) - 1] .= ' ' . $line;
            continue;
        }
        if ($items) {
            $flush();
        }
        $para[] = $line;
    }
    $flush();
    return implode("\n", $out);
}

function cy_plain_to_html($text)
{
    $html = [];
    foreach (preg_split('/\n\s*\n/u', trim($text)) as $p) {
        if (trim($p) === '') {
            continue;
        }
        $lines = array_map(function ($l) {
            return cy_md_inline(trim($l));
        }, explode("\n", $p));
        $html[] = '<p>' . implode('<br>', $lines) . '</p>';
    }
    return implode("\n", $html);
}

/** Control 'Description': first line is the synopsis, ' .' lines are blank lines. */
function cy_split_description($description)
{
    $lines = explode("\n", $description);
    $body = [];
    foreach (array_slice($lines, 1) as $line) {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
            $line = substr($line, 1);
        }
        $body[] = trim($line) === '.' ? '' : $line;
    }
    return [trim($lines[0]), trim(implode("\n", $body))];
}

/** '## 1.0.1 (2026-10-02)' headings followed by Markdown notes. */
function cy_parse_changelog($text)
{
    $entries = [];
    $current = -1;
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (preg_match('/^\s{0,3}#{1,3}\s+(.+?)\s*$/u', $line, $m)) {
            $parts = preg_split('/\s+/u', trim($m[1]), 2);
            if (in_array(cy_lower($parts[0]), ['version', 'версия'], true) && isset($parts[1])) {
                $parts = preg_split('/\s+/u', trim($parts[1]), 2);
            }
            $version = preg_replace('/^[vV](?=\d)/', '', $parts[0]);
            $date = isset($parts[1]) ? preg_replace('/^[\s\-–—()\[\]:]+|[\s\-–—()\[\]:]+$/u', '', $parts[1]) : '';
            $entries[] = ['version' => $version, 'date' => $date, 'body' => []];
            $current = count($entries) - 1;
        } elseif ($current >= 0) {
            $entries[$current]['body'][] = $line;
        }
    }
    foreach ($entries as $i => $entry) {
        $entries[$i]['body'] = trim(implode("\n", $entry['body']));
    }
    return $entries;
}

function cy_person_name($value)
{
    return trim(preg_replace('/\s*<[^>]*>\s*/u', ' ', (string)$value));
}

function cy_split_relations($value)
{
    $out = [];
    foreach (explode(',', (string)$value) as $r) {
        if (trim($r) !== '') {
            $out[] = trim($r);
        }
    }
    return $out;
}

/** 'firmware (>= 5.0), firmware (<< 7.0)' -> 'iOS 5.0 – 6.x'. */
function cy_firmware_range($depends, array $s)
{
    $low = null;
    $high = null;
    preg_match_all('/firmware\s*\(\s*(>=|>>|<=|<<|=)\s*([0-9][0-9.]*)\s*\)/', (string)$depends, $all, PREG_SET_ORDER);
    foreach ($all as $m) {
        if (in_array($m[1], ['>=', '>>', '='], true)) {
            $low = $m[2];
        }
        if (in_array($m[1], ['<=', '<<', '='], true)) {
            $high = [$m[1], $m[2]];
        }
    }
    if ($low === null && $high === null) {
        return '';
    }
    $top = null;
    if ($high !== null) {
        if ($high[0] === '<<' && preg_match('/^(\d+)(\.0+)*$/', $high[1], $m) && (int)$m[1] > 1) {
            $top = ((int)$m[1] - 1) . '.x';
        } elseif ($high[0] !== '<<') {
            $top = $high[1];
        }
    }
    if ($low !== null && $high !== null && $high[0] === '=') {
        return "iOS $low";
    }
    if ($low !== null && $high !== null) {
        return $top !== null ? "iOS $low – $top" : "iOS $low+, " . sprintf($s['ios_below'], $high[1]);
    }
    if ($low !== null) {
        return "iOS $low+";
    }
    return $top !== null ? "iOS ≤ $top" : 'iOS ' . sprintf($s['ios_below'], $high[1]);
}

function cy_image_size($path)
{
    $info = @getimagesize($path);
    return $info ? [(int)$info[0], (int)$info[1]] : null;
}

function cy_find_image($dir, $stem)
{
    foreach (CY_IMAGE_EXTS as $ext) {
        if (is_file("$dir/$stem.$ext")) {
            return "$dir/$stem.$ext";
        }
    }
    return null;
}

function cy_ext($path)
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}
