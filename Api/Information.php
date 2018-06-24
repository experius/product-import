<?php

namespace BigBridge\ProductImport\Api;

use BigBridge\ProductImport\Model\Persistence\Magento2DbConnection;
use BigBridge\ProductImport\Model\Resource\MetaData;

/**
 * @author Patrick van Bergen
 */
class Information
{
    /** @var MetaData */
    protected $metaData;

    /** @var Magento2DbConnection */
    protected $db;

    public function __construct(
        MetaData $metaData,
        Magento2DbConnection $db
    )
    {
        $this->metaData = $metaData;
        $this->db = $db;
    }

    /**
     * Returns the codes of all store views, except for the global store view.
     */
    public function getNonGlobalStoreViewCodes()
    {
        return $this->metaData->getNonGlobalStoreViewCodes();
    }
}