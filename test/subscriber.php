<?php

use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Utilities\Types\Arr;
use ZanPHP\Component\ServiceChain\Subscriber;





require "/Users/chuxiaofeng/yz_env/webroot/zan-com/tcp-demo/vendor/autoload.php";
require __DIR__ . "/../src/Subscriber.php";


Config::init();


Config::set("registry.etcd.nodes", [
    [
        "host" => "etcd-dev.s.qima-inc.com",
        "port" => 2379,
    ],
]);

$sub = new Subscriber([], "test-app");
$sub->workByEtcd();