<?php

return [
    \ZanPHP\ServiceChain\ServiceChain::class => [
        "interface" => \ZanPHP\Contracts\ServiceChain\ServiceChainer::class,
        "id" => \ZanPHP\Contracts\ServiceChain\ServiceChainer::class,
        "shared" => true,
    ],
];