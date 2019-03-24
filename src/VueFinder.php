<?php

namespace Ozdemir\Vuefinder;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use League\Flysystem\Filesystem;

class VueFinder
{
    private $storage;
    private $config;
    
    /**
     * VueFinder constructor.
     * @param Filesystem $storage
     */
    public function __construct(Filesystem $storage)
    {
        $this->storage = $storage;
        $this->request = Request::createFromGlobals();
    }
    
    /**
     * @param $files
     * @return array
     */
    public function directories($files)
    {
        return array_filter($files, function ($item) {
            return $item['type'] == 'dir';
        });
    }
    
    /**
     * @param $files
     * @return array
     */
    public function files($files)
    {
        return array_filter($files, function ($item) {
            return $item['type'] == 'file';
        });
    }
    
    /**
     * @param $config
     */
    public function init($config)
    {
        if (!array_key_exists('publicPaths', $config)) {
            $config['publicPaths'] = [];
        }
        if (!array_key_exists('deal_file_suffix', $config)) {
            $config['deal_file_suffix'] = 'png,jpg,gif,jpeg';
        }
        
        if (!array_key_exists('upload_max_kb_file_size', $config)) {
            $config['upload_max_kb_file_size'] = 1024 * 2;//上传文件限制=默认2MB
        }
        
        $this->config = $config;
        $query        = $this->request->get('q');
        $route_array  = ['index', 'newfolder', 'read', 'download', 'rename', 'delete', 'upload'];
        
        try {
            #####################################################################################
            ## 保证安全路径
            $dirname = $this->request->get('path') ?? '.';
            $root    = $this->storage->getAdapter()->getPathPrefix();
            if (!empty($dirname)) {
                $reslut = $this->getSalfPath($dirname, $root);
                if (!$reslut) {
                    throw new \Exception('用户的路径和系统的路径不符合.有风险!');
                }
            }
            #####################################################################################
            
            if (!\in_array($query, $route_array, true)) {
                throw new \Exception('The query does not have a valid method.');
            }
            $response = $this->$query();
            $response->send();
        } catch (\Exception $e) {
            $response = new JsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
            $response->send();
        }
    }
    
    /**
     * @return JsonResponse
     */
    public function index()
    {
        $root    = '.';
        $dirname = $this->request->get('path') ?? $root;
        $parent  = \dirname($dirname);
        $types   = $this->typeMap();
        
        
        $listcontent = $this->storage->listContents($dirname);
        
        $files = array_merge(
            $this->directories($listcontent),
            $this->files($listcontent)
        );
        
        $files = array_map(function ($node) use ($types) {
            if ($node['type'] == 'file' && isset($node['extension'])) {
                $node['type'] = $types[mb_strtolower($node['extension'])] ?? 'file';
            }
            
            if ($node['type'] == 'dir') {
                $node['type'] = 'folder';
            }
            
            if ($this->config['publicPaths'] && $node['type'] != 'folder') {
                foreach ($this->config['publicPaths'] as $path => $domain) {
                    $path = str_replace('/', '\/', $path);
                    if (preg_match('/^' . $path . '/i', $node['path'])) {
                        $node['fileUrl'] = preg_replace('/^' . $path . '/i', $domain, $node['path']);
                    }
                }
            }
            
            return $node;
        }, $files);
        
        return new JsonResponse(compact('root', 'parent', 'dirname', 'files'));
    }
    
    /**
     * @return JsonResponse
     */
    public function newfolder()
    {
        $path = $this->request->get('path');
        $name = $this->request->get('name');
        
        if (!strpbrk($name, "\\/?%*:|\"<>") === false) {
            throw new \Exception('文件夹名无效.');
        }
        
        return new JsonResponse(['status' => $this->storage->createDir("{$path}/{$name}")]);
    }
    
    /**
     * @return JsonResponse
     * @throws \League\Flysystem\FileExistsException
     */
    public function upload()
    {
        $path                 = $this->request->get('path');
        $file                 = $this->request->files->get('file');
        $file_suffix          = $file->getClientOriginalExtension(); //文件的扩展名
        $config_suffix        = $this->config['deal_file_suffix'];
        $config_upload_max_kb = $this->config['upload_max_kb_file_size'];
        
        if (isset($config_suffix)) {
            $up_file_suffix = explode(',', $config_suffix);
            if (!in_array(strtolower($file_suffix), $up_file_suffix)) {
                throw new \Exception($file_suffix . ' 文件不允许上传.');
            }
        }
        
        $now_file_size = $file->getSize() / 1024;
        if ($now_file_size > $config_upload_max_kb) {
            throw new \Exception('文件大小超过了' . $config_upload_max_kb . 'kb,不允许上传!');
        }
        
        $stream = fopen($file->getRealPath(), 'r+');
        $this->storage->writeStream(
            $path . '/' . $file->getClientOriginalName(),
            $stream
        );
        
        is_resource($stream) && fclose($stream);
        
        return new JsonResponse(['status' => true]);
    }
    
    /**
     * @return StreamedResponse
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function read()
    {
        $path = $this->request->get('path');
        return $this->streamFile($path);
    }
    
    /**
     * @return StreamedResponse
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function download()
    {
        $path     = $this->request->get('path');
        $response = $this->streamFile($path);
        
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($path)
        );
        $response->headers->set('Content-Disposition', $disposition);
        return $response;
    }
    
    /**
     * @return JsonResponse
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function rename()
    {
        $from = $this->request->get('from');
        $to   = $this->request->get('to');
        
        if (!strpbrk($to, "\\/?%*:|\"<>") === false) {
            throw new \Exception('文件名无效.');
        }
        
        if (strpos($from, './') !== false || strpos($from, '../') !== false) {
            throw new \Exception('来源文件错误！');
        }
        
        //若发现path里面跟了../则是非法路径,重置path为/根目录
        //防止/dir.php?filename=.\./.\./etc/passwd模式转义攻击
        if (strpos($to, '..%2F') !== false || strpos($to, '\.') !== false) {
            throw new \Exception('目标文件名错误！');
        }
        
        
        $to_arr        = pathinfo($to);
        $to_ext        = $to_arr['extension'];
        $config_suffix = $this->config['deal_file_suffix'];
        
        if (isset($config_suffix)) {
            $up_file_suffix = explode(',', $config_suffix);
            if (!in_array(strtolower($to_ext), $up_file_suffix)) {
                throw new \Exception('不准修改为 ' . $to_ext . ' 的文件！');
            }
        }
        
        $status = $this->storage->rename($from, $to);
        
        return new JsonResponse(['status' => $status]);
    }
    
    /**
     * @return JsonResponse
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function delete()
    {
        $items = json_decode($this->request->get('items'));
        
        foreach ($items as $item) {
            if ($item->type == 'folder') {
                $this->storage->deleteDir($item->path);
            } else {
                $this->storage->delete($item->path);
            }
        }
        
        return new JsonResponse(['status' => true]);
    }
    
    /**
     * @return array
     */
    private function typeMap()
    {
        $types = [
            'file-image'      => ['ai', 'bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'ps', 'psd', 'svg', 'tif', 'tiff'],
            'file-excel'      => ['ods', 'xlr', 'xls', 'xlsx'],
            'file-alt'        => ['txt'],
            'file-pdf'        => ['pdf'],
            'file-code'       => [
                'c',
                'class',
                'cpp',
                'cs',
                'h',
                'java',
                'sh',
                'swift',
                'vb',
                'js',
                'css',
                'htm',
                'html',
                'php'
            ],
            'file-archive'    => ['zip', 'zipx', 'tar', '7z', 'tar.bz2', 'tar.gz', 'z', 'pkg', 'deb', 'rpm'],
            'file-word'       => ['doc', 'docx', 'odt', 'rtf', 'tex', 'wks', 'wps', 'wpd'],
            'file-powerpoint' => ['key', 'odp', 'pps', 'ppt', 'pptx'],
            'file-audio'      => ['aif', 'cda', 'mid', 'midi', 'mp3', 'mpa', 'ogg', 'wav', 'wma', 'wpl'],
            'file-video'      => [
                '3g2',
                '3gp',
                'avi',
                'flv',
                'h264',
                'mkv',
                'm4v',
                'mov',
                'mp4',
                'mpg',
                'mpeg',
                'swf',
                'wmv'
            ]
        ];
        
        $types = array_map('array_fill_keys', $types, array_keys($types));
        
        return array_merge(...$types);
    }
    
    /**
     * @param $path
     * @return StreamedResponse
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function streamFile($path)
    {
        $stream = $this->storage->readStream($path);
        
        $response = new StreamedResponse();
        
        $mimeType = $this->storage->getMimetype($path);
        $size     = $this->storage->getSize($path);
        
        $response->headers->set('Content-Length', $size);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        
        $response->setCallback(function () use ($stream) {
            ob_end_clean();
            fpassthru($stream);
        });
        
        return $response;
    }
    
    //返回return false;非法
    //返回return true;正常
    private function getSalfPath($path, $root_path)
    {
        
        ## 传递过来的路径不能做任何处理
        ## 因为这里处理了以后，在其他地方重新获取，这里的修改没有用
        //若发现path里面跟了../则是非法路径,重置path为/根目录
        //防止基础模式攻击 /admin.php/url?mulu=%2F..%2F..%2F..%2Fapplication%2Fadmin%2Fcontroller&lang=cn
        if (strpos($path, './') !== false || strpos($path, '../') !== false) {
            return false;
        }
        
        //若发现path里面跟了../则是非法路径,重置path为/根目录
        //防止/dir.php?filename=.\./.\./etc/passwd模式转义攻击
        if (strpos($path, '..%2F') !== false || strpos($path, '\.') !== false) {
            return false;
        }
        
        
        ## 再严格一步,使用realpath来校验下用户传入的路径和实际的路径是否完全一样,若不一样,可能是风险路径了!
        ## DIRECTORY_SEPARATOR
        $path            = str_replace('\\', '/', $path);
        $user_path       = realpath($root_path . $path);
        $root_path       = realpath($root_path);
        $user_path_array = explode(DIRECTORY_SEPARATOR, $user_path);
        $root_path_array = explode(DIRECTORY_SEPARATOR, $root_path);
        /*var_dump($path);
        var_dump($user_path);
        var_dump($root_path);
        var_dump(count($user_path_array));
        var_dump(count($root_path_array));
        die;*/
        
        ##  比较两个数组个数，根目录数组要小
        ## 如果传递目录比根目录小，则在根目录上面，不允许访问的
        if (count($root_path_array) > count($user_path_array)) {
            return false;
        }
        return true;
        
        
    }
}
