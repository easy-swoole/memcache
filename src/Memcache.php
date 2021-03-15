<?php

namespace EasySwoole\Memcache;

use EasySwoole\Memcache\Exception\ConnectException;
use EasySwoole\Memcache\Exception\MemcacheException;
use Swoole\Coroutine\Client as CoroutineClient;

/**
 * 协程Memcache客户端
 * Class Memcache
 * @package EasySwoole\Memcache
 */
class Memcache
{
    private $config;

    /** @var CoroutineClient $client */
    private $client;

    protected $resultCode;
    protected $resultMessage;

    // GET SET 标记位
    const FLAG_TYPE_MASK = 0xf;
    const FLAG_TYPE_STRING = 0;
    const FLAG_TYPE_LONG = 1;
    const FLAG_TYPE_DOUBLE = 2;
    const FLAG_TYPE_BOOL = 3;
    const FLAG_TYPE_SERIALIZED = 4;
    const FLAG_TYPE_IGBINARY_SERIALIZED = 5;

    function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 连接客户端
     * @param null|integer $timeout
     * @return bool
     * @throws ConnectException
     */
    function connect($timeout = null)
    {
        // 如果当前没有客户端则创建一个客户端
        if (!$this->client instanceof CoroutineClient) {

            $this->client = new CoroutineClient(SWOOLE_TCP);
            $this->client->set([
                'open_length_check'     => 1,
                'package_length_offset' => 8,
                'package_body_offset'   => 24,
                'package_length_type'   => 'N',
                'package_max_length'    => 1024 * 1024 * 1,
            ]);

        }

        // 如果当前客户端没有连接则进行连接
        if (!$this->client->isConnected()) {
            $connected = $this->client->connect($this->config->getHost(), $this->config->getPort(), $timeout);
            if (!$connected) {
                $connectStr = "memcache://{$this->config->getHost()}:{$this->config->getPort()}";
                throw new ConnectException("Connect to Memcache {$connectStr} failed: {$this->client->errMsg}");
            }
        }

        return (bool)$this->client->isConnected();
    }

    /**
     * 发送一个原始命令
     * @param Package $package
     * @param int|null $timeout
     * @return Package
     * @throws ConnectException
     * @throws MemcacheException
     */
    function sendCommand(Package $package, int $timeout = null): Package
    {
        if ($this->connect()) {
            $this->client->send($package->__toString());
            $binaryPackage = $this->client->recv($timeout);
            if ($binaryPackage && $binaryPackage !== '') {

                $resPack = new Package;
                $resPack->unpack($binaryPackage);

                $this->resultCode = $resPack->getStatus();
                if ($this->resultCode !== Status::STAT_NO_ERROR) {
                    $this->resultMessage = $resPack->getValue();
                }

                return $resPack;

            }
        }

        $connectStr = "memcache://{$this->config->getHost()}:{$this->config->getPort()}";
        throw new MemcacheException("Send to Memcache {$connectStr} Failed: {$this->client->errMsg}");
    }


    /**
     * 摸一下(刷新有效期)
     * @param $key
     * @param $expiration
     * @param null $timeout
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function touch($key, $expiration, $timeout = null)
    {
        $extras = pack('N', $expiration);
        $reqPack = new Package(['opcode' => Opcode::OPX_TOUCH, 'key' => $key, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        // 如果不存在这个Key 不要乱摸
        if ($resPack->getStatus() === Status::STAT_KEY_NOTFOUND) {
            return false;
        }

        return $this->checkStatus($resPack);
    }

    /**
     * 自增KEY
     * @param $key
     * @param int $offset
     * @param int $initialValue 初始值
     * @param int $expiration 初始过期
     * @param null $timeout
     * @return array|false|int
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function increment($key, $offset = 1, $initialValue = 0, $expiration = 0, $timeout = null)
    {
        $packParam = [$offset << 32, $offset % (2 << 32), $initialValue << 32, $initialValue % (2 << 32), $expiration];
        $extras = pack('N2N2N', ...$packParam);
        $reqPack = new Package(['opcode' => Opcode::OP_INCREMENT, 'key' => $key, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);
        $this->checkStatus($resPack);
        $n = unpack('N2', $resPack->getValue());
        $n = $n[1] << 32 | $n[2];
        return $n;
    }

    /**
     * 自减KEY
     * @param $key
     * @param int $offset
     * @param int $initialValue
     * @param int $expiration
     * @param null $timeout
     * @return array|false|int
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function decrement($key, $offset = 1, $initialValue = 0, $expiration = 0, $timeout = null)
    {
        $packParam = [$offset << 32, $offset % (2 << 32), $initialValue << 32, $initialValue % (2 << 32), $expiration];
        $extras = pack('N2N2N', ...$packParam);
        $reqPack = new Package(['opcode' => Opcode::OP_DECREMENT, 'key' => $key, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);
        $this->checkStatus($resPack);
        $n = unpack('N2', $resPack->getValue());
        $n = $n[1] << 32 | $n[2];
        return $n;
    }

    /**
     * 设置KEY(覆盖)
     * @param $key
     * @param $value
     * @param int $expiration
     * @param null $timeout
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function set($key, $value, $expiration = 0, $timeout = null)
    {
        list($flag, $value) = $this->processValueFlags(0, $value);
        $extras = pack('NN', $flag, $expiration);
        $reqPack = new Package(['opcode' => Opcode::OP_SET, 'key' => $key, 'value' => $value, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        return $this->checkStatus($resPack);
    }

    /**
     * 增加KEY(非覆盖)
     * @param $key
     * @param $value
     * @param int $expiration
     * @param null $timeout
     * @return bool
     * @throws MemcacheException
     * @throws ConnectException
     */
    public function add($key, $value, $expiration = 0, $timeout = null)
    {
        list($flag, $value) = $this->processValueFlags(0, $value);
        $extras = pack('NN', $flag, $expiration);
        $reqPack = new Package(['opcode' => Opcode::OP_ADD, 'key' => $key, 'value' => $value, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        // 已经存在这个Key则设置失败返回false
        if ($resPack->getStatus() === Status::STAT_KEY_EXISTS) {
            return false;
        }

        return $this->checkStatus($resPack);
    }

    /**
     * 替换一个KEY
     * @param $key
     * @param $value
     * @param int $expiration
     * @param null $timeout
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function replace($key, $value, $expiration = 0, $timeout = null)
    {
        list($flag, $value) = $this->processValueFlags(0, $value);
        $extras = pack('NN', $flag, $expiration);
        $reqPack = new Package(['opcode' => Opcode::OP_REPLACE, 'key' => $key, 'value' => $value, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        // 如果不存在这个Key则必定Replace失败
        if ($resPack->getStatus() === Status::STAT_KEY_NOTFOUND) {
            return false;
        }

        return $this->checkStatus($resPack);
    }

    /**
     * 追加数据到末尾
     * TODO if OPT_COMPRESSION is enabled, the operation should failed.
     * @param $key
     * @param $value
     * @param null $timeout
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function append($key, $value, $timeout = null)
    {
        $reqPack = new Package(['opcode' => Opcode::OP_APPEND, 'key' => $key, 'value' => $value]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        // 如果不存在这个Key则必定Append失败
        if ($resPack->getStatus() === Status::STAT_ITEM_NOT_STORED) {
            return false;
        }

        return $this->checkStatus($resPack);
    }

    /**
     * 追加数据到开头
     * TODO if OPT_COMPRESSION is enabled, the operation should failed.
     * @param $key
     * @param $value
     * @param null $timeout
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function prepend($key, $value, $timeout = null)
    {
        $reqPack = new Package(['opcode' => Opcode::OP_PREPEND, 'key' => $key, 'value' => $value]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        // 如果不存在这个Key则必定Prepend失败
        if ($resPack->getStatus() === Status::STAT_ITEM_NOT_STORED) {
            return false;
        }

        return $this->checkStatus($resPack);
    }

    /**
     * 存储多个元素
     *
     * @param array $items
     * @param null $expiration
     * @param null $timeout
     * @return array
     * @throws ConnectException
     * @throws MemcacheException
     * CreateTime: 2021/3/14 9:38 下午
     */
    public function setMulti(array $items, $expiration = null, $timeout = null)
    {
        $result = [];
        foreach ($items as $key => $value) {
            $result[$key] = $this->set($key, $value, $expiration, $timeout);
        }
        return $result;
    }

    /**
     * 检索多个元素
     *
     * @param $keys
     * @param null $timeout
     * @return array
     * @throws ConnectException
     * @throws MemcacheException
     * CreateTime: 2021/3/14 9:26 下午
     */
    public function getMulti(array $keys, bool $isCas = false, $timeout = null)
    {
        $result = [];
        foreach ($keys as $key) {
            $reqPack = new Package(['opcode' => Opcode::OP_GET_K, 'key' => $key]);
            $resPack = $this->sendCommand($reqPack, $timeout);
            if($resPack->getStatus() === Status::STAT_KEY_NOTFOUND){
                $result[$key] = null;
                continue;
            } else {
                $this->checkStatus($resPack);
            }
            $valueType = $resPack->getExtras() & self::FLAG_TYPE_MASK;
            $value = $this->decodeByValueType($valueType, $resPack->getValue());
            if ($isCas) {
                $result[$key] = [
                    'value' => $value,
                    'cas' => $resPack->getCas2()
                ];
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 检查并设置
     *
     * @param float $casToken
     * @param string $key
     * @param $value
     * @param int|null $expiration
     * @param null $timeout
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     * CreateTime: 2021/3/15 12:49 上午
     */
    public function cas(float $casToken, string $key, $value, int $expiration = null, $timeout = null)
    {
        list($flag, $value) = $this->processValueFlags(0, $value);
        $extras = pack('NN', $flag, $expiration);
        $reqPack = new Package([
            'opcode' => Opcode::OP_SET
            , 'key' => $key
            , 'value' => $value
            , 'extras' => $extras
            , 'cas2' => $casToken
        ]);
        $resPack = $this->sendCommand($reqPack, $timeout);
        if (
            $resPack->getStatus() === Status::STAT_KEY_EXISTS
            && $resPack->getValue() === 'Data exists for key.'
        ) {
            return false;
        }
        return $this->checkStatus($resPack);
    }

    /**
     * 根据valueType解析value
     *
     * @param $valueType
     * @param $value
     * CreateTime: 2021/3/14 11:11 下午
     */
    private function decodeByValueType($valueType, $value)
    {
        switch ($valueType) {
            case self::FLAG_TYPE_STRING:
                $value = strval($value);
                break;
            case self::FLAG_TYPE_LONG:
                $value = intval($value);
                break;
            case self::FLAG_TYPE_DOUBLE:
                $value = doubleval($value);
                break;
            case self::FLAG_TYPE_BOOL:
                $value = $value ? TRUE : FALSE;
                break;
            case self::FLAG_TYPE_SERIALIZED:
                $value = unserialize($value);
                break;
            case self::FLAG_TYPE_IGBINARY_SERIALIZED:
                $value = igbinary_unserialize($value);
                break;
        }
        return $value;
    }

    /**
     * 获取KEY
     * @param $key
     * @param null $timeout
     * @return bool|float|int|mixed|string
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function get($key, $timeout = null)
    {
        $reqPack = new Package(['opcode' => Opcode::OP_GET, 'key' => $key]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        if($resPack->getStatus() === Status::STAT_KEY_NOTFOUND){
            return null;
        }

        $this->checkStatus($resPack);

        $value = $resPack->getValue();
        $valueType = $resPack->getExtras() & self::FLAG_TYPE_MASK;

        return $this->decodeByValueType($valueType, $value);
    }

    /**
     * 删除一个key
     * @param string $key 需要删除的key
     * @param int|null $timeout
     * @return bool KEY不存在同样视为删除成功
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function delete($key, $timeout = null)
    {
        $reqPack = new Package(['opcode' => Opcode::OP_DELETE, 'key' => $key]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        return $this->checkStatus($resPack, [Status::STAT_KEY_NOTFOUND]);
    }

    /**
     * 获取服务器状态
     * @param string $type
     * @param integer|null $timeout
     * @return array
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function stats($type = null, $timeout = null)
    {
        $status = array();
        $reqPack = new Package(['opcode' => Opcode::OP_STAT, 'key' => $type]);

        // 此处发包后需要一直收包 Opcode !== 0x10 || Opcode === 0x10 && key == '' 结束收包
        if ($this->connect()) {
            $this->client->send($reqPack->__toString());
            while (true) {

                $binaryPackage = $this->client->recv($timeout);
                if ($binaryPackage) {
                    $resPack = new Package;
                    $resPack->unpack($binaryPackage);
                    $this->checkStatus($resPack);

                    // 收到了全部的包则退出收包逻辑
                    if ($resPack->getOpcode() !== Opcode::OP_STAT || !$resPack->getKey()) {
                        break;
                    }

                    $status[$resPack->getKey()] = $resPack->getValue();
                }
            }
        }

        return $status;
    }

    /**
     * 获取服务器版本
     * @param int|null $timeout
     * @return mixed
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function version(int $timeout = null)
    {
        $reqPack = new Package(['opcode' => Opcode::OP_VERSION]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        if (!$this->checkStatus($resPack)) return false;
        return $resPack->getValue();
    }

    /**
     * 清空缓存
     * @param int|null $expiration 延时刷新(秒)
     * @param int $timeout 请求超时
     * @return bool
     * @throws ConnectException
     * @throws MemcacheException
     */
    public function flush(int $expiration = null, int $timeout = null)
    {
        $extras = pack('N', $expiration);
        $reqPack = new Package(['opcode' => Opcode::OP_FLUSH, 'extras' => $extras]);
        $resPack = $this->sendCommand($reqPack, $timeout);

        return $this->checkStatus($resPack);
    }

    /**
     * 是否正常响应
     * @param Package $package
     * @param array $except 排除特殊响应码(例如delete key not found视为正常)
     * @return bool
     * @throws MemcacheException
     */
    private function checkStatus(Package $package, $except = [])
    {
        $status = $package->getStatus();
        if (!in_array($status, $except) && $status !== Status::STAT_NO_ERROR) {
            $errorMsg = Status::code2Tips($package->getStatus());
            $exception = new MemcacheException("Memcache Error: {$errorMsg}", $package->getStatus());
            $exception->setPackage($package);
            throw  $exception;
        }
        return true;
    }

    /**
     * 处理标记位
     * @param $flag
     * @param $value
     * @return array
     */
    private function processValueFlags($flag, $value)
    {

        if (is_string($value)) {
            $flag |= self::FLAG_TYPE_STRING;
        } elseif (is_long($value)) {
            $flag |= self::FLAG_TYPE_LONG;
        } elseif (is_double($value)) {
            $flag |= self::FLAG_TYPE_DOUBLE;
        } elseif (is_bool($value)) {
            $flag |= self::FLAG_TYPE_BOOL;
        } else {
            $value = serialize($value);
            $flag |= self::FLAG_TYPE_SERIALIZED;
        }

        return array($flag, $value);
    }

    /**
     * 当前协程客户端
     * @return CoroutineClient
     */
    public function getClient(): CoroutineClient
    {
        return $this->client;
    }

    /**
     * 最后一次响应代码
     * @return mixed
     */
    public function getResultCode()
    {
        return $this->resultCode;
    }

    /**
     * 最后一次响应消息
     * @return mixed
     */
    public function getResultMessage()
    {
        return $this->resultMessage;
    }
}
