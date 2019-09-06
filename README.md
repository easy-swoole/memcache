# memcache

## 单元测试

```bash
./vendor/bin/co-phpunit ./vendor/easyswoole/memcache/tests
```

> 注意，请在项目目录下的phpunit.php文件中定义测试的信息常量。


```php
<?php

defined('MEMCACHE_HOST') or define('MEMCACHE_HOST', '127.0.0.1');
defined('MEMCACHE_PORT') or define('MEMCACHE_PORT', 11211);


```