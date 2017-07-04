<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Utilities\Types\Arr;

use ZanPHP\Component\EtcdClient\V2\Error;
use ZanPHP\Component\EtcdClient\V2\EtcdClient;
use ZanPHP\Component\EtcdClient\V2\Node;
use ZanPHP\Component\EtcdClient\V2\Response;
use ZanPHP\Component\EtcdClient\V2\Subscriber;
use ZanPHP\Component\EtcdClient\V2\Watcher;

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

    private $store;

    private $chainMap;

    public function __construct($appName, array $config)
    {
        $this->config = $config;

        $this->appName = $appName;

        $this->watchKey = "/$appName";

        $etcdClient = new EtcdClient([
            "endpoints" => Config::get("registry.etcd.nodes", []),
            "timeout" => static::DEFAULT_TIMEOUT,
        ]);
        $this->etcdKeyAPI = $etcdClient->keysAPI(static::KEY_PREFIX);

        $by = $appName;

        $this->store = ServiceChainStore::getInstanceBy($by, $appName);

        $this->chainMap = ServiceChainMap::getInstanceBy($by, $appName);
    }

    public function discover()
    {
        $task = $this->discoveringByEtcd();
        Task::execute($task);

        $timeout = Arr::get($this->config, "watch.timeout", self::DEFAULT_WATCH_TIMEOUT);
        $watcher = $this->etcdKeyAPI->watch($this->watchKey, $this);
        $watcher->watch([ "timeout" => $timeout ]);
    }

    public function getEndpoint($scKey)
    {
        return $this->chainMap->getEndpoint($scKey);
    }

    public function flush()
    {
        $this->store->setChainKeyMap($this->chainMap->getMap());
    }

    private function discoveringByEtcd()
    {
        try {
            $resp = (yield $this->etcdKeyAPI->get($this->watchKey, [
                "recursive" => true,
                "timeout" => Arr::get($this->config,"discovery.timeout", self::DEFAULT_DISCOVER_TIMEOUT),
            ]));

            if ($resp instanceof Response) {
                if ($resp->node && $nodes = $resp->node->nodes) {
                    $this->parseServiceChainKeyNodes($nodes);
                }
                return;
            }

            /** @var Error $error */
            $error = $resp;
            if ($error->index) {
                $this->store->updateWaitIndex($error->index);
            }

            if (!$error->isKeyNotFound()) {
                sys_error("service chain discovery fail:" . $error->__toString());
            }
        } catch (\Throwable $t) {
            echo_exception($t);
            sys_error("service chain discovery by etcd fail [app=$this->appName]");
        } catch (\Exception $ex) {
            echo_exception($ex);
            sys_error("service chain discovery by etcd fail [app=$this->appName]");
        }

        Timer::after(5000, function() {
            $task = $this->discoveringByEtcd();
            Task::execute($task);
        });
    }

    /**
     * @param Node[] $nodes
     */
    private function parseServiceChainKeyNodes(array $nodes)
    {
        $waitIndexList = [ 0 ];
        foreach ($nodes as $scKeyNode) {
            $this->parseServiceChainKeyNode($scKeyNode);

            // TODO 处理最外层 waitIndex ?!
            if ($scKeyNode->modifiedIndex) {
                $waitIndexList[] = $scKeyNode->modifiedIndex;
            }
        }

        $this->store->updateWaitIndex(max(...$waitIndexList) + 1);
        $this->flush();
    }

    private function parseServiceChainKeyNode(Node $scKeyNode)
    {
        if (!$scKeyNode->dir) {
            sys_error("service chain: invalid app key `$scKeyNode->key`");
            return;
        }

        if (empty($scKeyNode->nodes)) {
            return;
        }

        foreach ($scKeyNode->nodes as $serverNode) {
            if (!$serverNode->dir) {
                sys_error("service chain: invalid chain key `$serverNode->key`");
                continue;
            }
            $this->chainMap->create($serverNode);
        }
    }

    public function getCurrentIndex()
    {
        return $this->store->getCurrentIndex();
    }

    public function updateWaitIndex($index)
    {
        $this->store->updateWaitIndex($index);
    }

    /**
     * @param Watcher $watcher
     * @param Response|Error $response
     * @return void
     */
    public function onChange(Watcher $watcher, $response)
    {
        if ($response instanceof Error) {
            sys_error("service chain watch fail:" . $response->__toString());
            return;
        }

        // TODO 根据 $response->action 判断
        // set update create delete

        $flush = false;

        if ($response->node && !$response->prevNode) {
            // create
            $flush = $this->chainMap->create($response->node);
        } else if ($response->node && $response->prevNode) {
            // update
            $flush = $this->chainMap->update($response->node);
        } else if (!$response->node && $response->prevNode) {
            // delete
            $flush = $this->chainMap->delete($response->node);
        } else {

        }

        if ($flush) {
            $this->flush();
        }
    }
}