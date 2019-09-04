<?php

namespace EasySwoole\Memcache\Exception;

use EasySwoole\Memcache\Package;

/**
 * Class MemcacheException
 * @package EasySwoole\Memcache\Exception
 */
class MemcacheException extends \Exception
{
    private $package;

    /**
     * Package Getter
     * @return Package
     */
    public function getPackage(): Package
    {
        return $this->package;
    }

    /**
     * Package Setter
     * @param Package $package
     * @return MemcacheException
     */
    public function setPackage(Package $package)
    {
        $this->package = $package;
        return $this;
    }
}