<?php
define('DEBUG', 'on');
define("WEBPATH", str_replace("\\","/", __DIR__));
require __DIR__.'/libs/lib_config.php';
//require __DIR__'/phar://swoole.phar';
Swoole\Config::$debug = true;
$AppSvr = new Swoole\Network\Protocol\AppServer();
$AppSvr->loadSetting(__DIR__."/swoole.ini"); //���������ļ�
$AppSvr->setAppPath(__DIR__.'/apps/'); //����Ӧ�����ڵ�Ŀ¼
$AppSvr->setLogger(new Swoole\Log\EchoLog(true));

$server = new \Swoole\Network\Server('0.0.0.0', 8888);
$server->setProtocol($AppSvr);
$server->run(array('worker_num' => 4, 'max_request' => 5000));
