<?php

namespace ZanPHP\Component\ServiceChain;


interface ServiceChainDiscovery
{
    public function discover();

    public function getEndpoint($scKey);
}