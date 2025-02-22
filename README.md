# WordPress Interop

[![Github Workflow](https://github.com/williarin/wordpress-interop/workflows/Test/badge.svg)](https://github.com/williarin/wordpress-interop/actions)

## Introduction

This library aims to simplify the interaction with WordPress databases through third-party applications.
It relies on Doctrine DBAL and looks like Doctrine ORM.

It can perform simple tasks out of the box such as querying posts, retrieving attachment data, etc.

You can extend it by adding your own repositories and querying methods.

**Warning!** Although it looks like an ORM, it's not an ORM library. It doesn't have two-way data manipulation features.
See this as a simple WordPress database manipulation helper library.

## Installation

This library can be used as standalone:
```bash
composer require williarin/wordpress-interop
```

Or with Symfony:
```bash
composer require williarin/wordpress-interop-bundle
```

Find the documentation for the Symfony bundle on [the dedicated repository](https://github.com/williarin/wordpress-interop-bundle) page.

## Usage

### Overview

```php
$post = $manager->getRepository(Post::class)->find(15);
```

### In detail

The first thing to do is to create an entity manager linked to your DBAL connection targeting your WordPress database.

```php
$connection = DriverManager::getConnection(['url' => 'mysql://user:pass@localhost:3306/wp_mywebsite?serverVersion=8.0']);

$objectNormalizer = new ObjectNormalizer(
    new ClassMetadataFactory(new AnnotationLoader(new AnnotationReader())),
    new CamelCaseToSnakeCaseNameConverter(),
    null,
    new ReflectionExtractor()
);

$serializer = new Serializer([
    new DateTimeNormalizer(),
    new ArrayDenormalizer(),
    new SerializedArrayDenormalizer($objectNormalizer),
    $objectNormalizer,
]);

$manager = new EntityManager($connection, $serializer);
```

Then you can query the database:
```php
/** @var PostRepository $postRepository */
$postRepository = $manager->getRepository(Post::class);
$myPost = $postRepository->find(15);
$allPosts = $postRepository->findAll();
```

## Documentation

### Basic querying

This works with any entity inherited from `BaseEntity`.
Built-in entities are `Post`, `Page`, `Attachment` and `Product` but you can [create your own](#create-your-own-repositories).

```php
// Fetch a post by ID
$post = $manager->getRepository(Post::class)->find(1);

// Fetch the latest published post
$post = $manager->getRepository(Post::class)
    ->findOneByPostStatus('publish', ['post_date' => 'DESC']);

// Fetch the latest published post which has 1 comment
$post = $manager->getRepository(Post::class)
    ->findOneBy(
        ['post_status' => 'publish', 'comment_count' => 1],
        ['post_date' => 'DESC'],
    );

// Fetch the latest published post which has the most comments
$post = $manager->getRepository(Post::class)
    ->findOneByPostStatus(
        'publish',
        ['comment_count' => 'DESC', 'post_date' => 'DESC'],
    );

// Fetch all posts which have draft or private status
$posts = $manager->getRepository(Post::class)
    ->findByPostStatus(new Operand(['draft', 'private'], Operand::OPERATOR_IN));

// Fetch all posts
$posts = $manager->getRepository(Post::class)->findAll();

// Fetch all private posts
$posts = $manager->getRepository(Post::class)->findByPostStatus('private');

// Fetch all products whose titles match regexp
$products = $manager->getRepository(Product::class)
    ->findByPostTitle(new Operand('Hoodie.*Pocket|Zipper', Operand::OPERATOR_REGEXP));
```

### EAV querying

_The term EAV refers to the [entity-attribute-value model](https://en.wikipedia.org/wiki/Entity%E2%80%93attribute%E2%80%93value_model) used by WordPress through the term "meta" as in `wp_postmeta`, `wp_termmeta`, `wp_usermeta` etc. Here we're talking about `wp_postmeta`._

The query system supports directly querying EAV attributes.

In the example below, `sku` and `stock_status` are attributes from `wp_postmeta` table.

_Note: Field names are mapped to match their property name. As an example, `_sku` becomes `sku`, or `_wc_average_rating` becomes `average_rating`._

```php
// Fetch a product by its SKU
$product = $manager->getRepository(Product::class)->findOneBySku('woo-vneck-tee');

// Fetch the latest published product which is in stock
$product = $manager->getRepository(Product::class)
    ->findOneBy(
        ['stock_status' => 'instock', 'post_status' => 'publish'],
        ['post_date' => 'DESC'],
    );
    
// Fetch all published products which are in stock
$products = $manager->getRepository(Product::class)
    ->findBy(
        ['stock_status' => 'instock', 'post_status' => 'publish'],
        ['post_date' => 'DESC'],
    );

// Fetch all products whose sku match regexp
$products = $manager->getRepository(Product::class)
    ->findBySku(new Operand('hoodie.*logo|zipper', Operand::OPERATOR_REGEXP));

```

If you query an EAV attribute that doesn't exist in the entity, an `InvalidFieldNameException` exception will be thrown.

To allow extra dynamic properties to be queried, set `allow_extra_properties` option to `true` before the query. Careful though, options are set for the repository and not the query, which means they will apply to all further queries. 

```php
$page = $manager->getRepository(Page::class)
    ->setOptions([
        'allow_extra_properties' => true,
    ])
    ->findOneBy([
        new SelectColumns(['id', select_from_eav('wp_page_template')]),
        'post_status' => 'publish',
        'wp_page_template' => 'default',
    ])
;
// $page->wpPageTemplate === 'default'
```

### Nested conditions

For more complex querying needs, you can add nested conditions.

_Note: it only works with columns and not EAV attributes._

```php
// Fetch Hoodies as well as products with at least 30 comments, all of which are in stock
$products = $manager->getRepository(Product::class)
    ->findBy([
        new NestedCondition(NestedCondition::OPERATOR_OR, [
            'post_title' => new Operand('Hoodie%', Operand::OPERATOR_LIKE),
            'comment_count' => new Operand(30, Operand::OPERATOR_GREATER_THAN_OR_EQUAL),
        ]),
        'stock_status' => 'instock',
    ]);

// Fetch two products by their SKU and two by their ID
$products = $manager->getRepository(Product::class)
    ->findBy([
        new NestedCondition(NestedCondition::OPERATOR_OR, [
            'sku' => new Operand(['woo-tshirt', 'woo-single'], Operand::OPERATOR_IN),
            'id' => new Operand([19, 20], Operand::OPERATOR_IN),
        ]),
    ]);
// count($products) === 4
```

### EAV relationship conditions

Query entities based on their EAV relationships.

_Note: the EAV fields must have their original names, unlike mapped fields for direct EAV querying._

```php
// Fetch the featured image of the post with ID "4"
$attachment = $manager->getRepository(Attachment::class)
    ->findOneBy([
        new RelationshipCondition(4, '_thumbnail_id'),
    ]);

// Get featured images of posts 4, 13, 18 and 23 at once
$attachments = $manager->getRepository(Attachment::class)
    ->findBy([
        new RelationshipCondition(
            new Operand([4, 13, 18, 23], Operand::OPERATOR_IN),
            '_thumbnail_id',
        ),
    ]);

// Same as above example but include the original ID in the result
$attachments = $manager->getRepository(Attachment::class)
    ->findBy([
        new RelationshipCondition(
            new Operand([4, 13, 18, 23], Operand::OPERATOR_IN),
            '_thumbnail_id',
            'original_post_id',
        ),
    ]);
// $attachments[0]->originalPostId === 4
```


### Term and taxonomy relationship conditions

Query entities based on their terms and taxonomies relationships.

```php
// Fetch products in the category "Hoodies"
$products = $manager->getRepository(Product::class)
    ->findBy([
        new TermRelationshipCondition([
            'taxonomy' => 'product_cat',
            'name' => 'Hoodies',
        ]),
    ]);
```

Additionally, you can query terms from a joint entity, and specify the name of the term table.

In this example, we assume that the products have a `related_product` postmeta.
```php
// Fetch a product's category and the category of its related product
$product = $manager->getRepository(Product::class)
    ->findOneBy([
        new SelectColumns([
            'id',
            'main.name AS category',
            'related.name AS related_category',
            select_from_eav(
                fieldName: 'related_product',
                metaKey: 'related_product', // needed as it's not starting with an underscore
            ),
        ]),
        new TermRelationshipCondition(
            ['taxonomy' => 'product_cat'],
            termTableAlias: 'main',
        ),
        new TermRelationshipCondition(
            ['taxonomy' => 'product_cat'],
            joinConditionField: 'related_product',
            termTableAlias: 'related',
        ),
        'id' => 22,
    ]);
// $product->category === 'Hoodies'
// $product->relatedCategory === 'Accessories'
```

If not specified, the term table alias defaults to `t_0`, `t_1`, etc.

A special operator `Operand::OPERATOR_IN_ALL` is also provided to match exactly all values in an array.

```php
// Fetch products that have both 'featured' and 'accessories' terms
$products = $manager->getRepository(Product::class)
    ->findBy([
        new TermRelationshipCondition([
            'slug' => new Operand(['featured', 'accessories'], Operand::OPERATOR_IN_ALL),
        ]),
    ]);
```

This operator is not limited to terms querying, but it's the most obvious use case.

### Post relationship conditions

Query terms based on their posts relationships.

```php
// Fetch all terms of the product with SKU "super-forces-hoodie"
// belonging to all taxonomies except "product_tag", "product_type", "product_visibility".
$terms = $manager->getRepository(Term::class)
    ->findBy([
        new SelectColumns(['taxonomy', 'name']),
        new PostRelationshipCondition(Product::class, [
            'post_status' => new Operand(['publish', 'private'], Operand::OPERATOR_IN),
            'sku' => 'super-forces-hoodie',
        ]),
        'taxonomy' => new Operand(
            ['product_tag', 'product_type', 'product_visibility'],
            Operand::OPERATOR_NOT_IN,
        ),
    ]);
```

### Restrict selected columns

Querying all columns at once is slow, especially if you have a lot of entities to retrieve.
You can restrict the queried columns as the example below.

It works with base columns as well as EAV attributes.

```php
// Fetch only products title and SKU
$products = $manager->getRepository(Product::class)
    ->findBy([
        new SelectColumns(['post_title', 'sku']),
        'sku' => new Operand('hoodie.*logo|zipper', Operand::OPERATOR_REGEXP),
    ]);

// Product entities are filled with null values except $postTitle and $sku
```

You can as well select a column which doesn't have a mapped property in your entity.

```php
$product = $manager->getRepository(Product::class)
    ->findOneBy([
        new SelectColumns(['id', 'post_title', 'name AS category']),
        new TermRelationshipCondition([
            'taxonomy' => 'product_cat'
        ]),
    ]);

// $product->category will have the corresponding category name
```

### Extending the generated query

For advanced needs, it's also possible to retrieve the query builder and modify it to your needs.

_Note: use `select_from_eav()` function to query EAV attributes._
```php
// Fetch all products but override SELECT clause with only tree columns
$repository = $manager->getRepository(Product::class);
$result = $repository->createFindByQueryBuilder([], ['sku' => 'ASC'])
    ->select('id', 'post_title', select_from_eav('sku'))
    ->executeQuery()
    ->fetchAllAssociative();
$products = $repository->denormalize($result, Product::class . '[]');
```

### Add terms to an entity

```php
// Add all existing product tags to a product
$repository = $manager->getRepository(Term::class);
$repository->addTermsToEntity($product, $repository->findByTaxonomy('product_tag'));
```

### Remove terms from an entity

```php
// Remove all existing product tags from a product
$repository = $manager->getRepository(Term::class);
$repository->removeTermsFromEntity($product, $repository->findByTaxonomy('product_tag'));
```

### Field update
There's a type validation before update.
You can't assign a string to a date field, a string to an int field, etc.

```php
$repository = $manager->getRepository(Post::class);
$repository->updatePostTitle(4, 'New title');
$repository->updatePostContent(4, 'New content');
$repository->updatePostDate(4, new \DateTime());
// Alternative
$repository->updateSingleField(4, 'post_status', 'publish');
```

### Entity creation or update
Create or update an entity with all its fields at once.

Limitations:
* Only the base fields (the columns in `wp_posts` table) are persisted, not the EAV
* All properties must be filled before object creation or update as the schema doesn't support NULL values
* No change tracking

```php
$repository = $manager->getRepository(Post::class);
$post = $repository->findOneByPostTitle('My post');
$post->postTitle = 'A new title for my post';
$post->postStatus = 'publish';
$repository->persist($post);
// or directly calling the EntityManager
$manager->persist($post);
```

### Entity duplication
Duplicate an entity with all its EAV attributes and terms with `DuplicationService`.
The resulting entity is already persisted and has a new ID.

```php
$duplicationService = $manager->getDuplicationService();

// Duplicate by ID
$newProduct =  $duplicationService->duplicate(23, Product::class);

// Duplicate by object
$product = $manager->getRepository(Product::class)->findOneBySku('woo-hoodie-with-zipper');
$newProduct =  $duplicationService->duplicate($product);
```

### Available entities and repositories

* `Post` and `PostRepository`
* `Page` and `PageRepository`
* `Attachment` and `AttachmentRepository`
* `Option` and `OptionRepository`
* `PostMeta` and `PostMetaRepository`
* `Term` and `TermRepository`
* `User` and `UserRepository`
* `Product` and `ProductRepository` (WooCommerce)
* `ShopOrder` and `ShopOrderRepository` (WooCommerce)
* `ShopOrderItem` and `ShopOrderItemRepository` (WooCommerce)

### Get an option value

To retrieve a WordPress option, you have several choices:
```php
// Query the option name yourself
$blogName = $manager->getRepository(Option::class)->find('blogname');

// Use a predefined getter
$blogName = $manager->getRepository(Option::class)->findBlogName();

// If there isn't a predefined getter, use a magic method.
// Here we get the 'active_plugins' option, automatically unserialized.
$plugins = $manager->getRepository(Option::class)->findActivePlugins();
```

### Create your own entities and repositories

Say you have a custom post type named `project`.

First you create a simple entity:

```php
// App/Wordpress/Entity/Project.php
namespace App\Wordpress\Entity;

use App\Wordpress\Repository\ProjectRepository;
use Williarin\WordpressInterop\Attributes\RepositoryClass;
use Williarin\WordpressInterop\Bridge\Entity\BaseEntity;

#[RepositoryClass(ProjectRepository::class)]
final class Project extends BaseEntity
{
}
```

Then a repository:

```php
// App/Wordpress/Repository/ProjectRepository.php
namespace App\Wordpress\Repository;

use App\Wordpress\Entity\Project;
use Symfony\Component\Serializer\SerializerInterface;
use Williarin\WordpressInterop\Bridge\Repository\AbstractEntityRepository;
use Williarin\WordpressInterop\EntityManagerInterface;

/**
 * @method Project|null find($id)
 * @method Project[]    findAll()
 */
final class ProjectRepository extends AbstractEntityRepository
{
    public function __construct(/* inject additional services if you need them */)
    {
        parent::__construct(Project::class);
    }
    
    protected function getPostType(): string
    {
        return 'project';
    }
    
    // Add your own methods here
}
```
Then use it like this:
```php
$allProjects = $manager->getRepository(Project::class)->findAll();
```

It also works if your entity is in a separate table, with some additional configuration.
Take as an example [ShopOrderItemRepository](src/Bridge/Repository/ShopOrderItemRepository.php).

You'll have to override some constants:
```php
final class ShopOrderItemRepository extends AbstractEntityRepository
{
    protected const TABLE_NAME = 'woocommerce_order_items';
    protected const TABLE_META_NAME = 'woocommerce_order_itemmeta';
    protected const TABLE_IDENTIFIER = 'order_item_id';
    protected const TABLE_META_IDENTIFIER = 'order_item_id';
    protected const FALLBACK_ENTITY = ShopOrderItem::class;

    public function __construct()
    {
        parent::__construct(ShopOrderItem::class);
    }
}
```

### Entity and repository inheritance

You might have some custom attributes for existing entities such as `Post`.

1. Create a new entity that extends `Post` with new fields
2. Create a new repository that extends `PostRepository` and override `getEntityClassName()` method to return your new `MyPost` entity class name
3. Add mapped fields to your `PostRepository`
4. Add `#[RepositoryClass(MyPostRepository::class)]` to your `MyPost` entity

## License

[MIT](LICENSE)

Copyright (c) 2022, William Arin
