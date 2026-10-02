<?php
/*
 * Builds the static repo from debs/ and repo/: Packages, Release,
 * depictions (HTML + Sileo) and the iOS 6 style website.
 */
if (!defined('CYREPO')) {
    http_response_code(404);
    exit;
}

const CY_DEFAULT_CONFIG = [
    'name' => 'My Repo',
    'description' => '',
    'url' => '',
    'language' => 'en',
    'maintainer' => '',
    'origin' => '',
    'label' => '',
    'suite' => 'stable',
    'codename' => 'ios',
    'version' => '1.0',
    'architectures' => ['iphoneos-arm'],
    'managers' => ['cydia', 'sileo', 'zebra', 'installer'],
    'links' => [],
    'footer' => '',
    'tint' => '#2463de',
    'search' => 'auto',
    'keep_old_versions' => true,
    'legacy_checks' => true,
];

// "Add source" links; {url} is the repository URL.
const CY_MANAGERS = [
    'cydia' => ['Cydia', 'cydia://url/https://cydia.saurik.com/api/share#?source={url}'],
    'sileo' => ['Sileo', 'sileo://source/{url}'],
    'zebra' => ['Zebra', 'zbra://sources/add/{url}'],
    'installer' => ['Installer', 'installer://add/repo={url}'],
];

const CY_STRINGS = [
    'en' => [
        'add_to' => 'Add to %s',
        'back' => 'Back',
        'other_managers' => 'Other Package Managers',
        'repo_url' => 'Repository URL',
        'search' => 'Search',
        'no_results' => 'No Results',
        'no_packages' => 'No packages yet',
        'featured' => 'Featured',
        'recent' => 'Recently Updated',
        'links' => 'Links',
        'description' => 'Description',
        'screenshots' => 'Screenshots',
        'whats_new' => "What's New",
        'version_history' => 'Version History',
        'information' => 'Information',
        'developer' => 'Developer',
        'maintainer' => 'Maintainer',
        'version' => 'Version',
        'updated' => 'Updated',
        'size' => 'Size',
        'section' => 'Section',
        'compatibility' => 'Compatibility',
        'architecture' => 'Architecture',
        'identifier' => 'Identifier',
        'depends' => 'Depends',
        'conflicts' => 'Conflicts',
        'price' => 'Price',
        'free' => 'Free',
        'downloads' => 'Downloads',
        'open_in_cydia' => 'Open in Cydia',
        'not_found' => 'Not Found',
        'not_found_text' => "The page you're looking for doesn't exist.",
        'back_to_repo' => 'Back to Repository',
        'details' => 'Details',
        'changelog' => 'Changelog',
        'ios_below' => 'below %s',
        'packages_count' => ['%d package', '%d packages'],
        'updated_on' => 'Updated %s',
        'units' => ['B', 'KB', 'MB', 'GB'],
        'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'date' => '{m} {d}, {y}',
        'short_date' => '{m} {d}',
    ],
    'ru' => [
        'add_to' => 'Добавить в %s',
        'back' => 'Назад',
        'other_managers' => 'Другие менеджеры пакетов',
        'repo_url' => 'Адрес репозитория',
        'search' => 'Поиск',
        'no_results' => 'Ничего не найдено',
        'no_packages' => 'Пакетов пока нет',
        'featured' => 'Рекомендуемые',
        'recent' => 'Недавно обновлённые',
        'links' => 'Ссылки',
        'description' => 'Описание',
        'screenshots' => 'Скриншоты',
        'whats_new' => 'Что нового',
        'version_history' => 'История версий',
        'information' => 'Информация',
        'developer' => 'Разработчик',
        'maintainer' => 'Сопровождающий',
        'version' => 'Версия',
        'updated' => 'Обновлено',
        'size' => 'Размер',
        'section' => 'Раздел',
        'compatibility' => 'Совместимость',
        'architecture' => 'Архитектура',
        'identifier' => 'Идентификатор',
        'depends' => 'Зависимости',
        'conflicts' => 'Конфликты',
        'price' => 'Цена',
        'free' => 'Бесплатно',
        'downloads' => 'Загрузки',
        'open_in_cydia' => 'Открыть в Cydia',
        'not_found' => 'Не найдено',
        'not_found_text' => 'Такой страницы нет.',
        'back_to_repo' => 'Вернуться в репозиторий',
        'details' => 'Подробнее',
        'changelog' => 'Изменения',
        'ios_below' => 'ниже %s',
        'packages_count' => ['%d пакет', '%d пакета', '%d пакетов'],
        'updated_on' => 'Обновлено %s',
        'units' => ['Б', 'КБ', 'МБ', 'ГБ'],
        'months' => ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                     'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
        'short_months' => ['янв.', 'февр.', 'марта', 'апр.', 'мая', 'июня',
                           'июля', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'],
        'date' => '{d} {m} {y}',
        'short_date' => '{d} {m}',
    ],
];

// Marks the page as opened inside a package manager (which draws its own
// navigation bar) or on an iOS device. Runs before first paint, ES3 only.
const CY_HEAD_SCRIPT = "(function(d,u){var c=' js';"
    . "if(/Cydia|Sileo|Zebra|Installer|Saily/.test(u))c+=' in-app';"
    . "if(/iPhone|iPad|iPod/.test(u))c+=' ios';"
    . "d.className+=c})(document.documentElement,navigator.userAgent);";

// Apache settings, kept between the markers in the site's .htaccess.
// HTTPS is deliberately not forced: iOS 5/6 can't connect to most modern TLS setups.
const CY_HTACCESS = <<<'TXT'
# BEGIN cydia-repo (rewritten on every build; put your own rules outside this block)
AddType application/vnd.debian.binary-package .deb
AddType application/x-bzip2 .bz2
AddType application/gzip .gz
AddType application/json .json
RemoveEncoding .gz .bz2
AddDefaultCharset utf-8
ErrorDocument 404 {base}404.html
<FilesMatch "^(Packages|Release)$">
  ForceType text/plain
</FilesMatch>
<FilesMatch "^\.user\.ini$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>
<IfModule mod_headers.c>
  <FilesMatch "^(Packages.*|Release|index\.html|.*\.json)$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
# END cydia-repo
TXT;

class CyLog
{
    public $warnings = [];

    public function warn($message)
    {
        $this->warnings[] = $message;
    }
}

function cy_json($data)
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}

function cy_load_config($repo)
{
    $file = "$repo/config.json";
    if (!is_file($file)) {
        throw new CyError('config.json is not on the server yet: run "python3 tools/repo.py sync"');
    }
    $user = json_decode(cy_read($file), true);
    if (!is_array($user)) {
        throw new CyError('config.json: ' . json_last_error_msg());
    }
    $cfg = CY_DEFAULT_CONFIG;
    foreach ($user as $key => $value) {
        if ($key !== '' && $key[0] !== '_') {
            $cfg[$key] = $value;
        }
    }
    $url = trim((string)$cfg['url']);
    if (!preg_match('#^https?://[^/]+#', $url)) {
        throw new CyError('config.json: "url" must look like http://repo.example.com/');
    }
    $cfg['url'] = substr($url, -1) === '/' ? $url : "$url/";
    if (!array_key_exists($cfg['language'], CY_STRINGS)) {
        $cfg['language'] = 'en';
    }
    $cfg['origin'] = $cfg['origin'] ?: $cfg['name'];
    $cfg['label'] = $cfg['label'] ?: $cfg['name'];
    if (is_string($cfg['architectures'])) {
        $cfg['architectures'] = preg_split('/\s+/', trim($cfg['architectures']));
    }
    foreach (['managers', 'links', 'architectures'] as $key) {
        if (!is_array($cfg[$key])) {
            $cfg[$key] = [];
        }
    }
    return $cfg;
}

// --------------------------------------------------------------- package ---

/** All .deb files sharing one package id, plus metadata from repo/packages/<id>/. */
class CyPackage
{
    public $id;
    public $debs;
    public $latest;
    public $meta = [];
    public $version;
    public $name;
    public $tagline;
    public $author;
    public $maintainer;
    public $section;
    public $hidden;
    public $featured;
    public $tint;
    public $price;
    public $links;
    public $compatibility;
    public $depends;
    public $conflicts;
    public $archs;
    public $date;
    public $descriptionHtml;
    public $descriptionMd;
    public $changelog = [];
    public $page;
    public $icon;
    public $banner;
    public $iconUrl;
    public $bannerUrl;
    public $shots = [];

    public function __construct($pid, array $debs, array $cfg, array $s, $metaRoot, CyLog $log)
    {
        usort($debs, function ($a, $b) {
            $r = cy_version_compare($b->version, $a->version);
            return $r !== 0 ? ($r < 0 ? -1 : 1) : strcmp($a->arch, $b->arch);
        });
        $this->id = $pid;
        $this->debs = $debs;
        $this->latest = $debs[0];
        $control = $this->latest->control;
        $dir = "$metaRoot/$pid";

        if (is_file("$dir/meta.json")) {
            $meta = json_decode(cy_read("$dir/meta.json"), true);
            if (is_array($meta)) {
                $this->meta = $meta;
            } else {
                $log->warn("packages/$pid/meta.json: " . json_last_error_msg() . ' (ignored)');
            }
        }

        list($short, $long) = cy_split_description($control->get('Description'));
        $this->version = $this->latest->version;
        $this->name = $this->text('name') ?: ($control->get('Name') ?: $pid);
        $this->tagline = $this->text('tagline') ?: $short;
        $this->author = $this->text('author') ?: (cy_person_name($control->get('Author'))
            ?: (cy_person_name($control->get('Maintainer')) ?: cy_person_name($cfg['maintainer'])));
        $this->maintainer = cy_person_name($control->get('Maintainer')) ?: cy_person_name($cfg['maintainer']);
        $this->section = str_replace('_', ' ', $control->get('Section') ?: 'Tweaks');
        $this->hidden = !empty($this->meta['hidden']);
        $this->featured = !empty($this->meta['featured']);
        $this->tint = $this->text('tint') ?: $cfg['tint'];
        $this->price = $this->text('price');
        $this->links = [];
        if (isset($this->meta['links']) && is_array($this->meta['links'])) {
            foreach ($this->meta['links'] as $link) {
                if (is_array($link) && !empty($link['url'])) {
                    $this->links[] = $link;
                }
            }
        }
        $this->compatibility = $this->text('compatible') ?: cy_firmware_range($control->get('Depends'), $s);
        $this->depends = array_values(array_filter(cy_split_relations($control->get('Depends')), function ($d) {
            return strpos($d, 'firmware') !== 0;
        }));
        $this->conflicts = cy_split_relations($control->get('Conflicts'));
        $archs = [];
        $this->date = 0;
        foreach ($debs as $d) {
            if ($d->version === $this->version) {
                $archs[$d->arch] = true;
                $this->date = max($this->date, $d->date);
            }
        }
        $this->archs = array_keys($archs);
        sort($this->archs);

        // Description: description.html > description.md > control Description.
        if (is_file("$dir/description.html")) {
            $this->descriptionHtml = cy_read("$dir/description.html");
        } elseif (is_file("$dir/description.md")) {
            $this->descriptionMd = trim(cy_read("$dir/description.md"));
            $this->descriptionHtml = cy_markdown($this->descriptionMd);
        } else {
            $this->descriptionMd = $long !== '' ? $long : $short;
            $this->descriptionHtml = cy_plain_to_html($this->descriptionMd);
        }
        if (is_file("$dir/changelog.md")) {
            $this->changelog = cy_parse_changelog(cy_read("$dir/changelog.md"));
        }

        // Files copied next to the depiction: icon, banner, screenshots/NN.ext
        $this->page = 'depictions/' . str_replace('%2B', '+', rawurlencode($pid)) . '/';
        $this->icon = cy_find_image($dir, 'icon');
        $this->banner = cy_find_image($dir, 'banner');
        $this->iconUrl = $this->icon ? $this->page . 'icon.' . cy_ext($this->icon) : null;
        $this->bannerUrl = $this->banner ? $this->page . 'banner.' . cy_ext($this->banner) : null;
        $files = [];
        if (is_dir("$dir/screenshots")) {
            foreach (scandir("$dir/screenshots") as $name) {
                if ($name[0] !== '.' && in_array(cy_ext($name), CY_IMAGE_EXTS, true) && is_file("$dir/screenshots/$name")) {
                    $files[] = $name;
                }
            }
            usort($files, 'strnatcasecmp');
        }
        foreach ($files as $i => $name) {
            $path = "$dir/screenshots/$name";
            $this->shots[] = [$path, sprintf('screenshots/%02d.%s', $i + 1, cy_ext($name)), cy_image_size($path) ?: [320, 480]];
        }
    }

    public function text($key)
    {
        $value = isset($this->meta[$key]) ? $this->meta[$key] : null;
        return is_scalar($value) && $value !== false ? trim((string)$value) : '';
    }

    public function copyAssets($folder)
    {
        if ($this->icon) {
            copy($this->icon, "$folder/icon." . cy_ext($this->icon));
        }
        if ($this->banner) {
            copy($this->banner, "$folder/banner." . cy_ext($this->banner));
        }
        if ($this->shots) {
            cy_mkdir("$folder/screenshots");
            foreach ($this->shots as $shot) {
                copy($shot[0], "$folder/$shot[1]");
            }
        }
    }
}

// ------------------------------------------------------------------ site ---

class CySite
{
    public $cfg;
    public $lang;
    public $s;
    public $packages;
    public $visible;
    public $assetVersion;

    public function __construct(array $cfg, array $packages, $themeDir)
    {
        $this->cfg = $cfg;
        $this->lang = $cfg['language'];
        $this->s = CY_STRINGS[$this->lang];
        $this->packages = $packages;
        $this->visible = array_values(array_filter($packages, function ($p) {
            return !$p->hidden;
        }));
        $ctx = hash_init('sha1');
        foreach (['style.css', 'app.js'] as $name) {
            if (is_file("$themeDir/$name")) {
                hash_update($ctx, file_get_contents("$themeDir/$name"));
            }
        }
        $this->assetVersion = substr(hash_final($ctx), 0, 8);
    }

    // formatting
    public function date($ts)
    {
        return strtr($this->s['date'], [
            '{d}' => gmdate('j', $ts),
            '{m}' => $this->s['months'][(int)gmdate('n', $ts) - 1],
            '{y}' => gmdate('Y', $ts),
        ]);
    }

    public function shortDate($ts)
    {
        $months = isset($this->s['short_months']) ? $this->s['short_months'] : $this->s['months'];
        $text = strtr($this->s['short_date'], ['{d}' => gmdate('j', $ts), '{m}' => $months[(int)gmdate('n', $ts) - 1]]);
        if (gmdate('Y', $ts) !== gmdate('Y')) {
            $text .= ($this->lang === 'ru' ? ' ' : ', ') . gmdate('Y', $ts);
        }
        return $text;
    }

    public function entryDate(array $entry)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $entry['date'], $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $this->date(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]));
        }
        return $entry['date'];
    }

    public function size($n)
    {
        $units = $this->s['units'];
        $value = (float)$n;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        $text = $i === 0 ? ((int)$value . ' ' . $units[0]) : (str_replace('.0', '', sprintf('%.1f', $value)) . ' ' . $units[$i]);
        return $this->lang === 'ru' ? str_replace('.', ',', $text) : $text;
    }

    public function count($n)
    {
        $forms = $this->s['packages_count'];
        if (count($forms) === 3) {
            if ($n % 10 === 1 && $n % 100 !== 11) {
                $form = $forms[0];
            } elseif ($n % 10 >= 2 && $n % 10 <= 4 && !($n % 100 >= 12 && $n % 100 <= 14)) {
                $form = $forms[1];
            } else {
                $form = $forms[2];
            }
        } else {
            $form = $n === 1 ? $forms[0] : $forms[1];
        }
        return sprintf($form, $n);
    }

    // building blocks
    public function layout($title, $body, $root, $navTitle, $back = null, $top = '')
    {
        $backHtml = '';
        $h1 = '<h1>';
        if ($back) {
            $text = cy_len($back[1]) <= 11 ? $back[1] : $this->s['back'];  // iOS falls back to "Back" too
            $backHtml = '<a class="back" href="' . cy_esc($back[0]) . '"><span>' . cy_esc($text) . '</span></a>';
            if (cy_len($navTitle) > 11) {
                $h1 = '<h1 class="small">';
            }
        }
        $v = $this->assetVersion;
        $r = cy_esc($root);
        return "<!DOCTYPE html>\n<html lang=\"{$this->lang}\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"format-detection\" content=\"telephone=no\">\n"
            . '<title>' . cy_esc($title) . "</title>\n"
            . "<link rel=\"stylesheet\" href=\"{$r}assets/style.css?v=$v\">\n"
            . "<link rel=\"icon\" type=\"image/png\" href=\"{$r}CydiaIcon.png\">\n"
            . "<link rel=\"apple-touch-icon\" href=\"{$r}CydiaIcon.png\">\n"
            . '<script>' . CY_HEAD_SCRIPT . "</script>\n</head>\n<body ontouchstart=\"\">\n"
            . "<div class=\"navbar\">$backHtml$h1" . cy_esc($navTitle) . "</h1></div>\n"
            . "$top<div class=\"page\">\n$body\n</div>\n"
            . "<script src=\"{$r}assets/app.js?v=$v\"></script>\n</body>\n</html>\n";
    }

    public static function label($text)
    {
        return '<div class="label">' . cy_esc($text) . '</div>';
    }

    public static function group($inner, $cls = '')
    {
        return '<div class="group' . ($cls !== '' ? " $cls" : '') . "\">$inner</div>";
    }

    public static function linkRow($href, $title, $detail = '', $sub = '')
    {
        $detail = $detail !== '' ? '<span class="detail">' . cy_esc($detail) . '</span>' : '';
        $sub = $sub !== '' ? '<span class="sub">' . cy_esc($sub) . '</span>' : '';
        return '<a class="row" href="' . cy_esc($href) . "\">$detail<span class=\"title\">" . cy_esc($title) . "</span>$sub</a>";
    }

    public static function infoRow($title, $value)
    {
        if ($value === '' || $value === null) {
            return '';
        }
        if (cy_len($title) * 10 + cy_len($value) * 8.6 > 265) {  // rough px width at 17px Helvetica
            return '<div class="row stacked"><span class="title">' . cy_esc($title) . '</span>'
                . '<span class="value">' . cy_esc($value) . '</span></div>';
        }
        return '<div class="row"><span class="detail">' . cy_esc($value) . '</span><span class="title">' . cy_esc($title) . '</span></div>';
    }

    public function pkgRow(CyPackage $p, $root = '', $searchable = false, $showDate = false)
    {
        $icon = $root . ($p->iconUrl ?: 'assets/img/package.png');
        $attrs = '';
        if ($searchable) {
            $words = cy_lower(implode(' ', [$p->name, $p->id, $p->tagline, $p->author, $p->section]));
            $attrs = ' data-search="' . cy_esc($words) . '"';
        }
        $detail = $showDate ? $this->shortDate($p->date) : $p->version;
        return '<a class="row pkg" href="' . cy_esc($root . $p->page) . "\"$attrs>"
            . '<img class="icon" src="' . cy_esc($icon) . '" width="40" height="40" alt="">'
            . '<span class="detail">' . cy_esc($detail) . '</span>'
            . '<span class="title">' . cy_esc($p->name) . '</span>'
            . '<span class="sub">' . cy_esc($p->tagline) . '</span></a>';
    }

    public static function footer($inner)
    {
        return $inner !== '' ? "<div class=\"footer\">$inner</div>" : '';
    }

    public function info(CyPackage $p)
    {
        $s = $this->s;
        return [
            [$s['developer'], $p->author],
            [$s['maintainer'], $p->maintainer !== $p->author ? $p->maintainer : ''],
            [$s['version'], $p->version],
            [$s['updated'], $this->date($p->date)],
            [$s['size'], $this->size($p->latest->size)],
            [$s['section'], $p->section],
            [$s['compatibility'], $p->compatibility],
            [$s['architecture'], implode(', ', $p->archs)],
            [$s['identifier'], $p->id],
            [$s['depends'], implode(', ', $p->depends)],
            [$s['conflicts'], implode(', ', $p->conflicts)],
            [$s['price'], $p->price !== '' ? $p->price : $s['free']],
        ];
    }

    // pages
    public function renderIndex()
    {
        $s = $this->s;
        $cfg = $this->cfg;
        $url = $cfg['url'];
        $visible = $this->visible;
        $hide = ' data-hide-on-search=""';
        $parts = [];

        $desc = $cfg['description'] !== '' ? '<p>' . cy_esc($cfg['description']) . '</p>' : '';
        $parts[] = "<div class=\"group profile\"$hide><img class=\"icon\" src=\"CydiaIcon.png\" width=\"62\" height=\"62\" alt=\"\">"
            . '<h2>' . cy_esc($cfg['name']) . "</h2>$desc<p class=\"meta\">" . cy_esc($this->count(count($visible))) . '</p></div>';

        $managers = array_values(array_filter($cfg['managers'], function ($m) {
            return is_string($m) && array_key_exists($m, CY_MANAGERS);
        }));
        $add = '';
        if (in_array('cydia', $managers, true)) {
            $add .= '<a class="button" href="' . cy_esc(str_replace('{url}', $url, CY_MANAGERS['cydia'][1])) . '">'
                . cy_esc(sprintf($s['add_to'], 'Cydia')) . '</a>';
        }
        $rows = '';
        foreach ($managers as $m) {
            if ($m !== 'cydia') {
                $rows .= self::linkRow(str_replace('{url}', $url, CY_MANAGERS[$m][1]), CY_MANAGERS[$m][0]);
            }
        }
        if ($rows !== '') {
            $add .= self::label($s['other_managers']) . self::group($rows);
        }
        $add .= self::label($s['repo_url']) . self::group('<div class="row url">' . cy_esc($url) . '</div>');
        $parts[] = "<div$hide>$add</div>";

        $featured = '';
        foreach ($visible as $p) {
            if ($p->featured) {
                $featured .= $this->pkgRow($p);
            }
        }
        if ($featured !== '') {
            $parts[] = "<div$hide>" . self::label($s['featured']) . self::group($featured) . '</div>';
        }
        if (count($visible) > 6) {
            $recent = $visible;
            usort($recent, function ($a, $b) {
                return $b->date - $a->date;
            });
            $rows = '';
            foreach (array_slice($recent, 0, 3) as $p) {
                $rows .= $this->pkgRow($p, '', false, true);
            }
            $parts[] = "<div$hide>" . self::label($s['recent']) . self::group($rows) . '</div>';
        }

        $sections = [];
        foreach ($visible as $p) {
            $sections[$p->section][] = $p;
        }
        uksort($sections, function ($a, $b) {
            return strcmp(cy_lower($a), cy_lower($b));
        });
        foreach ($sections as $name => $list) {
            usort($list, function ($a, $b) {
                return strcmp(cy_lower($a->name), cy_lower($b->name));
            });
            $rows = '';
            foreach ($list as $p) {
                $rows .= $this->pkgRow($p, '', true);
            }
            $parts[] = '<div data-section="">' . self::label($name) . self::group($rows) . '</div>';
        }
        if (!$visible) {
            $parts[] = self::group('<div class="row empty">' . cy_esc($s['no_packages']) . '</div>');
        }
        $parts[] = '<div id="no-results" style="display:none">'
            . self::group('<div class="row empty">' . cy_esc($s['no_results']) . '</div>') . '</div>';

        $rows = '';
        foreach ($cfg['links'] as $link) {
            if (is_array($link) && !empty($link['url'])) {
                $rows .= self::linkRow($link['url'], !empty($link['title']) ? $link['title'] : $link['url'],
                    isset($link['detail']) ? (string)$link['detail'] : '');
            }
        }
        if ($rows !== '') {
            $parts[] = "<div$hide>" . self::label($s['links']) . self::group($rows) . '</div>';
        }

        $foot = $cfg['footer'] !== '' ? [cy_md_inline((string)$cfg['footer'])] : [];
        if ($visible) {
            $latest = max(array_map(function ($p) {
                return $p->date;
            }, $visible));
            $foot[] = cy_esc(sprintf($s['updated_on'], $this->date($latest)));
        }
        $parts[] = self::footer(implode('<br>', $foot));

        $top = '';
        if ($visible && ($cfg['search'] === true || ($cfg['search'] === 'auto' && count($visible) > 5))) {
            $top = '<div class="searchbar"><div class="field"><input id="search" type="search" '
                . 'placeholder="' . cy_esc($s['search']) . '" autocomplete="off" autocorrect="off" '
                . "autocapitalize=\"off\" spellcheck=\"false\"></div></div>\n";
        }
        return $this->layout($cfg['name'], implode("\n", $parts), '', $cfg['name'], null, $top);
    }

    public function renderPackage(CyPackage $p)
    {
        $s = $this->s;
        $cfg = $this->cfg;
        $root = '../../';
        $icon = $root . ($p->iconUrl ?: 'assets/img/package.png');
        $parts = [];
        $metaLine = implode(' · ', array_filter([$p->version, $p->section], 'strlen'));
        $author = $p->author !== '' ? '<p>' . cy_esc($p->author) . '</p>' : '';
        $parts[] = '<div class="group profile web-only"><img class="icon" src="' . cy_esc($icon) . '" width="62" height="62" alt="">'
            . '<h2>' . cy_esc($p->name) . "</h2>$author<p class=\"meta\">" . cy_esc($metaLine) . '</p></div>';
        $parts[] = '<a class="button web-only ios-only" href="cydia://package/' . cy_esc(str_replace('%2B', '+', rawurlencode($p->id))) . '">'
            . cy_esc($s['open_in_cydia']) . '</a>';

        if ($p->descriptionHtml !== '' && $p->descriptionHtml !== null) {
            $parts[] = self::label($s['description']) . self::group('<div class="text">' . $p->descriptionHtml . '</div>');
        }
        if ($p->shots) {
            $imgs = '';
            foreach ($p->shots as $shot) {
                list($w, $h) = $shot[2];
                $imgs .= '<img src="' . cy_esc($shot[1]) . '" width="' . ($h ? (int)round($w * 280 / $h) : 187) . '" height="280" alt="">';
            }
            $parts[] = self::label($s['screenshots']) . "<div class=\"shots\">$imgs</div>";
        }
        if ($p->changelog) {
            $entry = $p->changelog[0];
            $date = $entry['date'] !== '' ? ' <span class="date">' . cy_esc($this->entryDate($entry)) . '</span>' : '';
            $inner = '<div class="text"><p class="version-head"><strong>' . cy_esc($entry['version']) . "</strong>$date</p>"
                . cy_markdown($entry['body']) . '</div>';
            if (count($p->changelog) > 1) {
                $inner .= self::linkRow('changelog.html', $s['version_history']);
            }
            $parts[] = self::label($s['whats_new']) . self::group($inner);
        }

        $rows = '';
        foreach ($this->info($p) as $row) {
            $rows .= self::infoRow($row[0], $row[1]);
        }
        $parts[] = self::label($s['information']) . self::group($rows);
        if ($p->links) {
            $rows = '';
            foreach ($p->links as $link) {
                $rows .= self::linkRow($link['url'], !empty($link['title']) ? $link['title'] : $link['url']);
            }
            $parts[] = self::label($s['links']) . self::group($rows);
        }
        $rows = '';
        foreach (array_slice($p->debs, 0, 10) as $d) {
            $rows .= self::linkRow($root . cy_url_path($d->rel), $d->version, $this->size($d->size), $d->arch);
        }
        $parts[] = '<div class="web-only">' . self::label($s['downloads']) . self::group($rows) . '</div>';
        $parts[] = self::footer('<a href="' . $root . '">' . cy_esc($cfg['name']) . '</a>');
        return $this->layout("{$p->name} · {$cfg['name']}", implode("\n", $parts), $root, $p->name, [$root, $cfg['name']]);
    }

    public function renderChangelog(CyPackage $p)
    {
        $s = $this->s;
        $parts = [];
        foreach ($p->changelog as $entry) {
            $title = $entry['version'] . ($entry['date'] !== '' ? ' · ' . $this->entryDate($entry) : '');
            $body = $entry['body'] !== '' ? cy_markdown($entry['body']) : '<p>—</p>';
            $parts[] = self::label($title) . self::group("<div class=\"text\">$body</div>");
        }
        $parts[] = self::footer('<a href="../../">' . cy_esc($this->cfg['name']) . '</a>');
        return $this->layout("{$s['version_history']} · {$p->name}", implode("\n", $parts), '../../',
            $s['version_history'], ['./', $p->name]);
    }

    public function render404()
    {
        $s = $this->s;
        $base = parse_url($this->cfg['url'], PHP_URL_PATH) ?: '/';  // absolute: 404.html is served at any path
        $body = self::group('<div class="text center"><p>' . cy_esc($s['not_found_text']) . '</p></div>')
            . self::group(self::linkRow($base, $s['back_to_repo']));
        return $this->layout($s['not_found'], $body, $base, $s['not_found']);
    }

    // Sileo native depiction, see https://developer.getsileo.app/native-depictions
    public function sileoDepiction(CyPackage $p)
    {
        $s = $this->s;
        $url = $this->cfg['url'];
        $views = [];
        if ($p->descriptionMd !== null && $p->descriptionMd !== '') {
            $views[] = ['class' => 'DepictionMarkdownView', 'markdown' => $p->descriptionMd, 'useSpacing' => true];
        } elseif ($p->descriptionHtml !== '' && $p->descriptionHtml !== null) {
            $views[] = ['class' => 'DepictionMarkdownView', 'markdown' => $p->descriptionHtml, 'useRawFormat' => true];
        }
        if ($p->shots) {
            list($w, $h) = $p->shots[0][2];
            $shots = [];
            foreach ($p->shots as $shot) {
                $shots[] = ['url' => $url . $p->page . $shot[1], 'accessibilityText' => $s['screenshots']];
            }
            $views[] = [
                'class' => 'DepictionScreenshotsView',
                'itemCornerRadius' => 8,
                'itemSize' => '{160, ' . ($w ? (int)round(160 * $h / $w) : 240) . '}',
                'screenshots' => $shots,
            ];
        }
        $views[] = ['class' => 'DepictionSeparatorView'];
        $views[] = ['class' => 'DepictionHeaderView', 'title' => $s['information'], 'useBoldText' => true];
        foreach ($this->info($p) as $row) {
            if ($row[1] !== '' && !in_array($row[0], [$s['identifier'], $s['depends'], $s['conflicts']], true)) {
                $views[] = ['class' => 'DepictionTableTextView', 'title' => $row[0], 'text' => $row[1]];
            }
        }
        foreach ($p->links as $link) {
            $views[] = ['class' => 'DepictionTableButtonView', 'title' => !empty($link['title']) ? $link['title'] : $link['url'],
                        'action' => $link['url'], 'openExternal' => true];
        }
        $tabs = [['tabname' => $s['details'], 'class' => 'DepictionStackView', 'views' => $views]];
        if ($p->changelog) {
            $log = [];
            foreach ($p->changelog as $entry) {
                $title = $entry['version'] . ($entry['date'] !== '' ? ' · ' . $this->entryDate($entry) : '');
                $log[] = ['class' => 'DepictionSubheaderView', 'title' => $title, 'useBoldText' => true];
                if ($entry['body'] !== '') {
                    $log[] = ['class' => 'DepictionMarkdownView', 'markdown' => $entry['body'], 'useSpacing' => true];
                }
                $log[] = ['class' => 'DepictionSeparatorView'];
            }
            $tabs[] = ['tabname' => $s['changelog'], 'class' => 'DepictionStackView', 'views' => $log];
        }
        $depiction = ['minVersion' => '0.1', 'class' => 'DepictionTabView', 'tintColor' => $p->tint, 'tabs' => $tabs];
        if ($p->bannerUrl) {
            $depiction['headerImage'] = $url . $p->bannerUrl;
        }
        return $depiction;
    }

    public function sileoFeatured()
    {
        $banners = [];
        foreach ($this->visible as $p) {
            if ($p->featured && $p->bannerUrl) {
                $banners[] = ['url' => $this->cfg['url'] . $p->bannerUrl, 'title' => $p->name, 'package' => $p->id, 'hideShadow' => false];
            }
        }
        if (!$banners) {
            return null;
        }
        return ['class' => 'FeaturedBannersView', 'itemSize' => '{263, 148}', 'itemCornerRadius' => 8, 'banners' => $banners];
    }

    public function packagesJson()
    {
        $url = $this->cfg['url'];
        $list = [];
        foreach ($this->packages as $p) {
            $debs = [];
            foreach ($p->debs as $d) {
                $debs[] = ['version' => $d->version, 'architecture' => $d->arch, 'url' => $url . cy_url_path($d->rel),
                           'size' => $d->size, 'sha256' => $d->sha256];
            }
            $list[] = [
                'id' => $p->id,
                'name' => $p->name,
                'version' => $p->version,
                'section' => $p->section,
                'author' => $p->author,
                'description' => $p->tagline,
                'depiction' => $url . $p->page,
                'icon' => $url . ($p->iconUrl ?: 'assets/img/package.png'),
                'hidden' => $p->hidden,
                'updated' => gmdate('Y-m-d', $p->date),
                'debs' => $debs,
            ];
        }
        return ['name' => $this->cfg['name'], 'url' => $url, 'packages' => $list];
    }
}

function cy_url_path($rel)
{
    return implode('/', array_map(function ($part) {
        return str_replace(['%2B', '%7E'], ['+', '~'], rawurlencode($part));
    }, explode('/', $rel)));
}

// ----------------------------------------------------------- APT indexes ---

function cy_packages_index(array $cfg, array $packages)
{
    $url = $cfg['url'];
    $stanzas = [];
    foreach ($packages as $p) {
        $debs = $p->debs;
        if (!$cfg['keep_old_versions']) {
            $seen = [];
            $debs = array_filter($debs, function ($d) use (&$seen) {  // newest per arch
                if (isset($seen[$d->arch])) {
                    return false;
                }
                return $seen[$d->arch] = true;
            });
        }
        foreach ($debs as $d) {
            $c = new CyControl($d->control->fields);
            foreach (['Filename', 'Size', 'MD5sum', 'SHA1', 'SHA256', 'SHA512', 'Depiction', 'SileoDepiction'] as $key) {
                $c->remove($key);
            }
            if ($p->text('name') !== '' || $c->get('Name') === '') {
                $c->set('Name', $p->name);
            }
            if ($p->text('author') !== '') {
                $c->set('Author', $p->text('author'));
            }
            if ($c->get('Maintainer') === '' && $cfg['maintainer'] !== '') {
                $c->set('Maintainer', $cfg['maintainer']);
            }
            if ($c->get('Section') === '') {
                $c->set('Section', 'Tweaks');
            }
            if ($p->iconUrl) {
                $c->set('Icon', $url . $p->iconUrl);
            }
            $c->set('Depiction', $url . $p->page);
            $c->set('SileoDepiction', $url . $p->page . 'sileo.json');
            $c->set('Filename', $d->rel);
            $c->set('Size', (string)$d->size);
            $c->set('MD5sum', $d->md5);
            $c->set('SHA1', $d->sha1);
            $c->set('SHA256', $d->sha256);
            $stanzas[] = $c->dump();
        }
    }
    return implode("\n", $stanzas);
}

function cy_write_indexes($root, array $cfg, array $packages)
{
    $data = cy_packages_index($cfg, $packages);
    // Cydia on iOS 5/6 fetches Packages.bz2 or Packages.gz.
    $blobs = ['Packages' => $data, 'Packages.gz' => gzencode($data, 9)];
    if (function_exists('bzcompress')) {
        $blobs['Packages.bz2'] = bzcompress($data, 9);
    } elseif (is_file("$root/Packages.bz2")) {
        unlink("$root/Packages.bz2");
    }
    foreach ($blobs as $name => $blob) {
        cy_write("$root/$name", $blob);
    }
    foreach (['Packages.xz', 'Packages.lzma', 'Packages.zst'] as $stale) {  // left by other generators
        if (is_file("$root/$stale")) {
            unlink("$root/$stale");
        }
    }
    $archs = [];
    foreach ($cfg['architectures'] as $a) {
        $archs[(string)$a] = true;
    }
    foreach ($packages as $p) {
        foreach ($p->debs as $d) {
            if ($d->arch !== 'all') {
                $archs[$d->arch] = true;
            }
        }
    }
    $archs = array_keys($archs);
    sort($archs);
    $line = function ($value) {
        return trim(preg_replace('/\s+/u', ' ', (string)$value));
    };
    $lines = [
        'Origin: ' . $line($cfg['origin']),
        'Label: ' . $line($cfg['label']),
        'Suite: ' . $line($cfg['suite']),
        'Version: ' . $line($cfg['version']),
        'Codename: ' . $line($cfg['codename']),
        'Date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
        'Architectures: ' . $line(implode(' ', $archs)),
        'Components: main',
        'Description: ' . $line($cfg['description'] !== '' ? $cfg['description'] : $cfg['name']),
    ];
    foreach (['MD5Sum' => 'md5', 'SHA1' => 'sha1', 'SHA256' => 'sha256'] as $title => $algo) {
        $lines[] = "$title:";
        foreach ($blobs as $name => $blob) {
            $lines[] = sprintf(' %s %16d %s', hash($algo, $blob), strlen($blob), $name);
        }
    }
    cy_write("$root/Release", implode("\n", $lines) . "\n");  // last, so it matches the new Packages
}

// ----------------------------------------------------------------- build ---

function cy_check_deb(CyDeb $deb, array $cfg, CyLog $log)
{
    $missing = [];
    foreach (['Name', 'Description', 'Maintainer'] as $field) {
        if ($deb->control->get($field) === '') {
            $missing[] = $field;
        }
    }
    if ($missing) {
        $log->warn("$deb->rel: control has no " . implode(', ', $missing));
    }
    if (!preg_match('/^[a-z0-9][a-z0-9.+-]*$/', $deb->package)) {
        $log->warn("$deb->rel: package id \"$deb->package\" should use only a-z, 0-9, \".\", \"+\" and \"-\"");
    }
    if ($cfg['legacy_checks'] && $deb->arch === 'iphoneos-arm' && in_array($deb->dataCompression, ['xz', 'zst'], true)) {
        $log->warn("$deb->rel: data.tar.$deb->dataCompression may not install on iOS 6 and older (old dpkg)");
    }
}

/** Swap a freshly built folder into place. */
function cy_swap_dir($new, $target)
{
    if (is_dir($target)) {
        $old = $target . '.old-' . getmypid();
        if (!rename($target, $old)) {
            throw new CyError('cannot replace ' . basename($target) . ': check folder permissions');
        }
        rename($new, $target);
        cy_rmtree($old);
    } elseif (!rename($new, $target)) {
        throw new CyError('cannot create ' . basename($target) . ': check folder permissions');
    }
}

function cy_update_htaccess($root, array $cfg)
{
    $base = parse_url($cfg['url'], PHP_URL_PATH) ?: '/';
    $block = str_replace('{base}', $base, CY_HTACCESS);
    $file = "$root/.htaccess";
    $old = is_file($file) ? (string)file_get_contents($file) : '';
    $pattern = '/# BEGIN cydia-repo.*?# END cydia-repo\n?/s';
    $new = preg_match($pattern, $old)
        ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $block) . "\n", $old, 1)
        : $block . "\n" . $old;
    if ($new !== $old) {
        cy_write($file, $new);
    }
}

/**
 * Rebuild everything served from $root (the site folder).
 * Returns a summary: packages, debs, warnings, seconds.
 */
function cy_build($root)
{
    $started = microtime(true);
    $repo = "$root/repo";
    $log = new CyLog();
    cy_mkdir("$repo/data");
    $lock = fopen("$repo/data/build.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new CyError('cannot lock repo/data/build.lock');
    }
    try {
        $cfg = cy_load_config($repo);
        if (!is_writable($root)) {
            throw new CyError('PHP cannot write to the site folder; set folder permissions to 755');
        }
        $s = CY_STRINGS[$cfg['language']];
        foreach (['depictions', 'assets'] as $name) {  // leftovers of an interrupted build
            foreach (array_merge(glob("$root/$name.new-*") ?: [], glob("$root/$name.old-*") ?: []) as $junk) {
                cy_rmtree($junk);
            }
        }

        $cacheFile = "$repo/data/cache.json";
        $cache = is_file($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
        $groups = [];
        $seen = [];
        $total = 0;
        foreach (cy_list_files("$root/debs") as $rel) {
            if (cy_ext($rel) !== 'deb') {
                continue;
            }
            $rel = "debs/$rel";
            try {
                $deb = CyDeb::load("$root/$rel", $rel, $cache);
            } catch (CyError $e) {
                $log->warn("$rel: " . $e->getMessage() . ' (skipped)');
                continue;
            }
            $key = "$deb->package|$deb->version|$deb->arch";
            if (isset($seen[$key])) {
                $log->warn("$rel: same package/version/arch as {$seen[$key]}, skipped");
                continue;
            }
            $seen[$key] = $rel;
            cy_check_deb($deb, $cfg, $log);
            $groups[$deb->package][] = $deb;
            $total++;
        }
        $live = [];
        foreach (array_keys($cache) as $key) {
            $file = explode('|', $key)[0];
            if (is_file("$root/$file")) {
                $live[$key] = $cache[$key];
            }
        }
        cy_write($cacheFile, json_encode($live, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        ksort($groups, SORT_STRING);
        $packages = [];
        foreach ($groups as $pid => $debs) {
            $packages[] = new CyPackage((string)$pid, $debs, $cfg, $s, "$repo/packages", $log);
        }
        if (is_dir("$repo/packages")) {
            foreach (scandir("$repo/packages") as $name) {
                if ($name[0] !== '.' && $name[0] !== '_' && is_dir("$repo/packages/$name") && !isset($groups[$name])) {
                    $log->warn("packages/$name: no .deb with this package id");
                }
            }
        }

        $site = new CySite($cfg, $packages, "$repo/theme");
        $tag = getmypid();
        $depictions = "$root/depictions.new-$tag";
        cy_mkdir($depictions);
        foreach ($packages as $p) {
            $folder = "$depictions/$p->id";
            cy_mkdir($folder);
            $p->copyAssets($folder);
            cy_write("$folder/index.html", $site->renderPackage($p));
            if ($p->changelog) {
                cy_write("$folder/changelog.html", $site->renderChangelog($p));
            }
            cy_write("$folder/sileo.json", cy_json($site->sileoDepiction($p)));
        }
        if (!is_dir("$repo/theme")) {
            throw new CyError('repo/theme is missing: run "python3 tools/repo.py sync"');
        }
        cy_copytree("$repo/theme", "$root/assets.new-$tag");
        cy_swap_dir($depictions, "$root/depictions");
        cy_swap_dir("$root/assets.new-$tag", "$root/assets");

        $icon = is_file("$repo/assets/CydiaIcon.png") ? "$repo/assets/CydiaIcon.png" : "$repo/theme/img/package.png";
        cy_write("$root/CydiaIcon.png", (string)file_get_contents($icon));
        cy_write("$root/index.html", $site->renderIndex());
        cy_write("$root/404.html", $site->render404());
        cy_write("$root/packages.json", cy_json($site->packagesJson()));
        $featured = $site->sileoFeatured();
        if ($featured) {
            cy_write("$root/sileo-featured.json", cy_json($featured));
        } elseif (is_file("$root/sileo-featured.json")) {
            unlink("$root/sileo-featured.json");
        }
        cy_write_indexes($root, $cfg, $packages);
        cy_update_htaccess($root, $cfg);

        return [
            'packages' => count($packages),
            'debs' => $total,
            'warnings' => $log->warnings,
            'seconds' => round(microtime(true) - $started, 2),
            'url' => $cfg['url'],
        ];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
