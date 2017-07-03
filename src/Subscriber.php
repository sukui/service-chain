<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Foundation\Coroutine\Task;
use Zan\Framework\Network\Common\Exception\HttpClientTimeoutException;
use Zan\Framework\Network\Common\HttpClient;
use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Network\ServerManager\Exception\ServerDiscoveryEtcdException;
use Zan\Framework\Network\ServerManager\ServerRegister;
use Zan\Framework\Network\ServerManager\ServerStore;
use Zan\Framework\Utilities\Types\Arr;
use Zan\Framework\Utilities\Types\Json;
use Zan\Framework\Utilities\Types\Time;

class Subscriber
{
    const DISCOVERY_LOOP_TIME    = 1000;
    const WATCH_LOOP_TIME        = 5000;
    const WATCH_STORE_LOOP_TIME  = 1000;
    const DEFAULT_DISCOVER_TIMEOUT = 3000;
    const DEFAULT_LOOKUP_TIMEOUT = 30000;
    const DEFAULT_WATCH_TIMEOUT  = 30000;

    const SUB_KEY_PREFIX = "/service_chain/app_to_chain_nodes";

    private $config;

    private $appName;

    private $serverStore;

    /**
     * @var array serviceChainKey => [[ip, port], ]
     */
    private $keyMap = [];

    private $subUrl;

    public function __construct($config, $appName)
    {
        $this->config = $config;
        $this->appName = $appName;

        $prefix = static::SUB_KEY_PREFIX;
        $this->subUrl = "/v2/keys{$prefix}/$appName";

        $this->serverStore = ServerStore::getInstance("service_chain");
    }

    public function workByEtcd()
    {
        $this->discoverByEtcdTask();
        $this->watchByEtcdTask();
    }

    public function workByStore()
    {
        $this->discoverByStore();
    }

    public function discoverByEtcdTask()
    {
        $coroutine = $this->discoveringByEtcd();
        Task::execute($coroutine);
    }

    private function discoveringByEtcd()
    {
        try {
            $this->keyMap = (yield $this->getByEtcd());
            return;
        } catch (\Throwable $ex) {
        } catch (\Exception $ex) {}

        sys_error("service chain discovery by etcd fail [app=$this->appName]");
        echo_exception($ex);

        Timer::after(2000, function() {
            $co = $this->discoveringByEtcd();
            Task::execute($co);
        });
    }

    public function discoverByStore()
    {
        $keyMap = $this->getByStore();
        if (null == $keyMap) {
            $discoveryLoopTime = Arr::get($this->config, "discovery.loop_time", self::DISCOVERY_LOOP_TIME);
            Timer::after($discoveryLoopTime, [$this, 'discoverByStore'], $this->getGetServicesJobId());
        } else {
            // TODO
            $this->keyMap = $keyMap;

            $this->checkWatchingByEtcd();
            $this->watchByStore();
        }
    }

    private function getByStore()
    {
        return $this->serverStore->getServices($this->appName);
    }

    private function getByEtcd()
    {
        $node = ServerRegister::getRandEtcdNode();

        $httpClient = new HttpClient($node["host"], $node["port"]);
        $discoveryTimeout = Arr::get($this->config,"discovery.timeout", self::DEFAULT_DISCOVER_TIMEOUT);
        $response = (yield $httpClient->get("$this->subUrl", ["recursive" => true], $discoveryTimeout));
        $raw = $response->getBody();
        $jsonData = Json::decode($raw, true);
        $result = $jsonData ? $jsonData : [];

        $keyMap = $this->parseEtcdData($result);
        $this->saveServices($keyMap);
        yield $keyMap;
    }

    private function parseEtcdData($raw)
    {
        if (null === $raw || [] === $raw) {
            throw new ServerDiscoveryEtcdException("service chain discovery can not find key of the app {$this->appName}");
        }

        $serviceChainKeys = Arr::get($raw, "node.nodes", []);
        if (empty($serviceChainKeys)) {
            if (isset($raw["index"])) {
                $this->serverStore->setServiceWaitIndex($this->appName, $raw["index"]);
            }
            $detail = null;
            if (isset($raw["errorCode"])) {
                $detail = "[errno={$raw["errorCode"]}, msg={$raw["message"]}, cause={$raw["cause"]}]";
            } else if (isset($raw["errno"])) {
                $detail = "[errno={$raw["errno"]}, msg={$raw["msg"]}]";
            }
            throw new ServerDiscoveryEtcdException("service chain can not find node of the app {$this->appName} $detail");
        }
        $keyMap = [];
        $waitIndex = 0;
        foreach ($serviceChainKeys as $scKeys) {
            $map = $this->parseServiceChainKeyNodes($scKeys);
            $keyMap = Arr::merge($keyMap, $map);
            // TODO 处理最外层 waitIndex ?!
            $waitIndex = $waitIndex >= $scKeys['modifiedIndex'] ? $waitIndex : $scKeys['modifiedIndex'];
        }
        $waitIndex = $waitIndex + 1;
        $this->serverStore->setServiceWaitIndex($this->appName, $waitIndex);

        if (empty($keyMap)) {
            throw new ServerDiscoveryEtcdException("service chain can not find any valid node of the app $this->appName");
        }

        return $keyMap;
    }

    private function parseServiceChainKeyNodes($scKeys)
    {
        $keyMap = [];

        $scKeyPath = $scKeys["key"];
        $isDir = isset($scKeys["dir"]) && $scKeys["dir"];

        if (!$isDir) {
            sys_error("service chain: invalid app key `$scKeyPath`");
            return $keyMap;
        }

        $scKeysNodes = Arr::get($scKeys, "nodes");
        if (empty($scKeysNodes)) {
            return $keyMap;
        }


        foreach ($scKeysNodes as $serverNode) {
            $nodeKeyPath = $serverNode["key"];

            $isDir = isset($serverNode["dir"]) && $serverNode["dir"];
            if (!$isDir) {
                sys_error("service chain: invalid chain key `$nodeKeyPath`");
                continue;
            }

            $r = $this->parseServiceChainKey($nodeKeyPath);
            if ($r) {
                list($scKey, $ip, $port) = $r;

                if (!isset($keyMap[$scKey])) {
                    $keyMap[$scKey] = [];
                }

                $keyMap[$scKey]["$ip:$port"] = [$ip, $port];
            }
        }

        return $keyMap;
    }

    private function parseServiceChainKey($nodeKeyPath)
    {
        $prefixPath = static::SUB_KEY_PREFIX . "/{$this->appName}/";

        $serviceChainKey = substr($nodeKeyPath, strlen($prefixPath));
        $pos1 = strpos($serviceChainKey, "/");
        $pos2 = strpos($serviceChainKey, ":");
        if ($pos1 === false || $pos2 === false || $pos2 < $pos1) {
            sys_error("service chain: invalid chain key `$nodeKeyPath`");
            return false;
        }


        list($scKey, $ipPort) = explode("/", $serviceChainKey);
        list($ip, $port) = explode(":", $ipPort);

        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        if ($ip === false) {
            sys_error("service chain: invalid chain key `$nodeKeyPath`");
            return false;
        }

        return [$scKey, $ip, intval($port)];
    }

    private function saveServices($servers)
    {
        return $this->serverStore->setServices($this->appName, $servers);
    }

    public function watchByEtcdTask()
    {
        $coroutine = $this->watchByEtcd();
        Task::execute($coroutine);
    }

    private function watchByEtcd()
    {
        while (true) {
            $this->setDoWatchByEtcd();
            try {
                $raw = (yield $this->watchingByEtcd());
                if (null != $raw) {
                    $this->updateServersByEtcd($raw);
                }
            } catch (HttpClientTimeoutException $e) {
                yield taskSleep(50);
            } catch (\Throwable $t) {
                echo_exception($t);
                yield taskSleep(50);
            } catch (\Exception $ex) {
                echo_exception($ex);
                yield taskSleep(50);
            }
        }
    }

    private function setDoWatchByEtcd()
    {
        return $this->serverStore->setDoWatchLastTime($this->appName);
    }

    private function watchingByEtcd()
    {
        $waitIndex = $this->serverStore->getServiceWaitIndex($this->appName);
        $params = $waitIndex > 0 ? ['wait' => true, 'recursive' => true, 'waitIndex' => $waitIndex] : ['wait' => true, 'recursive' => true];

        $node = ServerRegister::getRandEtcdNode();
        $httpClient = new HttpClient($node["host"], $node["port"]);
        $watchTimeout = Arr::get($this->config, "watch.timeout", self::DEFAULT_WATCH_TIMEOUT);
        $response = (yield $httpClient->get($this->subUrl, $params, $watchTimeout));
        $raw = $response->getBody();
        $jsonData = Json::decode($raw, true);
        $result = $jsonData ? $jsonData : $raw;

        yield $result;
    }

    private function updateServersByEtcd($raw)
    {
        $isOutdated = $this->checkWaitIndexIsOutdatedCleared($raw);
        if (true == $isOutdated) {
            return;
        }
        $update = $this->parseWatchByEtcdData($raw);
        if (null == $update) {
            return;
        }

        // TODO

        if (isset($update['off_line'])) {
            sys_echo("watch by etcd nova client off line " . $this->appName . " host:" . $update['off_line']['host'] . " port:" . $update['off_line']['port']);
        }
        if (isset($update['add_on_line'])) {
            sys_echo("watch by etcd nova client add on line " . $this->appName . " host:" . $update['add_on_line']['host'] . " port:" . $update['add_on_line']['port']);
        }
        if (isset($update['update'])) {
            sys_echo("watch by etcd nova client update service " . $this->appName . " host:" . $update['update']['host'] . " port:" . $update['update']['port']);
        }
    }

    private function checkWaitIndexIsOutdatedCleared($raw)
    {
        $hasIndex = isset($raw['index']) && $raw['index'] > 0;
        if ($hasIndex && (isset($raw['errorCode']) || isset($raw["errno"]))) { /// $raw['errorCode'] == 401 !!
            sys_error("service chain waitIndex is outdated [errno={$raw["errorCode"]}, msg={$raw["message"]}, cause={$raw["cause"]}]");
            $waitIndex = $raw['index'] + 1;
            $this->serverStore->setServiceWaitIndex($this->appName, $waitIndex);
            return true;
        }  else {
            return false;
        }
    }

    // TODO 测试 create update delete
    private function parseWatchByEtcdData($raw)
    {
        if (null === $raw || [] === $raw) {
            throw new ServerDiscoveryEtcdException('watch service chain data error app_name :'.$this->appName);
        }
        if (!isset($raw['node']) && !isset($raw['prevNode'])) {
            if (isset($raw["index"])) {
                $this->serverStore->setServiceWaitIndex($this->appName, $raw["index"]);
            }
            $detail = null;
            if (isset($raw["errorCode"])) {
                $detail = "[errno={$raw["errorCode"]}, msg={$raw["message"]}, cause={$raw["cause"]}]";
            } else if (isset($raw["errno"])) {
                $detail = "[errno={$raw["errno"]}, msg={$raw["msg"]}]";
            }
            throw new ServerDiscoveryEtcdException("watch service chain can not find anything app_name:{$this->appName} $detail");
        }

        $nowStore = $this->getByStore();
        $waitIndex = $this->serverStore->getServiceWaitIndex($this->appName);

        // 注意: 非dev环境haunt, 因为下线节点不从etcd摘除, 理论上永远只会进去update分支
        // 1. update: 存在 node  && 存在 prevNode
        if (isset($raw['node']) && isset($raw['prevNode'])) {
            if (isset($raw['node']['modifiedIndex'])) {
                $waitIndex = $raw['node']['modifiedIndex'] >= $waitIndex ? $raw['node']['modifiedIndex'] : $waitIndex;
                $waitIndex = $waitIndex + 1;
                $this->serverStore->setServiceWaitIndex($this->appName, $waitIndex);
            }

            $r = $this->parseServiceChainKey($raw['node']['key']);
            if ($r) {
                list($scKey, $ip, $port) = $r;
                if (!isset($nowStore[$scKey])) {
                    $nowStore[$scKey] = [];
                }
                $nowStore[$scKey]["$ip:$port"] = [$ip, $port];
                $this->serverStore->setServices($this->appName, $nowStore);
            }
            return;
        }

        // 注意: 理论上node与prenode应该都存在, 这里兼容不同环境haunt的差异
        // 2. 离线: 只存在 prevNode node 不存在 node
        if (!isset($raw['node'])) {
            if (isset($raw['node']['modifiedIndex'])) {
                $waitIndex = $raw['node']['modifiedIndex'] >= $waitIndex ? $raw['node']['modifiedIndex'] : $waitIndex;
            }
            if (isset($raw['prevNode']['modifiedIndex'])) {
                $waitIndex = $raw['prevNode']['modifiedIndex'] >= $waitIndex ? $raw['prevNode']['modifiedIndex'] : $waitIndex;
            }
            $waitIndex = $waitIndex + 1;
            $this->serverStore->setServiceWaitIndex($this->appName, $waitIndex);

            $r = $this->parseServiceChainKey($raw['node']['key']);
            if ($r) {
                list($scKey, $ip, $port) = $r;
                if (isset($nowStore[$scKey])) {
                    unset($nowStore[$scKey]["$ip:$port"]);
                }
                $this->serverStore->setServices($this->appName, $nowStore);
            }
            return;
        }

        // 3. 上线: 不存在 prevNode && 只存在 node
        if (isset($raw['node']['modifiedIndex'])) {
            $waitIndex = $raw['node']['modifiedIndex'] >= $waitIndex ? $raw['node']['modifiedIndex'] : $waitIndex;
            $waitIndex = $waitIndex + 1;
            $this->serverStore->setServiceWaitIndex($this->appName, $waitIndex);
        }

        $r = $this->parseServiceChainKey($raw['node']['key']);
        if ($r) {
            list($scKey, $ip, $port) = $r;
            if (!isset($nowStore[$scKey])) {
                $nowStore[$scKey] = [];
            }
            $nowStore[$scKey]["$ip:$port"] = [$ip, $port];
            $this->serverStore->setServices($this->appName, $nowStore);
        }
    }

    public function checkWatchingByEtcd()
    {
        $isWatching = $this->checkIsWatchingByEtcdTimeout();
        if (!$isWatching) {
            $this->watchByEtcdTask();
            return;
        }
        $watchLoopTime = Arr::get($this->config, "watch.loop_time", self::WATCH_LOOP_TIME);
        Timer::after($watchLoopTime, [$this, 'checkWatchingByEtcd'], $this->getWatchServicesJobId());
    }

    private function checkIsWatchingByEtcdTimeout()
    {
        $watchTime = $this->serverStore->getDoWatchLastTime($this->appName);
        if (null === $watchTime) {
            return true;
        }

        $watchTimeout = Arr::get($this->config, "watch.timeout", self::DEFAULT_WATCH_TIMEOUT);
        if ((Time::current(true) - $watchTime) > ($watchTimeout + 10)) {
            return false;
        }
        return true;
    }

    // TODO
    public function watchByStore()
    {
        $watchStoreLoopTime = Arr::get($this->config, "watch_store.loop_time", self::WATCH_STORE_LOOP_TIME);
        Timer::after($watchStoreLoopTime, [$this, 'watchByStoreTask']);
    }

    public function watchByStoreTask()
    {
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        $coroutine = $this->watchingByStore();
        Task::execute($coroutine);
    }

    private function watchingByStore()
    {
        $storeServices = $this->serverStore->getServices($this->appName);


        return; // TODO

        $onLine = $offLine = $update = [];
        $useServices = NovaClientConnectionManager::getInstance()->getServersFromAppNameToServerMap($this->appName);
        if (!empty($storeServices)) {
            foreach ($useServices as $key => $service) {
                if (!isset($storeServices[$key])) {
                    $offLine[$key] = $service;
                } elseif (isset($useServices[$key]) && $service != $useServices[$key]) {
                    $update[$key] = $service;
                }
            }
            foreach ($storeServices as $key => $service) {
                if (!isset($useServices[$key])) {
                    $onLine[$key] = $service;
                }
            }
            if ([] != $offLine) {
                NovaClientConnectionManager::getInstance()->offline($this->appName, $offLine);
            }
            if ([] != $onLine) {
                NovaClientConnectionManager::getInstance()->addOnline($this->appName, $onLine);
            }
            if ([] != $update) {
                NovaClientConnectionManager::getInstance()->update($this->appName, $update);
            }
        } else {
            if (!empty($useServices)) {
                NovaClientConnectionManager::getInstance()->offline($this->appName, $useServices);
            }
        }
        $this->watchByStore();
    }

    private function getGetServicesJobId()
    {
        return spl_object_hash($this) . '_get_' . $this->appName;
    }

    private function getWatchServicesJobId()
    {
        return spl_object_hash($this) . '_watch_' . $this->appName;
    }
}