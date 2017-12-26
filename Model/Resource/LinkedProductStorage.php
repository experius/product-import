<?php

namespace BigBridge\ProductImport\Model\Resource;

use BigBridge\ProductImport\Api\Product;
use BigBridge\ProductImport\Model\Data\LinkInfo;
use BigBridge\ProductImport\Model\Db\Magento2DbConnection;

/**
 * @author Patrick van Bergen
 */
class LinkedProductStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var MetaData */
    protected $metaData;

    public function __construct(Magento2DbConnection $db, MetaData $metaData)
    {
        $this->db = $db;
        $this->metaData = $metaData;
    }

    /**
     * @param Product[] $products
     */
    public function insertLinkedProducts(array $products)
    {
        foreach ([LinkInfo::RELATED, LinkInfo::UP_SELL, LinkInfo::CROSS_SELL] as $linkType) {
            $this->insertProductLinks($linkType, $products);
        }
    }

    /**
     * @param Product[] $products
     */
    public function updateLinkedProducts(array $products)
    {
        foreach ([LinkInfo::RELATED, LinkInfo::UP_SELL, LinkInfo::CROSS_SELL] as $linkType) {

            $changedProducts = $this->findProductsWithChangedLinks($linkType, $products);

            $this->removeProductLinks($linkType, $changedProducts);
            $this->insertProductLinks($linkType, $changedProducts);

        }
    }

    /**
     * @param string $linkType
     * @param Product[] $products
     * @return array
     */
    protected function findProductsWithChangedLinks(string $linkType, array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');

        $linkInfo = $this->metaData->linkInfo[$linkType];

# note! DESC

        // Note: the position of the linked products is taken in account as well
        $existingLinks = $this->db->fetchMap("
            SELECT `product_id`, GROUP_CONCAT(L.`linked_product_id` ORDER BY P.`value` DESC SEPARATOR ' ')
            FROM `{$this->metaData->linkTable}` L
            INNER JOIN `{$this->metaData->linkAttributeIntTable}` P ON P.`link_id` = L.`link_id` AND P.product_link_attribute_id = {$linkInfo->positionAttributeId}
            WHERE 
                L.`link_type_id` = {$linkInfo->typeId} AND
                L.`product_id` IN (" . implode(', ', $productIds) . ")                 
            GROUP by L.`product_id`
        ");

        $changed = [];

        foreach ($products as $product) {
            $linkedIds = implode(' ', $product->getLinkedProductIds()[$linkType]);

            if (!array_key_exists($product->id, $existingLinks) || $existingLinks[$product->id] !== $linkedIds) {
                $changed[] = $product;
            }
        }

        return $changed;
    }

    /**
     * @param string $linkType
     * @param Product[] $products
     */
    protected function removeProductLinks(string $linkType, array $products)
    {
        if (empty($products)) {
            return;
        }

        $productIds = array_column($products, 'id');

        $linkInfo = $this->metaData->linkInfo[$linkType];

        $this->db->execute("
            DELETE FROM `{$this->metaData->linkTable}`
            WHERE 
                `product_id` IN (" . implode(', ', $productIds) . ") AND
                `link_type_id` = {$linkInfo->typeId}
        ");
    }

    /**
     * @param string $linkType
     * @param Product[] $products
     */
    protected function insertProductLinks(string $linkType, array $products)
    {
        $linkInfo = $this->metaData->linkInfo[$linkType];

        foreach ($products as $product) {
            $linkedIds = $product->getLinkedProductIds()[$linkType];
            $position = 1;
            foreach ($linkedIds as $i => $linkedId) {

                $this->db->execute("
                    INSERT INTO `{$this->metaData->linkTable}`
                    SET 
                        `product_id` = {$product->id},
                        `linked_product_id` = {$linkedId},
                        `link_type_id` = {$linkInfo->typeId}
                ");

                $linkId = $this->db->getLastInsertId();

                $this->db->execute("
                    INSERT INTO `{$this->metaData->linkAttributeIntTable}`
                    SET
                        `product_link_attribute_id` = {$linkInfo->positionAttributeId},
                        `link_id` = {$linkId},
                        `value` = {$position}
                ");

                $position++;
            }
        }
    }
}