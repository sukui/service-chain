<?php

namespace ZanPHP\ServiceChain;

use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Foundation\Coroutine\Task;
use ZanPHP\Cache\APCuStore;
use ZanPHP\Container\Container;
use ZanPHP\Contracts\Cache\ShareMemoryStore;
use ZanPHP\EtcdClient\V2\EtcdClient;
use ZanPHP\EtcdClient\V2\LocalSubscriber;
use ZanPHP\ServiceChain\EtcdDiscovery;


require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/../../etcd-client/vendor/autoload.php";
require __DIR__ . "/../../contracts/vendor/autoload.php";
require __DIR__ . "/../../container/vendor/autoload.php";
require __DIR__ . "/../../cache/vendor/autoload.php";



define("ETCD_HOST", "xx.xx.xx");

Config::init();
Config::set("registry.etcd.nodes", [
    [
        "host" => ETCD_HOST,
        "port" => 2379,
    ],
]);



$container = Container::getInstance();
$container->bind(ShareMemoryStore::class, APCuStore::class);
$container->when(APCuStore::class)
    ->needs('$prefix')
    ->give('test_');




call_user_func(function() {
    // http://xx.xx.xx.xx:2379/v2/keys/service_chain/app_to_chain_nodes/scrm-customer-base/test_key/xx.xx.xx.xx:8011
    $perfEndpoints = [[
        "host" => ETCD_HOST,
        "port" => 2379,
    ]];
    $etcdClient = new EtcdClient([
        "endpoints" => $perfEndpoints,
        "timeout" => 1000,
    ]);
    $prefix = "/service_chain/app_to_chain_nodes";
    $keysAPI = $etcdClient->keysAPI($prefix);


    $watcher = $keysAPI->watch("/scrm-customer-base", new LocalSubscriber(function() {
        var_dump(func_get_args());
    }));
    $watcher->watch([
        "timeout" => 1000, // long polling timeout
    ]);
});
return;


call_user_func(function() {
    $etcd = new EtcdDiscovery("test-app", [
        "watch" => [
            "full_update" => true,
            "timeout" => 2000,
        ]
    ]);
    $etcd->discover();
});


call_user_func(function() {
    $testEndpoints = [
        [
            "host" => ETCD_HOST,
            "port" => 2379,
        ],
        [
            "host" => ETCD_HOST,
            "port" => 2379,
        ],
    ];

    $etcdClient = new EtcdClient([
        "endpoints" => $testEndpoints,
        "timeout" => 1000,
    ]);
    $prefix = "/service_chain/app_to_chain_nodes";
    $keysAPI = $etcdClient->keysAPI($prefix);

    $change = function() use($keysAPI, &$change) {
        try {
            (yield $keysAPI->delete("/test-app", ["dir" => true, "recursive" => true,]));
            yield taskSleep(1000);

            yield $keysAPI->set("/test-app/chain_a/127.0.0.1:8000", rand(1, 10));
            yield taskSleep(1000);
            yield $keysAPI->set("/test-app/chain_a/127.0.0.1:8001", rand(1, 10));
            yield taskSleep(1000);
            yield $keysAPI->deleteDir("/test-app/chain_a/127.0.0.1:8000", true);
            yield taskSleep(1000);
            yield $keysAPI->deleteDir("/test-app/chain_a/127.0.0.1:8001", true);
            yield taskSleep(1000);

//            // ttl refresh 貌似不触发 修改事件
//            yield $keysAPI->refreshTTL("/a/b/c", rand(1, 10));
//            yield taskSleep(1000);
//
//            yield $keysAPI->set("/a/b/c", rand(1, 10));
//            yield taskSleep(1000);
//
//            yield taskSleep(1000);
//            yield $keysAPI->delete("/a/b/c");

        } catch (\Throwable $e) {
            echo_exception($e);
        } catch (\Exception $e) {
            echo_exception($e);
        }

        yield taskSleep(1000);
        Task::execute($change());
    };

    Task::execute($change());
});



//$apcu = new ApcuDiscovery("test-app");
//$apcu->discover();
