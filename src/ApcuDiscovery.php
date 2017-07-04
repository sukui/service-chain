<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Utilities\Types\Arr;

class ApcuDiscovery implements ServiceChainDiscovery
{
    const WATCH_TICK = 1000;

    private $config;

    private $appName;

    private $store;

    public function __construct($appName, array $config = [])
    {
        $this->config = $config;

        $this->appName = $appName;

        $by = $appName;

        $this->store = ServiceChainStore::getInstanceBy($by, $appName);

        $this->chainMap = ServiceChainMap::getInstanceBy($by, $appName);
    }

    public function discover()
    {
        $tick = Arr::get($this->config, "watch_store.loop_time", self::WATCH_TICK);

        Timer::tick($tick, function() {
            $this->chainMap->setMap($this->store->getChainKeyMap());
        });
    }

    public function getEndpoint($scKey)
    {
        return $this->chainMap->getEndpoint($scKey);
    }
}