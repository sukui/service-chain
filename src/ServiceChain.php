<?php

namespace ZanPHP\ServiceChain;


use Zan\Framework\Foundation\Core\Config;
use ZanPHP\Cache\APCuStore;
use ZanPHP\Container\Container;
use ZanPHP\Contracts\Cache\ShareMemoryStore;
use ZanPHP\Contracts\ServiceChain\ServiceChainer;

class ServiceChain implements ServiceChainer
{
    const ARG_KEY = "service-chain";
    const ENV_KEY = "KDT_X_SERVICE_CHAIN";
    const CFG_KEY = "kdt.X-Service-Chain";

    const HDR_KEY = "X-Service-Chain";
    const CTX_KEY = "service.chain";

    /**
     * @var ServiceChainDiscovery[]
     */
    private $discoveries = [];

    public function __construct()
    {
        $this->initShareMemoryStore();

        $registry = Config::get("registry", []);
        $apps = Config::get("registry.app_names", []);

        foreach ($apps as $appName) {
            $discovery = $this->makeDiscovery($appName, $registry);
            $discovery->discover();
            $this->discoveries[$appName] = $discovery;
        }
    }

    private function initShareMemoryStore()
    {
        $container = Container::getInstance();
        if (!$container->has(ShareMemoryStore::class)) {
            $container->bind(ShareMemoryStore::class, APCuStore::class);
            $container->when(APCuStore::class)
                ->needs('$prefix')
                ->give('service_chain');
        }
    }

    /**
     * @param $appName
     * @param array $config  同 config/$env/registry.php
     * @return ServiceChainDiscovery
     */
    private function makeDiscovery($appName, array $config)
    {
        if (isset($_SERVER["WORKER_ID"]) && $_SERVER["WORKER_ID"] !== 0) {
            return new APCuDiscovery($appName, $config);
        } else {
            return new EtcdDiscovery($appName, $config);
        }
    }

    /**
     * For Dispatch Nova Call
     * @param string $appName
     * @param string $scKey
     * @return array
     */
    public function getEndpoints($appName, $scKey = null)
    {
        if (isset($this->discoveries[$appName])) {
            return $this->discoveries[$appName]->getEndpoints($scKey);
        } else {
            $appList = implode(", ", array_keys($this->discoveries));
            throw new \InvalidArgumentException("$appName is not in [ $appList ]");
        }
    }

    public function getChainKey($type)
    {
        switch($type) {
            case ServiceChainer::TYPE_HTTP:
                return static::HDR_KEY;
            case ServiceChainer::TYPE_TCP:
                return static::CTX_KEY;
            default:
                return null;
        }
    }

    public function getChainValue($type, array $ctx = [])
    {
        switch($type) {
            case ServiceChainer::TYPE_HTTP:
                $value = $this->getIgnoreCase(static::HDR_KEY, $ctx);
                break;
            case ServiceChainer::TYPE_TCP:
                $value =  $this->getIgnoreCase(static::CTX_KEY, $ctx);
                break;
            case ServiceChainer::TYPE_JOB:
                // PHP_SAPI === 'cli'
                $value =  $this->fromEnv();
                break;
            default:
                $value = null;
                break;
        }

        return $this->parserChainValue($value);
    }

    private function parserChainValue($raw)
    {
        if (is_array($raw)) {
            return $raw;
        } else if (is_string($raw) && preg_match('/^\s*[\[|\{].*[\]|\}\s*$]/', $raw)) {
            return json_decode($raw,true) ?: [];
        } else {
            return [];
        }
    }

    private function fromEnv()
    {
        $opts = getopt("", [ static::ARG_KEY . "::"]);
        if ($opts && isset($opts[static::ARG_KEY]) &&
            $chain = $opts[static::ARG_KEY]) {
            return $chain;
        }

        $chain = getenv(static::ENV_KEY);
        if ($chain !== false) {
            return $chain;
        }

        $chain = get_cfg_var(static::CFG_KEY);
        if ($chain !== false) {
            return $chain;
        }

        return null;
    }

    private function getIgnoreCase($key, array $arr)
    {
        if (empty($arr)) {
            return null;
        }

        $arr = array_change_key_case($arr, CASE_UPPER);
        $key = strtoupper($key);
        return isset($arr[$key]) ? $arr[$key] : null;
    }
}