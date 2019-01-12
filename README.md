# vuefinder-php
php serverside library for vuefinder

#怎么用,看下面吧.composer安装之后
```php
    public function vueFinder()
    {
        //因为这是一个第三方类库,所以他的请求到了最后的时候,很有可能没有返回后到路由上执行最后的跨域设置.所以这里需要手动跨域,或者使用中间件前置操作跨域

        // Set Filesystem Storage

        $root_path = Env::get('root_path') . 'public/uploads/';

        $adapter = new Local($root_path);
        $storage = new Filesystem($adapter);

        // Set VueFinder class
        $vuefinder = new VueFinder($storage);

        // http://jwpt.com/uploads/images/2018-12/5c0620eee77ec.jpg
        $config = [
            'publicPaths' => [
                '指定一个规定目录下文件夹路径' => '替换为domain域名模式,返回url给前端',
                'image'          => 'http://jwpt.com/uploads/image',
            ],
        ];

        // Perform the class
        $vuefinder->init($config);

    }
```

## Installation 
```
composer require ozdemir/vuefinder-php
```
## Usage
```php
use Ozdemir\Vuefinder\Vuefinder;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;

// Set Filesystem Storage 
$adapter = new Local(\dirname(__DIR__).'/storage');
$storage = new Filesystem($adapter);

// Set VueFinder class
$vuefinder = new VueFinder($storage);

// Perform the class
$vuefinder->init();
```
