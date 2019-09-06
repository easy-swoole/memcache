<?php

namespace EasySwoole\Memcache;

use EasySwoole\Spl\SplBean;

/**
 * 服务器配置
 * Class Config
 * @package EasySwoole\Memcache
 */
class Config extends SplBean
{
    protected $host;
    protected $port = 11211;

    /**
     * Host Getter
     * @return mixed
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Host Setter
     * @param mixed $host
     * @return Config
     */
    public function setHost($host)
    {
        $this->host = $host;
        return $this;
    }

    /**
     * Port Getter
     * @return mixed
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Port Setter
     * @param mixed $port
     * @return Config
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

}