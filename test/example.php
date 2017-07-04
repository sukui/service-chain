<?php

namespace ZanPHP\Component\ServiceChain;

use Zan\Framework\Foundation\Core\Config;
use ZanPHP\Component\ServiceChain\EtcdDiscovery;


require "/Users/chuxiaofeng/yz_env/webroot/zan-com/tcp-demo/vendor/autoload.php";
require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/../../etcd-client/vendor/autoload.php";


Config::init();
Config::set("registry.etcd.nodes", [
    [
        "host" => "etcd-dev.s.qima-inc.com",
        "port" => 2379,
    ],
]);


// TODO
// 各种 edge case 的测试
// 1. CURD
// 2. 401 问题


$etcd = new EtcdDiscovery("test-app", [
    "watch" => [
        "timeout" => 2000,
    ]
]);

$etcd->discover();

//$apcu = new ApcuDiscovery("test-app");
//$apcu->discover();
