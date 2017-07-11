<?php

namespace ZanPHP\ServiceChain;


use Zan\Framework\Utilities\DesignPattern\Singleton;
use ZanPHP\Contracts\Cache\ShareMemoryStore;

/**
 * Class ServiceChainStore
 * @package ZanPHP\ServiceChain
 */
class ServiceChainStore
{
    use Singleton;

    private $appName;

    private $store;

    public function __construct($appName)
    {
        $this->appName = $appName;
        $this->store = make(ShareMemoryStore::class);
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