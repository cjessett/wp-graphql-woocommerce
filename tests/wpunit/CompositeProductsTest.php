<?php
/**
 * Tests covering the Composite Products integration.
 */

use Tests\WPGraphQL\WooCommerce\TestCase\WooGraphQLTestCase;
use WPGraphQL\WooCommerce\Extension\Composite_Products;

if ( ! class_exists( 'WC_Product_Composite' ) ) {
        /**
         * Minimal stub for the Composite Products product class.
         */
        class WC_Product_Composite extends WC_Product_Simple {
                /**
                 * Components assigned to mock products.
                 *
                 * @var array<int,array>
                 */
                protected static $component_map = [];

                /**
                 * Composite products use a custom product type identifier.
                 *
                 * @var string
                 */
                protected $product_type = 'composite';

                /**
                 * Stores mock component data for a given product.
                 *
                 * @param int   $product_id Product ID.
                 * @param array $components Component objects.
                 *
                 * @return void
                 */
                public static function set_mock_components( $product_id, array $components ) {
                        self::$component_map[ $product_id ] = $components;
                }

                /**
                 * Returns the configured components.
                 *
                 * @return array
                 */
                public function get_components() {
                        return self::$component_map[ $this->get_id() ] ?? [];
                }

                /**
                 * Overrides add to cart form location to simplify assertions.
                 *
                 * @return string
                 */
                public function get_add_to_cart_form_location() {
                        return 'summary';
                }

                /**
                 * Provides a predictable layout for testing.
                 *
                 * @return string
                 */
                public function get_layout() {
                        return 'stacked';
                }
        }
}

if ( ! class_exists( 'WooGraphQL_Test_Component' ) ) {
        /**
         * Simple component stub implementing the Composite Products API surface we rely on.
         */
        class WooGraphQL_Test_Component {
                /**
                 * Component identifier.
                 *
                 * @var string
                 */
                private $id;

                /**
                 * Component metadata.
                 *
                 * @var array
                 */
                private $data;

                /**
                 * Constructor.
                 *
                 * @param string $id   Component ID.
                 * @param array  $data Component settings.
                 */
                public function __construct( $id, array $data ) {
                        $this->id   = $id;
                        $defaults   = [
                                'title'            => '',
                                'description'      => '',
                                'optional'         => false,
                                'quantity_min'     => 1,
                                'quantity_max'     => 1,
                                'default'          => null,
                                'options'          => [],
                                'options_style'    => 'dropdown',
                                'pagination_style' => 'list',
                        ];
                        $this->data = array_merge( $defaults, $data );
                }

                public function get_id() {
                        return $this->id;
                }

                public function get_title() {
                        return $this->data['title'];
                }

                public function get_description() {
                        return $this->data['description'];
                }

                public function is_optional() {
                        return (bool) $this->data['optional'];
                }

                public function get_quantity_min() {
                        return $this->data['quantity_min'];
                }

                public function get_quantity_max() {
                        return $this->data['quantity_max'];
                }

                public function get_default_option() {
                        return $this->data['default'];
                }

                public function get_options() {
                        return $this->data['options'];
                }

                public function get_options_style() {
                        return $this->data['options_style'];
                }

                public function get_pagination_style() {
                        return $this->data['pagination_style'];
                }
        }
}

if ( ! class_exists( 'WooGraphQL_Test_Component_Option' ) ) {
        /**
         * Option stub for composite components.
         */
        class WooGraphQL_Test_Component_Option {
                private $id;
                private $product_id;
                private $variation_id;
                private $title;
                private $description;

                public function __construct( $id, $product_id, $variation_id = 0, $title = '', $description = '' ) {
                        $this->id           = $id;
                        $this->product_id   = $product_id;
                        $this->variation_id = $variation_id;
                        $this->title        = $title;
                        $this->description  = $description;
                }

                public function get_id() {
                        return $this->id;
                }

                public function get_product_id() {
                        return $this->product_id;
                }

                public function get_variation_id() {
                        return $this->variation_id;
                }

                public function get_title() {
                        return $this->title;
                }

                public function get_description() {
                        return $this->description;
                }
        }
}

/**
 * @group composite-products
 */
class CompositeProductsTest extends WooGraphQLTestCase {
        /**
         * Callback reference for the WooCommerce product class filter.
         *
         * @var callable
         */
        private $product_class_filter;

        public function setUp(): void {
                parent::setUp();

                // Ensure the composite product class is used when the type is requested.
                $this->product_class_filter = static function ( $classname, $product_type ) {
                        if ( 'composite' === $product_type ) {
                                return 'WC_Product_Composite';
                        }

                        return $classname;
                };

                add_filter( 'woocommerce_product_class', $this->product_class_filter, 10, 2 );

                // Re-run extension setup to ensure hooks are in place for the test suite.
                Composite_Products::init();
        }

        public function tearDown(): void {
                if ( $this->product_class_filter ) {
                        remove_filter( 'woocommerce_product_class', $this->product_class_filter, 10 );
                }

                parent::tearDown();
        }

        public function test_composite_product_query_exposes_components() {
                $this->loginAsShopManager();

                $simple_product_id = $this->factory->product->createSimple();

                $composite_id = $this->factory->product->create(
                        [
                                'name'          => 'Composite Product',
                                'slug'          => 'composite-product',
                                'product_class' => 'WC_Product_Composite',
                        ]
                );

                $option = new WooGraphQL_Test_Component_Option( 'cpu-1', $simple_product_id, 0, 'Standard CPU', 'Quad core CPU' );

                WC_Product_Composite::set_mock_components(
                        $composite_id,
                        [
                                new WooGraphQL_Test_Component(
                                        'cpu',
                                        [
                                                'title'            => 'CPU',
                                                'description'      => 'Choose your processor',
                                                'optional'         => false,
                                                'quantity_min'     => 1,
                                                'quantity_max'     => 1,
                                                'default'          => 'cpu-1',
                                                'options_style'    => 'dropdown',
                                                'pagination_style' => 'dots',
                                                'options'          => [ $option ],
                                        ]
                                ),
                        ]
                );

                $query = <<<'GQL'
query ($id: ID!) {
  product(id: $id, idType: DATABASE_ID) {
    __typename
    ... on CompositeProduct {
      addToCartFormLocation
      layout
      components {
        databaseId
        name
        optional
        quantityMin
        quantityMax
        options {
          productId
          product {
            __typename
            ... on SimpleProduct {
              databaseId
            }
          }
        }
      }
    }
  }
}
GQL;

                $variables = [ 'id' => $composite_id ];
                $response  = $this->graphql( compact( 'query', 'variables' ) );

                $expected = [
                        $this->expectedField( 'product.__typename', 'CompositeProduct' ),
                        $this->expectedField( 'product.addToCartFormLocation', 'summary' ),
                        $this->expectedField( 'product.layout', 'stacked' ),
                        $this->expectedField( 'product.components.0.name', 'CPU' ),
                        $this->expectedField( 'product.components.0.optional', false ),
                        $this->expectedField( 'product.components.0.options.0.productId', $simple_product_id ),
                        $this->expectedField( 'product.components.0.options.0.product.__typename', 'SimpleProduct' ),
                        $this->expectedField( 'product.components.0.options.0.product.databaseId', $simple_product_id ),
                ];

                $this->assertQuerySuccessful( $response, $expected );
        }

        public function test_add_composite_to_cart_mutation_builds_configuration() {
                $this->loginAsCustomer();

                $simple_product_id = $this->factory->product->createSimple();
                $composite_id      = $this->factory->product->create(
                        [
                                'name'          => 'Composite Product',
                                'slug'          => 'composite-product',
                                'product_class' => 'WC_Product_Composite',
                        ]
                );

                $option = new WooGraphQL_Test_Component_Option( 'cpu-1', $simple_product_id, 0, 'Standard CPU' );
                WC_Product_Composite::set_mock_components(
                        $composite_id,
                        [
                                new WooGraphQL_Test_Component(
                                        'cpu',
                                        [
                                                'title'   => 'CPU',
                                                'default' => 'cpu-1',
                                                'options' => [ $option ],
                                        ]
                                ),
                        ]
                );

                $mutation = <<<'GQL'
mutation ($productId: Int!, $configuration: [CompositeProductConfigurationInput!]!) {
  addCompositeToCart(
    input: {
      productId: $productId,
      quantity: 1,
      configuration: $configuration
    }
  ) {
    cartItem {
      quantity
    }
    cart {
      contentsCount
    }
  }
}
GQL;

                $variables = [
                        'productId'    => $composite_id,
                        'configuration' => [
                                [
                                        'componentId' => 'cpu',
                                        'productId'   => $simple_product_id,
                                        'quantity'    => 1,
                                ],
                        ],
                ];

                $response = $this->graphql( compact( 'mutation', 'variables' ) );

                $this->assertQuerySuccessful(
                        $response,
                        [
                                $this->expectedField( 'addCompositeToCart.cartItem.quantity', 1 ),
                                $this->expectedField( 'addCompositeToCart.cart.contentsCount', 1 ),
                        ]
                );

                $cart      = \WC()->cart->get_cart();
                $cart_item = reset( $cart );

                $this->assertArrayHasKey( 'configuration', $cart_item );
                $this->assertSame( $simple_product_id, $cart_item['configuration']['cpu']['product_id'] );
        }
}
