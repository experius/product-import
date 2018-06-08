<?php

namespace BigBridge\ProductImport\Model\Reader;

use BigBridge\ProductImport\Api\Data\DownloadableProduct;
use BigBridge\ProductImport\Api\Data\ProductStockItem;
use BigBridge\ProductImport\Api\Data\VirtualProduct;
use BigBridge\ProductImport\Api\Data\Product;
use BigBridge\ProductImport\Api\Data\ProductStoreView;
use BigBridge\ProductImport\Api\Data\SimpleProduct;
use BigBridge\ProductImport\Api\Importer;
use Exception;

/**
 * @author Patrick van Bergen
 */
class ElementHandler
{
    /** @var Importer */
    protected $importer;

    /** @var Product */
    protected $product = null;

    /** @var ProductStoreView */
    protected $storeView = null;

    /** @var ProductStockItem */
    protected $defaultStockItem = null;

    /** @var string  */
    protected $characterData = "";

    /** @var string[] */
    protected $items = [];

    /** @var string[] */
    protected $elementPath = [self::ROOT];

    /** @var array[] */
    protected $attributePath = [[]];
    
    /**
     * Attributes
     */
    const TYPE = 'type';
    const SKU = 'sku';
    const CODE = "code";
    const REMOVE = "remove";
    const ATTRIBUTE_SET_NAME = "attribute_set_name";

    /**
     * Tags
     */
    const ROOT = "root";
    const IMPORT = "import";
    const PRODUCT = "product";
    const GLOBAL = "global";
    const STORE_VIEW = "store_view";
    const CATEGORY_GLOBAL_NAMES = "category_global_names";
    const CATEGORY_IDS = "category_ids";
    const WEBSITE_CODES = "website_codes";
    const WEBSITE_IDS = "website_ids";
    const TAX_CLASS_NAME = "tax_class_name";
    const META_KEYWORDS = "meta_keywords";
    const GENERATE_URL_KEY = "generate_url_key";
    const CUSTOM = "custom";
    const SELECT = "select";
    const MULTI_SELECT = "multi_select";
    const STOCK = "stock";

    public function __construct(Importer $importer)
    {
        $this->importer = $importer;
    }

    protected $multiAttributes = [
        self::CATEGORY_GLOBAL_NAMES,
        self::CATEGORY_IDS,
        self::WEBSITE_CODES,
        self::WEBSITE_IDS,
        self::MULTI_SELECT
    ];

    protected $globalMultiAttributes = [
        self::CATEGORY_GLOBAL_NAMES,
        self::CATEGORY_IDS,
        self::WEBSITE_CODES,
        self::WEBSITE_IDS,
    ];

    /**
     * Initialize data structures.
     *
     * @param $parser
     * @param $element
     * @param $attributes
     * @throws Exception
     */
    public function elementStart($parser, $element, $attributes)
    {
        $this->characterData = "";
        $scope = $this->elementPath[count($this->elementPath) - 1];

        $this->elementPath[] = $element;
        $this->attributePath[] = $attributes;

        if ($scope === self::IMPORT) {
            if ($element === self::PRODUCT) {
                $this->product = $this->createProduct($parser, $attributes);
            }
        } elseif ($scope === self::PRODUCT) {
            if ($element === self::GLOBAL) {
                $this->storeView = $this->product->global();
            } elseif ($element === self::STORE_VIEW) {
                $this->storeView = $this->createStoreView($parser, $attributes, $this->product);
            } elseif ($element === self::STOCK) {
                $this->defaultStockItem = $this->product->defaultStockItem();
            }
        }

        if (in_array($element, $this->multiAttributes)) {
            $this->items = [];
        }
    }

    /**
     * @param $parser
     * @param $data
     */
    public function characterData($parser, $data)
    {
        $this->characterData .= $data;
    }

    /**
     * @param $parser
     * @param $element
     * @throws \Exception
     */
    public function elementEnd($parser, $element)
    {
        $scope = $this->elementPath[count($this->elementPath) - 2];
        $value = $this->characterData;
        
        if ($scope === self::IMPORT) {
            if ($element === self::PRODUCT) {
                $this->importer->importAnyProduct($this->product);
            }
        } elseif ($scope === self::PRODUCT) {
            if ($element === self::CATEGORY_GLOBAL_NAMES) {
                $this->product->addCategoriesByGlobalName($this->items);
            } elseif ($element === self::CATEGORY_IDS) {
                $this->product->addCategoryIds($this->items);
            } elseif ($element === self::WEBSITE_CODES) {
                $this->product->setWebsitesByCode($this->items);
            } elseif ($element === self::WEBSITE_IDS) {
                $this->product->setWebsitesIds($this->items);
            }
        } elseif ($scope === self::GLOBAL || $scope === self::STORE_VIEW) {

            $attributes = $this->attributePath[count($this->attributePath) - 1];

            if (array_key_exists(self::REMOVE, $attributes) && $attributes[self::REMOVE] === "1") {
                $value = null;
            }

            if ($element === ProductStoreView::ATTR_NAME) {
                $this->storeView->setName($value);
            } elseif ($element === ProductStoreView::ATTR_PRICE) {
                $this->storeView->setPrice($value);
            } elseif ($element === ProductStoreView::ATTR_STATUS) {
                $this->storeView->setStatus($value);
            } elseif ($element === ProductStoreView::ATTR_VISIBILITY) {
                $this->storeView->setVisibility($value);
            } elseif ($element === self::TAX_CLASS_NAME) {
                $this->storeView->setTaxClassName($value);
            } elseif ($element === ProductStoreView::ATTR_DESCRIPTION) {
                $this->storeView->setDescription($value);
            } elseif ($element === ProductStoreView::ATTR_SHORT_DESCRIPTION) {
                $this->storeView->setShortDescription($value);
            } elseif ($element === ProductStoreView::ATTR_URL_KEY) {
                $this->storeView->setUrlKey($value);
            } elseif ($element === self::GENERATE_URL_KEY) {
                if ($value === "1") {
                    $this->storeView->generateUrlKey();
                }
            } elseif ($element === ProductStoreView::ATTR_GIFT_MESSAGE_AVAILABLE) {
                $this->storeView->setGiftMessageAvailable($value);
            } elseif ($element === ProductStoreView::ATTR_META_TITLE) {
                $this->storeView->setMetaTitle($value);
            } elseif ($element === ProductStoreView::ATTR_META_DESCRIPTION) {
                $this->storeView->setMetaDescription($value);
            } elseif ($element === self::META_KEYWORDS) {
                $this->storeView->setMetaKeywords($value);
            } elseif ($element === ProductStoreView::ATTR_COST) {
                $this->storeView->setCost($value);
            } elseif ($element === ProductStoreView::ATTR_MSRP) {
                $this->storeView->setMsrp($value);
            } elseif ($element === ProductStoreView::ATTR_MSRP_DISPLAY_ACTUAL_PRICE_TYPE) {
                $this->storeView->setMsrpDisplayActualPriceType($value);
            } elseif ($element === ProductStoreView::ATTR_WEIGHT) {
                $this->storeView->setWeight($value);
            } elseif ($element === ProductStoreView::ATTR_SPECIAL_PRICE) {
                $this->storeView->setSpecialPrice($value);
            } elseif ($element === ProductStoreView::ATTR_SPECIAL_FROM_DATE) {
                $this->storeView->setSpecialFromDate($value);
            } elseif ($element === ProductStoreView::ATTR_SPECIAL_TO_DATE) {
                $this->storeView->setSpecialToDate($value);
            } elseif ($element === ProductStoreView::ATTR_NEWS_FROM_DATE) {
                $this->storeView->setNewsFromDate($value);
            } elseif ($element === ProductStoreView::ATTR_NEWS_TO_DATE) {
                $this->storeView->setNewsToDate($value);
            } elseif ($element === ProductStoreView::ATTR_MANUFACTURER) {
                $this->storeView->setManufacturer($value);
            } elseif ($element === ProductStoreView::ATTR_COUNTRY_OF_MANUFACTURE) {
                $this->storeView->setCountryOfManufacture($value);
            } elseif ($element === ProductStoreView::ATTR_COLOR) {
                $this->storeView->setColor($value);
            } elseif ($element === self::SELECT) {
                $this->setSelectAttribute($parser, $attributes, $value);
            } elseif ($element === self::MULTI_SELECT) {
                $this->setMultiSelectAttribute($parser, $attributes, $this->items);
            } elseif ($element === self::CUSTOM) {
                $this->setCustomAttribute($parser, $attributes, $value);
            }
        } elseif (in_array($scope, $this->multiAttributes)) {
            if ($element === "item") {
                $this->items[] = $value;
            }
        } elseif ($scope === self::STOCK) {
            if ($element === ProductStockItem::QTY) {
                $this->defaultStockItem->setQty($value);
            } elseif ($element === ProductStockItem::IS_IN_STOCK) {
                $this->defaultStockItem->setIsInStock($value);
            } elseif ($element === ProductStockItem::MIN_QTY) {
                $this->defaultStockItem->setMinimumQuantity($value);
            } elseif ($element === ProductStockItem::NOTIFY_STOCK_QTY) {
                $this->defaultStockItem->setNotifyStockQuantity($value);
            } elseif ($element === ProductStockItem::MIN_SALE_QTY) {
                $this->defaultStockItem->setMinimumSaleQuantity($value);
            } elseif ($element === ProductStockItem::MAX_SALE_QTY) {
                $this->defaultStockItem->setMaximumSaleQuantity($value);
            } elseif ($element === ProductStockItem::QTY_INCREMENTS) {
                $this->defaultStockItem->setQuantityIncrements($value);
            } elseif ($element === ProductStockItem::LOW_STOCK_DATE) {
                $this->defaultStockItem->setLowStockDate($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_MIN_QTY) {
                $this->defaultStockItem->setUseConfigMinimumQuantity($value);
            } elseif ($element === ProductStockItem::IS_QTY_DECIMAL) {
                $this->defaultStockItem->setIsQuantityDecimal($value);
            } elseif ($element === ProductStockItem::BACKORDERS) {
                $this->defaultStockItem->setBackorders($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_BACKORDERS) {
                $this->defaultStockItem->setUseConfigBackorders($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_MIN_SALE_QTY) {
                $this->defaultStockItem->setUseConfigMinimumSaleQuantity($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_MAX_SALE_QTY) {
                $this->defaultStockItem->setUseConfigMaximumSaleQuantity($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_NOTIFY_STOCK_QTY) {
                $this->defaultStockItem->setUseConfigNotifyStockQuantity($value);
            } elseif ($element === ProductStockItem::MANAGE_STOCK) {
                $this->defaultStockItem->setManageStock($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_MANAGE_STOCK) {
                $this->defaultStockItem->setUseConfigManageStock($value);
            } elseif ($element === ProductStockItem::STOCK_STATUS_CHANGED_AUTO) {
                $this->defaultStockItem->setStockStatusChangedAuto($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_QTY_INCREMENTS) {
                $this->defaultStockItem->setUseConfigQuantityIncrements($value);
            } elseif ($element === ProductStockItem::USE_CONFIG_ENABLE_QTY_INC) {
                $this->defaultStockItem->setUseConfigEnableQuantityIncrements($value);
            } elseif ($element === ProductStockItem::ENABLE_QTY_INCREMENTS) {
                $this->defaultStockItem->setEnableQuantityIncrements($value);
            } elseif ($element === ProductStockItem::IS_DECIMAL_DIVIDED) {
                $this->defaultStockItem->setIsDecimalDivided($value);
            }
        }

        array_pop($this->elementPath);
        array_pop($this->attributePath);

        $this->characterData = "";
    }

    /**
     * @param $parser
     * @param array $attributes
     * @return Product
     * @throws Exception
     */
    protected function createProduct($parser, array $attributes)
    {
        if (!isset($attributes[self::TYPE])) {
            throw new Exception("Missing type in line " . xml_get_current_line_number($parser));
        } elseif (!isset($attributes[self::SKU])) {
            throw new Exception("Missing sku in line " . xml_get_current_line_number($parser));
        } else {

            $type = $attributes[self::TYPE];
            $sku = $attributes[self::SKU];

            if ($type === SimpleProduct::TYPE_SIMPLE) {
                $product = new SimpleProduct($sku);
            } elseif ($type === VirtualProduct::TYPE_VIRTUAL) {
                $product = new VirtualProduct($sku);
            } elseif ($type === DownloadableProduct::TYPE_DOWNLOADABLE) {
                $product = new VirtualProduct($sku);
            } else {
                throw new Exception("Unknown type: " . $attributes[self::TYPE] . " in line " . xml_get_current_line_number($parser));
            }

            $product->lineNumber = xml_get_current_line_number($parser);

            if (isset($attributes[self::ATTRIBUTE_SET_NAME])) {
                $attributeSetName = $attributes[self::ATTRIBUTE_SET_NAME];
                $product->setAttributeSetByName($attributeSetName);
            }

            return $product;
        }
    }

    /**
     * @param $parser
     * @param $attributes
     * @param Product $product
     * @return ProductStoreView
     * @throws Exception
     */
    protected function createStoreView($parser, $attributes, Product $product)
    {
        if (!isset($attributes[self::CODE])) {
            throw new Exception("Missing code in line " . xml_get_current_line_number($parser));
        } else {
            $storeView = $product->storeView($attributes[self::CODE]);
            return $storeView;
        }
    }

    /**
     * @param $parser
     * @param $attributes
     * @param $value
     * @throws Exception
     */
    protected function setCustomAttribute($parser, $attributes, $value)
    {
        if (!array_key_exists(self::CODE, $attributes)) {
            throw new Exception("Missing code in line " . xml_get_current_line_number($parser));
        } else {
            $attributeCode = $attributes[self::CODE];
            $this->storeView->setCustomAttribute($attributeCode, $value);
        }
    }

    /**
     * @param $parser
     * @param $attributes
     * @param $value
     * @throws Exception
     */
    protected function setSelectAttribute($parser, $attributes, $value)
    {
        if (!array_key_exists(self::CODE, $attributes)) {
            throw new Exception("Missing code in line " . xml_get_current_line_number($parser));
        } else {
            $attributeCode = $attributes[self::CODE];
            $this->storeView->setSelectAttribute($attributeCode, $value);
        }
    }

    /**
     * @param $parser
     * @param $attributes
     * @param array $values
     * @throws Exception
     */
    protected function setMultiSelectAttribute($parser, $attributes, array $values)
    {
        if (!array_key_exists(self::CODE, $attributes)) {
            throw new Exception("Missing code in line " . xml_get_current_line_number($parser));
        } else {
            $attributeCode = $attributes[self::CODE];
            $this->storeView->setMultipleSelectAttribute($attributeCode, $values);
        }
    }
}