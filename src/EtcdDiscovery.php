<?php

namespace ZanPHP\ServiceChain;


use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Foundation\Core\Debug;
use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Utilities\Types\Arr;

use ZanPHP\EtcdClient\V2\Error;
use ZanPHP\EtcdClient\V2\EtcdClient;
use ZanPHP\EtcdClient\V2\Node;
use ZanPHP\EtcdClient\V2\Response;
use ZanPHP\EtcdClient\V2\Subscriber;
use ZanPHP\EtcdClient\V2\Watcher;

class EtcdDiscovery implements Subscriber, ServiceChainDiscovery
{
    const DEFAULT_TIMEOUT           = 1000;
    const DEFAULT_DISCOVER_TIMEOUT  = 3000;
    const DEFAULT_WATCH_TIMEOUT     = 30000;

    const KEY_PREFIX = "/service_chain/app_to_chain_nodes";

    private $config;

    private $appName;

    private $watchKey;

    private $etcdKeyAPI;

    private $etcdWatcher;

    private $store;

    private $chainMap;

    private $waitIndex;

    private $isFullUpdate;

    public function __construct($appName, array $config = [])
    {
        // 同 config/$env/registry.php
        $defaultConf = [
            "watch" => [
                "full_update" => true,
                "timeout" => self::DEFAULT_WATCH_TIMEOUT,
            ],
            "discovery" => [
                "timeout" => self::DEFAULT_DISCOVER_TIMEOUT,
            ],
        ];

        $this->config = Arr::merge($defaultConf, $config);

        $this->isFullUpdate = $this->config["watch"]["full_update"];

        $this->appName = $appName;

        $this->watchKey = "/$appName";

        $etcdClient = new EtcdClient([
            "endpoints" => Config::get("registry.etcd.nodes", []),
            "timeout" => static::DEFAULT_TIMEOUT,
        ]);

        $this->etcdKeyAPI = $etcdClient->keysAPI(static::KEY_PREFIX);

        $this->etcdWatcher = $this->etcdKeyAPI->watch($this->watchKey, $this);

        $this->store = new ServiceChainStore($appName);

        $this->chainMap = new ServiceChainMap($appName);
    }

    public function discover()
    {
        if (Debug::get()) {
            sys_echo("service chain discovery by etcd");
        }

        $task = $this->doDiscover();
        Task::execute($task);
    }

    private function doDiscover()
    {
        try {
            $resp = (yield $this->etcdKeyAPI->get($this->watchKey, [
                "recursive" => true,
                "timeout" => $this->config["discovery"]["timeout"],
            ]));

            if ($resp instanceof Response) {
                if ($resp->node && $resp->node->nodes) {
                    $this->parseServiceChainKeyNodes($resp->node);
                }

                // 开始 watch
                $timeout = $this->config["watch"]["timeout"];
                $this->etcdWatcher->watch([ "timeout" => $timeout ], $this->isFullUpdate);
                return;
            }

            /** @var Error $error */
            $error = $resp;
            if ($error->index) {
                $this->updateWaitIndex($error->header->etcdIndex + 1);
            }

            if (!$error->isKeyNotFound()) {
                sys_error("service chain discovery fail:" . $error);
            }
        } catch (\Throwable $t) {
            echo_exception($t);
            sys_error("service chain discovery by etcd fail [app=$this->appName]");
        } catch (\Exception $ex) {
            echo_exception($ex);
            sys_error("service chain discovery by etcd fail [app=$this->appName]");
        }

        Timer::after(5000, function() {
            $task = $this->doDiscover();
            Task::execute($task);
        });
    }

    public function getEndpoints($scKey = null)
    {
        if ($scKey === null) {
            return $this->chainMap->getMap();
        } else {
            return $this->chainMap->getEndpoint($scKey);
        }
    }

    public function getCurrentIndex()
    {
        return $this->waitIndex;
    }

    public function updateWaitIndex($index)
    {
        $this->waitIndex = $index;
    }

    /**
     * @param Watcher $watcher
     * @param Response|Error $response
     * @return void
     */
    public function onChange(Watcher $watcher, $response)
    {
        if ($response instanceof Error) {
            return;
        }

        if ($this->isFullUpdate) {
            $this->doFullChange($response);
        } else {
            $this->doIncrementalChange($response);
        }
    }

    private function doFullChange($response)
    {
        $maps = [];
        if ($response->node && $nodes = $response->node->nodes) {
            foreach ($nodes as $scKeyNode) {
                $maps[] = $this->chainMap->parseNodes($scKeyNode);
            }
        }
        $map = Arr::merge(...$maps);

        $this->chainMap->setMap($map);
        $this->store->setChainKeyMap($map);
    }

    private function doIncrementalChange($response)
    {
        $flush = false;

        if (($response->action === "delete" && $response->node) || (!$response->node && $response->prevNode)) {
            $flush = $this->chainMap->delete($response->node);
        } else if ($response->action === "set" && $response->node && !$response->prevNode) { // create
            $flush = $this->chainMap->create($response->node);
        } else if ($response->action === "set" && $response->node && $response->prevNode) { // update
            $flush = $this->chainMap->update($response->node);
        }

        if ($flush) {
            $this->store->setChainKeyMap($this->chainMap->getMap());
        }
    }

    /**
     * for incremental watch
     * @param Node $appNode
     */
    private function parseServiceChainKeyNodes(Node $appNode)
    {
        $waitIndexList = [ $this->getCurrentIndex(), $appNode->modifiedIndex ];

        foreach ($appNode->nodes as $scKeyNode) {
            $this->parseServiceChainKeyNode($scKeyNode);
            if ($scKeyNode->modifiedIndex) {
                $waitIndexList[] = $scKeyNode->modifiedIndex;
            }
        }

        // 从nodes最大的waitIndex开始监听, ??
        $this->updateWaitIndex(max(...$waitIndexList) + 1);

        $map = $this->chainMap->getMap();
        $this->store->setChainKeyMap($map);
    }

    /**
     * for incremental watch
     * @param Node $scKeyNode
     */
    private function parseServiceChainKeyNode(Node $scKeyNode)
    {
        if (!$scKeyNode->dir) {
            sys_error("service chain: invalid app key `$scKeyNode->key`: is dir");
            return;
        }

        if (empty($scKeyNode->nodes)) {
            return;
        }

        foreach ($scKeyNode->nodes as $serverNode) {
            // $prefix/$app/$key/$ip:$port/...
            /*
            if (!$serverNode->dir) {
                sys_error("service chain: invalid chain key `$serverNode->key`: is dir");
                continue;
            }
            */
            $this->chainMap->create($serverNode);
        }
    }
}