<?php
header('Access-Control-Allow-Origin: *');
include_once 'htmlparser.php';

$dbhost = "localhost";
$dbname = "test";
$user = "root";
$passwd = "";

$stderr = fopen('php://stderr', 'w');

$url = $_SERVER['REQUEST_URI'];
$path = parse_url($url, PHP_URL_PATH);
$query = parse_url($url, PHP_URL_QUERY);

$dsn = "mysql:host=" . $dbhost . ";dbname=" . $dbname;
$pdo = new PDO($dsn, $user, $passwd);

function _load($page) {
    global $pdo;
    $stm = $pdo->prepare("SELECT `id` FROM `cachedpages` WHERE `page` = :page LIMIT 1");
    $stm->bindParam(":page", $page, PDO::PARAM_STR);
    $stm->execute();
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    if(isset($row['id']) && $row['id']>0) return $row['id'];
    else return 0;
}

function _save($page) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $stm = $pdo->prepare("INSERT INTO `cachedpages` SET `page` = :page");
        $stm->bindParam(":page", $page, PDO::PARAM_STR);
        $stm->execute();
        $inserted_id = $pdo->lastInsertId();
        $pdo->commit();
        return $inserted_id;
    } catch(Exception $e) {
        $pdo->rollback();
        throw $e;
    }
    return 0;
}

class PHPProxy {
  private $mode = 1;
  private $cachedelimiter = "\r\n\r\n::PROXYCACHE::\r\n\r\n";
  private $cookieFile;
  public $result;
  private $useragent = 'Mozilla/5.0 (Linux; Mobile; Android) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Safari/537.36';

  public function __construct($opts = array()) {

    $this->cookieFile = 'cookies.txt';

    if(isset($opts['cookiefile'])) {
      $this->cookieFile = $opts['cookiefile'];
    }
    if(isset($opts['useragent'])) {
      $this->useragent = $opts['useragent'];
    }
  }

  public function request($opts) {

    if (!$opts['url']) {
      throw new Exception('No "url" option supplied');
    }

    $curlOptions = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER => true,     // return headers in addition to content
        CURLOPT_FOLLOWLOCATION => true,     // follow redirects
        CURLOPT_ENCODING => "",       // handle all encodings
        CURLOPT_AUTOREFERER => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 30,      // timeout on connect
        CURLOPT_TIMEOUT => 120,      // timeout on response
        CURLOPT_MAXREDIRS => 10,       // stop after 10 redirects
        CURLINFO_HEADER_OUT => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_COOKIEJAR => $this->cookieFile,
        CURLOPT_COOKIEFILE => $this->cookieFile,
        CURLOPT_USERAGENT => $this->useragent,
    );

    $ch = curl_init($opts['url']);
    curl_setopt_array($ch, $curlOptions);

    if ($opts['method'] === 'post') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }

    if ($opts['data']) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['data']);
    }

    if ($opts['referer']) {
      curl_setopt($ch, CURLOPT_REFERER, $opts['referer']);
    }

    $rough_content = curl_exec($ch);
    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $header = curl_getinfo($ch);
    curl_close($ch);

    $header_content = substr($rough_content, 0, $header['header_size']);
    $body_content = trim(str_replace($header_content, '', $rough_content));
    $pattern = "#Set-Cookie:\\s+(?<cookie>[^=]+=[^;]+)#m";
    preg_match_all($pattern, $header_content, $matches);
    $cookiesOut = implode("; ", $matches['cookie']);
    
    if (!$rough_content) die('503 ProxyCacheNoNetwork');
    
    $header['errno'] = $err;
    $header['errmsg'] = $errmsg;
    $header['headers'] = $header_content;
    $header['content'] = $body_content;
    $header['cookies'] = $cookiesOut;
    $this->result = $header;
    return $header;
  }

  public function proxy($baseUrl, $opts = array()) {
    $data = array();
    $urloriginal = preg_replace('/\/$/', '', $baseUrl);
    if (strpos($urloriginal, '/', 8) === false) $urloriginal .= '/';
    $url2 = preg_replace('/^https?:\/\//', '', $urloriginal);
    if (count($_POST)) {
      foreach ($_POST as $k => $v) {
        if (is_array($v)) {
          $this->_stringifyPostField($v, $data, $k);
        } else {
          $data[$k] = $v;
        }
      }
    } else if($this->mode == 1 && $this->isCached($url2)) {
      $this->load($url2);
      return $this->result;
    }
    if (isset($opts['data'])) {
      $data = array_merge($data, $opts['data']);
    }
    $this->request(array(
      'url' => $urloriginal,
      'method' => $_SERVER['REQUEST_METHOD'],
      'data' => $data,
      'referer' => ((isset($opts['referer'])?$opts['referer']:preg_replace('/\/$/', '', $baseUrl).$_SERVER['REQUEST_URI']))
    ));
    $this->save($url2);
    return $this->result;
  }

  public function save($f) {
      global $targ_site;
      $res = _save($f);
      return file_put_contents('data/' . $targ_site . '/' . $res, $this->result['headers'] . $this->cachedelimiter . $this->result['content']);
  }

  public function load($f) {
      global $targ_site;
      $res = file_get_contents('data/' . $targ_site . '/' . _load($f));
      $r = explode($this->cachedelimiter, $res);
      $this->result['headers'] = $r[0];
      $this->result['content'] = $r[1];
  }

  public function isCached($f) {
      global $targ_site;
      @mkdir('data/' . $targ_site, 0777, true);
      return file_exists('data/' . $targ_site . '/' . _load($f));
  }

  public function getContent() {
    return $this->result['content'];
  }

  public function setContent($content) {
    return $this->result['content'] = $content;
  }

  public function output() {
    // output relevant headers
    foreach (explode("\n", $this->result['headers']) as $header) {
      if (preg_match('/^(Status|HTTP|Content-Type)/i', trim($header))) {
        if (preg_match('/^HTTP.+(\d\d\d)/i', trim($header), $matches)) {
          header("Status: ".$matches[1]);
          header("HTTP/1.1 ".$matches[1]);
        } else {
          header($header);
        }
      }
    }
    echo $this->result['content'];
  }

  private function _injectHTML($data) {
    $this->result['content'] = str_replace($this->result['content'], '</head>', $data."\n".'</head>');
  }

  private function _stringifyPostField($field, &$data, $previous) {
    foreach ($field as $k => $v) {
      $newKey = $previous.'['.$k.']';
      if (is_array($v)) {
        $this->_stringifyPostField($v, $data, $newKey);
      } else {
        $data[$newKey] = $v;
      }
    }
    return $data;
  }
}

$newPath = ltrim($path, '/');
    
if ($query) $newPath .= '?' . $query;

/*$base = 'http://github.com/';
$proxyUrl = $base . $newPath;
*/
$site = $_SERVER['HTTP_HOST'];
$targ_url = $_SERVER['REQUEST_URI'];
$stpos = strpos($targ_url, '::');
if ($stpos !== false) {        
    $targ_site = substr($targ_url, 1, $stpos-1);
    $targ_url = '/'.substr($targ_url, $stpos+2);
}

if(empty($targ_site)) {
    if(isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])){
        $pa = strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        $pe = strpos($_SERVER['HTTP_REFERER'], '::');
        if($pa === false || $pe === false){
            return exit("Cant parse referer");
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
        //return exit("Site not defined");
    }
}

//if works only google services
//$targ_site = str_replace('.','-', str_replace('-','--',$targ_site));
$gtr='';//$gtr = '_x_tr_sl=en&_x_tr_tl=en&_x_tr_hl=en&_x_tr_pto=wapp';
$gtp='';//$gtp = '.translate.goog';

$queryStr = '?' . (empty($_SERVER['QUERY_STRING']) ? $gtr : $_SERVER['QUERY_STRING'] . '&' . $gtr);
$targetLink = $targ_site . $gtp . (empty($targ_url) ? '/' : $targ_url) . (strlen($queryStr)>1 ? $queryStr : '');
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
            $data = substr($proxy->getContent(),0,128);
            if(strpos($data,'<')===false || strpos($data,'>')===false) {
                $proxy->output();
                exit();
            }
        break;
    }
}

$data = $proxy->getContent();
$psr = str_get_html($data);

function repi($v) {
    global $site,$targ_url,$targ_site; //TODO: refactor

	      	if(empty($targ_site)) return exit("Site not defined");
        //$targ_site = str_replace('.','-', str_replace('-','--',$targ_site));
      		$gtr='';//$gtr = '_x_tr_sl=en&_x_tr_tl=en&_x_tr_hl=en&_x_tr_pto=wapp';
      		$gtp='';//$gtp = '.translate.goog';
        $queryStr = '?' . (empty($_SERVER['QUERY_STRING']) ? $gtr : $_SERVER['QUERY_STRING'] . '&' . $gtr);
        $targetURL = 'https://' . $targ_site . $gtp . (empty($targ_url) ? '/' : $targ_url) . (strlen($queryStr)>1 ? $queryStr : "");

        $rpt = strrpos($targ_url, '/');
        $bpt = substr($targ_url, 0, $rpt+1);

        $v = preg_replace('/(https?)?:?\/\/([a-zA-Z0-9-.]+)/', 'http://'.$site.'/$2::', $v);
        $v = preg_replace('/^\//', 'http://'.$site.'/'.$targ_site.'::', $v);
        $v = preg_replace('/^([^:]+)$/', 'http://'.$site.'/'.$targ_site.'::'.$bpt.'$1', $v);
        $v = preg_replace('/\/\.\//', '/', $v);
        $v = preg_replace('/::\//', '::', $v);
    return $v;
}

if($psr === false) {
    $proxy->output();
    exit();
} else {
    file_put_contents('lastsite.txt', $targ_site);
}
foreach ($psr->find('base,a,link') as $k => $v) {
    if (isset($v->href)) $v->href = repi($v->href);
    if (isset($v->integrity)) unset($v->integrity);
}

foreach ($psr->find('form') as $k => $v) $v->action = repi($v->action);

foreach ($psr->find('frame,iframe,img') as $k => $v) $v->src = repi($v->src);

foreach ($psr->find('script') as $k => $v) {
    if (isset($v->src)) $v->src = repi($v->src);
    if (isset($v->integrity)) unset($v->integrity);
    //if(isset($v->innertext)) $v->innertext=repi($v->innertext);
}

$proxy->setContent($psr->root->innertext());
$proxy->output();
