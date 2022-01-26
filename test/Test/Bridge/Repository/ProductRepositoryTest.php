<?php

declare(strict_types=1);

namespace Williarin\WordpressInterop\Test\Bridge\Repository;

use Williarin\WordpressInterop\Bridge\Entity\Product;
use Williarin\WordpressInterop\Bridge\Repository\ProductRepository;
use Williarin\WordpressInterop\Exception\EntityNotFoundException;
use Williarin\WordpressInterop\Test\TestCase;

class ProductRepositoryTest extends TestCase
{
    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ProductRepository($this->manager, $this->serializer);
    }

    public function testFindReturnsCorrectProduct(): void
    {
        $product = $this->repository->find(14);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame(14, $product->id);
        self::assertSame('V-Neck T-Shirt', $product->postTitle);
        self::assertSame('woo-vneck-tee', $product->sku);
        self::assertStringContainsString('Pellentesque habitant morbi tristique', $product->postContent);
    }

    public function testFindThrowsExceptionIfNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->repository->find(150);
    }

    public function testFindAllReturnsCorrectNumberOfPosts(): void
    {
        $products = $this->repository->findAll();
        self::assertContainsOnlyInstancesOf(Product::class, $products);
        self::assertCount(18, $products);
    }

    public function testFindBySku(): void
    {
        $product = $this->repository->findOneBySku('woo-vneck-tee');
        self::assertSame(14, $product->id);
        self::assertSame('woo-vneck-tee', $product->sku);
    }

    public function testProductAttributesAreAccessibleAsGenericData(): void
    {
        $product = $this->repository->find(14);
        self::assertSame([
            'name' => 'pa_color',
            'value' => '',
            'position' => 0,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 1,
        ], $product->productAttributes->getPaColor());
    }

    public function testProductWeightIsConvertedToFloat(): void
    {
        $product = $this->repository->find(14);
        self::assertSame(0.5, $product->weight);
    }

    public function testLatestPublishedProductInStock(): void
    {
        $product = $this->repository->findOneBy(
            ['stock_status' => 'instock', 'post_status' => 'publish'],
            ['post_date' => 'DESC'],
        );

        self::assertSame(37, $product->id);
    }
}
