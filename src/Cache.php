<?php

namespace Basster\Doctrine\Cache\Elastica;

use Doctrine\Common\Cache\CacheProvider;
use Elastica\Client;
use Elastica\Exception\NotFoundException;
use Elastica\Index;
use Elastica\Type;

class Cache extends CacheProvider
{
    const ID_FIELD    = 'id';
    const VALUE_FIELD = 'value';
    const TYPE_NAME   = 'cache-item';

    /** @var string */
    private $typeName;

    /** @var  Client */
    private $client;

    /** @var  Index */
    private $index;

    /** @var  Type */
    private $type;

    /** @var  string */
    private $indexName;

    /**
     * ElasticaCache constructor.
     *
     * @param Client $client
     * @param array  $options
     */
    public function __construct(Client $client, array $options)
    {
        if (!array_key_exists('index', $options)) {
            throw new \InvalidArgumentException(
              'You must provide the "index" option for ' . __CLASS__
            );
        }

        $this->typeName  = self::TYPE_NAME;
        $this->client    = $client;
        $this->indexName = $options['index'];
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return string|boolean The cached data or FALSE, if no cache entry exists for the given id.
     */
    protected function doFetch($id)
    {
        try {
            $doc  = $this->getDocumentById($id);
            $data = $doc->get(self::VALUE_FIELD);

            return unserialize($data);
        } catch (NotFoundException $ex) {
            return false;
        }
    }

    private function getDocumentById($id)
    {
        $doc = $this->getType()->getDocument($id);

        return $doc;
    }

    /**
     * @return Type
     */
    private function getType()
    {
        if (null === $this->type) {
            $this->type = $this->getIndex()->getType($this->typeName);

            $mapping = new Type\Mapping();
            $mapping->setType($this->type);
            $mapping->setTtl(['enabled' => true]);

            $mapping->setProperties(
              [
                self::ID_FIELD    => [
                  'type'           => 'string',
                  'include_in_all' => true,
                ],
                self::VALUE_FIELD => [
                  'type'           => 'string',
                  'include_in_all' => true,
                ],
              ]
            );

            $mapping->send();
        }

        return $this->type;
    }

    /**
     * @return Index
     */
    private function getIndex()
    {
        if (null === $this->index) {
            $this->index = $this->client->getIndex($this->indexName);
            if (!$this->index->exists()) {
                $this->index->create();
            }
        }

        return $this->index;
    }

    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     *
     * @return boolean TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    protected function doContains($id)
    {
        try {
            $this->getDocumentById($id);

            return true;
        } catch (NotFoundException $ex) {
            return false;
        }
    }

    /**
     * Puts data into the cache.
     *
     * @param string $id         The cache id.
     * @param string $data       The cache entry/data.
     * @param int    $lifeTime   The lifetime. If != 0, sets a specific lifetime for this
     *                           cache entry (0 => infinite lifeTime).
     *
     * @return boolean TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {
        $fields = [
          self::VALUE_FIELD => serialize($data),
        ];

        $lifeTime = (int)$lifeTime;

        $type = $this->getType();

        try {
            $doc = $type->getDocument($id);
            $doc->setData($fields);
            if ($lifeTime > 0) {
                $doc->setTtl($lifeTime);
            }
            $type->updateDocument($doc);

        } catch (NotFoundException $ex) {
            $doc = $type->createDocument($id, $fields);
            if ($lifeTime > 0) {
                $doc->setTtl($lifeTime);
            }
            $type->addDocument($doc);
        } catch (\Exception $e) {
            return false;
        }

        $type->getIndex()->refresh();

        return true;
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    protected function doDelete($id)
    {
        try {
            $this->getType()->deleteById($id);

            return true;
        } catch (NotFoundException $ex) {
            return false;
        }
    }

    /**
     * Flushes all cache entries.
     *
     * @return boolean TRUE if the cache entries were successfully flushed, FALSE otherwise.
     */
    protected function doFlush()
    {
        $this->getType()->delete();

        return true;
    }

    /**
     * Retrieves cached information from the data store.
     *
     * @since 2.2
     *
     * @return array|null An associative array with server's statistics if available, NULL otherwise.
     */
    protected function doGetStats()
    {
        return $this->client->getStatus()->getServerStatus();
    }
}
