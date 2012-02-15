<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Profiler;

/**
 * Base Memcache storage for profiling information in a Memcache.
 *
 * @author Andrej Hudec <pulzarraider@gmail.com>
 */
abstract class BaseMemcacheProfilerStorage implements ProfilerStorageInterface
{
    const TOKEN_PREFIX = 'sf_profiler_';

    protected $dsn;
    protected $lifetime;

    /**
     * Constructor.
     *
     * @param string $dsn      A data source name
     * @param string $username
     * @param string $password
     * @param int    $lifetime The lifetime to use for the purge
     */
    public function __construct($dsn, $username = '', $password = '', $lifetime = 86400)
    {
        $this->dsn = $dsn;
        $this->lifetime = (int) $lifetime;
    }

    /**
     * {@inheritdoc}
     */
    public function find($ip, $url, $limit, $method)
    {
        $indexName = $this->getIndexName();

        $indexContent = $this->getValue($indexName);
        if (!$indexContent) {
            return array();
        }

        $profileList = explode("\n", $indexContent);
        $result = array();

        foreach ($profileList as $item) {

            if ($limit === 0) {
                break;
            }

            if ($item=='') {
                continue;
            }

            list($itemToken, $itemIp, $itemMethod, $itemUrl, $itemTime, $itemParent) = explode("\t", $item, 6);

            if ($ip && false === strpos($itemIp, $ip) || $url && false === strpos($itemUrl, $url) || $method && false === strpos($itemMethod, $method)) {
                continue;
            }

            $result[$itemToken]  = array(
                'token'  => $itemToken,
                'ip'     => $itemIp,
                'method' => $itemMethod,
                'url'    => $itemUrl,
                'time'   => $itemTime,
                'parent' => $itemParent,
            );
            --$limit;
        }

        return array_values($result);
    }

    /**
     * {@inheritdoc}
     */
    public function purge()
    {
        $this->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function read($token)
    {
        if (empty($token)) {
            return false;
        }

        $profile = $this->getValue($this->getItemName($token));

        if (false !== $profile) {
            $profile = $this->createProfileFromData($token, $profile);
        }

        return $profile;
    }

    /**
     * {@inheritdoc}
     */
    public function write(Profile $profile)
    {
        $data = array(
            'token'    => $profile->getToken(),
            'parent'   => $profile->getParentToken(),
            'children' => array_map(function ($p) { return $p->getToken(); }, $profile->getChildren()),
            'data'     => $profile->getCollectors(),
            'ip'       => $profile->getIp(),
            'method'   => $profile->getMethod(),
            'url'      => $profile->getUrl(),
            'time'     => $profile->getTime(),
        );

        if ($this->setValue($this->getItemName($profile->getToken()), $data, $this->lifetime)) {
            // Add to index
            $indexName = $this->getIndexName();

            $indexRow = implode("\t", array(
                $profile->getToken(),
                $profile->getIp(),
                $profile->getMethod(),
                $profile->getUrl(),
                $profile->getTime(),
                $profile->getParentToken(),
            ))."\n";

            return $this->appendValue($indexName, $indexRow, $this->lifetime);
        }

        return false;
    }

    /**
     * Retrieve item from the memcache server
     *
     * @param string $key
     *
     * @return mixed
     */
    abstract protected function getValue($key);

    /**
     * Store an item on the memcache server under the specified key
     *
     * @param string $key
     * @param mixed $value
     * @param int $expiration
     *
     * @return boolean
     */
    abstract protected function setValue($key, $value, $expiration = 0);

    /**
     * Flush all existing items at the memcache server
     *
     * @return boolean
     */
    abstract protected function flush();

    /**
     * Append data to an existing item on the memcache server
     * @param string $key
     * @param string $value
     * @param int $expiration
     *
     * @return boolean
     */
    abstract protected function appendValue($key, $value, $expiration = 0);

    private function createProfileFromData($token, $data, $parent = null)
    {
        $profile = new Profile($token);
        $profile->setIp($data['ip']);
        $profile->setMethod($data['method']);
        $profile->setUrl($data['url']);
        $profile->setTime($data['time']);
        $profile->setCollectors($data['data']);

        if (!$parent && $data['parent']) {
            $parent = $this->read($data['parent']);
        }

        if ($parent) {
            $profile->setParent($parent);
        }

        foreach ($data['children'] as $token) {
            if (!$token) {
                continue;
            }

            if (!$childProfileData = $this->getValue($this->getItemName($token))) {
                continue;
            }

            $profile->addChild($this->createProfileFromData($token, $childProfileData, $profile));
        }

        return $profile;
    }

    /**
     * Get item name
     *
     * @param string $token
     *
     * @return string
     */
    private function getItemName($token)
    {
        $name = self::TOKEN_PREFIX . $token;

        if ($this->isItemNameValid($name)) {
            return $name;
        }

        return false;
    }

    /**
     * Get name of index
     *
     * @return string
     */
    private function getIndexName()
    {
        $name = self::TOKEN_PREFIX . 'index';

        if ($this->isItemNameValid($name)) {
            return $name;
        }

        return false;
    }

    private function isItemNameValid($name)
    {
        $length = strlen($name);

        if ($length > 250) {
            throw new \RuntimeException(sprintf('The memcache item key "%s" is too long (%s bytes). Allowed maximum size is 250 bytes.', $name, $length));
        }

        return true;
    }

}
