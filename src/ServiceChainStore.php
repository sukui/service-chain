<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Network\ServerManager\ServerStore;
use Zan\Framework\Utilities\DesignPattern\Singleton;

/**
 * Class ServiceChainStore
 * @package ZanPHP\Component\ServiceChain
 *
 * 以后如果将Apcu访问单独封装，直接改这里就好了
 */
class ServiceChainStore
{
    use Singleton;

    private $appName;

    private $store;

    public function __construct($appName)
    {
        $this->appName = $appName;
        $by = $appName;
        $this->store = ServerStore::getInstanceBy($by,"service_chain");
    }

    public function getChainKeyMap()
    {
        return $this->store->getServices($this->appName);
    }

    public function setChainKeyMap($keyMap)
    {
        return $this->store->setServices($this->appName, $keyMap);
    }

    public function getCurrentIndex()
    {
        return $this->store->getServiceWaitIndex($this->appName);
    }

    public function updateWaitIndex($index)
    {
        $this->store->setServiceWaitIndex($this->appName, $index);
    }
}