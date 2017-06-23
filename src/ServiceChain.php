<?php

namespace ZanPHP\Component\ServiceChain;


class ServiceChain
{
    const CFG_KEY = "kdt.X-Service-Chain";
    const ENV_KEY = "KDT_X_SERVICE_CHAIN";
    const HDR_KEY = "X-Service-Chain";
    const CTX_KEY = "service.chain";

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

    private static function fromEnv()
    {
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