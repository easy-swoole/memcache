<?php


namespace EasySwoole\Memcache;


use EasySwoole\Spl\SplBean;

class Package extends SplBean
{
    protected $command;
    protected $key;
    protected $flags;
    protected $exptime = 0;
    protected $bytes = 0;
    protected $data;

    /**
     * @return mixed
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * @param mixed $command
     */
    public function setCommand($command): void
    {
        $this->command = $command;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $key
     */
    public function setKey($key): void
    {
        $this->key = $key;
    }

    /**
     * @return mixed
     */
    public function getFlags()
    {
        return $this->flags;
    }

    /**
     * @param mixed $flags
     */
    public function setFlags($flags): void
    {
        $this->flags = $flags;
    }

    /**
     * @return int
     */
    public function getExptime(): int
    {
        return $this->exptime;
    }

    /**
     * @param int $exptime
     */
    public function setExptime(int $exptime): void
    {
        $this->exptime = $exptime;
    }

    /**
     * @return int
     */
    public function getBytes(): int
    {
        return $this->bytes;
    }

    /**
     * @param int $bytes
     */
    public function setBytes(int $bytes): void
    {
        $this->bytes = $bytes;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     */
    public function setData($data): void
    {
        $this->data = $data;
    }
}