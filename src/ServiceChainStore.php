<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Utilities\DesignPattern\Singleton;
use ZanPHP\Component\Cache\APCuStore;

/**
 * Class ServiceChainStore
 * @package ZanPHP\Component\ServiceChain
 */
class ServiceChainStore
{
    use Singleton;

    private $appName;

    private $store;

    public function __construct($appName)
    {
        $this->appName = $appName;
        // TODO refactor DI
        // composer 依赖contracts & di
        $this->store = new APCuStore("service_chain");
    }

    public function getChainKeyMap()
    {
        return $this->store->get($this->appName);
    }

    public function setChainKeyMap($keyMap)
    {
        $this->store->forever($this->appName, $keyMap);
    }

    public function getCurrentIndex()
    {
        return $this->store->get($this->appName . "_waitIndex");
    }

    public function updateWaitIndex($index)
    {
        $this->store->forever($this->appName . "_waitIndex", $index);
    }
}