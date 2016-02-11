<?php

namespace Basster\Doctrine\Cache\Elastica\Test;

use Basster\Doctrine\Cache\Elastica\Cache;
use Elastica\Document;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Class CacheTest
 *
 * @package DeviceManager\Api\Tests\Cache\Elastica
 * @group   cache
 */
class CacheTest extends \PHPUnit_Framework_TestCase
{
    private $indexName = 'elastic-cache';

    /** @var  \Elastica\Type|ObjectProphecy */
    private $type;

    /** @var  \Elastica\Index|ObjectProphecy */
    private $index;

    /** @var  \Elastica\Client|ObjectProphecy */
    private $client;

    /** @var  Cache */
    private $cache;

    private $namespaceId = 1;

    /**
     * @test
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage You must provide the "index" option
     */
    public function cannotInitializeWithoutIndexOption()
    {
        $cache = new Cache($this->client->reveal(), []);
    }

    /**
     * @test
     */
    public function doFetchExistingDoc()
    {
        $id   = 1;
        $data = 'foobar';

        $this->prophesizeFindDocument($id, $data);

        self::assertEquals($data, $this->cache->fetch($id));
    }

    /**
     * @param $id
     * @param $data
     *
     * @return Document
     */
    private function prophesizeFindDocument($id, $data)
    {
        $document = $this->getDocument($id, $data);

        $this->type->getDocument($this->getCacheId($id))
                   ->shouldBeCalled()
                   ->willReturn($document);

        return $document;
    }

    /**
     * @param $id
     * @param $data
     *
     * @return Document
     */
    private function getDocument($id, $data)
    {
        $document = new Document($id, [Cache::VALUE_FIELD => serialize($data)]);

        return $document;
    }

    private function getCacheId($id)
    {
        return sprintf('[%s][%s]', $this->namespaceId, $id);
    }

    /**
     * @test
     */
    public function doFetchNonExistingDoc()
    {
        $id = 1;
        $this->prophesizeGetDocumentThrowsException($id);

        self::assertFalse($this->cache->fetch($id));
    }

    /**
     * @param $id
     */
    private function prophesizeGetDocumentThrowsException($id)
    {
        $this->type->getDocument($this->getCacheId($id))
                   ->shouldBeCalled()
                   ->willThrow('\Elastica\Exception\NotFoundException');
    }

    /**
     * @test
     */
    public function doContainsExistingDoc()
    {
        $id = 1;
        $this->prophesizeFindDocument($id, 'foobar');
        self::assertTrue($this->cache->contains($id));
    }

    /**
     * @test
     */
    public function doContainsNonExistingDoc()
    {
        $id = 1;
        $this->prophesizeGetDocumentThrowsException($id);
        self::assertFalse($this->cache->contains($id));
    }

    /**
     * @test
     */
    public function doUpdateExistingDocumentOnSave()
    {
        $id   = 1;
        $data = 'foobar';

        $doc = $this->prophesizeFindDocument($id, $data);
        $this->index->refresh()->shouldBeCalled();

        $this->type->updateDocument($doc)->shouldBeCalled();

        self::assertTrue($this->cache->save($id, $data));
        self::assertEquals(serialize($data), $doc->get(Cache::VALUE_FIELD));
    }

    /**
     * @test
     */
    public function createNewDocumentOnSave()
    {
        $id   = 1;
        $data = 'foobar';

        $this->prophesizeGetDocumentThrowsException($id);
        $this->index->refresh()->shouldBeCalled();

        $doc = $this->getDocument($id, $data);
        $this->type->createDocument(
          $this->getCacheId($id),
          [Cache::VALUE_FIELD => serialize($data)]
        )
                   ->shouldBeCalled()
                   ->willReturn($doc);
        $this->type->addDocument($doc)->shouldBeCalled();

        self::assertTrue($this->cache->save($id, $data));
    }

    /**
     * @test
     */
    public function doDelete()
    {
        $id = 1;
        $this->type->deleteById($this->getCacheId($id))->shouldBeCalled();
        self::assertTrue($this->cache->delete($id));
    }

    /**
     * @test
     */
    public function doDeleteReturnsFalseWhenNotFound()
    {
        $id = 1;
        $this->type->deleteById($this->getCacheId($id))
                   ->shouldBeCalled()
                   ->willThrow('\Elastica\Exception\NotFoundException');
        self::assertFalse($this->cache->delete($id));
    }

    /**
     * @test
     */
    public function doFlush()
    {
        $this->type->delete()->shouldBeCalled();
        self::assertTrue($this->cache->flushAll());
    }

    /**
     * @test
     */
    public function doGetStats()
    {
        $data   = ['foo' => 'bar'];
        $status = $this->prophesize('\Elastica\Status');
        $status->getServerStatus()->shouldBeCalled()->willReturn($data);

        $this->client->getStatus()
                     ->shouldBeCalled()
                     ->willReturn($status->reveal());

        self::assertEquals($data, $this->cache->getStats());
    }

    protected function setUp()
    {
        $typeName = Cache::TYPE_NAME;

        $this->type = $this->prophesize('\Elastica\Type');
        $this->type->request(Argument::any(), Argument::cetera())
                   ->willReturn(true);
        $this->type->getName()->willReturn($typeName);

        $this->index = $this->prophesize('\Elastica\Index');
        $this->index->getType($typeName)->willReturn($this->type->reveal());
        $this->index->exists()->willReturn(true);

        $nsDoc = new Document(
          'DoctrineNamespaceCacheKey[]',
          [Cache::VALUE_FIELD => serialize($this->namespaceId)]
        );

        $this->type->getIndex()->willReturn($this->index->reveal());
        $this->type->getDocument("DoctrineNamespaceCacheKey[]")
                   ->willReturn($nsDoc);

        $this->client = $this->prophesize('\Elastica\Client');
        $this->client->getIndex($this->indexName)->willReturn(
          $this->index->reveal()
        );

        $this->cache = new Cache(
          $this->client->reveal(),
          ['index' => $this->indexName]
        );
    }
}
