<?php

namespace EasySwoole\Memcache;

use EasySwoole\Memcache\Exception\ConnectException;
use Exception;
use Swoole\Coroutine\Client;
use Throwable;

/**
 * 协程Memcache客户端
 * Class Memcache
 * @package EasySwoole\Memcache
 */
class Memcache
{
    private $config;

    /** @var Client $client */
    private $client;

    function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 连接到服务端
     * @param float|null $timeout
     * @return bool
     * @throws ConnectException
     */
    function connect(float $timeout = null): bool
    {
        if (!$this->client instanceof Client) {
            $this->client = new Client(SWOOLE_TCP);
            $this->client->set([
                'open_length_check'     => 1,
                'package_length_type'   => 'N',
                'package_length_offset' => 8,
                'package_body_offset'   => 24,
                'package_max_length'    => 1024 * 1024 * 1,
            ]);
        }

        if (!$this->client->isConnected()) {
            $connected = $this->client->connect($this->config->getHost(), $this->config->getPort(), $timeout);
            if (!$connected) {
                throw new ConnectException('Connect to Memcache failed: ' . $this->client->errMsg);
            }
        }

        return (bool)$this->client->isConnected();
    }

    /**
     * 发送命令并处理响应
     * @param Package $package
     * @param null $timeout
     * @return Package
     * @throws ConnectException
     */
    public function sendAndRecv(Package $package, $timeout = null): Package
    {
        $this->connect();
        $this->client->send($package->__toString());

        // Recv Package
        $content = $this->client->recv($timeout);
        $responsePackage = new Package;
        $responsePackage->unpack($content);
        return $responsePackage;

    }

    /**
     * 获取一个值
     * @param string $key
     * @param null $default
     * @return mixed
     * @throws Throwable
     */
    function get(string $key, $default = null)
    {
        $package = new Package(['opCode' => 0x00, 'key' => $key]);
        $response = $this->sendAndRecv($package);

        // Key not found
        if ($response->getStatus() === 0x0001) {
            return $default;
        }

        // No error
        if ($response->getStatus() === 0x0000) {
            return $response->getValue();
        }

        throw new Exception($response->getValue(), $response->getStatus());
    }

    /**
     * 设置缓存
     * @param string $key
     * @param $value
     * @param int|null $ttl
     * @return mixed
     * @throws Throwable
     */
    function set(string $key, $value, int $ttl = null)
    {
        $extras = pack('NN', 0xdeadbeef, $ttl);
        $package = new Package(['opCode' => 0x01, 'key' => $key, 'value' => $value, 'extras' => $extras]);
        $response = $this->sendAndRecv($package);

        // No error
        if ($response->getStatus() === 0x0000) {
            return $package->getValue();
        }

        throw new Exception($response->getValue(), $response->getStatus());
    }
}