<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Storage;

use Filegator\Services\Service;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Util;

class Filesystem implements Service
{
    protected $separator;

    protected $storage;

    protected $path_prefix;

    public function init(array $config = [])
    {
        $this->separator = $config['separator'];
        $this->path_prefix = $this->separator;

        $adapter = $config['adapter'];
        $config = isset($config['config']) ? $config['config'] : [];

        $this->storage = new Flysystem($adapter(), $config);
    }

    public function createDir(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while (! empty($this->storage->listContents($destination, true))) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->createDir($destination);
    }

    public function createFile(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        $this->storage->put($destination, '');
    }

    public function fileExists(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->has($path);
    }

    public function isDir(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->getSize($path) === false;
    }

    public function copyFile(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($source);
        $destination = $this->joinPaths($this->applyPathPrefix($destination), $this->getBaseName($source));

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->copy($source, $destination);
    }

    public function copyDir(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($this->addSeparators($source));
        $destination = $this->applyPathPrefix($this->addSeparators($destination));
        $source_dir = $this->getBaseName($source);
        $real_destination = $this->joinPaths($destination, $source_dir);

        while (! empty($this->storage->listContents($real_destination, true))) {
            $real_destination = $this->upcountName($real_destination);
        }

        $contents = $this->storage->listContents($source, true);

        if (empty($contents)) {
            $this->storage->createDir($real_destination);
        }

        foreach ($contents as $file) {
            $source_path = $this->separator.ltrim($file['path'], $this->separator);
            $path = substr($source_path, strlen($source), strlen($source_path));

            if ($file['type'] == 'dir') {
                $this->storage->createDir($this->joinPaths($real_destination, $path));

                continue;
            }

            if ($file['type'] == 'file') {
                $this->storage->copy($file['path'], $this->joinPaths($real_destination, $path));
            }
        }
    }

    public function deleteDir(string $path)
    {
        return $this->storage->deleteDir($this->applyPathPrefix($path));
    }

    public function deleteFile(string $path)
    {
        return $this->storage->delete($this->applyPathPrefix($path));
    }

    public function readStream(string $path): array
    {
        if ($this->isDir($path)) {
            throw new \Exception('Cannot stream directory');
        }

        $path = $this->applyPathPrefix($path);

        return [
            'filename' => $this->getBaseName($path),
            'stream' => $this->storage->readStream($path),
            'filesize' => $this->storage->getSize($path),
        ];
    }

    public function move(string $from, string $to): bool
    {
        $from = $this->applyPathPrefix($from);
        $to = $this->applyPathPrefix($to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to);
    }

    public function rename(string $destination, string $from, string $to): bool
    {
        $from = $this->joinPaths($this->applyPathPrefix($destination), $from);
        $to = $this->joinPaths($this->applyPathPrefix($destination), $to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to);
    }

    public function store(string $path, string $name, $resource, bool $overwrite = false): bool
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            if ($overwrite) {
                $this->storage->delete($destination);
            } else {
                $destination = $this->upcountName($destination);
            }
        }

        return $this->storage->putStream($destination, $resource);
    }
    
    public function chmod(string $path, int $permissions)
    {
        $path = $this->applyPathPrefix($path);
        $path = Util::normalizePath($path);
        $this->storage->assertPresent($path);
        $adapter = $this->storage->getAdapter();
        
        switch (get_class($adapter)) {
            case 'League\Flysystem\Adapter\Local':
                // local does not support chmod, but we can do it manually, because it's local
                $path = $adapter->applyPathPrefix($path); // get the full path
                return chmod($path, octdec($permissions));
                break;
            case 'League\Flysystem\Sftp\SftpAdapter':
                return $adapter->getConnection()->chmod($path, $permissions);
                break;
            case 'League\Flysystem\Ftp\FtpAdapter':
            case 'League\Flysystem\Adapter\Ftp':
                return ftp_chmod($adapter->getConnection(), octdec($permissions), $path);
                break;
            default:
                throw new \Exception('Selected adapter does not support unix permissions');
                break;
        }
    }

    public function setPathPrefix(string $path_prefix)
    {
        $this->path_prefix = $this->addSeparators($path_prefix);
    }

    public function getSeparator()
    {
        return $this->separator;
    }

    public function getPathPrefix(): string
    {
        return $this->path_prefix;
    }

    public function getDirectoryCollection(string $path, bool $recursive = false): DirectoryCollection
    {
        $adapter = $this->storage->getAdapter();
        $collection = new DirectoryCollection($path);
        
        $rawPermissions = null;
        if ( in_array(get_class($adapter), ['League\Flysystem\Ftp\FtpAdapter', 'League\Flysystem\Adapter\Ftp'])) {
            $rawPermissions = $this->getFtpRawPermissions($adapter, $this->applyPathPrefix($path), $recursive);
        }

        foreach ($this->storage->listContents($this->applyPathPrefix($path), $recursive) as $entry) {
            // By default only 'path' and 'type' is present

            $name = $this->getBaseName($entry['path']);
            $userpath = $this->stripPathPrefix($entry['path']);
            $dirname = isset($entry['dirname']) ? $entry['dirname'] : $path;
            $size = isset($entry['size']) ? $entry['size'] : 0;
            $timestamp = isset($entry['timestamp']) ? $entry['timestamp'] : 0;
            $permissions = $this->getPermissions($entry['path'], $rawPermissions);

            $collection->addFile($entry['type'], $userpath, $name, $size, $timestamp, $permissions);
        }

        if (! $recursive && $this->addSeparators($path) !== $this->separator) {
            $collection->addFile('back', $this->getParent($path), '..', 0, 0, -1);
        }

        return $collection;
    }
    
    protected function getPermissions(string $path, $rawPermissions = null): int
    {
        $adapter = $this->storage->getAdapter();
        
        switch (get_class($adapter)) {
            case 'League\Flysystem\Adapter\Local':
                // local does not support chmod, but we can do it manually, because it's local
                $path = $adapter->applyPathPrefix($path); // get the full path
                $permissions = substr(sprintf('%o', fileperms($path)), -3);
                return $permissions;
                break;
            case 'League\Flysystem\Sftp\SftpAdapter':
                // return $adapter->getConnection()->chmod($path, $permissions);
                break;
            case 'League\Flysystem\Ftp\FtpAdapter':
            case 'League\Flysystem\Adapter\Ftp':
                if (!$rawPermissions) return -1;
                if (isset($rawPermissions[$path])) return $rawPermissions[$path];
                break;
        }
        return -1;
    }
    
    protected function getFtpRawPermissions($adapter, $directory, $recursive = true)
    {
        $directory = Util::normalizePath($directory);
        if ($recursive) {
            return $this->getFtpRawPermissionsRecursive($adapter, $directory);
        }

        $options = $recursive ? '-alnR' : '-aln';
        $listing = $this->ftpRawlist($adapter, $options, $directory);

        return $listing ? $this->ftpParsePermissions($listing, $directory) : [];
    }
    
    protected function getFtpRawPermissionsRecursive($adapter, $directory)
    {
        $listing = $this->ftpParsePermissions($this->ftpRawlist($adapter, '-aln', $directory) ?: [], $directory);
        $output = [];

        foreach ($listing as $item) {
            $output[] = $item;
            if ($item['type'] !== 'dir') {
                continue;
            }
            $output = array_merge($output, $this->getFtpRawPermissionsRecursive($adapter, $item['path']));
        }

        return $output;
    }
    
    protected function ftpRawlist($adapter, $options, $path)
    {
        $connection = $adapter->getConnection();
        return ftp_rawlist($connection, $options . ' ' . $path);
    }
    
    protected function ftpParsePermissions(array $listing, $prefix = '')
    {
        $base = $prefix;
        $result = [];
        
        while ($item = array_shift($listing)) {
            $systemType = preg_match('/^[0-9]{2,4}-[0-9]{2}-[0-9]{2}/', $item) ? 'windows' : 'unix';
            if ($systemType === 'unix') {
                $item = preg_replace('#\s+#', ' ', trim($item), 7);
                list($permissions, /* $number */, /* $owner */, /* $group */, $size, $month, $day, $timeOrYear, $name) = explode(' ', $item, 9);
                $type = substr($permissions, 0, 1) === 'd' ? 'dir' : 'file';
                $path = $base === '' ? $name : $base . $this->separator . $name;
                if (is_numeric($permissions)) {
                    $result[$path] = ((int) $permissions) & 0777;
                } else {
                    $mode = 0;
                    // Convert the string representation to octal - credits to ChatGPT
                    $mode += ($permissions[1] === 'r') ? 400 : 0;
                    $mode += ($permissions[2] === 'w') ? 200 : 0;
                    $mode += ($permissions[3] === 'x' || $permissions[3] === 's' || $permissions[3] === 't') ? 100 : 0;
                    $mode += ($permissions[4] === 'r') ? 40 : 0;
                    $mode += ($permissions[5] === 'w') ? 20 : 0;
                    $mode += ($permissions[6] === 'x' || $permissions[6] === 's' || $permissions[6] === 't') ? 10 : 0;
                    $mode += ($permissions[7] === 'r') ? 4 : 0;
                    $mode += ($permissions[8] === 'w') ? 2 : 0;
                    $mode += ($permissions[9] === 'x' || $permissions[9] === 's' || $permissions[9] === 't') ? 1 : 0;
                    $result[$path] = $mode;
                }
            } elseif ($systemType === 'windows') {
                $item = preg_replace('#\s+#', ' ', trim($item), 3);
                list($date, $time, $size, $name) = explode(' ', $item, 4);
                $path = $base === '' ? $name : $base . $this->separator . $name;
                $result[$path] = 777;
            }
        }

        return $result;
    }

    protected function upcountCallback($matches)
    {
        $index = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        $ext = isset($matches[2]) ? $matches[2] : '';

        return ' ('.$index.')'.$ext;
    }

    protected function upcountName($name)
    {
        return preg_replace_callback(
            '/(?:(?: \(([\d]+)\))?(\.[^.]+))?$/',
            [$this, 'upcountCallback'],
            $name,
            1
        );
    }

    private function applyPathPrefix(string $path): string
    {
        if ($path == '..'
            || strpos($path, '..'.$this->separator) !== false
            || strpos($path, $this->separator.'..') !== false
        ) {
            $path = $this->separator;
        }

        return $this->joinPaths($this->getPathPrefix(), $path);
    }

    private function stripPathPrefix(string $path): string
    {
        $path = $this->separator.ltrim($path, $this->separator);

        if (substr($path, 0, strlen($this->getPathPrefix())) == $this->getPathPrefix()) {
            $path = $this->separator.substr($path, strlen($this->getPathPrefix()));
        }

        return $path;
    }

    private function addSeparators(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        return $this->separator.trim($dir, $this->separator).$this->separator;
    }

    private function joinPaths(string $path1, string $path2): string
    {
        $path1 = $this->escapeDots($path1);
        $path2 = $this->escapeDots($path2);

        if (! $path2 || ! trim($path2, $this->separator)) {
            return $this->addSeparators($path1);
        }

        return $this->addSeparators($path1).ltrim($path2, $this->separator);
    }

    private function getParent(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($dir, $this->separator));
        array_pop($tmp);

        return $this->separator.trim(implode($this->separator, $tmp), $this->separator);
    }

    private function getBaseName(string $path): string
    {
        if (! $path || $path == $this->separator || ! trim($path, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($path, $this->separator));

        return  (string) array_pop($tmp);
    }

    private function escapeDots(string $path): string
    {
        $path = preg_replace('/\\\+\.{2,}/', '', $path);
        $path = preg_replace('/\.{2,}\\\+/', '', $path);
        $path = preg_replace('/\/+\.{2,}/', '', $path);
        $path = preg_replace('/\.{2,}\/+/', '', $path);

        return $path;
    }
}
