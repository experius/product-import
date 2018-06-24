<?php

namespace BigBridge\ProductImport\Test\Integration;

use BigBridge\ProductImport\Api\Data\ProductStoreView;
use BigBridge\ProductImport\Model\Resource\Resolver\CategoryImporter;
use BigBridge\ProductImport\Model\Resource\Serialize\JsonValueSerializer;
use BigBridge\ProductImport\Model\Resource\Serialize\SerializeValueSerializer;
use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Api\ProductRepositoryInterface;
use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Model\Persistence\Magento2DbConnection;
use BigBridge\ProductImport\Api\ImportConfig;
use BigBridge\ProductImport\Model\Resource\MetaData;
use BigBridge\ProductImport\Api\Data\SimpleProduct;
use BigBridge\ProductImport\Api\ImporterFactory;

/**
 * @author Patrick van Bergen
 */
class UrlRewriteTest extends \PHPUnit\Framework\TestCase
{
    /** @var  ImporterFactory */
    private static $factory;

    /** @var ProductRepositoryInterface $repository */
    private static $repository;

    /** @var  Magento2DbConnection */
    protected static $db;

    /** @var  Metadata */
    protected static $metadata;

    public static function setUpBeforeClass()
    {
        // include Magento
        require_once __DIR__ . '/../../../../../index.php';

        /** @var ImporterFactory $factory */
        self::$factory = ObjectManager::getInstance()->get(ImporterFactory::class);

        /** @var ProductRepositoryInterface $repository */
        self::$repository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);

        /** @var Magento2DbConnection $db */
        self::$db = ObjectManager::getInstance()->get(Magento2DbConnection::class);

        self::$metadata = ObjectManager::getInstance()->get(MetaData::class);

        $table = self::$metadata->productEntityTable;
        self::$db->execute("DELETE FROM `{$table}` WHERE sku LIKE '%-product-import'");
        $table = self::$metadata->urlRewriteTable;
        self::$db->execute("DELETE FROM `{$table}` WHERE request_path LIKE '%product-import.html'");
    }

    /**
     * @throws Exception
     */
    public function testUrlRewriteCompoundCategories()
    {
        /** @var Magento2DbConnection $db */
        $db = ObjectManager::getInstance()->get(Magento2DbConnection::class);
        /** @var MetaData $metadata */
        $metadata = ObjectManager::getInstance()->get(MetaData::class);
        /** @var CategoryImporter $categoryImporter */
        $categoryImporter = ObjectManager::getInstance()->get(CategoryImporter::class);
        list($c1,) = $categoryImporter->importCategoryPath("Default Category/Boxes", true, '/');
        list($c2a,) = $categoryImporter->importCategoryPath("Default Category/Colored Things", true, '/');
        list($c2b,) = $categoryImporter->importCategoryPath("Default Category/Colored Things/Containers", true, '/');
        list($c2c,) = $categoryImporter->importCategoryPath("Default Category/Colored Things/Containers/Large", true, '/');

        $config = new ImportConfig();
        self::$metadata->valueSerializer = new SerializeValueSerializer();

        $importer = self::$factory->createImporter($config);

        $sku1 = uniqid("bb");
        $urlKey = 'u' . $sku1;

        $product = new SimpleProduct($sku1);

        $product->setAttributeSetByName("Default");
        $product->addCategoriesByGlobalName(["Default Category/Boxes", "Default Category/Colored Things/Containers/Large", "Default Category/Colored Things/Containers"]);

        $global = $product->global();
        $global->setName("Big Purple Box");
        $global->setPrice("1.25");
        $global->setUrlKey($urlKey);
        $global->setVisibility(ProductStoreView::VISIBILITY_BOTH);

        $importer->importSimpleProduct($product);

        $importer->flush();

        $this->assertEquals([], $product->getErrors());

        $actual = $db->fetchAllNonAssoc("
            SELECT `entity_type`, `request_path`, `target_path`, `redirect_type`, `store_id`, `is_autogenerated`, `metadata` FROM `{$metadata->urlRewriteTable}` 
            WHERE `store_id` = 1 AND `entity_id` = {$product->id}
            ORDER BY `url_rewrite_id`
        ");

        $expected = [
            ['product', $urlKey . '.html', 'catalog/product/view/id/' . $product->id, '0', '1', '1', null],
            ['product', 'boxes/' . $urlKey . '.html', 'catalog/product/view/id/' . $product->id . '/category/' . $c1, '0', '1', '1', serialize(['category_id' => (string)$c1])],
            ['product', 'colored-things/' . $urlKey . '.html', 'catalog/product/view/id/' . $product->id . '/category/' . $c2a, '0', '1', '1', serialize(['category_id' => (string)$c2a])],
            ['product', 'colored-things/containers/' . $urlKey . '.html', 'catalog/product/view/id/' . $product->id . '/category/' . $c2b, '0', '1', '1', serialize(['category_id' => (string)$c2b])],
            ['product', 'colored-things/containers/large/' . $urlKey . '.html', 'catalog/product/view/id/' . $product->id . '/category/' . $c2c, '0', '1', '1', serialize(['category_id' => (string)$c2c])],
        ];

        $this->assertEquals($expected, $actual);
    }

    /**
     * @throws Exception
     */
    public function testNoUrlRewrites()
    {
        /** @var Magento2DbConnection $db */
        $db = ObjectManager::getInstance()->get(Magento2DbConnection::class);
        /** @var MetaData $metadata */
        $metadata = ObjectManager::getInstance()->get(MetaData::class);

        $config = new ImportConfig();
        self::$metadata->valueSerializer = new SerializeValueSerializer();

        $importer = self::$factory->createImporter($config);

        $sku1 = uniqid("bb");
        $urlKey = 'u' . $sku1;

        $product = new SimpleProduct($sku1);
        $product->setAttributeSetByName("Default");

        $global = $product->global();
        $global->setName("Big Purple Box");
        $global->setPrice("1.25");
        $global->setUrlKey($urlKey);
        // note: no visibility

        $importer->importSimpleProduct($product);

        $importer->flush();

        $this->assertEquals([], $product->getErrors());

        $actual = $db->fetchAllNonAssoc("
            SELECT * FROM `{$metadata->urlRewriteTable}` 
            WHERE `store_id` = 1 AND `entity_id` = {$product->id}
            ORDER BY `url_rewrite_id`
        ");

        $expected = [];

        $this->assertEquals($expected, $actual);

        $product = new SimpleProduct($sku1);
        $product->setAttributeSetByName("Default");

        $global = $product->global();
        $global->setName("Big Purple Box");
        $global->setPrice("1.25");
        $global->setUrlKey($urlKey);
        // note: visibility: not visible individually
        $global->setVisibility(ProductStoreView::VISIBILITY_NOT_VISIBLE);

        $importer->importSimpleProduct($product);

        $importer->flush();

        $this->assertEquals([], $product->getErrors());

        $actual = $db->fetchAllNonAssoc("
            SELECT * FROM `{$metadata->urlRewriteTable}` 
            WHERE `store_id` = 1 AND `entity_id` = {$product->id}
            ORDER BY `url_rewrite_id`
        ");

        $expected = [];

        $this->assertEquals($expected, $actual);
    }

    /**
     * @throws Exception
     */
    public function testUrlRewritesGeneration()
    {
        $config = new ImportConfig();
        self::$metadata->valueSerializer = new SerializeValueSerializer();

        $importer = self::$factory->createImporter($config);

        // create a product just for the category
        $productX = new SimpleProduct('cat-product-import');
        $productX->setAttributeSetByName("Default");
        $productX->addCategoriesByGlobalName(["Default Category/Boxes"]);
        $productX->global()->setName("Category dummy");
        $productX->global()->setPrice("0");
        $importer->importSimpleProduct($productX);
        $importer->flush();

        $categoryId = $productX->getCategoryIds()[0];

        // give this category a Dutch name
        self::$metadata->allCategoryInfo[$categoryId]->urlKeys[1] = 'dozen';

        // product
        $product1 = new SimpleProduct('1-product-import');
        $product1->setAttributeSetByName("Default");
        $product1->addCategoriesByGlobalName(["Default Category/Boxes"]);
        $product1->global()->setName("Big Turquoise Box product-import");
        $product1->global()->setPrice("2.75");
        $product1->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product1->global()->generateUrlKey();

        // same sku, different store view
        $default = $product1->storeView('default');
        $default->setName("Grote Turquoise Doos product-import");
        $default->generateUrlKey();

        $importer->importSimpleProduct($product1);

        // another product
        $product3 = new SimpleProduct('2-product-import');
        $product3->setAttributeSetByName("Default");
        $product3->addCategoriesByGlobalName(["Default Category/Boxes"]);
        $product3->global()->setName("Big Grass Green Box product-import");
        $product3->global()->setPrice("2.65");
        $product3->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product3->global()->generateUrlKey();

        $importer->importSimpleProduct($product3);


        $importer->flush();

        // insert

        $expectedRewrites = [
            ["product", "grote-turquoise-doos-product-import.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "dozen/grote-turquoise-doos-product-import.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}", "0", "1", "1", null],
            ["product", "dozen/big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],
        ];

        $expectedIndexes = [
            [(string)$categoryId, $product1->id],
            [(string)$categoryId, $product3->id],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product3);

        // store again, with no changes

        $importer->importSimpleProduct($product1);
        $importer->importSimpleProduct($product3);

        $importer->flush();

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product3);

        // change url_key

        $product3->global()->setUrlKey("a-" . $product3->global()->getUrlKey());

        $importer->importSimpleProduct($product1);
        $importer->importSimpleProduct($product3);

        $importer->flush();

        $expectedRewrites = [
            ["product", "grote-turquoise-doos-product-import.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "dozen/grote-turquoise-doos-product-import.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "big-grass-green-box-product-import.html", "a-big-grass-green-box-product-import.html", "301", "1", "0", serialize([])],
            ["product", "dozen/big-grass-green-box-product-import.html", "dozen/a-big-grass-green-box-product-import.html", "301", "1", "0",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "a-big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}", "0", "1", "1", null],
            ["product", "dozen/a-big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product3);

        // change categories

        $product3->addCategoriesByGlobalName(["Default Category/Containers"]);

        $importer->importSimpleProduct($product1);
        $importer->importSimpleProduct($product3);

        $importer->flush();

        $newCategoryId = $product3->getCategoryIds()[0];

        $expectedRewrites = [
            ["product", "grote-turquoise-doos-product-import.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "dozen/grote-turquoise-doos-product-import.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "big-grass-green-box-product-import.html", "a-big-grass-green-box-product-import.html", "301", "1", "0", serialize([])],
            ["product", "dozen/big-grass-green-box-product-import.html", "dozen/a-big-grass-green-box-product-import.html", "301", "1", "0",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "a-big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}", "0", "1", "1", null],
            ["product", "dozen/a-big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "containers/a-big-grass-green-box-product-import.html", "catalog/product/view/id/{$product3->id}/category/{$newCategoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$newCategoryId])],
        ];

        $expectedIndexes = [
            [(string)$categoryId, $product1->id],
            [(string)$categoryId, $product3->id],
            [(string)$newCategoryId, $product3->id],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product3);
    }

    /**
     * @throws Exception
     */
    public function testUrlRewritesWithJson()
    {
        $config = new ImportConfig();
        self::$metadata->valueSerializer = new JsonValueSerializer();

        $importer = self::$factory->createImporter($config);

        // create a product just for the category
        $productX = new SimpleProduct('cat-product-import');
        $productX->setAttributeSetByName("Default");
        $productX->addCategoriesByGlobalName(["Default Category/Boxes"]);
        $productX->global()->setName("Category dummy");
        $productX->global()->setPrice("0");
        $importer->importSimpleProduct($productX);
        $importer->flush();

        $categoryId = $productX->getCategoryIds()[0];

        // give this category a Dutch name
        self::$metadata->allCategoryInfo[$categoryId]->urlKeys[1] = 'dozen';

        // product
        $product1 = new SimpleProduct('3-product-import');
        $product1->setAttributeSetByName("Default");
        $product1->addCategoriesByGlobalName(["Default Category/Boxes"]);
        $product1->global()->setName("Big Red Box product-import");
        $product1->global()->setPrice("2.75");
        $product1->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product1->global()->generateUrlKey();

        $default = $product1->storeView('default');
        $default->setName("Grote Rode Doos product-import");
        $default->generateUrlKey();

        $importer->importSimpleProduct($product1);

        // another product
        $product3 = new SimpleProduct('4-product-import');
        $product3->setAttributeSetByName("Default");
        $product3->addCategoriesByGlobalName(["Default Category/Boxes"]);
        $product3->global()->setName("Big Grass Yellow Box product-import");
        $product3->global()->setPrice("2.65");
        $product3->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product3->global()->generateUrlKey();

        $importer->importSimpleProduct($product3);

        $importer->flush();

        // change url_key

        $product3->global()->setUrlKey("a-" . $product3->global()->getUrlKey());

        $importer->importSimpleProduct($product1);
        $importer->importSimpleProduct($product3);

        $importer->flush();

        // change categories

        $product3->addCategoriesByGlobalName(["Default Category/Containers"]);

        $importer->importSimpleProduct($product1);
        $importer->importSimpleProduct($product3);

        $importer->flush();

        $newCategoryId = $product3->getCategoryIds()[0];

        $expectedRewrites = [
            ["product", "grote-rode-doos-product-import.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "dozen/grote-rode-doos-product-import.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                json_encode(['category_id' => (string)$categoryId])],

            ["product", "big-grass-yellow-box-product-import.html", "a-big-grass-yellow-box-product-import.html", "301", "1", "0", json_encode([])],
            ["product", "dozen/big-grass-yellow-box-product-import.html", "dozen/a-big-grass-yellow-box-product-import.html", "301", "1", "0",
                json_encode(['category_id' => (string)$categoryId])],

            ["product", "a-big-grass-yellow-box-product-import.html", "catalog/product/view/id/{$product3->id}", "0", "1", "1", null],
            ["product", "dozen/a-big-grass-yellow-box-product-import.html", "catalog/product/view/id/{$product3->id}/category/{$categoryId}", "0", "1", "1",
                json_encode(['category_id' => (string)$categoryId])],
            ["product", "containers/a-big-grass-yellow-box-product-import.html", "catalog/product/view/id/{$product3->id}/category/{$newCategoryId}", "0", "1", "1",
                json_encode(['category_id' => (string)$newCategoryId])],

        ];

        $expectedIndexes = [
            [(string)$categoryId, $product1->id],
            [(string)$categoryId, $product3->id],
            [(string)$newCategoryId, $product3->id],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product3);
    }

    private function doAsserts(array $expectedRewrites, array $expectedIndexes, Product $product1, Product $product3)
    {
        $productIds = "{$product1->id}, {$product3->id}";

        $actualErrors = [$product1->getErrors(), $product3->getErrors()];

        $this->assertEquals([[], []], $actualErrors);

        $actualRewrites = self::$db->fetchAllNonAssoc("
            SELECT `entity_type`, `request_path`, `target_path`, `redirect_type`, `store_id`, `is_autogenerated`, `metadata` FROM `" . self::$metadata->urlRewriteTable . "`
            WHERE `store_id` = 1 AND `entity_id` IN ({$productIds})
            ORDER BY `url_rewrite_id`
        ");

        $this->assertEquals($expectedRewrites, $actualRewrites);

        $actualIndexes = self::$db->fetchAllNonAssoc("
            SELECT `category_id`, `product_id` FROM `" . self::$metadata->urlRewriteProductCategoryTable . "`
            WHERE `product_id` IN ({$productIds})
            ORDER BY `url_rewrite_id`
        ");
        $this->assertEquals($expectedIndexes, $actualIndexes);

    }

    /**
     * @throws Exception
     */
    public function testSwitchUrlKeys()
    {
        $config = new ImportConfig();
        self::$metadata->valueSerializer = new SerializeValueSerializer();
        $config->duplicateUrlKeyStrategy = ImportConfig::DUPLICATE_KEY_STRATEGY_ALLOW;

        $importer = self::$factory->createImporter($config);

        // create a product just for the category
        $productX = new SimpleProduct('cat-product-import');
        $productX->setAttributeSetByName("Default");
        $productX->addCategoriesByGlobalName(["Default Category/Names of Things"]);
        $productX->global()->setName("Category dummy");
        $productX->global()->setPrice("0");
        $importer->importSimpleProduct($productX);
        $importer->flush();

        $categoryId = $productX->getCategoryIds()[0];

        // product
        $product1 = new SimpleProduct('5-product-import');
        $product1->setAttributeSetByName("Default");
        $product1->addCategoriesByGlobalName(["Default Category/Names of Things"]);
        $product1->global()->setName("The First Name");
        $product1->global()->setPrice("2.75");
        $product1->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product1->global()->generateUrlKey();

        $importer->importSimpleProduct($product1);

        // another product
        $product2 = new SimpleProduct('6-product-import');
        $product2->setAttributeSetByName("Default");
        $product2->addCategoriesByGlobalName(["Default Category/Names of Things"]);
        $product2->global()->setName("The Second Name");
        $product2->global()->setPrice("2.65");
        $product2->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product2->global()->generateUrlKey();

        $importer->importSimpleProduct($product2);

        $importer->flush();

        // insert

        $expectedRewrites = [
            ["product", "the-first-name.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "names-of-things/the-first-name.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "the-second-name.html", "catalog/product/view/id/{$product2->id}", "0", "1", "1", null],
            ["product", "names-of-things/the-second-name.html", "catalog/product/view/id/{$product2->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],
        ];

        $expectedIndexes = [
            [(string)$categoryId, $product1->id],
            [(string)$categoryId, $product2->id],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product2);

        // swap url_keys

        $product1->global()->setName('The Second Name');
        $product1->global()->generateUrlKey();
        $product2->global()->setName('The First Name');
        $product2->global()->generateUrlKey();

        $importer->importSimpleProduct($product1);
        $importer->importSimpleProduct($product2);

        $importer->flush();

        $expectedRewrites = [

            ["product", "the-second-name.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "names-of-things/the-second-name.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],

            ["product", "the-first-name.html", "catalog/product/view/id/{$product2->id}", "0", "1", "1", null],
            ["product", "names-of-things/the-first-name.html", "catalog/product/view/id/{$product2->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product2);
    }

    /**
     * @throws Exception
     */
    public function testReplaceUrlKey()
    {
        $keep = self::$metadata->saveRewritesHistory;
        self::$metadata->saveRewritesHistory = false;
        self::$metadata->valueSerializer = new SerializeValueSerializer();

        $config = new ImportConfig();

        $importer = self::$factory->createImporter($config);

        // create a product just for the category
        $productX = new SimpleProduct('cat-product-import');
        $productX->setAttributeSetByName("Default");
        $productX->addCategoriesByGlobalName(["Default Category/Names of Things"]);
        $productX->global()->setName("Category dummy");
        $productX->global()->setPrice("0");
        $importer->importSimpleProduct($productX);
        $importer->flush();

        $categoryId = $productX->getCategoryIds()[0];

        // product
        $product1 = new SimpleProduct('7-product-import');
        $product1->setAttributeSetByName("Default");
        $product1->addCategoriesByGlobalName(["Default Category/Names of Things"]);
        $product1->global()->setName("The Old Name");
        $product1->global()->setPrice("2.75");
        $product1->global()->setVisibility(ProductStoreView::VISIBILITY_IN_CATALOG);
        $product1->global()->generateUrlKey();

        $importer->importSimpleProduct($product1);

        $importer->flush();

        // insert

        $expectedRewrites = [
            ["product", "the-old-name.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "names-of-things/the-old-name.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],
        ];

        $expectedIndexes = [
            [(string)$categoryId, $product1->id],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product1);

        // update url_key

        $product1->global()->setName('The New Name');
        $product1->global()->generateUrlKey();

        $importer->importSimpleProduct($product1);

        $importer->flush();

        $expectedRewrites = [

            ["product", "the-new-name.html", "catalog/product/view/id/{$product1->id}", "0", "1", "1", null],
            ["product", "names-of-things/the-new-name.html", "catalog/product/view/id/{$product1->id}/category/{$categoryId}", "0", "1", "1",
                serialize(['category_id' => (string)$categoryId])],
        ];

        $this->doAsserts($expectedRewrites, $expectedIndexes, $product1, $product1);

        self::$metadata->saveRewritesHistory = $keep;
    }
}