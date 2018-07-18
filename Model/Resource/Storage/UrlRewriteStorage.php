<?php

namespace BigBridge\ProductImport\Model\Resource\Storage;

use BigBridge\ProductImport\Api\Data\ProductStoreView;
use BigBridge\ProductImport\Model\Data\UrlRewrite;
use BigBridge\ProductImport\Model\Persistence\Magento2DbConnection;
use BigBridge\ProductImport\Model\Resource\MetaData;
use BigBridge\ProductImport\Model\Resource\Resolver\CategoryImporter;

/**
 * Updates / fixes url_rewrite and catalog_url_rewrite_product_category tables.
 *
 * I consider the table catalog_url_rewrite_product_category as an extension of the table url_rewrite.
 * It extends the table with the category_id column it lacks.
 * I treat matching records from both tables as a single UrlRewrite entry.
 * If an entry in catalog_url_rewrite_product_category is available, the UrlRewrite will have an extension = true.
 *
 * note: an absent category is represented as 0 throughout this class
 *
 * @author Patrick van Bergen
 */
class UrlRewriteStorage
{
    const NO_REDIRECT = 0;
    const REDIRECT = 301;

    const TARGET_PATH_BASE = 'catalog/product/view/id/';
    const TARGET_PATH_EXT = '/category/';

    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    /** @var CategoryImporter */
    protected $categoryImporter;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData,
        CategoryImporter $categoryImporter)
    {
        $this->db = $db;
        $this->metaData = $metaData;
        $this->categoryImporter = $categoryImporter;
    }

    public function updateRewrites(array $products)
    {
        $productIds = array_column($products, 'id');
        $nonGlobalStoreIds = $this->metaData->getNonGlobalStoreViewIds();

        $this->updateRewritesByProductIds($productIds, $nonGlobalStoreIds);
    }

    /**
     * @param int[] $productIds
     * @param array $storeViewIds
     */
    public function updateRewritesByProductIds(array $productIds, array $storeViewIds)
    {
        $allExistingUrlRewrites = $this->getExistingUrlRewrites($productIds, $storeViewIds);
        $allProductCategoryIds = $this->getExistingProductCategoryIds($productIds);
        $allProductUrlKeys = $this->getExistingProductUrlKeys($productIds);
        $allVisibilities = $this->getExistingVisibilities($productIds);

        $inserts = $deletes = [];

        foreach ($productIds as $productId) {

            $productCategoryIds = array_key_exists($productId, $allProductCategoryIds) ? $allProductCategoryIds[$productId] : [];

            foreach ($storeViewIds as $storeViewId) {

                if (isset($allVisibilities[$productId][$storeViewId])) {
                    $visibility = $allVisibilities[$productId][$storeViewId];
                } elseif (isset($allVisibilities[$productId][0])) {
                    $visibility = $allVisibilities[$productId][0];
                } else {
                    $visibility = null;
                }

                if ($visibility === null || $visibility === ProductStoreView::VISIBILITY_NOT_VISIBLE) {
                    continue;
                }

                $existingUrlRewrites = isset($allExistingUrlRewrites[$storeViewId][$productId]) ? $allExistingUrlRewrites[$storeViewId][$productId] : [];

                list($newDeletes, $newInserts) = $this->updateRewriteGroup($productId, $storeViewId, $existingUrlRewrites, $productCategoryIds, $allProductUrlKeys);

                $inserts = array_merge($inserts, $newInserts);
                $deletes = array_merge($deletes, $newDeletes);
            }
        }

        $this->removeUrlRewrites($deletes);
        $this->replaceUrlRewrites($inserts);
    }

    /**
     * @param int[] $productIds
     * @param array $allStoreIds
     * @return array
     */
    protected function getExistingUrlRewrites(array $productIds, array $allStoreIds)
    {
        // group products by store view
        $collection = [];
        foreach ($productIds as $productId) {
            foreach ($allStoreIds as $storeViewId) {
                $collection[$storeViewId][] = $productId;
            }
        }

        $data = [];
        foreach ($collection as $storeId => $ids) {

            // the ORDER BY is expected in collectNewRewrites()
            $urlRewriteData = $this->db->fetchAllAssoc("
                SELECT R.`url_rewrite_id`, `entity_id`, `request_path`, `target_path`, `redirect_type`, `metadata`, `is_autogenerated`, E.`category_id`
                FROM `{$this->metaData->urlRewriteTable}` R
                LEFT JOIN `{$this->metaData->urlRewriteProductCategoryTable}` E ON E.`url_rewrite_id` = R.`url_rewrite_id`  
                WHERE
                    `store_id` = ? AND 
                    `entity_id` IN (" . $this->db->getMarks($ids) . ") AND
                    `entity_type` = 'product'
                ORDER BY `redirect_type`    
            ", array_merge([
                $storeId
            ], $ids));

            foreach ($urlRewriteData as $datum) {

                $categoryId = (int)$this->metaData->valueSerializer->extract($datum['metadata'], 'category_id');

                $urlRewrite = new UrlRewrite($datum['url_rewrite_id'], $datum['entity_id'], $datum['request_path'],
                    $datum['target_path'], $datum['redirect_type'], $storeId, $categoryId, $datum['is_autogenerated'], (bool)$datum['category_id']);

                $data[$storeId][$datum['entity_id']][$categoryId][$urlRewrite->getKey()] = $urlRewrite;
            }
        }

        return $data;
    }

    protected function getExistingProductCategoryIds(array $productIds)
    {
        if (empty($productIds)) {
            return [];
        }

        $rows = $this->db->fetchAllAssoc("
            SELECT `product_id`, `category_id`
            FROM `{$this->metaData->categoryProductTable}`
            WHERE `product_id` IN (" . $this->db->getMarks($productIds) . ")
        ", $productIds);

        $categoryIds = [];
        foreach ($rows as $row) {
            $categoryIds[(int)$row['product_id']][] = (int)$row['category_id'];
        }

        return $categoryIds;
    }

    protected function getExistingProductUrlKeys(array $productIds)
    {
        if (empty($productIds)) {
            return [];
        }

        $attributeId = $this->metaData->productEavAttributeInfo['url_key']->attributeId;

        $rows = $this->db->fetchAllAssoc("
            SELECT `entity_id`, `store_id`, `value`
            FROM `{$this->metaData->productEntityTable}_varchar`
            WHERE `entity_id` IN (" . $this->db->getMarks($productIds) . ") AND
                attribute_id = ?
        ", array_merge($productIds, [$attributeId]));

        $urlKeys = [];
        foreach ($rows as $row) {
            $urlKeys[(int)$row['entity_id']][(int)$row['store_id']] = $row['value'];
        }

        return $urlKeys;
    }

    protected function getExistingVisibilities(array $productIds)
    {
        if (empty($productIds)) {
            return [];
        }

        $attributeId = $this->metaData->productEavAttributeInfo['visibility']->attributeId;

        $rows = $this->db->fetchAllAssoc("
            SELECT `entity_id`, `store_id`, `value`
            FROM `{$this->metaData->productEntityTable}_int`
            WHERE `entity_id` IN (" . $this->db->getMarks($productIds) . ") AND
                attribute_id = ?
        ", array_merge($productIds, [$attributeId]));

        $visibilities = [];
        foreach ($rows as $row) {
            $visibilities[(int)$row['entity_id']][(int)$row['store_id']] = (int)$row['value'];
        }

        return $visibilities;
    }

    /**
     * Updates all url_rewrites from a single product / store view.
     *
     * @param int $productId
     * @param int $storeViewId
     * @param array $existingUrlRewrites
     * @param array $categoryIds
     * @param array $allProductUrlKeys
     * @return array
     */
    protected function updateRewriteGroup(int $productId, int $storeViewId, array $existingUrlRewrites, array $categoryIds, array $allProductUrlKeys)
    {
        list($deletes, $inserts) = $this->updateRewrite($productId, $storeViewId, 0, $existingUrlRewrites, $allProductUrlKeys);

        $productCategoryIds = $this->collectSubcategories($categoryIds);

        foreach ($productCategoryIds as $categoryId) {
            list($newDeletes, $newInserts) = $this->updateRewrite($productId, $storeViewId, $categoryId, $existingUrlRewrites, $allProductUrlKeys);
            $deletes = array_merge($deletes, $newDeletes);
            $inserts = array_merge($inserts, $newInserts);
        }

        // rewrites for categories that are no longer linked to the product are removed
        $this->removeObsoleteCategoryRewrites($productCategoryIds, $existingUrlRewrites);

        return [$deletes, $inserts];
    }

    /**
     * Updates all url_rewrites from a single product / store view / category_id (may be 0)
     *
     * @param int $productId
     * @param int $storeViewId
     * @param int $categoryId
     * @param array $existingUrlRewrites
     * @param array $allProductUrlKeys
     * @return array
     */
    protected function updateRewrite(int $productId, int $storeViewId, int $categoryId, array $existingUrlRewrites, array $allProductUrlKeys)
    {
        $oldRewrites = array_key_exists($categoryId, $existingUrlRewrites) ? $existingUrlRewrites[$categoryId] : [];
        $newRewrites = $this->collectNewRewrites($productId, $storeViewId, $categoryId, $existingUrlRewrites, $allProductUrlKeys);

        if ($newRewrites === false) {
            return [[], []];
        }

        $inserts = array_diff_key($newRewrites, $oldRewrites);
        $deletes = array_diff_key($oldRewrites, $newRewrites);

        return [$deletes, $inserts];
    }

    /**
     * Creates an array of all url rewrites that a product / store view / category should have.
     * It also contains the existing 301 rewrites.
     *
     * @param int $productId
     * @param int $storeViewId
     * @param int $categoryId
     * @param array $existingUrlRewrites
     * @param array $allProductUrlKeys
     * @return array|bool
     */
    protected function collectNewRewrites(int $productId, int $storeViewId, int $categoryId, array $existingUrlRewrites, array $allProductUrlKeys)
    {
        $targetPath = self::TARGET_PATH_BASE . $productId . ($categoryId === 0 ? '' : self::TARGET_PATH_EXT . $categoryId);
        $requestPath = $this->createRequestPath($productId, $storeViewId, $categoryId, $allProductUrlKeys);
        if ($requestPath === null) {
            return false;
        }

        $currentZeroRewrite = new UrlRewrite(null, $productId, $requestPath, $targetPath,
            self::NO_REDIRECT, $storeViewId, $categoryId, true, $categoryId);

        $newRewrites = [];
        /** @var UrlRewrite $existingZeroRewrite */
        $existingZeroRewrite = null;
        if (isset($existingUrlRewrites[$categoryId])) {
            /** @var UrlRewrite $urlRewrite */
            foreach ($existingUrlRewrites[$categoryId] as $urlRewrite) {
                if ($urlRewrite->getRedirectType() === self::NO_REDIRECT) {
                    // if there is more than one zero rewrites (should not occur), just pick (the last) one
                    $existingZeroRewrite = $urlRewrite;
                } else {
                    // if save history was used before, but not anymore, update existing rewrites that have the old request path as a target
                    if (!$this->metaData->saveRewritesHistory && $existingZeroRewrite && $urlRewrite->getTargetPath() === $existingZeroRewrite->getRequestPath()) {
                        $newRewrite = new UrlRewrite($urlRewrite->getUrlRewriteId(), $urlRewrite->getProductId(), $urlRewrite->getRequestPath(),
                            $requestPath, $urlRewrite->getRedirectType(), $urlRewrite->getStoreId(), $urlRewrite->getCategoryId(),
                            $urlRewrite->getAutogenerated(), false);
                        $newRewrites[$newRewrite->getKey()] = $newRewrite;
                    } else {
                        // copy existing redirect
                        $newRewrites[$urlRewrite->getKey()] = $urlRewrite;
                    }
                }
            }
        }

        // Magento config says keep a copy of old url rewrites
        if ($this->metaData->saveRewritesHistory) {
            // has the non-redirect rewrite changed?
            if ($existingZeroRewrite && !$existingZeroRewrite->equals($currentZeroRewrite)) {

                // create a new redirect
                $newRewrite = new UrlRewrite($existingZeroRewrite->getUrlRewriteId(), $existingZeroRewrite->getProductId(), $existingZeroRewrite->getRequestPath(),
                    $requestPath, self::REDIRECT, $existingZeroRewrite->getStoreId(), $existingZeroRewrite->getCategoryId(), false, false);
                $newRewrites[$newRewrite->getKey()] = $newRewrite;
            }
        }

        // always add the active non-redirect
        $newRewrites[$currentZeroRewrite->getKey()] = $currentZeroRewrite;

        return $newRewrites;
    }

    protected function removeObsoleteCategoryRewrites(array $productCategoryIds, array $existingUrlRewrites)
    {
        // find the categories of all existing rewrites
        // and remove from these the actual categories, and 0 (for no category)
        $obsoleteCategoryIds = array_diff(array_keys($existingUrlRewrites), $productCategoryIds, [0]);

        foreach ($obsoleteCategoryIds as $categoriesId) {
            $this->removeUrlRewrites($existingUrlRewrites[$categoriesId]);
        }
    }

    /**
     * @param int[] $categoryIds
     * @return array
     */
    protected function collectSubcategories(array $categoryIds)
    {
        $subCategories = [];

        foreach ($categoryIds as $categoryId) {
            $categoryInfo = $this->categoryImporter->getCategoryInfo($categoryId);
            $categoriesWithUrlKeysIds = array_slice($categoryInfo->path, 2);
            $subCategories = array_merge($subCategories, $categoriesWithUrlKeysIds);
        }

        return array_unique($subCategories);
    }

    /**
     * @param int $productId
     * @param int $storeViewId
     * @param int $categoryId
     * @param array $allProductUrlKeys
     * @return string|null
     */
    protected function createRequestPath(int $productId, int $storeViewId, int $categoryId, array $allProductUrlKeys)
    {
        $pieces = [];

        if ($categoryId !== 0) {

            $categoryInfo = $this->categoryImporter->getCategoryInfo($categoryId);
            $parentIds = $categoryInfo->path;

            for ($i = 2; $i < count($parentIds); $i++) {

                $parentId = $parentIds[$i];

                if (($parentCategoryInfo = $this->categoryImporter->getCategoryInfo($parentId)) === null) {
                    // parent category in path (no longer) exists
                    return null;
                }

                if (array_key_exists($storeViewId, $parentCategoryInfo->urlKeys)) {
                    $section = $parentCategoryInfo->urlKeys[$storeViewId];
                } elseif (array_key_exists(0, $parentCategoryInfo->urlKeys)) {
                    $section = $parentCategoryInfo->urlKeys[0];
                } else {
                    // parent category has no url_key
                    return null;
                }

                $pieces[] = $section;
            }
        }

        // product url_key for store view (inherits from global)

        if (isset($allProductUrlKeys[$productId][$storeViewId])) {
            $productUrlKey =  $allProductUrlKeys[$productId][$storeViewId];
        } elseif (isset($allProductUrlKeys[$productId][0])) {
            $productUrlKey =  $allProductUrlKeys[$productId][0];
        } else {
            return null;
        }

        $pieces[] = $productUrlKey;

        return implode('/', $pieces) . $this->metaData->productUrlSuffix;
    }

    /**
     * @param UrlRewrite[] $urlRewrites
     */
    protected function replaceUrlRewrites(array $urlRewrites)
    {
        // collect existing url rewrites by request_path/store
        $existingRewrites = [];
        foreach ($urlRewrites as $urlRewrite) {
            $result = $this->db->fetchRow("
                SELECT `request_path`, `store_id`, `entity_type`, `redirect_type` 
                FROM `{$this->metaData->urlRewriteTable}` 
                WHERE `request_path` = ? and `store_id` = ?
            ", [
                $urlRewrite->getRequestPath(),
                $urlRewrite->getStoreId()
            ]);
            $existingRewrites[$result['store_id']][$result['request_path']] = $result;
        }

        /** @var UrlRewrite[] $replacingUrlRewrites */
        $replacingUrlRewrites = [];

        // skip url rewrites that are less important then the existing values
        foreach ($urlRewrites as $i => $urlRewrite) {

            $storeId = $urlRewrite->getStoreId();
            $requestPath = $urlRewrite->getRequestPath();
            $redirectType = $urlRewrite->getRedirectType();

            if (isset($existingRewrites[$storeId][$requestPath])) {

                $existing = $existingRewrites[$storeId][$requestPath];

                if ((int)$existing['redirect_type'] === self::NO_REDIRECT) {
                    // a redirect should not overwrite a non-redirect
                    if ($urlRewrite->getRedirectType() === self::REDIRECT) {
                        continue;
                    }
                }
                if ($existing['entity_type'] !== 'product') {
                    // a product rewrite should not overwrite a cms-page or category rewrite
                    continue;
                }
            }

            $existingRewrites[$storeId][$requestPath] = ['redirect_type' => $redirectType, 'entity_type' => 'product'];

            $replacingUrlRewrites[] = $urlRewrite;
        }

        if (empty($replacingUrlRewrites)) {
            return;
        }

        // perform the REPLACE INTO url_rewrite
        $values = [];
        foreach ($replacingUrlRewrites as $urlRewrite) {
            $values[] = $urlRewrite->getUrlRewriteId();
            $values[] = 'product';
            $values[] = $urlRewrite->getProductId();
            $values[] = $urlRewrite->getRequestPath();
            $values[] = $urlRewrite->getTargetPath();
            $values[] = $urlRewrite->getRedirectType();
            $values[] = $urlRewrite->getStoreId();
            $values[] = $urlRewrite->getAutogenerated();
            $values[] = $this->metaData->valueSerializer->serialize($urlRewrite->getMetadata());
        }

        // replace is needed, for example when two products swap their url_key
        // otherwise a "duplicate key" error occurs
        // and there are many other cases
        // also, the old entry in catalog_url_rewrite_product_category must be replaced
        $this->db->replaceMultiple($this->metaData->urlRewriteTable, [
            'url_rewrite_id', 'entity_type', 'entity_id', 'request_path', 'target_path', 'redirect_type', 'store_id', 'is_autogenerated', 'metadata'
        ], $values, Magento2DbConnection::_1_KB);

        // collect new url_rewrite_ids for the next INSERT
        $values = [];
        foreach ($replacingUrlRewrites as $urlRewrite) {

            if ($urlRewrite->getCategoryId() === 0 || !$urlRewrite->hasExtension()) {
                continue;
            }

            $urlRewriteDatum = $this->db->fetchRow("
                SELECT `url_rewrite_id`, `entity_id`, `metadata`
                FROM {$this->metaData->urlRewriteTable}
                WHERE `request_path` = ? AND `store_id` = ?
            ", [
                $urlRewrite->getRequestPath(),
                $urlRewrite->getStoreId()
            ]);

            if (!$urlRewriteDatum) {
                continue;
            }

            $values[] = $urlRewriteDatum['url_rewrite_id'];
            $values[] = $this->metaData->valueSerializer->extract($urlRewriteDatum['metadata'], 'category_id');
            $values[] = $urlRewriteDatum['entity_id'];
        }

        // perform the INSERT INTO catalog_url_rewrite_product_category
        $this->db->insertMultiple($this->metaData->urlRewriteProductCategoryTable,
            ['url_rewrite_id', 'category_id', 'product_id'],
            $values, Magento2DbConnection::_1_KB);
    }

    /**
     * @param UrlRewrite[] $urlRewrites
     */
    protected function removeUrlRewrites(array $urlRewrites)
    {
        $ids = [];
        foreach ($urlRewrites as $urlRewrite) {
            $ids[] = $urlRewrite->getUrlRewriteId();
        }

        $this->db->deleteMultiple($this->metaData->urlRewriteTable, 'url_rewrite_id', $ids);
    }
}
