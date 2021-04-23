<?php

    /*
		Plugin Name: WooCommerce Dutch Shipping Tax
		Plugin URI: https://www.burovoordeboeg.nl
		Description: Past de Nederlandse BTW Regels toe op verzendkosten voor shops met meerdere BTW percentages.
		Author: Justin Streuper
		Author URI: https://www.burovoordeboeg.nl
		License: GNU License
		Version: 0.0.1
	*/


    // Make sure WooCommerce exists
    add_action('init', function() {
        if( class_exists( 'woocommerce' ) )
        {
            new WooCommerce_Dutch_Shipping_Taxes();
        }
    });

    /**
     * Plug-in for WooCommerce to apply dutch shipping rules
     */
    Class WooCommerce_Dutch_Shipping_Taxes 
    {
        
        public function __construct()
        {
            add_filter('woocommerce_calc_shipping_tax', array($this, 'calculate_shipping_taxes'), 10, 3);
        }

        /**
         * Get the taxes which are active for shipping
         *
         * @return array taxable rates for shipping (array order: slug => ID)
         */
        private function get_shipping_tax_rates()
        {
            // Get tax rates
            $tax_rates = array();
        
            // Retrieve all tax classes.
            $tax_classes = \WC_Tax::get_tax_classes();
        
            // Make sure "Standard rate" (empty class name) is present.
            if ( !in_array( '', $tax_classes ) ) {
                array_unshift( $tax_classes, '' );
            }
        
            // For each tax class, get all rates.
            foreach ( $tax_classes as $tax_class ) {
                $rates = \WC_Tax::get_rates_for_tax_class( $tax_class );
                $tax = reset($rates);
        
                // Check if is active for shipping
                if( $tax->tax_rate_shipping )
                {
                    // Add to tax rates
                    $tax_rates[ (( $tax->tax_rate_class === '' ) ? 'standard' : $tax->tax_rate_class ) ] = $tax->tax_rate_id;
                }
            }

            // Return the tax rates
            return $tax_rates;
        }

        /**
         * Format the cart total array
         *
         * @param array $tax_rates
         * @return array $cart_total
         */
        private function format_cart_totals( array $tax_rates )
        {
            // Data store
            $cart_total = array();

            // Loop tax rates and create cart totals
            foreach( $tax_rates as $tax_rate_class => $tax_rate_id )
            {
                // Add cart total
                $cart_total[ $tax_rate_id ] = array(
                    'total' => 0,
                    'percentage' => 0,
                    'tax_rate_percentage' => 0,
                    'tax_class_name' => $tax_rate_class
                );
            }

            return $cart_total;
        }

        /**
         * Get the tax percentage
         *
         * @param object $product
         * @return float $tax_rate
         */
        private function get_tax_percentage( $product )
        {
            $product_tax = \WC_Tax::get_rates( $product->get_tax_class() );
            $tax = reset($product_tax);

            // Return the rate
            return $tax['rate'];
        }

        /**
         * Calculate taxes based on products in the cart
         *
         * @param array $taxes
         * @param float $price
         * @param array $rates
         * @return array $taxes
         */
        public function calculate_shipping_taxes( $taxes, $price, $rates )
        {
            // Get taxes for shipping
            $tax_rates = $this->get_shipping_tax_rates();

            // When no tax rates are set for shipping, return default taxes
            if( empty($tax_rates) )
            {
                return $taxes;
            }

            /**
             * The cart total which stores the data for calculation
             * @var array $cart_total
             */
            $cart_total = array();
        
            /**
             * The total which is taxable for shipping needed 
             * for calculation of percentage of shipping taxes.
             * @var float $taxable total
             */
            $taxable_total = 0;

            /**
             * Setup calculated taxes array to return when cart is not empty
             * @var array $calculated_taxes (order: tax_id => amount (float) )
             */
            $calculated_taxes = array();
        
            // Get cart items
            $cart = \WC()->cart->get_cart();
            if( !empty($cart) ) 
            {
                // Format cart total based on tax rates
                $cart_total = $this->format_cart_totals( $tax_rates );

                // Loop cart items and add them to the correct cart total
                // for further processing when calculating percentages
                foreach($cart as $cart_item) {
                    
                    // Get product data
                    $product = $cart_item['data'];
        
                    // Get tax class
                    $cart_item_tax_id = ( ($product->get_tax_class() === '') ? 'standard' : $product->get_tax_class() );
                    
                    // Check if 
                    if( isset($tax_rates[$cart_item_tax_id]) )
                    {
                        // Get subtotal
                        $product_price = $product->get_price();
                        $quantity = $cart_item['quantity'];
                        $subtotal = $product_price * $quantity;
                        
                        // Get the tax class ID
                        $tax_class_id = $tax_rates[ $cart_item_tax_id ];
        
                        // Add to the cart total
                        $cart_total[$tax_class_id]['total'] += $subtotal;
                        $taxable_total += $subtotal;

                        // Set tax rate percentage
                        $cart_total[$tax_class_id]['tax_rate_percentage'] = $this->get_tax_percentage( $product );
                    }
                }

                // Calculate the percentages of cart items
                foreach( $cart_total as $tax_id => $data )
                {
                    // Start by calculating the percentage towards the taxable total
                    $shipping_percentage = ( ( $data['total'] / $taxable_total ) * 100 );

                    // Add to cart total array
                    $cart_total[$tax_id]['percentage'] = $shipping_percentage;

                    // Calculate part of shipping costs
                    // Shipping costs * shipping percentage
                    $shipping_costs_part = ( ( $price / 100 ) * $shipping_percentage );

                    // Calculate percentage to amount
                    // Shipping costs * tax rate percentage
                    $shipping_tax_amount = round( ( ( $shipping_costs_part / 100 ) * $data['tax_rate_percentage'] ), 2);

                    // Add shipping percentage
                    $cart_total[$tax_id]['shipping_amount'] = $shipping_tax_amount;

                    // Add to calculcated taxes array
                    $calculated_taxes[ $tax_id ] = $shipping_tax_amount;
                }

                // You could uncomment this for testing purposes
                // echo '<pre>';
                // print_r($cart_total);
                // echo '</pre>';

                // Set the new taxes array   
                return $calculated_taxes;
            }
        
            // Return taxes
            return $taxes;
        }


    }


?>