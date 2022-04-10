<?php

class PHPProxy {
/*
Mode:
1 - force cache only (0 kb/s),
    when not cached - return 503 NotProxyCached

2 - cache all (<5kb/s)

3 - cache media (<20kb/s)

4 - cache if timeout/shutdown (only GET)
    (for non stable network)

*/

  private $mode = 1;
  private $cachedelimiter = "\r\n\r\n::PROXYCACHE::\r\n\r\n";
  private $cookieFile;
  public $result;
  private $useragent = 'Mozilla/5.0 (Linux; Mobile; Android) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/99.0.0.0 Safari/537.36';

  public function __construct($opts = array()) {

    $this->cookieFile = 'cookies.txt';

    if(isset($opts['cookiefile'])) {
      $this->cookieFile = $opts['cookiefile'];
    }
    if(isset($opts['useragent'])) {
      $this->useragent = $opts['useragent'];
    }
  }

    public function sanitize_file_name($file_name) {
        return preg_replace('/\/$/', '/index.php', preg_replace('/[^a-zA-Z0-9\/\\-\\._]/', '_', $file_name));
    }

  public function request($opts) {

    if(!$opts['url']) {
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

    if($opts['method'] === 'post') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }

    if($opts['data']) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['data']);
    }

    if($opts['referer']) {
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
    
    if(!$rough_content) die('503 ProxyCacheNoNetwork');
    
    $header['errno']   = $err;
    $header['errmsg']  = $errmsg;
    $header['headers']  = $header_content;
    $header['content'] = $body_content;
    $header['cookies'] = $cookiesOut;
    $this->result = $header;
    return $header;
  }

  public function proxy($baseUrl, $opts = array()) {
    $data = array();
    $urloriginal = preg_replace('/\/$/', '', $baseUrl);
    if(strpos($urloriginal,'/',8)===false) $urloriginal .= '/';
    $url2 = preg_replace('/^https?:\/\//', '', $urloriginal);
    if(count($_POST)) {
      foreach($_POST as $k => $v) {
        if(is_array($v)) {
          $this->_stringifyPostField($v, $data, $k);
        } else {
          $data[$k] = $v;
        }
      }
    } else if($this->mode == 1 && $this->isCached($url2)){
      $this->load($url2);
      return $this->result;
    }
    if(isset($opts['data'])) {
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
      $res = db::ins('cachedpages', ['page' => $f]);
      return file_put_contents('data/' . $res, $this->result['headers'] . $this->cachedelimiter . $this->result['content']);


    /*$f = $this->sanitize_file_name($f);
    if(!empty($_POST)) return;
    //if(strpos($f,'/')===false) $f .= '/index.php';
    $f = preg_replace('/\\/\\??$/', '/index.php', $f);
    $dir = dirname($f);
    @mkdir('data/' . $dir, 0777, true);
    return file_put_contents('data/' . $f, $this->result['headers'] . $this->cachedelimiter . $this->result['content']);
 */ }

  public function load($f) {
      $res = db::que('SELECT `id` FROM `cachedpages` WHERE `page`=? LIMIT 1', [$f])->fetch(PDO::FETCH_ASSOC);
      $r = explode($this->cachedelimiter, file_get_contents('data/' . $res));
      $this->result['headers'] = $r[0];
      $this->result['content'] = $r[1];


/*
      $f = $this->sanitize_file_name($f);
    $r = explode($this->cachedelimiter, file_get_contents('data/' . $f));
    $this->result['headers'] = $r[0];
    $this->result['content'] = $r[1];*/
  }

  public function isCached($f) {
      $res = db::que('SELECT `id` FROM `cachedpages` WHERE `page`=? LIMIT 1', [$f])->num_rows();

/*
      $f = $this->sanitize_file_name($f);
    return file_exists('data/' . $f);*/
  }

  public function getContent() {
    return $this->result['content'];
  }

  public function setContent($content) {
    return $this->result['content'] = $content;
  }

  public function output() {
    // output relevant headers
    foreach(explode("\n", $this->result['headers']) as $header) {
      if(preg_match('/^(Status|HTTP|Content-Type)/i', trim($header))) {
        if(preg_match('/^HTTP.+(\d\d\d)/i', trim($header), $matches)) {
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
    foreach($field as $k => $v) {
      $newKey = $previous.'['.$k.']';
      if(is_array($v)) {
        $this->_stringifyPostField($v, $data, $newKey);
      } else {
        $data[$newKey] = $v;
      }
    }
    return $data;
  }
}