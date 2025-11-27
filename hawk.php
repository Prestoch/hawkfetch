<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
define('MAX_FILE_SIZE', 120000000);
error_reporting(E_ALL);

require 'vendor/autoload.php';
require_once('config.php');
require_once('sd.php');

$last_curl_fetch_info = [];

$debug = false;
if (isset($_GET['debug'])) {
    $debug = true;
}

function rdie($a) {
    echo json_encode($a);
    die();
}

function pre($a) {
    echo '<pre>' . json_encode($a, JSON_PRETTY_PRINT) . '</pre>';
}

function cn($s) {
    // Normalize common Unicode non-breaking/narrow spaces to ASCII space
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s); // NBSP, NNBSP
    // Strip apostrophe-like characters that may be treated as letters in Unicode
    $s = str_replace([
        "'",              // ASCII apostrophe
        "\x60",           // grave accent
        "\xC2\xB4",      // acute accent
        "\xE2\x80\x99", // right single quotation mark (’)
        "\xE2\x80\x98", // left single quotation mark (‘)
        "\xE2\x80\x9A", // single low-9 quotation mark (‚)
        "\xCA\xBC",      // modifier letter apostrophe (ʼ)
        "\xE2\x80\xB2"  // prime (′)
    ], '', $s);
    // Collapse any whitespace to single space
    $s = preg_replace('/\s+/u', ' ', $s);
    $a = strtolower($s);
    if ($a === 'outworld devourer') {
        $s = 'outworld destroyer';
    }
    // Keep letters, numbers and spaces across Unicode, then drop digits, lower and trim
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', '', $s);
    $s = strtolower($s);
    $s = preg_replace('/[0-9]+/', '', $s);
    // Finally, remove spaces so hyphen/space/concat variants unify (e.g., anti mage, anti-mage -> antimage)
    $s = str_replace(' ', '', $s);
    return trim($s);
}

// Load counters database from cs.json (same structure used by index.html and dltv.php)
if (!file_exists(dirname(__FILE__) . '/cs.json')) {
    die('echo cs.json not found');
}
$csjson = file_get_contents(dirname(__FILE__) . '/cs.json');

$heroesMatch = [];
if (!preg_match('/var\s+heroes\s*=\s*(\[[^\]]*\])/m', $csjson, $heroesMatch)) {
    die('cs.json heroes problem');
}
$h = json_decode($heroesMatch[1], true);
if (!is_array($h)) {
    die('cs.json heroes problem');
}

$heroesWrMatch = [];
if (!preg_match('/heroes_wr\s*=\s*(\[[^\]]*\])/m', $csjson, $heroesWrMatch)) {
    die('cs.json heroes_wr problem');
}
$h_wr = json_decode($heroesWrMatch[1], true);
if (!is_array($h_wr)) {
    die('cs.json heroes_wr problem');
}

$winRatesMatch = [];
if (!preg_match('/win_rates\s*=\s*(\[[\s\S]*?\])\s*(?:[,;]\s*)?(?:(?:var|let|const)\s+)?update_time/m', $csjson, $winRatesMatch)) {
    die('cs.json win_rates problem');
}
$h_wrs = json_decode($winRatesMatch[1], true);
if (!is_array($h_wrs)) {
    die('cs.json win_rates problem');
}

// Build canonical hero names list for lookup
$hero = [];
foreach ($h as $hh) {
    $hero[] = cn($hh);
}

// Storage for processed matches
$mf = dirname(__FILE__) . '/matches_hawk';
if (!file_exists($mf)) {
    mkdir($mf, 0777, true);
}

// Basic HTTP fetch (direct)
function curl_fetch($url) {
    global $last_curl_fetch_info;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/119 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ]);
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_errno($ch) ? curl_error($ch) : '';
    $snippet = '';
    if (is_string($res) && $res !== '') {
        $snippet = substr(preg_replace('/\s+/', ' ', strip_tags(substr($res, 0, 1000))), 0, 220);
    }
    $last_curl_fetch_info = [
        'attempt' => 'direct',
        'url' => $url,
        'http_code' => isset($info['http_code']) ? $info['http_code'] : null,
        'error' => $error,
        'bytes' => is_string($res) ? strlen($res) : 0,
        'body_snippet' => $snippet
    ];
    curl_close($ch);
    return $res;
}

// Optional proxy rendering via scrape.do token from settings (not real JS-rendering, but can bypass blocks)
function fetch_with_proxy($url) {
    // Try plain proxy first, then JS-rendering if needed
    if (function_exists('get_html')) {
        $r = get_html($url);
        if ($r) return $r;
    }
    if (function_exists('get_html_js')) {
        $r = get_html_js($url);
        if ($r) return $r;
    }
    return '';
}

// Try to get hawk.live content. Prefer direct; fallback to proxy if no picks found.
$candidateUrls = [
    'https://hawk.live/',
    'https://hawk.live/en',
    'https://hawk.live/dota2',
    'https://hawk.live/en/dota2',
];

$page = '';
// Helper to detect if page likely contains usable data
$hasData = function($content) {
    if (!$content) return false;
    if (strpos($content, 'series-list__item') !== false) return true;
    if (strpos($content, 'data-page=') !== false) return true; // Inertia JSON blob
    if (strpos($content, 'id="app"') !== false && strpos($content, 'SeriesListView') !== false) return true;
    return false;
};

$forceProxy = isset($_GET['use_proxy']) && $_GET['use_proxy'];

global $scrapingbee_last_request;
$fetchAttempts = [];

// Try direct unless proxy is forced
if (!$forceProxy) {
    foreach ($candidateUrls as $u) {
        $page = curl_fetch($u);
        $attemptMeta = $last_curl_fetch_info;
        $attemptMeta['matched'] = $hasData($page);
        if ($attemptMeta['matched']) {
            $attemptMeta['body_snippet'] = '';
        }
        $fetchAttempts[] = $attemptMeta;
        if ($attemptMeta['matched']) { break; }
    }
}

// Try proxy if forced or direct didn't yield usable content
if ($forceProxy || !$hasData($page)) {
    foreach ($candidateUrls as $u) {
        $page = fetch_with_proxy($u);
        $attemptMeta = $scrapingbee_last_request;
        if (!is_array($attemptMeta)) { $attemptMeta = []; }
        $attemptMeta['attempt'] = isset($attemptMeta['mode']) ? $attemptMeta['mode'] : 'proxy';
        $attemptMeta['url'] = $u;
        $attemptMeta['matched'] = $hasData($page);
        if (!$attemptMeta['matched'] && is_string($page) && $page !== '') {
            $attemptMeta['body_snippet'] = substr(preg_replace('/\s+/', ' ', strip_tags(substr($page, 0, 1000))), 0, 220);
            $attemptMeta['bytes'] = strlen($page);
        } else if ($attemptMeta['matched']) {
            $attemptMeta['body_snippet'] = '';
            if (!isset($attemptMeta['bytes'])) {
                $attemptMeta['bytes'] = is_string($page) ? strlen($page) : 0;
            }
        } else {
            if (!isset($attemptMeta['bytes'])) {
                $attemptMeta['bytes'] = is_string($page) ? strlen($page) : 0;
            }
        }
        $fetchAttempts[] = $attemptMeta;
        if ($attemptMeta['matched']) { break; }
    }
}

// Allow injecting a testing HTML (e.g., pastes) via ?source_url=...
if (isset($_GET['source_url']) && $_GET['source_url']) {
    $test = $forceProxy ? fetch_with_proxy($_GET['source_url']) : curl_fetch($_GET['source_url']);
    if ($test) { $page = $test; }
}

if (!$page) {
    $logPayload = [
        'timestamp' => date('c'),
        'attempts' => $fetchAttempts
    ];
    $logFile = $mf . '/hawk_fetch.log';
    @file_put_contents($logFile, json_encode($logPayload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    if ($debug) {
        pre($fetchAttempts);
    }
    rdie(['error' => 'Failed to load hawk.live']);
}

$html = str_get_html($page);
if (!$html) {
    $logPayload = [
        'timestamp' => date('c'),
        'attempts' => $fetchAttempts,
        'reason' => 'parse_failed'
    ];
    $logFile = $mf . '/hawk_fetch.log';
    @file_put_contents($logFile, json_encode($logPayload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    if ($debug) {
        pre($fetchAttempts);
    }
    rdie(['error' => 'Failed to parse hawk.live HTML']);
}

// Parse series items
$items = $html->find('div.series-list__item');
$res_matches = [];

// Fallback: parse JSON embedded in #app[data-page] if server-side list isn't rendered
if (!is_array($items) || !count($items)) {
    if ($debug) { echo 'No series-list__item found<br/>'; }

    $app = $html->find('#app', 0);
    if ($app && $app->hasAttribute('data-page')) {
        $dataPageRaw = $app->getAttribute('data-page');
        $dataPageJson = html_entity_decode($dataPageRaw, ENT_QUOTES | ENT_HTML5);
        $dp = json_decode($dataPageJson, true);
        if (is_array($dp) && isset($dp['props']) && isset($dp['props']['series']) && is_array($dp['props']['series'])) {
            foreach ($dp['props']['series'] as $series) {
                $seriesName = isset($series['championship_name']) ? $series['championship_name'] : 'Series';
                // Skip leagues if configured
                if (isset($skip_leagues) && is_array($skip_leagues) && count($skip_leagues)) {
                    $skip = false;
                    foreach ($skip_leagues as $sl) {
                        if ($sl !== '' && stripos($seriesName, $sl) !== false) { $skip = true; break; }
                    }
                    if ($skip) { continue; }
                }
                $team1Name = isset($series['team1']['name']) ? $series['team1']['name'] : 'Team 1';
                $team2Name = isset($series['team2']['name']) ? $series['team2']['name'] : 'Team 2';
                if (!isset($series['matches']) || !is_array($series['matches']) || !count($series['matches'])) {
                    continue;
                }
                // Only consider live match (is_radiant_won === null) with 10 heroes (skip finished or not-started maps)
                $chosen = null;
                foreach ($series['matches'] as $m) {
                    if (isset($m['heroes']) && is_array($m['heroes']) && count($m['heroes']) === 10 && array_key_exists('is_radiant_won', $m) && is_null($m['is_radiant_won'])) {
                        $chosen = $m; break;
                    }
                }
                if (!$chosen) { continue; }

                $team1Heroes = [];
                $team2Heroes = [];
                $team1Radiant = isset($chosen['is_team1_radiant']) ? (bool)$chosen['is_team1_radiant'] : true;
                foreach ($chosen['heroes'] as $hObj) {
                    if (!isset($hObj['name'])) { continue; }
                    $nameNorm = cn($hObj['name']);
                    // Alias fixes
                    $aliases = [
                        'nevermore' => 'shadow fiend',
                        'wisp' => 'io',
                        'windrunner' => 'windranger',
                        'outworld devourer' => 'outworld destroyer',
                        'furion' => 'natures prophet',
                    ];
                    if (isset($aliases[$nameNorm])) { $nameNorm = cn($aliases[$nameNorm]); }
                    if ($nameNorm === 'naturesprophet' || $nameNorm === 'naturesprophet') { $nameNorm = 'naturesprophet'; }
                    $hid = array_search($nameNorm, $hero);
                    // Fallback: map by code_name when name mapping fails
                    if ($hid === false && isset($hObj['code_name'])) {
                        $code = $hObj['code_name']; // e.g., npc_dota_hero_furion
                        $short = preg_replace('/^npc_dota_hero_/', '', $code);
                        $short = str_replace('_', ' ', $short);
                        $shortNorm = cn($short);
                        $codeAliases = [
                            'life stealer' => 'lifestealer',
                            'queenofpain' => 'queen of pain',
                            'doom bringer' => 'doom',
                            'skeleton king' => 'wraith king',
                            'rattletrap' => 'clockwerk',
                            'nevermore' => 'shadow fiend',
                            'windrunner' => 'windranger',
                            'furion' => 'natures prophet',
                            'zuus' => 'zeus',
                            'magnataur' => 'magnus',
                            'obsidian destroyer' => 'outworld destroyer',
                            'vengefulspirit' => 'vengeful spirit',
                            'shredder' => 'timbersaw',
                            'necrolyte' => 'necrophos'
                        ];
                        if (isset($codeAliases[$shortNorm])) { $shortNorm = cn($codeAliases[$shortNorm]); }
                        if ($shortNorm === 'naturesprophet' || $shortNorm === 'natureprophet') { $shortNorm = 'naturesprophet'; }
                        $hid = array_search($shortNorm, $hero);
                    }
                    if ($hid === false) {
                        if ($debug) {
                            echo 'Unmatched hero (JSON): ' . htmlspecialchars($hObj['name']) . ' | code=' . htmlspecialchars($hObj['code_name'] ?? '') . ' | norm=' . htmlspecialchars($nameNorm) . '<br/>';
                        }
                        continue;
                    }
                    $pic = [
                        'id' => $hid,
                        'hname' => $hObj['name'],
                    ];
                    if (isset($hObj['code_name']) && $hObj['code_name']) {
                        $pic['image'] = 'https://hawk.live/images/heroes/' . $hObj['code_name'] . '.png';
                    }
                    $isRad = isset($hObj['is_radiant']) ? (bool)$hObj['is_radiant'] : false;
                    if ($isRad === $team1Radiant) { $team1Heroes[] = $pic; } else { $team2Heroes[] = $pic; }
                }
                if (count($team1Heroes) === 5 && count($team2Heroes) === 5) {
                    $nm = [];
                    $nm['name'] = $seriesName;
                    $nm['mid'] = isset($chosen['id']) ? $chosen['id'] : uniqid('hawk_');
                    $nm['team1'] = [ 'name' => $team1Name, 'heroes' => $team1Heroes ];
                    $nm['team2'] = [ 'name' => $team2Name, 'heroes' => $team2Heroes ];
                    $res_matches[] = $nm;
                } else if ($debug) {
                    echo 'JSON fallback: incomplete picks ' . count($team1Heroes) . ' vs ' . count($team2Heroes) . '<br/>';
                }
            }
        } else if ($debug) {
            echo 'JSON fallback: data-page missing or invalid<br/>';
        }
    } else if ($debug) {
        echo '#app[data-page] not found<br/>';
    }
}

// Primary DOM path (when server renders series list for crawlers)
foreach ($items as $item) {
    // Series name (try first label outside matches)
    $seriesName = '';
    $seriesHeader = $item->find('div.series-list__item > span.text-body-2', 0);
    if (!$seriesHeader) {
        // fallback: first text-body-2 under item
        $seriesHeader = $item->find('span.text-body-2', 0);
    }
    if ($seriesHeader) {
        $seriesName = trim($seriesHeader->plaintext);
    }

    // Team names
    $team1Name = '';
    $team2Name = '';
    $teamNameSpans = $item->find('span.series-teams-item__name');
    if (is_array($teamNameSpans) && count($teamNameSpans) >= 2) {
        $team1Name = trim($teamNameSpans[0]->plaintext);
        $team2Name = trim($teamNameSpans[1]->plaintext);
    }

    // Determine current map anchor: prefer one containing a red dot icon
    $mapAnchors = $item->find('a.series-list__match');
    if (!is_array($mapAnchors) || !count($mapAnchors)) {
        continue;
    }

    $currentAnchor = null;
    foreach ($mapAnchors as $a) {
        $mapNumDiv = $a->find('div.series-list__match-map-number', 0);
        if ($mapNumDiv && $mapNumDiv->find('i[class*=text-red]', 0)) {
            $currentAnchor = $a;
            break;
        }
    }
    // Only process live maps (with red dot). Skip finished or not-started maps.
    if (!$currentAnchor) {
        continue;
    }

    // Extract match id from href
    $href = $currentAnchor->getAttribute('href');
    $matchId = '';
    if ($href) {
        $parts = explode('/', trim($href));
        foreach ($parts as $p) {
            if ($p && is_numeric($p)) { $matchId = $p; break; }
        }
    }

    // Extract heroes (5/5)
    $team1Heroes = [];
    $team2Heroes = [];
    $t1Imgs = $currentAnchor->find('div.series-list__heroes--team1 img');
    $t2Imgs = $currentAnchor->find('div.series-list__heroes--team2 img');

    $buildPick = function($img) use ($hero) {
        $alt = '';
        $src = '';
        if ($img) {
            if ($img->hasAttribute('alt')) { $alt = trim($img->getAttribute('alt')); }
            if ($img->hasAttribute('title') && !$alt) { $alt = trim($img->getAttribute('title')); }
            if ($img->hasAttribute('src')) { $src = trim($img->getAttribute('src')); }
        }
        if (!$alt) { return null; }
        $altNorm = cn($alt);
        $aliases = [
            'nevermore' => 'shadow fiend',
            'wisp' => 'io',
            'windrunner' => 'windranger',
            'outworld devourer' => 'outworld destroyer',
            'furion' => 'natures prophet',
        ];
        if (isset($aliases[$altNorm])) { $altNorm = cn($aliases[$altNorm]); }
        if ($altNorm === 'naturesprophet' || $altNorm === 'natureprophet') { $altNorm = 'naturesprophet'; }
        $hid = array_search($altNorm, $hero);
        if ($hid === false) { return null; }
        $pic = [
            'id' => $hid,
            'hname' => $alt,
        ];
        if ($src) {
            $pic['image'] = (strpos($src, 'http') === 0 ? $src : ('https://hawk.live' . $src));
        }
        return $pic;
    };

    if (is_array($t1Imgs)) {
        foreach ($t1Imgs as $im) {
            $built = $buildPick($im);
            if ($built !== null) { $team1Heroes[] = $built; }
        }
    }
    if (is_array($t2Imgs)) {
        foreach ($t2Imgs as $im) {
            $built = $buildPick($im);
            if ($built !== null) { $team2Heroes[] = $built; }
        }
    }

    if (count($team1Heroes) === 5 && count($team2Heroes) === 5) {
        $nm = [];
        $nm['name'] = $seriesName ?: 'Series';
        $nm['mid'] = $matchId ?: uniqid('hawk_');
        $nm['team1'] = [ 'name' => $team1Name ?: 'Team 1', 'heroes' => $team1Heroes ];
        $nm['team2'] = [ 'name' => $team2Name ?: 'Team 2', 'heroes' => $team2Heroes ];
        $res_matches[] = $nm;
    } else {
        if ($debug) {
            echo 'Skipping series due to incomplete picks: ' . (count($team1Heroes)) . ' vs ' . (count($team2Heroes)) . '<br/>';
        }
    }
}

if ($debug) {
    echo 'Games : ' . sizeof($res_matches) . '<br/>';
}

foreach ($res_matches as $m) {
    echo $m['mid'] . '<br/>';
    $file = $mf . '/hawk.' . $m['mid'] . '.json';
    if ($debug || !file_exists($file)) {
        $cond_one = false;
        $cond_2 = false;
        $cond_3 = false;
        $cond_4 = false;

        $hero_have_hh = false;
        $hero_have_anh = false;
        $nb1 = 0;
        $nb2 = 0;
        $m['team1']['cc_neg'] = 0;
        $m['team1']['cc_pos'] = 0;
        $m['team2']['cc_neg'] = 0;
        $m['team2']['cc_pos'] = 0;
        // tower damage condition removed

        for ($i = 0; $i < 5; $i++) {
            $m['team1']['heroes'][$i]['wr'] = $h_wr[$m['team1']['heroes'][$i]['id']];
            $m['team2']['heroes'][$i]['wr'] = $h_wr[$m['team2']['heroes'][$i]['id']];

            if ((isset($hero_have) && is_array($hero_have) && in_array($m['team2']['heroes'][$i]['id'], $hero_have))
                || (isset($hero_have) && is_array($hero_have) && in_array($m['team1']['heroes'][$i]['id'], $hero_have))) {
                $hero_have_hh = true;
            }

            $nb1 += floatval($h_wr[$m['team1']['heroes'][$i]['id']]);
            $nb2 += floatval($h_wr[$m['team2']['heroes'][$i]['id']]);

            $m['team1']['heroes'][$i]['name'] = $h[$m['team1']['heroes'][$i]['id']];
            $m['team2']['heroes'][$i]['name'] = $h[$m['team2']['heroes'][$i]['id']];

            $nb1a = 0;
            $nb2a = 0;
            for ($a = 0; $a < 5; $a++) {
                $team2_id = $m['team2']['heroes'][$a]['id'];
                $team1_id_i = $m['team1']['heroes'][$i]['id'];
                $team1_id = $m['team1']['heroes'][$a]['id'];
                $team2_id_i = $m['team2']['heroes'][$i]['id'];

                if (isset($h_wrs[$team2_id]) && isset($h_wrs[$team2_id][$team1_id_i]) && isset($h_wrs[$team2_id][$team1_id_i][0])) {
                    $nb1a += floatval($h_wrs[$team2_id][$team1_id_i][0]) * -1;
                }
                if (isset($h_wrs[$team1_id]) && isset($h_wrs[$team1_id][$team2_id_i]) && isset($h_wrs[$team1_id][$team2_id_i][0])) {
                    $nb2a += floatval($h_wrs[$team1_id][$team2_id_i][0]) * -1;
                }
            }

            $m['team1']['heroes'][$i]['wr_2_success'] = $nb1a > 0 ? false : true;
            $m['team2']['heroes'][$i]['wr_2_success'] = $nb2a > 0 ? false : true;

            $m['team1'][($nb1a > 0 ? 'cc_neg' : 'cc_pos')]++;
            $m['team2'][($nb2a > 0 ? 'cc_neg' : 'cc_pos')]++;

            $m['team1']['heroes'][$i]['wr_2'] = number_format($nb1a, 2, '.', '') * -1;
            $m['team2']['heroes'][$i]['wr_2'] = number_format($nb2a, 2, '.', '') * -1;

            $an_t1 = $m['team1']['heroes'][$i]['wr_2'];
            $an_t2 = $m['team2']['heroes'][$i]['wr_2'];
            if (isset($anh_have) && is_array($anh_have) && sizeof($anh_have)) {
                foreach ($anh_have as $an) {
                    $anh_f = floatval(str_replace('-', '', str_replace('+', '', $an)));
                    $cv1 = floatval(str_replace('-', '', str_replace('+', '', $an_t1)));
                    $cv2 = floatval(str_replace('-', '', str_replace('+', '', $an_t2)));
                    if (strpos($an, '-') === false) {
                        if (strpos($an_t1, '-') === false) {
                            if ($cv1 > $anh_f) { $hero_have_anh = true; break; }
                        }
                        if (strpos($an_t2, '-') === false) {
                            if ($cv2 > $anh_f) { $hero_have_anh = true; break; }
                        }
                    } else {
                        if (strpos($an_t1, '-') !== false) {
                            if ($cv1 > $anh_f) { $hero_have_anh = true; break; }
                        }
                        if (strpos($an_t2, '-') !== false) {
                            if ($cv2 > $anh_f) { $hero_have_anh = true; break; }
                        }
                    }
                }
            }

            $nb1 += $nb1a * -1;
            $nb2 += $nb2a * -1;
        }

        $m['team1']['score'] = number_format($nb1, 2, '.', '');
        $m['team2']['score'] = '- ' . number_format($nb2, 2, '.', '');
        $m['total'] = number_format(($nb1 - $nb2), 2, '.', '');
        $m['total_success'] = ($nb1 > $nb2) ? true : false;

        $gh = '<div style="width:600px;max-width:100%;border:1px solid gray;padding:20px;">';
        $gh .= '<h1 style="margin:0px 0px 20px 0px;">' . ($m['name'] ?? 'Match') . '</h1>';
        $gh .= '<h3>' . ($m['team1']['name'] ?? 'Team 1') . '</h3>';
        $gh .= '<div style="display:flex;justify-content: space-between;align-content: space-between;">';
        for ($i = 0; $i < sizeof($m['team1']['heroes']); $i++) {
            $heroIt = $m['team1']['heroes'][$i];
            $gh .= '<div style="width:80px;margin-right:20px;">';
            $gh .= '<span>' . $heroIt['wr'] . ' + <span style="' . ($heroIt['wr_2_success'] ? 'color:green;' : 'color:red;') . '">' . $heroIt['wr_2'] . '</span></span>';
            if (isset($heroIt['image'])) { $gh .= '<img style="width:100%;" src="' . $heroIt['image'] . '">'; }
            $gh .= '<span>' . $heroIt['name'] . '</span>';
            $gh .= '</div>';
        }
        $gh .= '<div><div>' . $m['team1']['score'] . '</div></div>';
        $gh .= '</div>';

        $gh .= '<div style="width:100%;display:block;align-items:center;justify-content:space-between;">';
        $gh .= '<h3 style="display:inline-block;">' . $m['team2']['name'] . '</h3>';
        $gh .= '</div>';

        $gh .= '<div style="display:flex;justify-content: space-between;align-content: space-between;">';
        for ($i = 0; $i < sizeof($m['team2']['heroes']); $i++) {
            $heroIt = $m['team2']['heroes'][$i];
            $gh .= '<div style="width:80px;margin-right:20px;">';
            $gh .= '<span>' . $heroIt['wr'] . ' + <span style="' . ($heroIt['wr_2_success'] ? 'color:green;' : 'color:red;') . '">' . $heroIt['wr_2'] . '</span></span>';
            if (isset($heroIt['image'])) { $gh .= '<img style="width:100%;" src="' . $heroIt['image'] . '">'; }
            $gh .= '<span>' . $heroIt['name'] . '</span>';
            $gh .= '</div>';
        }
        $gh .= '<div><div>' . $m['team2']['score'] . '</div></div>';
        $gh .= '</div>';

        $gh .= '<span style="display:block;font-size:30px;margin-top:20px;' . ($m['total_success'] ? 'color:green;' : 'color:red;') . '">' . $m['total'] . '</span>';
        $gh .= '</div>';

        $mets = [];
        $total_f = floatval($m['total']);
        if (($total_f < 0 && $total_f < $email_if_less) || $total_f > $email_if_greater) {
            $cond_one = true;
            $mets[] = 'Condition 1 is met';
        }

        if ((!isset($team_have_plus) || !is_array($team_have_plus) || !sizeof($team_have_plus))) {
            $cond_2 = true;
            $mets[] = 'Condition 2 is met';
        } else if (
            in_array($m['team1']['cc_pos'] . '+' . $m['team2']['cc_neg'] . '-', $team_have_plus) ||
            in_array($m['team2']['cc_pos'] . '+' . $m['team1']['cc_neg'] . '-', $team_have_plus) ||
            in_array($m['team1']['cc_pos'] . '+' . $m['team2']['cc_pos'] . '+', $team_have_plus) ||
            in_array($m['team2']['cc_pos'] . '+' . $m['team1']['cc_pos'] . '+', $team_have_plus) ||
            in_array($m['team1']['cc_neg'] . '-' . $m['team2']['cc_neg'] . '-', $team_have_plus) ||
            in_array($m['team2']['cc_neg'] . '-' . $m['team1']['cc_neg'] . '-', $team_have_plus)
        ) {
            $cond_2 = true;
            $mets[] = 'Condition 2 is met';
        }

        if ((!isset($hero_have) || !sizeof($hero_have)) || $hero_have_hh) {
            $cond_3 = true;
            $mets[] = 'Condition 3 is met';
        }

        if ((!isset($anh_have) || !sizeof($anh_have)) || $hero_have_anh) {
            $cond_4 = true;
            $mets[] = 'Condition 4 is met';
        }

        // Tower damage condition removed

        echo '<pre>' . json_encode($mets, JSON_PRETTY_PRINT) . '</pre>';

        // Condition mode: all vs any (default all if not set)
        // Respect enabled conditions from settings
        $condMap = [ 'delta' => $cond_one, 'pns' => $cond_2, 'hh' => $cond_3, 'anh' => $cond_4 ];
        $activeConds = [];
        if (isset($enabled_conditions) && is_array($enabled_conditions)) {
            foreach ($condMap as $k=>$v) { if (!empty($enabled_conditions[$k])) { $activeConds[] = $v; } }
        } else {
            $activeConds = array_values($condMap);
        }
        if (!count($activeConds)) { $activeConds = array_values($condMap); }
        $allConditions = $activeConds;
        $mode = isset($condition_mode) && in_array($condition_mode, ['all','any']) ? $condition_mode : 'all';
        $met = ($mode === 'all') ? (!in_array(false, $allConditions, true)) : in_array(true, $allConditions, true);
        if ($debug || $met) {
            $mail = new PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->SMTPSecure = $smtp_pro;
                $mail->Port = $smtp_port;

                $mail->setFrom($smtp_from, $smtp_from_name);

                if ($debug) {
                    $mail->addAddress('razorgamefun@gmail.com');
                } else if (isset($hawk_email) && $hawk_email) {
                    $mail->addAddress($hawk_email);
                } else if (isset($email_destination) && $email_destination) {
                    $mail->addAddress($email_destination);
                } else if (isset($dltv_email) && $dltv_email) { // fallback legacy
                    $mail->addAddress($dltv_email);
                } else if (isset($cyber_email) && $cyber_email) {
                    $mail->addAddress($cyber_email);
                }

                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);
                $mail->Subject = ($m['team1']['name'] ?? 'Team 1') . ' vs ' . ($m['team2']['name'] ?? 'Team 2') . ' - ' . ($m['name'] ?? 'Match');
                $mail->Body = $gh;
                $mail->AltBody = 'Matches notification';

                $mail->send();
                echo 'email sent';
            } catch (Exception $e) {
                echo 'email error';
            }
            echo '<br/>';
        }

        $fp = fopen($file, 'w');
        fwrite($fp, json_encode($m));
        fclose($fp);
    }
}

?>