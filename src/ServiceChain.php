<?php

namespace ZanPHP\Component\ServiceChain;


use Zan\Framework\Foundation\Core\Config;

class ServiceChain
{
    const ARG_KEY = "service-chain";
    const ENV_KEY = "KDT_X_SERVICE_CHAIN";
    const CFG_KEY = "kdt.X-Service-Chain";

    const HDR_KEY = "X-Service-Chain";
    const CTX_KEY = "service.chain";

    /**
     * @var ServiceChainDiscovery[]
     */
    private static $discoveries = [];

    public static function init()
    {
        $apps = Config::get("registry.app_names", []);
        $config = Config::get("service_chain");

        foreach ($apps as $appName) {
            $discovery = self::makeDiscovery($appName, $config);
            $discovery->discover();

            self::$discoveries[$appName] = $discovery;
        }
    }

    /**
     * For Dispatch Nova Call
     * @param string $appName
     * @param string $scKey
     * @return array|null list($host, $port) =
     */
    public static function getEndpoint($appName, $scKey)
    {
        if (isset(self::$discoveries[$appName])) {
            return self::$discoveries[$appName]->getEndpoint($scKey);
        } else {
            $appList = implode(", ", array_keys(self::$discoveries));
            throw new \InvalidArgumentException("$appName is not in [ $appList ]");
        }
    }

    /**
     * For Iron Only
     * @return array|false|null|string
     */
    public static function get()
    {
        if (PHP_SAPI === 'cli') {
            return self::fromEnv();
        } else {
            return self::fromHeader();
        }
    }

    public static function getFromRpcCtx()
    {
        yield getRpcContext(static::CTX_KEY, null);
    }

    /**
     * @param $appName
     * @param array $config
     * @return ServiceChainDiscovery
     */
    private static function makeDiscovery($appName, array $config)
    {
        if (isset($_SERVER["WORKER_ID"]) && $_SERVER["WORKER_ID"] !== 0) {
            return new APCuDiscovery($appName, $config);
        } else {
            return new EtcdDiscovery($appName, $config);
        }
    }

    private static function fromEnv()
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

    private static function fromHeader()
    {
        return isset($_SERVER[static::HDR_KEY]) ? $_SERVER[static::HDR_KEY] : null;
    }
}