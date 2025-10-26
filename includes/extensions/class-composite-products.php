<?php
/**
 * Composite Products extension integration.
 *
 * @package WPGraphQL\WooCommerce\Extension
 */

namespace WPGraphQL\WooCommerce\Extension;

use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use WPGraphQL\AppContext;
use WPGraphQL\WooCommerce\Data\Mutation\Cart_Mutation;
use WPGraphQL\WooCommerce\Model\Product as ProductModel;
use WPGraphQL\WooCommerce\Model\Product_Variation as VariationModel;

/**
 * Class Composite_Products
 */
class Composite_Products {
        /**
         * Flag to avoid registering hooks multiple times.
         *
         * @var bool
         */
        private static $initialized = false;

        /**
         * Flag to avoid re-registering types on the same request.
         *
         * @var bool
         */
        private static $types_registered = false;

        /**
         * Registers hooks for the Composite Products extension.
         *
         * @return void
         */
        public static function init() {
                if ( self::$initialized ) {
                        return;
                }

                self::$initialized = true;

                add_filter( 'graphql_woocommerce_product_types', [ __CLASS__, 'register_product_type' ] );
                add_filter( 'graphql_product_types_enum_values', [ __CLASS__, 'register_product_type_enum' ] );
                add_filter( 'graphql_swp_result_possible_types', [ __CLASS__, 'register_search_possible_types' ] );
                add_action( 'graphql_register_types', [ __CLASS__, 'register_graphql_types' ], 11 );
                add_filter( 'graphql_woocommerce_new_cart_item_data', [ __CLASS__, 'inject_cart_item_configuration' ], 10, 4 );
        }

        /**
         * Determines whether the extension should be enabled.
         *
         * @return bool
         */
        private static function is_enabled() {
                return class_exists( '\\WC_Product_Composite' );
        }

        /**
         * Adds the composite product type to the enabled product types map.
         *
         * @param array $types  Current product type map.
         *
         * @return array
         */
        public static function register_product_type( $types ) {
                if ( ! self::is_enabled() ) {
                        return $types;
                }

                $types['composite'] = 'CompositeProduct';

                return $types;
        }

        /**
         * Adds the composite type to the ProductTypesEnum.
         *
         * @param array $values  Enum values.
         *
         * @return array
         */
        public static function register_product_type_enum( $values ) {
                if ( ! self::is_enabled() ) {
                        return $values;
                }

                if ( ! isset( $values['COMPOSITE'] ) ) {
                        $values['COMPOSITE'] = [
                                'value'       => 'composite',
                                'description' => __( 'A composite product', 'wp-graphql-woocommerce' ),
                        ];
                }

                return $values;
        }

        /**
         * Registers the CompositeProduct type as a possible SearchWP result type.
         *
         * @param array $possible_types Current possible type list.
         *
         * @return array
         */
        public static function register_search_possible_types( $possible_types ) {
                if ( ! self::is_enabled() ) {
                        return $possible_types;
                }

                if ( ! in_array( 'CompositeProduct', $possible_types, true ) && in_array( 'Product', $possible_types, true ) ) {
                        $possible_types[] = 'CompositeProduct';
                }

                return $possible_types;
        }

        /**
         * Registers GraphQL object and input types as well as the mutation needed for Composite Products.
         *
         * @return void
         */
        public static function register_graphql_types() {
                if ( ! self::is_enabled() ) {
                        return;
                }

                if ( self::$types_registered ) {
                        return;
                }

                self::$types_registered = true;

                self::register_component_option_type();
                self::register_component_type();
                self::register_composite_product_type();
                self::register_configuration_input_type();
                self::register_add_to_cart_mutation();
        }

        /**
         * Registers the CompositeProduct GraphQL object type.
         *
         * @return void
         */
        private static function register_composite_product_type() {
                \register_graphql_object_type(
                        'CompositeProduct',
                        [
                                'eagerlyLoadType' => true,
                                'model'           => ProductModel::class,
                                'description'     => __( 'A composite product object', 'wp-graphql-woocommerce' ),
                                'interfaces'      => \WPGraphQL\WooCommerce\Type\WPObject\Product_Types::get_product_interfaces(
                                        [
                                                'DownloadableProduct',
                                                'InventoriedProduct',
                                                'ProductWithAttributes',
                                                'ProductWithDimensions',
                                                'ProductWithPricing',
                                        ]
                                ),
                                'fields'          => [
                                        'addToCartFormLocation' => [
                                                'type'        => 'String',
                                                'description' => __( 'Location where the add to cart form should be displayed.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( ProductModel $source ) {
                                                        $product = self::get_composite_product( $source );
                                                        if ( ! $product ) {
                                                                return null;
                                                        }

                                                        $value = self::call_object_method( $product, [ 'get_add_to_cart_form_location', 'get_composite_add_to_cart_form_location' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'layout'                => [
                                                'type'        => 'String',
                                                'description' => __( 'Layout used to present the composite product.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( ProductModel $source ) {
                                                        $product = self::get_composite_product( $source );
                                                        if ( ! $product ) {
                                                                return null;
                                                        }

                                                        $value = self::call_object_method( $product, [ 'get_layout', 'get_composite_layout' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'components'            => [
                                                'type'        => [ 'list_of' => 'CompositeProductComponent' ],
                                                'description' => __( 'Components that compose this product.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( ProductModel $source ) {
                                                        return self::get_component_data( $source );
                                                },
                                        ],
                                ],
                        ]
                );
        }

        /**
         * Registers the CompositeProductComponent type.
         *
         * @return void
         */
        private static function register_component_type() {
                \register_graphql_object_type(
                        'CompositeProductComponent',
                        [
                                'description' => __( 'A component that is part of a composite product.', 'wp-graphql-woocommerce' ),
                                'fields'      => [
                                        'id'              => [
                                                'type'        => 'ID',
                                                'description' => __( 'Global ID for the component.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $id = self::resolve_component_value( $component, [ 'get_id', 'get_component_id' ], [ 'component_id', 'id' ] );
                                                        return null !== $id ? self::to_relay_id( 'wc_composite_component', $id ) : null;
                                                },
                                        ],
                                        'databaseId'      => [
                                                'type'        => 'Int',
                                                'description' => __( 'Database ID for the component.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $id = self::resolve_component_value( $component, [ 'get_id', 'get_component_id' ], [ 'component_id', 'id' ] );
                                                        return null !== $id ? absint( $id ) : null;
                                                },
                                        ],
                                        'name'            => [
                                                'type'        => 'String',
                                                'description' => __( 'Component display name.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'get_title', 'get_name' ], [ 'title', 'name' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'description'     => [
                                                'type'        => 'String',
                                                'description' => __( 'Component description.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'get_description' ], [ 'description' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'optional'        => [
                                                'type'        => 'Boolean',
                                                'description' => __( 'Whether the component can be deselected.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'is_optional' ], [ 'optional' ] );
                                                        if ( is_bool( $value ) ) {
                                                                return $value;
                                                        }

                                                        if ( is_string( $value ) ) {
                                                                return 'yes' === strtolower( $value );
                                                        }

                                                        return null;
                                                },
                                        ],
                                        'quantityMin'     => [
                                                'type'        => 'Int',
                                                'description' => __( 'Minimum quantity allowed for the component.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'get_quantity_min', 'get_min_quantity' ], [ 'quantity_min', 'min_quantity', 'min_qty' ] );
                                                        return null !== $value ? absint( $value ) : null;
                                                },
                                        ],
                                        'quantityMax'     => [
                                                'type'        => 'Int',
                                                'description' => __( 'Maximum quantity allowed for the component.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'get_quantity_max', 'get_max_quantity' ], [ 'quantity_max', 'max_quantity', 'max_qty' ] );
                                                        return null !== $value ? absint( $value ) : null;
                                                },
                                        ],
                                        'defaultOption'   => [
                                                'type'        => 'CompositeProductComponentOption',
                                                'description' => __( 'Default option selected for the component.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $default = self::resolve_component_value( $component, [ 'get_default_option', 'get_default_product' ], [ 'default_id', 'default_option' ] );
                                                        if ( empty( $default ) ) {
                                                                return null;
                                                        }

                                                        $options = self::get_component_options( $component );
                                                        foreach ( $options as $option ) {
                                                                $option_id = self::resolve_option_value( $option, [ 'get_id' ], [ 'id' ] );
                                                                if ( (string) $option_id === (string) $default ) {
                                                                        return $option;
                                                                }
                                                        }

                                                        return null;
                                                },
                                        ],
                                        'options'         => [
                                                'type'        => [ 'list_of' => 'CompositeProductComponentOption' ],
                                                'description' => __( 'Available options for the component.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        return self::get_component_options( $component );
                                                },
                                        ],
                                        'optionsStyle'    => [
                                                'type'        => 'String',
                                                'description' => __( 'Display style for component options.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'get_options_style', 'get_option_style' ], [ 'options_style', 'option_style' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'paginationStyle' => [
                                                'type'        => 'String',
                                                'description' => __( 'Pagination style for the component options list.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $component ) {
                                                        $value = self::resolve_component_value( $component, [ 'get_pagination_style' ], [ 'pagination_style' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                ],
                        ]
                );
        }

        /**
         * Registers the CompositeProductComponentOption type.
         *
         * @return void
         */
        private static function register_component_option_type() {
                \register_graphql_object_type(
                        'CompositeProductComponentOption',
                        [
                                'description' => __( 'An option belonging to a composite component.', 'wp-graphql-woocommerce' ),
                                'fields'      => [
                                        'id'          => [
                                                'type'        => 'ID',
                                                'description' => __( 'Global ID for the component option.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $option ) {
                                                        $id = self::resolve_option_value( $option, [ 'get_id' ], [ 'id' ] );
                                                        if ( empty( $id ) ) {
                                                                $id = self::resolve_option_value( $option, [ 'get_product_id' ], [ 'product_id' ] );
                                                        }

                                                        return null !== $id ? self::to_relay_id( 'wc_composite_component_option', $id ) : null;
                                                },
                                        ],
                                        'productId'   => [
                                                'type'        => 'Int',
                                                'description' => __( 'Database ID for the selected product.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $option ) {
                                                        $value = self::resolve_option_value( $option, [ 'get_product_id' ], [ 'product_id' ] );
                                                        return null !== $value ? absint( $value ) : null;
                                                },
                                        ],
                                        'variationId' => [
                                                'type'        => 'Int',
                                                'description' => __( 'Database ID for the selected variation.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $option ) {
                                                        $value = self::resolve_option_value( $option, [ 'get_variation_id' ], [ 'variation_id' ] );
                                                        return null !== $value ? absint( $value ) : null;
                                                },
                                        ],
                                        'name'        => [
                                                'type'        => 'String',
                                                'description' => __( 'Display name for the option.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $option ) {
                                                        $value = self::resolve_option_value( $option, [ 'get_title', 'get_name' ], [ 'title', 'name' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'description' => [
                                                'type'        => 'String',
                                                'description' => __( 'Description for the option.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $option ) {
                                                        $value = self::resolve_option_value( $option, [ 'get_description' ], [ 'description' ] );
                                                        return is_string( $value ) ? $value : null;
                                                },
                                        ],
                                        'product'     => [
                                                'type'        => 'ProductUnion',
                                                'description' => __( 'Product represented by the option.', 'wp-graphql-woocommerce' ),
                                                'resolve'     => static function ( $option ) {
                                                        $variation_id = self::resolve_option_value( $option, [ 'get_variation_id' ], [ 'variation_id' ] );
                                                        $product_id   = self::resolve_option_value( $option, [ 'get_product_id' ], [ 'product_id' ] );

                                                        try {
                                                                if ( ! empty( $variation_id ) ) {
                                                                        return new VariationModel( absint( $variation_id ) );
                                                                }

                                                                if ( ! empty( $product_id ) ) {
                                                                        return new ProductModel( absint( $product_id ) );
                                                                }
                                                        } catch ( \Exception $e ) {
                                                                return null;
                                                        }

                                                        return null;
                                                },
                                        ],
                                ],
                        ]
                );
        }

        /**
         * Registers the CompositeProductConfigurationInput input type.
         *
         * @return void
         */
        private static function register_configuration_input_type() {
                \register_graphql_input_type(
                        'CompositeProductConfigurationInput',
                        [
                                'description' => __( 'Component configuration used to add a composite product to the cart.', 'wp-graphql-woocommerce' ),
                                'fields'      => [
                                        'componentId' => [
                                                'type'        => [ 'non_null' => 'String' ],
                                                'description' => __( 'Identifier for the component being configured.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'productId'   => [
                                                'type'        => 'Int',
                                                'description' => __( 'Selected product ID for the component.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'variationId' => [
                                                'type'        => 'Int',
                                                'description' => __( 'Selected variation ID for the component.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'quantity'    => [
                                                'type'        => 'Int',
                                                'description' => __( 'Quantity selected for the component.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'hidden'      => [
                                                'type'        => 'Boolean',
                                                'description' => __( 'Whether the component is hidden in the cart.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'variation'   => [
                                                'type'        => [ 'list_of' => 'ProductAttributeInput' ],
                                                'description' => __( 'Variation attributes for the chosen option.', 'wp-graphql-woocommerce' ),
                                        ],
                                ],
                        ]
                );
        }

        /**
         * Registers the addCompositeToCart mutation.
         *
         * @return void
         */
        private static function register_add_to_cart_mutation() {
                \register_graphql_mutation(
                        'addCompositeToCart',
                        [
                                'inputFields'         => [
                                        'productId'    => [
                                                'type'        => [ 'non_null' => 'Int' ],
                                                'description' => __( 'Composite product database ID or global ID.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'quantity'     => [
                                                'type'        => 'Int',
                                                'description' => __( 'Quantity of the composite product.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'extraData'    => [
                                                'type'        => 'String',
                                                'description' => __( 'JSON string representation of extra cart item data.', 'wp-graphql-woocommerce' ),
                                        ],
                                        'configuration' => [
                                                'type'        => [ 'non_null' => [ 'list_of' => [ 'non_null' => 'CompositeProductConfigurationInput' ] ] ],
                                                'description' => __( 'Component configuration for the composite product.', 'wp-graphql-woocommerce' ),
                                        ],
                                ],
                                'outputFields'        => [
                                        'cartItem' => [
                                                'type'    => 'CartItem',
                                                'resolve' => static function ( $payload ) {
                                                        return \WC()->cart->get_cart_item( $payload['key'] );
                                                },
                                        ],
                                        'cart'     => Cart_Mutation::get_cart_field( true ),
                                ],
                                'mutateAndGetPayload' => self::mutate_add_to_cart(),
                        ]
                );
        }

        /**
         * Returns the mutateAndGetPayload callback for addCompositeToCart.
         *
         * @return callable
         */
        private static function mutate_add_to_cart() {
                return static function ( $input, AppContext $context, ResolveInfo $info ) {
                        if ( ! self::is_enabled() ) {
                                throw new UserError( __( 'Composite Products support is not enabled.', 'wp-graphql-woocommerce' ) );
                        }

                        if ( empty( $input['configuration'] ) || ! is_array( $input['configuration'] ) ) {
                                throw new UserError( __( 'No component configuration provided.', 'wp-graphql-woocommerce' ) );
                        }

                        Cart_Mutation::check_session_token();

                        $cart_item_args = Cart_Mutation::prepare_cart_item( $input, $context, $info );

                        $configuration = self::prepare_configuration( absint( $input['productId'] ), $input['configuration'] );

                        if ( empty( $configuration ) ) {
                                throw new UserError( __( 'Unable to parse composite configuration.', 'wp-graphql-woocommerce' ) );
                        }

                        $extra_data = isset( $cart_item_args[4] ) && is_array( $cart_item_args[4] ) ? $cart_item_args[4] : [];
                        $extra_data['configuration'] = $configuration;
                        $cart_item_args[4]           = $extra_data;

                        try {
                                $cart_item_key = \WC()->cart->add_to_cart( ...$cart_item_args );
                        } catch ( \Throwable $e ) {
                                throw new UserError( $e->getMessage() );
                        }

                        if ( false !== $cart_item_key ) {
                                return [ 'key' => $cart_item_key ];
                        }

                        $notices = \WC()->session->get( 'wc_notices' );
                        if ( ! empty( $notices['error'] ) ) {
                                $cart_error_messages = implode( ' ', array_column( $notices['error'], 'notice' ) );
                                \wc_clear_notices();
                                throw new UserError( $cart_error_messages );
                        }

                        throw new UserError( __( 'Failed to add composite product to the cart. Please check input.', 'wp-graphql-woocommerce' ) );
                };
        }

        /**
         * Filters new cart item data to ensure composite configuration is preserved when addToCart is used directly.
         *
         * @param array      $cart_item_args Arguments being passed to WC()->cart->add_to_cart.
         * @param array      $input          Raw GraphQL input.
         * @param AppContext $context        App context.
         * @param ResolveInfo $info          Resolve info.
         *
         * @return array
         */
        public static function inject_cart_item_configuration( $cart_item_args, $input, $context, $info ) {
                if ( ! self::is_enabled() ) {
                        return $cart_item_args;
                }

                if ( empty( $input['configuration'] ) || ! is_array( $input['configuration'] ) ) {
                        return $cart_item_args;
                }

                $product_id = ! empty( $input['productId'] ) ? absint( $input['productId'] ) : 0;
                if ( ! $product_id ) {
                        return $cart_item_args;
                }

                $configuration = self::prepare_configuration( $product_id, $input['configuration'] );
                if ( empty( $configuration ) ) {
                        return $cart_item_args;
                }

                $cart_item_args[4] = isset( $cart_item_args[4] ) && is_array( $cart_item_args[4] ) ? $cart_item_args[4] : [];
                $cart_item_args[4]['configuration'] = $configuration;

                return $cart_item_args;
        }

        /**
         * Normalises the configuration input into the structure expected by WooCommerce Composite Products.
         *
         * @param int   $product_id    Composite product ID.
         * @param array $configuration Component configuration array.
         *
         * @return array
         */
        private static function prepare_configuration( $product_id, array $configuration ) {
                $prepared = [];

                foreach ( $configuration as $component ) {
                        if ( empty( $component['componentId'] ) ) {
                                continue;
                        }

                        $component_id   = $component['componentId'];
                        $product_choice = ! empty( $component['productId'] ) ? absint( $component['productId'] ) : 0;
                        $variation_id   = ! empty( $component['variationId'] ) ? absint( $component['variationId'] ) : 0;
                        $quantity       = isset( $component['quantity'] ) ? max( 0, absint( $component['quantity'] ) ) : 1;
                        $hidden         = isset( $component['hidden'] ) ? (bool) $component['hidden'] : false;

                        $prepared_component = [ 'quantity' => $quantity ];

                        if ( $product_choice ) {
                                $prepared_component['product_id'] = $product_choice;
                        }

                        if ( $variation_id ) {
                                $prepared_component['variation_id'] = $variation_id;
                        }

                        if ( $hidden ) {
                                $prepared_component['hidden'] = true;
                        }

                        if ( ! empty( $component['variation'] ) ) {
                                $attribute_product_id = $product_choice;

                                if ( ! $attribute_product_id && $variation_id ) {
                                        $variation = \wc_get_product( $variation_id );
                                        if ( $variation && is_callable( [ $variation, 'get_parent_id' ] ) ) {
                                                $attribute_product_id = $variation->get_parent_id();
                                        }
                                }

                                if ( $attribute_product_id ) {
                                        $prepared_component['attributes'] = Cart_Mutation::prepare_attributes( $attribute_product_id, $component['variation'] );
                                }
                        }

                        $prepared[ $component_id ] = $prepared_component;
                }

                return $prepared;
        }

        /**
         * Retrieves the composite product instance for a GraphQL product model.
         *
         * @param ProductModel $product Product model.
         *
         * @return null|\WC_Product_Composite
         */
        private static function get_composite_product( ProductModel $product ) {
                $wc_product = \wc_get_product( $product->ID );
                if ( $wc_product && is_a( $wc_product, '\\WC_Product_Composite' ) ) {
                        return $wc_product;
                }

                return null;
        }

        /**
         * Retrieves component data for the provided product model.
         *
         * @param ProductModel $product Product model.
         *
         * @return array
         */
        private static function get_component_data( ProductModel $product ) {
                $wc_product = self::get_composite_product( $product );
                if ( ! $wc_product ) {
                                return [];
                }

                $components = self::call_object_method( $wc_product, [ 'get_components' ] );

                if ( is_array( $components ) && ! empty( $components ) ) {
                        return array_values( $components );
                }

                $raw_meta = self::call_object_method( $wc_product, [ 'get_meta' ], [ '_composite_data', true ] );
                if ( ! is_array( $raw_meta ) ) {
                        return [];
                }

                return array_values( $raw_meta );
        }

        /**
         * Retrieves component options for the provided component.
         *
         * @param mixed $component Component representation.
         *
         * @return array
         */
        private static function get_component_options( $component ) {
                $options = self::resolve_component_value( $component, [ 'get_options', 'get_component_options' ], [ 'assigned_ids', 'options' ] );

                if ( empty( $options ) ) {
                        return [];
                }

                // When raw IDs are returned fallback to creating pseudo option arrays.
                if ( is_array( $options ) && isset( $options[0] ) && ! is_array( $options[0] ) && ! is_object( $options[0] ) ) {
                        $options = array_map(
                                static function ( $product_id ) {
                                        return [
                                                'id'         => $product_id,
                                                'product_id' => $product_id,
                                        ];
                                },
                                $options
                        );
                }

                return array_values( $options );
        }

        /**
         * Attempts to call one of the provided methods on the object.
         *
         * @param object $object   Target object.
         * @param array  $methods  Method names to try.
         * @param array  $args     Arguments to pass when invoking.
         *
         * @return mixed|null
         */
        private static function call_object_method( $object, array $methods, array $args = [] ) {
                foreach ( $methods as $method ) {
                        if ( is_object( $object ) && is_callable( [ $object, $method ] ) ) {
                                return $object->$method( ...$args );
                        }
                }

                return null;
        }

        /**
         * Resolves a component value by checking known methods and keys.
         *
         * @param mixed $component Component instance or array.
         * @param array $methods   Possible methods that may return the desired value.
         * @param array $keys      Possible array keys that may contain the desired value.
         *
         * @return mixed|null
         */
        private static function resolve_component_value( $component, array $methods, array $keys = [] ) {
                $value = self::call_object_method( $component, $methods );
                if ( null !== $value ) {
                        return $value;
                }

                if ( is_array( $component ) ) {
                        foreach ( $keys as $key ) {
                                if ( isset( $component[ $key ] ) ) {
                                        return $component[ $key ];
                                }
                        }
                }

                return null;
        }

        /**
         * Resolves an option value by checking known methods and keys.
         *
         * @param mixed $option  Option instance or array.
         * @param array $methods Possible methods that may return the desired value.
         * @param array $keys    Possible array keys that may contain the desired value.
         *
         * @return mixed|null
         */
        private static function resolve_option_value( $option, array $methods, array $keys = [] ) {
                $value = self::call_object_method( $option, $methods );
                if ( null !== $value ) {
                        return $value;
                }

                if ( is_array( $option ) ) {
                        foreach ( $keys as $key ) {
                                if ( isset( $option[ $key ] ) ) {
                                        return $option[ $key ];
                                }
                        }
                }

                return null;
        }

        /**
         * Generates a Relay global ID for an arbitrary node.
         *
         * @param string $type Node type.
         * @param mixed  $id   Node identifier.
         *
         * @return string
         */
        private static function to_relay_id( $type, $id ) {
                return base64_encode( $type . ':' . $id );
        }
}
