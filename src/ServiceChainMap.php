<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Utilities\DesignPattern\Singleton;
use ZanPHP\Component\EtcdClient\V2\Node;

class ServiceChainMap
{
    use Singleton;

    private $appName;

    /**
     * @var array serviceChainKey => [[ip, port], ]
     */
    private $keyMap;

    public function __construct($appName)
    {
        $this->appName = $appName;
        $this->keyMap = [];
    }

    public function getEndpoint($scKey)
    {
        if (isset($this->keyMap[$scKey]) &&
            $endpoints = array_values($this->keyMap[$scKey])) {
            return $endpoints[array_rand($endpoints)];
        } else {
            return null;
        }
    }

    public function getMap()
    {
        return $this->keyMap;
    }

    public function setMap($map)
    {
        if (is_array($map)) {
            $this->keyMap = $map;
        } else {
            $this->keyMap = [];
        }
    }

    public function create($scKeyNode)
    {
        return $this->set($scKeyNode);
    }

    public function update(Node $scKeyNode)
    {
        return $this->set($scKeyNode);
    }

    public function delete(Node $scKeyNode)
    {
        $tuple =$this->parseNode($scKeyNode);
        if ($tuple === false) {
            return false;
        }

        list($scKey, $ip, $port) = $tuple;
        if (isset($this->keyMap[$scKey])) {
            unset($this->keyMap[$scKey]["$ip:$port"]);
        }
        return true;
    }

    private function set(Node $scKeyNode)
    {
        $tuple =$this->parseNode($scKeyNode);
        if ($tuple === false) {
            return false;
        }

        list($scKey, $ip, $port) = $tuple;
        if (!isset($this->keyMap[$scKey])) {
            $this->keyMap[$scKey] = [];
        }

        $this->keyMap[$scKey]["$ip:$port"] = [$ip, $port];
        return true;
    }

    private function parseNode(Node $scKeyNode)
    {
        $nodeKeyPath = $scKeyNode->key;

        $pos0 = strpos($nodeKeyPath, $this->appName);
        if ($pos0 === false) {
            sys_error("service chain: invalid chain key `$nodeKeyPath`");
            return false;
        }

        $serviceChainKey = substr($nodeKeyPath, $pos0 + strlen($this->appName) + 1);

        $pos1 = strpos($serviceChainKey, "/");
        $pos2 = strpos($serviceChainKey, ":");
        if ($pos1 === false || $pos2 === false || $pos2 < $pos1) {
            sys_error("service chain: invalid chain key `$nodeKeyPath`");
            return false;
        }

        list($scKey, $ipPort) = explode("/", $serviceChainKey);
        list($ip, $port) = explode(":", $ipPort);

        return [$scKey, $ip, intval($port)];
    }
}