<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Foundation\Core\Config;
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
        $registry = Config::get("registry", []);
        $apps = Config::get("registry.app_names", []);

        foreach ($apps as $appName) {
            $discovery = $this->makeDiscovery($appName, $registry);
            $discovery->discover();
            $this->discoveries[$appName] = $discovery;
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
     * @return array|null list($host, $port) = getEndpoint()
     */
    public function getEndpoint($appName, $scKey)
    {
        if (isset($this->discoveries[$appName])) {
            return $this->discoveries[$appName]->getEndpoint($scKey);
        } else {
            $appList = implode(", ", array_keys($this->discoveries));
            throw new \InvalidArgumentException("$appName is not in [ $appList ]");
        }
    }

    public function getKey($type)
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

    public function getValue($type, array $ctx = [])
    {
        switch($type) {
            case ServiceChainer::TYPE_HTTP:
                return $this->getIgnoreCase(static::HDR_KEY, $ctx);
            case ServiceChainer::TYPE_TCP:
                return $this->getIgnoreCase(static::CTX_KEY, $ctx);
            case ServiceChainer::TYPE_JOB:
                // PHP_SAPI === 'cli'
                return $this->fromEnv();
            default:
                return null;
        }
    }

    public function getFromRpcCtx()
    {
        yield getRpcContext(static::CTX_KEY, null);
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