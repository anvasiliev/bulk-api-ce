<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\GraphQl\Bundle;

use Magento\Bundle\Model\Product\OptionList;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\GraphQl\Query\EnumLookup;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\TestCase\GraphQlAbstract;

class BundleProductViewTest extends GraphQlAbstract
{
    const KEY_PRICE_TYPE_FIXED = 'FIXED';
    /**
     * @magentoApiDataFixture Magento/Bundle/_files/product_1.php
     */
    public function testAllFielsBundleProducts()
    {
        $productSku = 'bundle-product';
        $query
            = <<<QUERY
{
   products(filter: {sku: {eq: "{$productSku}"}})
   {
       items{
           sku
           type_id
           id
           name
           attribute_set_id
           ... on PhysicalProductInterface {
             weight
           }
           category_ids 
           ... on BundleProduct {
           dynamic_sku
            dynamic_price
            dynamic_weight
            price_view
            ship_bundle_items
            items {
              option_id
              title
              required
              type
              position
              sku              
              options {
                id
                qty
                position
                is_default
                price
                price_type
                can_change_quantity
                label
                product {
                  id
                  name
                  sku
                  type_id
                   }
                }
            }
           }
       }
   }   
}
QUERY;

        $response = $this->graphQlQuery($query);

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        $bundleProduct = $productRepository->get($productSku, false, null, true);
        if ((bool)$bundleProduct->getShipmentType()) {
            $this->assertEquals('SEPARATELY', $response['products']['items'][0]['ship_bundle_items']);
        } else {
            $this->assertEquals('TOGETHER', $response['products']['items'][0]['ship_bundle_items']);
        }
        if ((bool)$bundleProduct->getPriceView()) {
            $this->assertEquals('AS_LOW_AS', $response['products']['items'][0]['price_view']);
        } else {
            $this->assertEquals('PRICE_RANGE', $response['products']['items'][0]['price_view']);
        }
        $this->assertBundleBaseFields($bundleProduct, $response['products']['items'][0]);

        $this->assertBundleProductOptions($bundleProduct, $response['products']['items'][0]);
        $this->assertNotEmpty(
            $response['products']['items'][0]['items'],
            "Precondition failed: 'items' must not be empty"
        );
    }

    /**
     * @param ProductInterface $product
     * @param array $actualResponse
     */
    private function assertBundleBaseFields($product, $actualResponse)
    {
        $assertionMap = [
            ['response_field' => 'sku', 'expected_value' => $product->getSku()],
            ['response_field' => 'type_id', 'expected_value' => $product->getTypeId()],
            ['response_field' => 'id', 'expected_value' => $product->getId()],
            ['response_field' => 'name', 'expected_value' => $product->getName()],
            ['response_field' => 'attribute_set_id', 'expected_value' => $product->getAttributeSetId()],
             ['response_field' => 'weight', 'expected_value' => $product->getWeight()],
            ['response_field' => 'dynamic_price', 'expected_value' => !(bool)$product->getPriceType()],
            ['response_field' => 'dynamic_weight', 'expected_value' => !(bool)$product->getWeightType()],
            ['response_field' => 'dynamic_sku', 'expected_value' => !(bool)$product->getSkuType()]
        ];

        $this->assertResponseFields($actualResponse, $assertionMap);
    }

    /**
     * @param ProductInterface $product
     * @param  array $actualResponse
     */
    private function assertBundleProductOptions($product, $actualResponse)
    {
        $this->assertNotEmpty(
            $actualResponse['items'],
            "Precondition failed: 'bundle_product_items' must not be empty"
        );
        /** @var OptionList $optionList */
        $optionList = ObjectManager::getInstance()->get(\Magento\Bundle\Model\Product\OptionList::class);
        $options = $optionList->getItems($product);
        $option = $options[0];
        $bundleProductLinks = $option->getProductLinks();
        $bundleProductLink = $bundleProductLinks[0];
        $childProductSku = $bundleProductLink->getSku();
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        $childProduct = $productRepository->get($childProductSku);
        $this->assertEquals(1, count($options));
        $this->assertResponseFields(
            $actualResponse['items'][0],
            [
                'option_id' => $option->getOptionId(),
                'title' => $option->getTitle(),
                'required' =>(bool)$option->getRequired(),
                'type' => $option->getType(),
                'position' => $option->getPosition(),
                'sku' => $option->getSku()
            ]
        );
        $this->assertResponseFields(
            $actualResponse['items'][0]['options'][0],
            [
                'id' => $bundleProductLink->getId(),
                'qty' => (int)$bundleProductLink->getQty(),
                'position' => $bundleProductLink->getPosition(),
                'is_default' => (bool)$bundleProductLink->getIsDefault(),
                'price' =>  $bundleProductLink->getPrice(),
                 'price_type' => self::KEY_PRICE_TYPE_FIXED,
                'can_change_quantity' => $bundleProductLink->getCanChangeQuantity(),
                'label' => $childProduct->getName()
            ]
        );
        $this->assertResponseFields(
            $actualResponse['items'][0]['options'][0]['product'],
            [
                'id' => $childProduct->getId(),
                'name' => $childProduct->getName(),
                'type_id' => $childProduct->getTypeId(),
                'sku' => $bundleProductLink->getSku()
            ]
        );
    }

    /**
     * @param array $actualResponse
     * @param array $assertionMap ['response_field_name' => 'response_field_value', ...]
     *                         OR [['response_field' => $field, 'expected_value' => $value], ...]
     */
    private function assertResponseFields($actualResponse, $assertionMap)
    {
        foreach ($assertionMap as $key => $assertionData) {
            $expectedValue = isset($assertionData['expected_value'])
                ? $assertionData['expected_value']
                : $assertionData;
            $responseField = isset($assertionData['response_field']) ? $assertionData['response_field'] : $key;
            $this->assertNotNull(
                $expectedValue,
                "Value of '{$responseField}' field must not be NULL"
            );
            $this->assertEquals(
                $expectedValue,
                $actualResponse[$responseField],
                "Value of '{$responseField}' field in response does not match expected value: "
                . var_export($expectedValue, true)
            );
        }
    }

    /**
     * @magentoApiDataFixture Magento/Bundle/_files/product_with_multiple_options_1.php
     */
    public function testAndMaxMinPriceBundleProduct()
    {
        $productSku = 'bundle-product';
        $query
            = <<<QUERY
{
   products(filter: {sku: {eq: "{$productSku}"}})
   {
       items{
           id           
           type_id
           ... on PhysicalProductInterface {
             weight
           }
           category_ids 
           price {
             minimalPrice {
               amount {
                 value
                 currency
               }
               adjustments {
                 amount {
                   value
                   currency
                 }
                 code
                 description
               }
             }
             maximalPrice {
               amount {
                 value
                 currency
               }
               adjustments {
                 amount {
                   value
                   currency
                 }
                 code
                 description
               }
             }
             regularPrice {
               amount {
                 value
                 currency
               }
               adjustments {
                 amount {
                   value
                   currency
                 }
                 code
                 description
               }
             }
           }
           ... on BundleProduct {
           dynamic_sku
            dynamic_price
            dynamic_weight
            price_view
            ship_bundle_items
            items {
                options {
                    label
                    product {
                        id
                        name
                        sku
                    }
                }
            }
           }
       }
   }   
}
QUERY;
        $response = $this->graphQlQuery($query);

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        $bundleProduct = $productRepository->get($productSku, false, null, true);
        /** @var \Magento\Framework\Pricing\PriceInfo\Base $priceInfo */
        $priceInfo = $bundleProduct->getPriceInfo();
        $priceCode = \Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE;
        $minimalPrice = $priceInfo->getPrice($priceCode)->getMinimalPrice()->getValue();
        $maximalPrice = $priceInfo->getPrice($priceCode)->getMaximalPrice()->getValue();
        $this->assertEquals(
            $minimalPrice,
            $response['products']['items'][0]['price']['minimalPrice']['amount']['value']
        );
        $this->assertEquals(
            $maximalPrice,
            $response['products']['items'][0]['price']['maximalPrice']['amount']['value']
        );
    }

    /**
     * @magentoApiDataFixture Magento/Bundle/_files/product_1.php
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testNonExistentFieldQtyExceptionOnBundleProduct()
    {
        $productSku = 'bundle-product';
        $query
            = <<<QUERY
{
   products(filter: {sku: {eq: "{$productSku}"}})
   {
       items{
           id           
           type_id
           qty
           ... on PhysicalProductInterface {
             weight
           }
           category_ids 
           
           ... on BundleProduct {
           dynamic_sku
            dynamic_price
            dynamic_weight
            price_view
            ship_bundle_items             
             bundle_product_links {
               id
               name
               sku
             }
           }
       }
   }   
}
QUERY;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('GraphQL response contains errors: Cannot'. ' ' .
            'query field "qty" on type "ProductInterface".');
        $this->graphQlQuery($query);
    }
}
