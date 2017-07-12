<?php

namespace ZanPHP\ServiceChain;


use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Utilities\Types\Arr;

class APCuDiscovery implements ServiceChainDiscovery
{
    const WATCH_TICK = 1000;

    private $config;

    private $appName;

    private $store;

    private $chainMap;

    public function __construct($appName, array $config = [])
    {
        // 同 config/$env/registry.php
        $defaultConf = [
            "watch_store" => [
                "loop_time" => self::WATCH_TICK,
            ]
        ];

        $this->config = Arr::merge($defaultConf, $config);

        $this->appName = $appName;

        $this->store = new ServiceChainStore($appName);

        $this->chainMap = new ServiceChainMap($appName);
    }

    public function discover()
    {
        $tick = $this->config["watch_store"]["loop_time"];

        Timer::tick($tick, function() {
            $keyMap = $this->store->getChainKeyMap();
            $this->chainMap->setMap($keyMap);
        });
    }

    public function getEndpoints($scKey = null)
    {
        return $this->chainMap->getMap($scKey);
    }
}