<?php
header('Access-Control-Allow-Origin: *');
include 'htmlparser.php';
include 'PHPProxy.php';

$useGoogleTranslateProxy = false;

$url = $_SERVER['REQUEST_URI'];
$path = parse_url($url, PHP_URL_PATH);
$query = parse_url($url, PHP_URL_QUERY);


$newPath = ltrim($path, '/');

if ($query) $newPath .= '?' . $query;

$site = $_SERVER['HTTP_HOST'];
$targ_url = $_SERVER['REQUEST_URI'];
$stpos = strpos($targ_url, '::');
if ($stpos !== false) {
    $targ_site = substr($targ_url, 1, $stpos - 1);
    $targ_url = '/' . substr($targ_url, $stpos + 2);
}

if (empty($targ_site)) {
    if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
        $pa = strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        $pe = strpos($_SERVER['HTTP_REFERER'], '::');
        if ($pa === false || $pe === false) {
            //return exit("Cant parse referer");
            $targ_site = file_get_contents('lastsite.txt');
            $targ_url = $_SERVER['REQUEST_URI'];
        } else {
            $sh = strlen($_SERVER['HTTP_HOST']) + $pa + 1;
            $sb = $pe - $sh;
            $pb = substr($_SERVER['HTTP_REFERER'], $sh, $sb);
            //$pc = substr($_SERVER['HTTP_REFERER'], $pe+2);
            $targ_site = $pb;
            $targ_url = $_SERVER['REQUEST_URI'];
        }
    } else {
        $targ_site = file_get_contents('lastsite.txt');
        $targ_url = $_SERVER['REQUEST_URI'];
    }
}

if ($useGoogleTranslateProxy) {
    $targ_site = str_replace('.','-', str_replace('-','--',$targ_site));
    $gtr = '_x_tr_sl=en&_x_tr_tl=en&_x_tr_hl=en&_x_tr_pto=wapp';
    $gtp = '.translate.goog';
} else {
    $gtr = '';
    $gtp = '';
}

$queryStr = (empty($_SERVER['QUERY_STRING']) ? (empty($gtr) ? '' : '?' . $gtr) : (empty($gtr) ? '' : '&' . $gtr));
$targetLink = $targ_site . $gtp . (empty($targ_url) ? '/' : $targ_url) . (strlen($queryStr) > 1 ? $queryStr : '');
$targetURL = 'https://' . $targetLink;
$dataLink = 'data/' . $targetLink;

$useragent = $_SERVER['HTTP_USER_AGENT'];

$proxy = new PHPProxy(compact('useragent'));
$proxy->proxy($targetURL);

if (strripos($path, '.') !== false) {
    $pos = strripos($path, '.') + 1;
    $format = substr($path, $pos);

    switch ($format) {
        case 'css':
        case 'js':
            $proxy->setContent(str_replace('/https?:\\\/\\\/(' . preg_quote($targ_site) . ')/', 'http://' . $_SERVER['HTTP_HOST'] . '/' . '::$1', $proxy->getContent()));
        case 'woff':
        case 'woff2':
        case 'ttf':
        case 'png':
        case 'jpg':
            $proxy->output();
            exit();
        break;

        default:
            $data = substr($proxy->getContent() , 0, 128);
            if (strpos($data, '<') === false || strpos($data, '>') === false) {
                $proxy->output();
                exit();
            }
        break;
    }
}

$data = $proxy->getContent();
$psr = str_get_html($data);

function repi($v) {
    global $site, $targ_url, $targ_site, $gtr, $gtp; //TODO: refactor
    if (empty($targ_site)) return exit("Site not defined");
    $queryStr = '?' . (empty($_SERVER['QUERY_STRING']) ? $gtr : $_SERVER['QUERY_STRING'] . '&' . $gtr);
    $targetURL = 'https://' . $targ_site . $gtp . (empty($targ_url) ? '/' : $targ_url) . (strlen($queryStr) > 1 ? $queryStr : "");

    $rpt = strrpos($targ_url, '/');
    $bpt = substr($targ_url, 0, $rpt + 1);

    $v = preg_replace('/(https?)?:?\/\/([a-zA-Z0-9-.]+)/', 'http://' . $site . '/$2::', $v);
    $v = preg_replace('/^\//', 'http://' . $site . '/' . $targ_site . '::', $v);
    $v = preg_replace('/^([^:]+)$/', 'http://' . $site . '/' . $targ_site . '::' . $bpt . '$1', $v);
    $v = preg_replace('/\/\.\//', '/', $v);
    $v = preg_replace('/::\//', '::', $v);
    return $v;
}

if ($psr === false) {
    $proxy->output();
    exit();
} else {
    file_put_contents('lastsite.txt', $targ_site);
}

foreach ($psr->find('base') as $k => $v) {
    if (isset($v->href)) $v->href = repi($v->href);
}

foreach ($psr->find('a,link,script') as $k => $v) {
    if (isset($v->href)) $v->href = repi($v->href);
    if (isset($v->integrity)) unset($v->integrity);
    if (isset($v->src)) $v->src = repi($v->src);
    if (isset($v->innertext)) $v->innertext=repi($v->innertext);
}

foreach ($psr->find('form') as $k => $v) $v->action = repi($v->action);

foreach ($psr->find('frame,iframe,img') as $k => $v) $v->src = repi($v->src);

$proxy->setContent($psr->root->innertext());
$proxy->output();
