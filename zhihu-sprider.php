#!/usr/bin/php
<?php
/**
 * 知乎 爬虫
 * 
 * @author  Yang,junlong at 2016-03-08 13:43:50 build.
 * @version $Id$
 */

error_reporting(E_ALL);

if (function_exists( 'date_default_timezone_set' )){
	date_default_timezone_set('UTC');
}

require_once 'Http.class.php';
require_once 'Mysql.class.php';
require_once 'simple_html_dom.php';
require_once 'checkLogin.php';

$http = new Http('http://www.zhihu.com/', array('request_headers' => array('Cookie'=>getLoginCookie())));

$dom = new simple_html_dom();

$lock_name = 'lock';

$time = time();


if(file_exists($lock_name)) {
	$currentmodif = filemtime($lock_name);

	if(($time - $currentmodif) > 600) {
		if(file_exists('lock')){
			unlink($lock_name);
		}
	} else {
		return;
	}
}

//checkLogin();

file_put_contents('lock', '1');

//配置
// 入口种子用户
$seeds = array('kaifulee', 'onlyswan');

$process_count = 8;

// 开启8个进程
for ($i = 1; $i <= $process_count; $i++) {
	try {
		$pid = pcntl_fork();
		if ($pid == -1) {
			echo "Could not fork!\n";
			exit(1);
		}
		if (!$pid) {
			$_pid = getmypid();
			echo "child process $_pid running\n";

			while ( true) {
			    save_user_index();
			}

			exit($_pid);
		}
	} catch(Exception $e) {
		if(file_exists('lock')){
			unlink($lock_name);
		}
	}
}

while (pcntl_waitpid(0, $status) != -1) {
    $status = pcntl_wexitstatus($status);
    echo "Child $status completed\n";

    if(file_exists('lock')){
		unlink($lock_name);
	}
}

function save_user_index() {
	$dbh = get_dbh();
	
	$username = get_user_queue();
	$progress_id = posix_getpid();

	$time = time();

	if (!empty($username)) {
		$username = addslashes($username);
		// 更新采集时间, 让队列每次都取到不同的用户
        $dbh->update('people_index', array('index_uptime'=>$time, 'index_progress_id'=>$progress_id), array('username' => $username));
        zh_log("采集用户 --- " . $username . " --- 开始");

        //$followees_user = get_user_index($username, 'followees');
        //zh_log("采集用户列表 --- " . $username . " --- 关注了 --- 成功");
        // 获取关注者
        $followers_user = get_user_index($username, 'followers');
        zh_log("采集用户列表 --- " . $username . " --- 关注者 --- 成功");

        // 合并 关注了 和 关注者
        //$user_rows = array_merge($followers_user, $followees_user);

	} else {
		zh_log("采集用户 ---  队列不存在");
	}
}

function get_user_queue($key = 'index', $count = 10000){
	$dbh = get_dbh();
	global $seeds;

	$redis = get_redis();

	$redis_key = 'zhihu_user_queue';

    // 如果队列为空, 从数据库取一些
    if (!$redis->lsize($redis_key)) {
        $sql = "Select `username`, `index_uptime` From `people_index` Order By `index_uptime` Asc Limit {$count}";
        $result = $dbh->query($sql);
        $rows = $dbh->fetch_all($result);
        if(!$rows) {
        	$rows = array();

        	foreach ($seeds as $index => $value) {
        		$rows[$index] = array(
        			'username' => $value
        		);

        		$dbh->save('people_index', $rows[$index], array('username' => $value));
        	}
        }
        foreach ($rows as $row) {
            //echo $row['username'] . " --- " . date("Y-m-d H:i:s", $row['index_uptime']) . "\n";
            $redis->lpush($redis_key, $row['username']);
        }
    }
    // 从队列中取出一条数据
    return $redis->lpop($redis_key);
}


function get_user_index($username, $user_type = 'followees', $worker = null) {
	global $http;
	static $userInfo = array();

    echo $url = "https://www.zhihu.com/people/{$username}/{$user_type}";

    $http->get($url, function($html) use($http, $userInfo){
    	global $dom;

    	$html = $dom->load($html);

    	$followers_list = $html->find('.zh-general-list', 0);

    	if(!$followers_list){
    		return;
    	}

    	$ajax_params = $followers_list->getAttribute('data-init');

    	$ajax_params = empty($ajax_params) ? '' : json_decode(html_entity_decode($ajax_params), true);

    	$count = count($followers_list->children());

    	$_xsrf = $html->find('input[name="_xsrf"]', 0)->value;

    	if (!empty($_xsrf) && !empty($ajax_params) && is_array($ajax_params)) {
    		$url = "http://www.zhihu.com/node/" . $ajax_params['nodename'];
    		$params = $ajax_params['params'];

    		$params['offset'] = 30;
            $post_data = array(
                'method'=>'next',
                'params'=>json_encode($params),
                '_xsrf'=>$_xsrf,
            );

            $userInfo = get_user_info($url, $post_data);
    	}
    });
}



function get_user_info($url, $data, $offset = 0){
	global $http;
	static $userInfo = array();
	static $userCount = 0;

	$params = json_decode($data['params'], true);
	$params['offset'] = $offset;
	$data['params'] = json_encode($params);

	$http->post($url, $data, function($result) use (&$userInfo, &$userCount, $url, $data, $offset){
		global $dom;

		$json = json_decode($result, true);
		$msg = $json['msg'];

		$user_count = count($msg);

		if($user_count == 0) {
			return false;
		}

		file_put_contents('lock', '1');

		foreach ($msg as $key => $value) {
	        $html = $dom->load($value);
	        $username_ret = $html->find('.zm-list-content-title a', 0);

	        $href = $username_ret->href;

	        $nickname = $username_ret->text();
	        $username = substr($href, strrpos($href, '/') + 1);

	        $userInfo[$username] = array(
	        	'username' => addslashes($username),
	        	'nickname' => addslashes($nickname)
	        );

	        saveUserInfo($userInfo[$username]);
	    }

	    echo $userCount += $user_count;

	    get_user_info($url, $data, $offset + 20);
    });

    return $userInfo;
}


function saveUserInfo($data) {
    $dbh = get_dbh();

    $data['ctime'] = time();

    $dbh->save('people_index', $data, array('username'=>$data['username']));

    echo "{$data['username']} success...\n";
}




// util
function get_redis() {
	static $instances = array();
	$key = getmypid();
	if (empty($instances[$key])){
		$instances[$key] = new Redis();
		$instances[$key]->connect('127.0.0.1', '6379');
	}
	return $instances[$key];
}

function zh_log($msg){
	$msg = "[".date("Y-m-d H:i:s")."] " . $msg . "\n";
	echo $msg;

    //file_put_contents($this->zh_log_file, $msg, FILE_APPEND | LOCK_EX); }
}

function get_dbh() {
	static $instances = array();
	$key = getmypid();
	if (empty($instances[$key])){
		$instances[$key] = new Mysql('127.0.0.1', 'root', 'Yjl&2014', 'zhihu');
		$instances[$key]->set_char();
	}
	return $instances[$key];
}

if(file_exists('lock')){
	unlink($lock_name);
}
