<?php
$db['master'] = array(
    'type'    => Swoole\Database::TYPE_MYSQL, //Database Driver������ѡ��PdoDB , MySQL, MySQL2(MySQLi) , AdoDb(��Ҫ��װadodb���)
    'host'    => "localhost",
    'port'    => 3306,
    'dbms'    => 'mysql',
    'engine'  => 'MyISAM',
    'user'    => "root",
    'passwd'  => "root",
    'name'    => "test",
    'charset' => "utf8",
    'setname' => true,
);
return $db;