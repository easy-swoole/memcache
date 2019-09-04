<?php


namespace EasySwoole\Memcache;


use Swoole\Coroutine\Client;

class Memcache
{
    private $config;
    private $client;

    function __construct(Config $config)
    {
        $this->config = $config;
    }

    function connect(float $timeout = null):bool
    {
        if(!$this->client instanceof Client){
            $this->client = new Client(SWOOLE_TCP);
            $this->client->set([
                'open_eof_check' => true,
                'package_eof' => "\r\n",
                'package_max_length' => 1024 * 1024 * 2,
            ]);
        }
        if(!$this->client->isConnected()){

        }
        return (bool)$this->client->isConnected();
    }

    function get(string $key)
    {

    }

    function set(string $key,$value)
    {

    }


}