<?php

namespace ZanPHP\ServiceChain;


interface ServiceChainDiscovery
{
    public function discover();

    public function getEndpoints($scKey = null);
}