<?php
/**
 * Klarna checkout class
 *
 * @link http://www.woothemes.com/products/klarna/
 * @since 1.0.0
 *
 * @package WC_Gateway_Klarna
 */


class WC_Gateway_Klarna_Checkout extends WC_Gateway_Klarna {
			
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() { 

		global $woocommerce;

		parent::__construct();

		$this->id           = 'klarna_checkout';
		$this->method_title = __( 'Klarna Checkout', 'klarna' );
		$this->has_fields   = false;

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		include( KLARNA_DIR . 'includes/variables-checkout.php' );

		// Helper class
		include_once( KLARNA_DIR . 'classes/class-klarna-helper.php' );
		$this->klarna_helper = new WC_Gateway_Klarna_Helper( $this );
		
		// Define Klarna object
		require_once( KLARNA_LIB . 'Klarna.php' );

		// Test mode or Live mode		
		if ( $this->testmode == 'yes' ) {
			// Disable SSL if in testmode
			$this->klarna_ssl = 'false';
			$this->klarna_mode = Klarna::BETA;
		} else {
			// Set SSL if used in webshop
			if ( is_ssl() ) {
				$this->klarna_ssl = 'true';
			} else {
				$this->klarna_ssl = 'false';
			}
			$this->klarna_mode = Klarna::LIVE;
		}

		// Actions
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		// Push listener
		add_action( 'woocommerce_api_wc_gateway_klarna_checkout', array( $this, 'check_checkout_listener' ) );

		// We execute the woocommerce_thankyou hook when the KCO Thank You page is rendered,
		// because other plugins use this, but we don't want to display the actual WC Order
		// details table in KCO Thank You page. This action is removed here, but only when
		// in Klarna Thank You page.
		if ( is_page() ) {
			global $post;
			$klarna_checkout_page_id = url_to_postid( $this->klarna_checkout_thanks_url );
			if ( $post->ID == $klarna_checkout_page_id ) {
				remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
			}
		}
		

		// Subscription support
		$this->supports = array( 
			'products', 
			'refunds',
			'subscriptions',
			'subscription_cancellation', 
			'subscription_suspension', 
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change'
		);


		// Enqueue scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'klarna_checkout_enqueuer' ) );

		// Cancel and activate the order
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_klarna_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'activate_klarna_order' ) );

		// Add link to KCO page in standard checkout
	
		/**
		 * Checkout page AJAX
		 */
				
		// Add coupon
		add_action( 'wp_ajax_klarna_checkout_coupons_callback', array( $this, 'klarna_checkout_coupons_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_coupons_callback', array( $this, 'klarna_checkout_coupons_callback' ) );

		// Remove coupon
		add_action( 'wp_ajax_klarna_checkout_remove_coupon_callback', array( $this, 'klarna_checkout_remove_coupon_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_remove_coupon_callback', array( $this, 'klarna_checkout_remove_coupon_callback' ) );
		
		// Cart quantity
		add_action( 'wp_ajax_klarna_checkout_cart_callback_update', array( $this, 'klarna_checkout_cart_callback_update' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_cart_callback_update', array( $this, 'klarna_checkout_cart_callback_update' ) );

		// Cart remove
		add_action( 'wp_ajax_klarna_checkout_cart_callback_remove', array( $this, 'klarna_checkout_cart_callback_remove' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_cart_callback_remove', array( $this, 'klarna_checkout_cart_callback_remove' ) );
		
		// Shipping method selector
		add_action( 'wp_ajax_klarna_checkout_shipping_callback', array( $this, 'klarna_checkout_shipping_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_shipping_callback', array( $this, 'klarna_checkout_shipping_callback' ) );
		
		// Country selector
		add_action( 'wp_ajax_klarna_checkout_country_callback', array( $this, 'klarna_checkout_country_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_country_callback', array( $this, 'klarna_checkout_country_callback' ) );

		// Order note
		add_action( 'wp_ajax_klarna_checkout_order_note_callback', array( $this, 'klarna_checkout_order_note_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_order_note_callback', array( $this, 'klarna_checkout_order_note_callback' ) );

		// KCO iframe update
		add_action( 'wp_ajax_klarna_checkout_iframe_update_callback', array( $this, 'klarna_checkout_iframe_update_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_iframe_update_callback', array( $this, 'klarna_checkout_iframe_update_callback' ) );

		// Add order item
		// Need to use this hook, because in woocommerce_new_order_item item meta is not populated
		add_action( 'woocommerce_ajax_add_order_item_meta', array( $this, 'update_klarna_order_add_item' ), 10, 3 );

		// Remove order item
		// This must be done BEFORE deletion, because otherwise we can't tie order item to an order
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'update_klarna_order_delete_item' ) );

		// Edit an order item and save
		add_action( 'woocommerce_saved_order_items', array( $this, 'update_klarna_order_edit_item' ), 10, 2 );

		// Process subscription payment
		add_action( 'scheduled_subscription_payment_klarna_checkout', array( $this, 'scheduled_subscription_payment' ), 10, 3 );

		// Add activate settings field for recurring orders
		add_filter( 'klarna_checkout_form_fields', array( $this, 'add_activate_recurring_option' ) );

		/**
		 * Checkout page shortcodes
		 */ 
		add_shortcode( 'woocommerce_klarna_checkout_widget', array( $this, 'klarna_checkout_widget' ) );
		add_shortcode( 'woocommerce_klarna_login', array( $this, 'klarna_checkout_login') );
		add_shortcode( 'woocommerce_klarna_country', array( $this, 'klarna_checkout_country') );

		// Register new order status
		add_action( 'init', array( $this, 'register_klarna_incomplete_order_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_kco_incomplete_to_order_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $this, 'kco_incomplete_payment_complete' ) );

		// Do not copy invoice number to recurring orders
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'kco_recurring_do_not_copy_meta_data' ), 10, 4 );

    }

	/**
	 * Register KCO Incomplete order status
	 * 
	 * @since  2.0
	 **/
	function register_klarna_incomplete_order_status() {
		if ( $this->debug ) {
			$show_in_admin_status_list = true;
		} else {
			$show_in_admin_status_list = false;
		}
		register_post_status( 'wc-kco-incomplete', array(
			'label'                     => 'KCO incomplete',
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => $show_in_admin_status_list,
			'label_count'               => _n_noop( 'KCO incomplete <span class="count">(%s)</span>', 'KCO incomplete <span class="count">(%s)</span>' ),
		) );
   	}


	/**
	 * Add KCO Incomplete to list of order status
	 * 
	 * @since  2.0
	 **/
	function add_kco_incomplete_to_order_statuses( $order_statuses ) {
		$order_statuses['wc-kco-incomplete'] = 'Incomplete Klarna Checkout';

		return $order_statuses;
	}

	/**
	 * Allows $order->payment_complete to work for KCO incomplete orders
	 * 
	 * @since  2.0
	 **/
	function kco_incomplete_payment_complete( $order_statuses ) {
		$order_statuses[] = 'kco-incomplete';

		return $order_statuses;
	}

	/**
	 * Add options for recurring order activation.
	 * 
	 * @since  2.0
	 **/
	function add_activate_recurring_option( $settings ) {
		if ( class_exists( 'WC_Subscriptions_Manager' ) ) {
			$settings['activate_recurring'] = array(
				'title' => __( 'Automatically activate recurring orders', 'klarna' ), 
				'type' => 'checkbox', 
				'label' => __( 'If this option is checked recurring orders will be activated automatically', 'klarna' ),
				'default' => 'yes'
			);
		}

		return $settings;
	}

	/**
	 * Scheduled subscription payment.
	 * 
	 * @since  2.0
	 **/
	function scheduled_subscription_payment( $amount_to_charge, $order, $product_id ) {
		// Check if order was created using this method
		if ( $this->id == get_post_meta( $order->id, '_payment_method', true ) ) {
			// Prevent hook from firing twice
			if ( ! get_post_meta( $order->id, '_schedule_klarna_subscription_payment', true ) ) {
				$result = $this->process_subscription_payment( $amount_to_charge, $order, $product_id );

				if ( false == $result ) {
					WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
				} else {
					WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
				}
				add_post_meta( $order->id, '_schedule_klarna_subscription_payment', 'no', true );
			} else {
				delete_post_meta( $order->id, '_schedule_klarna_subscription_payment', 'no' );
			}
		}
	}


	/**
	 * Process subscription payment.
	 * 
	 * @since  2.0
	 **/
	function process_subscription_payment( $amount_to_charge, $order, $product_id ) {
		$subscriptions_in_order = WC_Subscriptions_Order::get_recurring_items( $order );
		$subscription_item      = array_pop( $subscriptions_in_order );
		$subscription_key       = WC_Subscriptions_Manager::get_subscription_key( $order->id, $subscription_item['product_id'] );
		$subscription           = WC_Subscriptions_Manager::get_subscription( $subscription_key, $order->customer_user );

		$product = wc_get_product( $product_id );

		if ( 0 == $amount_to_charge ) {
			// Payment complete
			$order->payment_complete();
			return true;
		}

		$klarna_recurring_token = get_post_meta( $order->id, '_klarna_recurring_token', true );
		$klarna_currency = get_post_meta( $order->id, '_order_currency', true );
		$klarna_country = get_post_meta( $order->id, '_billing_country', true );
		$klarna_locale = get_post_meta( $order->id, '_klarna_locale', true );
		$klarna_eid = $this->klarna_eid;
		$klarna_secret = $this->klarna_secret;

		$klarna_billing  = array(
			'postal_code'     => get_post_meta( $order->id, '_billing_postcode', true ),
			'email'           => get_post_meta( $order->id, '_billing_email', true ),
			'country'         => get_post_meta( $order->id, '_billing_country', true ),
			'city'            => get_post_meta( $order->id, '_billing_city', true ),
			'family_name'     => get_post_meta( $order->id, '_billing_last_name', true ),
			'given_name'      => get_post_meta( $order->id, '_billing_first_name', true ),
			'street_address'  => get_post_meta( $order->id, '_billing_address_1', true ),
			'phone'           => get_post_meta( $order->id, '_billing_phone', true )
		);
		$shipping_email = get_post_meta( $order->id, '_shipping_email', true ) ? get_post_meta( $order->id, '_shipping_email', true ) : get_post_meta( $order->id, '_billing_email', true );
		$shipping_phone = get_post_meta( $order->id, '_shipping_phone', true ) ? get_post_meta( $order->id, '_shipping_phone', true ) : get_post_meta( $order->id, '_billing_phone', true );
		$klarna_shipping  = array(
			'postal_code'     => get_post_meta( $order->id, '_shipping_postcode', true ),
			'email'           => $shipping_email,
			'country'         => get_post_meta( $order->id, '_shipping_country', true ),
			'city'            => get_post_meta( $order->id, '_shipping_city', true ),
			'family_name'     => get_post_meta( $order->id, '_shipping_last_name', true ),
			'given_name'      => get_post_meta( $order->id, '_shipping_first_name', true ),
			'street_address'  => get_post_meta( $order->id, '_shipping_address_1', true ),
			'phone'           => $shipping_phone
		);

		$cart = array();

		$recurring_price = ( $subscription_item['recurring_line_total'] + $subscription_item['recurring_line_tax'] ) * 100;
		$recurring_tax_rate = ( $subscription_item['recurring_line_tax'] / $subscription_item['recurring_line_total'] ) * 10000;
		$cart[] = array(
			'reference'     => strval( $product->id ),
			'name'          => $product->post->post_title,
			'quantity'      => (int) $subscription_item['qty'],
			'unit_price'    => (int) $recurring_price,
			'discount_rate' => 0,
			'tax_rate'      => (int) $recurring_tax_rate			
		);

		if ( $order->get_total_shipping() > 0 ) {
			$shipping_price = ( $order->get_total_shipping() + $order->get_shipping_tax() ) * 100;
			$shipping_tax_rate = ( $order->get_shipping_tax() / $order->get_total_shipping() ) * 10000;
			$cart[] = array(
				'type'       => 'shipping_fee',
				'reference'  => 'SHIPPING',
				'name'       => 'Shipping Fee',
				'quantity'   => 1,
				'unit_price' => (int) $shipping_price,
				'tax_rate'   => (int) $shipping_tax_rate
			);
		}

		$create = array();
		if ( 'yes' == $this->activate_recurring ) {
			$create['activate'] = true;
		} else {
			$create['activate'] = false;			
		}
		$create['purchase_currency']  = $klarna_currency;
		$create['purchase_country']   = $klarna_country;
		$create['locale']             = $klarna_locale;
		$create['merchant']['id']     = $klarna_eid;
		$create['billing_address']    = $klarna_billing;
		$create['shipping_address']   = $klarna_shipping;
		$create['merchant_reference'] = array(
			'orderid1' => $order->id . '_' . $product_id . '_' . time()
		);
		$create['cart'] = array();
		foreach ( $cart as $item ) {
			$create['cart']['items'][] = $item;
		}

		require_once( KLARNA_LIB . '/src/Klarna/Checkout.php' );
		$connector = Klarna_Checkout_Connector::create(
			$klarna_secret,
			$this->klarna_server
		);
		$klarna_order = new Klarna_Checkout_RecurringOrder( $connector, $klarna_recurring_token );

		try {
			$klarna_order->create( $create );
			if ( isset( $klarna_order['invoice'] ) ) {
				add_post_meta( $order->id, '_klarna_order_invoice_recurring', $klarna_order['invoice'], true );
				$order->add_order_note(
					__( 'Klarna subscription payment invoice number: ', 'klarna' ) . $klarna_order['invoice']
				);
			} elseif ( isset( $klarna_order['reservation'] ) ) {
				add_post_meta( $order->id, '_klarna_order_reservation_recurring', $klarna_order['reservation'], true );
				$order->add_order_note(
					__( 'Klarna subscription payment reservation number: ', 'klarna' ) . $klarna_order['reservation']
				);
			}
			return true;
		} catch ( Klarna_Checkout_ApiErrorException $e ) {
			$order->add_order_note(
				sprintf(
					__( 'Klarna subscription payment failed. Error code %s. Error message %s', 'klarna' ),
					$e->getCode(),
					utf8_encode( $e->getMessage() )
				)					
			);
			return false;
		}
	}

	/**
	 * Do not copy Klarna invoice number from completed subscription order to its renewal orders.
	 * 
	 * @since  2.0
	 **/
	function kco_recurring_do_not_copy_meta_data( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {
		$order_meta_query .= " AND `meta_key` NOT IN ('_klarna_invoice_number')";
		return $order_meta_query;
	}

	/**
	 * Enqueue Klarna Checkout javascript.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_enqueuer() {
		global $woocommerce;

		$suffix               = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$assets_path          = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
		$frontend_script_path = $assets_path . 'js/frontend/';

		wp_register_script( 'klarna_checkout', KLARNA_URL . 'js/klarna-checkout.js' );
		wp_localize_script( 'klarna_checkout', 'kcoAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'klarna_checkout_nonce' => wp_create_nonce( 'klarna_checkout_nonce' ) ) );        

		wp_register_style( 'klarna_checkout', KLARNA_URL . 'css/klarna-checkout.css' );	
		if ( is_page() ) {
			global $post;

			// Need to check for HTTPS on non-HTTPS websites
			$explode = explode( '://', $this->klarna_checkout_url );
			if ( url_to_postid( 'https://' . $explode[1] ) ) {
				$klarna_checkout_page_id = url_to_postid( 'https://' . $explode[1] );
			} elseif ( url_to_postid( 'http://' . $explode[1] ) ) {
				$klarna_checkout_page_id = url_to_postid( 'http://' . $explode[1] );
			}

			if ( $post->ID == $klarna_checkout_page_id ) {
				wp_enqueue_script( 'jquery' );
				wp_enqueue_script( 'wc-checkout', $frontend_script_path . 'checkout' . $suffix . '.js', array( 'jquery', 'woocommerce', 'wc-country-select', 'wc-address-i18n' ) );
				wp_enqueue_script( 'klarna_checkout' );
				wp_enqueue_style( 'klarna_checkout' );
			}
		}
	}


	//
	// Shortcode callbacks
	//


	/**
	 * Klarna Checkout widget shortcode callback.
	 * 
	 * Parameters:
	 * col            - whether to show it as left or right column in two column layout, options: 'left' and 'right'
	 * order_note     - whether to show order note or not, option: 'false' (to hide it)
	 * 'hide_columns' - select columns to hide, comma separated string, options: 'remove', 'price'
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_widget( $atts ) {
		// Don't show on thank you page
		if ( isset( $_GET['thankyou'] ) && 'yes' == $_GET['thankyou'] )
			return;

		// Check if iframe needs to be displayed
		if ( ! $this->show_kco() )
			return;

		global $woocommerce;

		$atts = shortcode_atts(
			array(
				'col' => '',
				'order_note' => '',
				'hide_columns' => ''
			),
			$atts
		);

		if ( '' != $atts['hide_columns'] ) {
			$hide_columns = explode( ',', $atts['hide_columns'] );
		}

		$widget_class = '';

		if ( 'left' == $atts['col'] ) {
			$widget_class .= ' kco-left-col';
		} elseif ( 'right' == $atts['col'] ) {
			$widget_class .= ' kco-right-col';			
		}

		// Recheck cart items so that they are in stock
		$result = $woocommerce->cart->check_cart_item_stock();
		if ( is_wp_error( $result ) ) {
			echo '<p>' . $result->get_error_message() . '</p>';
			// exit();
		}

		if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {
			ob_start(); ?>
				
				<div id="klarna-checkout-widget" class="woocommerce <?php echo $widget_class; ?>">

					<?php woocommerce_checkout_coupon_form(); ?>

					<?php /*
					<?php if ( WC()->cart->coupons_enabled() ) { ?>
					<div id="klarna-checkout-coupons">
						<form class="klarna_checkout_coupon" method="post">
							<p class="form-row form-row-first">
								<input type="text" name="coupon_code" class="input-text" placeholder="Coupon Code" id="coupon_code" value="" />
							</p>
							<p class="form-row form-row-last" style="text-align:right">
								<input type="submit" class="button" name="apply_coupon" value="<?php _e( 'Apply Coupon', 'klarna' ); ?>" />
							</p>
							<div class="clear"></div>
						</form>
					</div>
					<?php } ?>
					*/ ?>

					<div>
					<table id="klarna-checkout-cart">
						<tbody>
							<tr>
								<?php if ( ! in_array( 'remove', $hide_columns ) ) { ?>
								<th class="product-remove kco-centeralign"></th>
								<?php } ?>
								<th class="product-name kco-leftalign"><?php _e( 'Product', 'klarna' ); ?></th>
								<?php if ( ! in_array( 'price', $hide_columns ) ) { ?>
								<th class="product-price kco-centeralign"><?php _e( 'Price', 'klarna' ); ?></th>
								<?php } ?>
								<th class="product-quantity kco-centeralign"><?php _e( 'Quantity', 'klarna' ); ?></th>
								<th class="product-total kco-rightalign"><?php _e( 'Total', 'klarna' ); ?></th>
							</tr>
							<?php
							foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {
								$_product = $cart_item['data'];
								$cart_item_product = wc_get_product( $cart_item['product_id'] );
								echo '<tr>';
									if ( ! in_array( 'remove', $hide_columns ) ) {
									echo '<td class="kco-product-remove kco-centeralign"><a href="#">x</a></td>';
									}
									echo '<td class="product-name kco-leftalign">';
										if ( ! $_product->is_visible() ) {
											echo apply_filters( 'woocommerce_cart_item_name', $_product->get_title(), $cart_item, $cart_item_key ) . '&nbsp;';
										} else { 
											echo apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s </a>', $_product->get_permalink( $cart_item ), $_product->get_title() ), $cart_item, $cart_item_key );
										}
										// Meta data
										echo $woocommerce->cart->get_item_data( $cart_item );
									echo '</td>';
									if ( ! in_array( 'price', $hide_columns ) ) {
									echo '<td class="product-price kco-centeralign"><span class="amount">';
										echo apply_filters( 'woocommerce_cart_item_price', $woocommerce->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
									echo '</span></td>';
									}
									echo '<td class="product-quantity kco-centeralign" data-cart_item_key="' . $cart_item_key .'">';
										if ( $_product->is_sold_individually() ) {
											$product_quantity = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key );
										} else {
											$product_quantity = woocommerce_quantity_input( array(
												'input_name'  => "cart[{$cart_item_key}][qty]",
												'input_value' => $cart_item['quantity'],
												'max_value'   => $_product->backorders_allowed() ? '' : $_product->get_stock_quantity(),
												'min_value'   => '1'
											), $_product, false );
										}
										echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key );
									echo '</td>';
									echo '<td class="product-total kco-rightalign"><span class="amount">';
										echo apply_filters( 'woocommerce_cart_item_subtotal', $woocommerce->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
									echo '</span></td>';
								echo '</tr>';
							}
							?>
						</tbody>
					</table>
					</div>

					<?php
					if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
						define( 'WOOCOMMERCE_CART', true );
					}
					$woocommerce->cart->calculate_shipping();
					$woocommerce->cart->calculate_totals();
					?>
					<div>
					<table id="kco-totals">
						<tbody>
							<tr id="kco-page-subtotal">
								<td class="kco-col-desc kco-rightalign"><?php _e( 'Subtotal', 'klarna' ); ?></td>
								<td id="kco-page-subtotal-amount" class="kco-col-number kco-rightalign"><span class="amount"><?php echo $woocommerce->cart->get_cart_subtotal(); ?></span></td>
							</tr>
							
							<?php echo $this->klarna_checkout_get_shipping_options_row_html(); ?>
							
							<?php foreach ( $woocommerce->cart->get_applied_coupons() as $coupon ) { ?>
								<tr class="kco-applied-coupon">
									<td class="kco-rightalign">
										Coupon: <?php echo $coupon; ?> 
										<a class="kco-remove-coupon" data-coupon="<?php echo $coupon; ?>" href="#">(remove)</a>
									</td>
									<td class="kco-rightalign">-<?php echo wc_price( $woocommerce->cart->get_coupon_discount_amount( $coupon, $woocommerce->cart->display_cart_ex_tax ) ); ?></td>
								</tr>
							<?php }	?>

							<tr id="kco-page-total">
								<td class="kco-rightalign kco-bold"><?php _e( 'Total', 'klarna' ); ?></a></td>
								<td id="kco-page-total-amount" class="kco-rightalign kco-bold"><span class="amount"><?php echo $woocommerce->cart->get_total(); ?></span></td>
							</tr>
						</tbody>
					</table>
					</div>

					<?php if ( 'false' != $atts['order_note'] ) { ?>
					<div>
						<form>
							<textarea id="klarna-checkout-order-note" class="input-text" name="klarna-checkout-order-note" placeholder="<?php _e( 'Notes about your order, e.g. special notes for delivery.', 'klarna' ); ?>"></textarea>
						</form>
					</div>
					<?php } ?>

				</div>

			<?php return ob_get_clean();
		}
	}


	/**
	 * Klarna Checkout login shortcode callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_login() {
		if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {
			if ( ! is_user_logged_in() ) {
				wp_login_form();
			}
		}
	}
	

	/**
	 * Klarna Checkout country selector shortcode callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_country() {	
		if ( sizeof( WC()->cart->get_cart() ) > 0 && 'EUR' == get_woocommerce_currency() ) {
			ob_start();
			
			// Get array of Euro Klarna Checkout countries with Eid and secret defined
			$klarna_checkout_countries = array();
			if ( in_array( 'FI', $this->authorized_countries )  ) {
				$klarna_checkout_countries['FI'] = __( 'Finland', 'klarna' );
			}
			if ( in_array( 'DE', $this->authorized_countries )  ) {
				$klarna_checkout_countries['DE'] = __( 'Germany', 'klarna' );
			}
			if ( in_array( 'AT', $this->authorized_countries )  ) {
				$klarna_checkout_countries['AT'] = __( 'Austria', 'klarna' );
			}
			/*
			$klarna_checkout_countries = array(
				'FI' => __( 'Finland', 'klarna' ),
				'DE' => __( 'Germany', 'klarna' ),
				'AT' => __( 'Austria', 'klarna' )
			);
			*/
			$klarna_checkout_enabled_countries = array();
			foreach( $klarna_checkout_countries as $klarna_checkout_country_code => $klarna_checkout_country ) {
				$lowercase_country_code = strtolower( $klarna_checkout_country_code );
				if ( isset( $this->settings["eid_$lowercase_country_code"] ) && isset( $this->settings["secret_$lowercase_country_code"] ) ) {
					if ( array_key_exists( $klarna_checkout_country_code, WC()->countries->get_allowed_countries() ) ) {
						$klarna_checkout_enabled_countries[ $klarna_checkout_country_code ] = $klarna_checkout_country;
					}
				}
			}
			
			// If there's no Klarna enabled countries, or there's only one, bail
			if ( count( $klarna_checkout_enabled_countries ) < 2 ) {
				return;
			}

			if ( WC()->session->get( 'klarna_euro_country' ) ) {
				$kco_euro_country = WC()->session->get( 'klarna_euro_country' );
			} else {
				$kco_euro_country = $this->shop_country;
			}

			echo '<div class="woocommerce">';
			echo '<label for="klarna-checkout-euro-country">';
			echo __( 'Country:', 'klarna' );
			echo '<br />';
			echo '<select id="klarna-checkout-euro-country" name="klarna-checkout-euro-country">';
			foreach( $klarna_checkout_enabled_countries as $klarna_checkout_enabled_country_code => $klarna_checkout_enabled_country ) {
				echo '<option value="' . $klarna_checkout_enabled_country_code . '"' . selected( $klarna_checkout_enabled_country_code, $kco_euro_country, false ) . '>' . $klarna_checkout_enabled_country . '</option>';
			}
			echo '</select>';
			echo '</label>';
			echo '</div>';

			return ob_get_clean();
		}
	}


	//
	// AJAX callbacks
	//


	/**
	 * Klarna Checkout cart AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_cart_callback_update() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		global $woocommerce;
		
		$updated_item_key = $_REQUEST['cart_item_key'];
		$new_quantity = $_REQUEST['new_quantity'];

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}
				
		$cart_items = $woocommerce->cart->get_cart();
		$updated_item = $cart_items[ $updated_item_key ];
		$updated_product = wc_get_product( $updated_item['product_id'] );
		
		// Update WooCommerce cart and transient order item
		$klarna_sid = $woocommerce->session->get( 'klarna_sid' );
		$woocommerce->cart->set_quantity( $updated_item_key, $new_quantity );
		$woocommerce->cart->calculate_totals();

		$this->update_or_create_local_order();
		
		$data['cart_total'] = wc_price( $woocommerce->cart->total );
		$data['cart_subtotal'] = $woocommerce->cart->get_cart_subtotal();
		$data['shipping_row'] = $this->klarna_checkout_get_shipping_options_row_html();

		// Update Klarna order line item
		$data['line_total'] = apply_filters( 
			'woocommerce_cart_item_subtotal', 
			$woocommerce->cart->get_product_subtotal( 
				$updated_product, 
				$new_quantity
			), 
			$updated_item, 
			$updated_item_key 
		);
	
		if ( WC()->session->get( 'klarna_checkout' ) ) {
			$this->ajax_update_klarna_order();				
		}

		wp_send_json_success( $data );

		wp_die();
	}

	/**
	 * Klarna Checkout cart AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_cart_callback_remove() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		global $woocommerce;

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		$cart_items = $woocommerce->cart->get_cart();

		// Remove line item row
		$removed_item_key = esc_attr( $_REQUEST['cart_item_key_remove'] );

		$woocommerce->cart->remove_cart_item( $removed_item_key );

		if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {
			$woocommerce->cart->calculate_totals();

			$this->update_or_create_local_order();
		} else {
			if ( $woocommerce->session->get( 'ongoing_klarna_order' ) ) {
				wp_delete_post( $woocommerce->session->get( 'ongoing_klarna_order' ) );
				$woocommerce->session->__unset( 'ongoing_klarna_order' );
			}
		}
		
		// This needs to be sent back to JS, so cart widget can be updated
		$data['cart_total'] = wc_price( $woocommerce->cart->total );
		$data['cart_subtotal'] = $woocommerce->cart->get_cart_subtotal();
		$data['shipping_row'] = $this->klarna_checkout_get_shipping_options_row_html();
		$data['item_count'] = $woocommerce->cart->get_cart_contents_count();
		$data['cart_url'] = $woocommerce->cart->get_cart_url();

		// Update ongoing Klarna order
		if ( WC()->session->get( 'klarna_checkout' ) ) {
			$this->ajax_update_klarna_order();				
		}

		wp_send_json_success( $data );

		wp_die();
	}


	/**
	 * Klarna Checkout coupons AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_coupons_callback() {
		global $woocommerce;

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		$data = array();
		
		// Adding coupon
		if ( !empty( $_REQUEST['coupon'] ) && is_string( $_REQUEST['coupon'] ) ) {
			
			$coupon = $_REQUEST['coupon'];
			$coupon_success = $woocommerce->cart->add_discount( $coupon );
			$applied_coupons = $woocommerce->cart->applied_coupons;
			$woocommerce->session->set( 'applied_coupons', $applied_coupons );
			$woocommerce->cart->calculate_totals();
			wc_clear_notices(); // This notice handled by Klarna plugin	

			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			$woocommerce->cart->calculate_shipping();
			$woocommerce->cart->calculate_fees();
			$woocommerce->cart->calculate_totals();

			$this->update_or_create_local_order();
			
			$coupon_object = new WC_Coupon( $coupon );
	
			$amount = wc_price( $woocommerce->cart->get_coupon_discount_amount( $coupon, $woocommerce->cart->display_cart_ex_tax ) );
			$data['amount'] = $amount;			
			$data['coupon_success'] = $coupon_success;
			$data['coupon'] = $coupon;

			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			$data['cart_total'] = wc_price( $woocommerce->cart->total );
			$data['cart_subtotal'] = $woocommerce->cart->get_cart_subtotal();
			$data['shipping_row'] = $this->klarna_checkout_get_shipping_options_row_html();
	
			if ( WC()->session->get( 'klarna_checkout' ) ) {
				$this->ajax_update_klarna_order();				
			}
			
		}
		
		wp_send_json_success( $data );

		wp_die();
	}


	/**
	 * Klarna Checkout coupons AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_remove_coupon_callback() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		global $woocommerce;

		$data = array();
		
		// Removing coupon
		if ( isset( $_REQUEST['remove_coupon'] ) ) {
			
			$remove_coupon = $_REQUEST['remove_coupon'];
			
			$woocommerce->cart->remove_coupon( $remove_coupon );
			$applied_coupons = $woocommerce->cart->applied_coupons;
			$woocommerce->session->set( 'applied_coupons', $applied_coupons );
			$woocommerce->cart->calculate_totals();
			wc_clear_notices(); // This notice handled by Klarna plugin	

			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			$woocommerce->cart->calculate_shipping();
			$woocommerce->cart->calculate_fees();
			$woocommerce->cart->calculate_totals();

			$this->update_or_create_local_order();
	
			$data['cart_total'] = wc_price( $woocommerce->cart->total );
			$data['cart_subtotal'] = $woocommerce->cart->get_cart_subtotal();
			$data['shipping_row'] = $this->klarna_checkout_get_shipping_options_row_html();

			if ( WC()->session->get( 'klarna_checkout' ) ) {
				$this->ajax_update_klarna_order();				
			}
					
		}
		
		wp_send_json_success( $data );

		wp_die();
	}
	

	/**
	 * Klarna Checkout shipping AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_shipping_callback() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		global $woocommerce;

		$new_method = $_REQUEST['new_method'];
		$chosen_shipping_methods[] = wc_clean( $new_method );
		$woocommerce->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		$woocommerce->cart->calculate_shipping();
		$woocommerce->cart->calculate_fees();
		$woocommerce->cart->calculate_totals();

		$this->update_or_create_local_order();

		$data['new_method'] = $new_method;
		$data['cart_total'] = wc_price( $woocommerce->cart->total );
		$data['cart_shipping_total'] = $woocommerce->cart->get_cart_shipping_total();

		if ( WC()->session->get( 'klarna_checkout' ) ) {
			$this->ajax_update_klarna_order();				
		}

		wp_send_json_success( $data );

		wp_die();
	}	
		
	
	/**
	 * Klarna Checkout coupons AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_order_note_callback() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		global $woocommerce;

		$data = array();
		
		// Adding coupon
		if ( isset( $_REQUEST['order_note'] ) && is_string( $_REQUEST['order_note'] ) ) {
			$order_note = sanitize_text_field( $_REQUEST['order_note'] );
	
			if ( WC()->session->get( 'klarna_checkout' ) ) {
				$woocommerce->cart->calculate_shipping();
				$woocommerce->cart->calculate_fees();
				$woocommerce->cart->calculate_totals();

				$this->update_or_create_local_order();

				$this->ajax_update_klarna_order();				
			}
		}
		
		wp_send_json_success( $data );

		wp_die();
	}
	

	/**
	 * Klarna Checkout country selector AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_country_callback() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		$data = array();
		
		if ( isset( $_REQUEST['new_country'] ) && is_string( $_REQUEST['new_country'] ) ) {
			$new_country = sanitize_text_field( $_REQUEST['new_country'] );

			// Reset session
			$klarna_order = null;
			WC()->session->__unset( 'klarna_checkout' );

			// Store new country as WC session value
			WC()->session->set( 'klarna_euro_country', $new_country );	
		}

		wp_send_json_success( $data );

		wp_die();
	}

	/**
	 * Pushes Klarna order update in AJAX calls.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_iframe_update_callback() {
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		global $woocommerce;
		$data = array();

    	// Check stock
		if ( is_wp_error( $woocommerce->cart->check_cart_item_stock() ) ) {
			wp_send_json_error();
			wp_die();
		}

		// Capture email
		if ( isset( $_REQUEST['email'] ) && is_string( $_REQUEST['email'] ) && ! is_user_logged_in() ) {
			$this->update_or_create_local_order( $_REQUEST['email'] );
			$orderid = $woocommerce->session->get( 'ongoing_klarna_order' );
			$data['orderid'] = $orderid;

			require_once( KLARNA_LIB . '/src/Klarna/Checkout.php' );
			$connector = Klarna_Checkout_Connector::create( $this->klarna_secret, $this->klarna_server );		
			$klarna_order = new Klarna_Checkout_Order(
				$connector,
				WC()->session->get( 'klarna_checkout' )
			);
			$klarna_order->fetch();

			$update['merchant']['push_uri'] = add_query_arg(
				array(
					'sid' => $orderid
				),
				$klarna_order['merchant']['push_uri']
			);
			$update['merchant']['confirmation_uri'] = add_query_arg(
				array(
					'sid' => $orderid,
					'order-received' => $orderid
				),
				$klarna_order['merchant']['confirmation_uri']
			);
			$klarna_order->update( $update );
		}

		// Capture postal code
		if ( isset( $_REQUEST['postal_code'] ) && is_string( $_REQUEST['postal_code'] ) && WC_Validation::is_postcode( $_REQUEST['postal_code'], $this->klarna_country ) ) {
			$woocommerce->customer->set_shipping_postcode( $_REQUEST['postal_code'] );

			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			$woocommerce->cart->calculate_shipping();
			$woocommerce->cart->calculate_fees();
			$woocommerce->cart->calculate_totals();

			$this->update_or_create_local_order();

			$data['cart_total'] = wc_price( $woocommerce->cart->total );
			$data['cart_shipping_total'] = $woocommerce->cart->get_cart_shipping_total();
			$data['shipping_row'] = $this->klarna_checkout_get_shipping_options_row_html();

			if ( WC()->session->get( 'klarna_checkout' ) ) {
				$this->ajax_update_klarna_order();				
			}
		}

		wp_send_json_success( $data );

		wp_die();
	}

	/**
	 * Pushes Klarna order update in AJAX calls.
	 * 
	 * @since  2.0
	 **/
	function ajax_update_klarna_order() {
		global $woocommerce;

		// Check if Euro is selected, get correct country
		if ( WC()->session->get( 'aelia_cs_selected_currency' ) && 'EUR' == WC()->session->get( 'aelia_cs_selected_currency' ) && WC()->session->get( 'klarna_euro_country' ) ) {
			$klarna_c = strtolower( WC()->session->get( 'klarna_euro_country' ) );
			$eid = $this->settings["eid_$klarna_c"];
			$sharedSecret = $this->settings["secret_$klarna_c"];
		} else {
			$eid = $this->klarna_eid;
			$sharedSecret = $this->klarna_secret;
		}

		if ( $this->is_rest() ) {
			require_once( KLARNA_LIB . 'vendor/autoload.php' );
			$connector = Klarna\Rest\Transport\Connector::create(
				$eid,
				$sharedSecret,
				Klarna\Rest\Transport\ConnectorInterface::TEST_BASE_URL
			);

			$klarna_order = new \Klarna\Rest\Checkout\Order(
				$connector,
				WC()->session->get( 'klarna_checkout' )
			);
		} else {
			require_once( KLARNA_LIB . '/src/Klarna/Checkout.php' );
			$connector = Klarna_Checkout_Connector::create( $sharedSecret, $this->klarna_server );
	
			$klarna_order = new Klarna_Checkout_Order(
				$connector,
				WC()->session->get( 'klarna_checkout' )
			);

			$klarna_order->fetch();
		}

		// Process cart contents and prepare them for Klarna
		include_once( KLARNA_DIR . 'classes/class-wc-to-klarna.php' );
		$wc_to_klarna = new WC_Gateway_Klarna_WC2K( $this->is_rest() );
		$cart = $wc_to_klarna->process_cart_contents();

		if ( 0 == count( $cart ) ) {
			$klarna_order = null;
		} else {
			// Reset cart
			if ( $this->is_rest() ) {
				$update['order_lines'] = array();
				foreach ( $cart as $item ) {
					$update['order_lines'][] = $item;
				}
				$update['order_amount'] = $woocommerce->cart->total * 100;
				$update['order_tax_amount'] = $woocommerce->cart->get_taxes_total() * 100;
			} else {
				$update['cart']['items'] = array();
				foreach ( $cart as $item ) {
					$update['cart']['items'][] = $item;
				}			
			}
			
			$klarna_order->update( apply_filters( 'kco_update_order', $update ) );
		}
	}


	//
	//
	//
	

	/**
	 * Gets shipping options as formatted HTML.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_get_shipping_options_row_html() {
		global $woocommerce;

		ob_start();
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}
		$woocommerce->cart->calculate_shipping();
		$woocommerce->cart->calculate_fees();
		$woocommerce->cart->calculate_totals();

		?>
		<tr id="kco-page-shipping">
			<td class="kco-rightalign">
				<?php
					$woocommerce->cart->calculate_shipping();
					$packages = $woocommerce->shipping->get_packages();
					foreach ( $packages as $i => $package ) {
						$chosen_method = isset( $woocommerce->session->chosen_shipping_methods[ $i ] ) ? $woocommerce->session->chosen_shipping_methods[ $i ] : '';
						$available_methods = $package['rates'];
						$show_package_details = sizeof( $packages ) > 1;
						$index = $i;
						?>
							<?php if ( ! empty( $available_methods ) ) { ?>
					
								<?php if ( 1 === count( $available_methods ) ) {
									$method = current( $available_methods );
									echo wp_kses_post( wc_cart_totals_shipping_method_label( $method ) ); ?>
									<input type="hidden" name="shipping_method[<?php echo $index; ?>]" data-index="<?php echo $index; ?>" id="shipping_method_<?php echo $index; ?>" value="<?php echo esc_attr( $method->id ); ?>" class="shipping_method" />
					
								<?php } else { ?>
					
									<ul id="shipping_method">
										<?php foreach ( $available_methods as $method ) : ?>
											<li>
												<input style="margin-left:3px" type="radio" name="shipping_method[<?php echo $index; ?>]" data-index="<?php echo $index; ?>" id="shipping_method_<?php echo $index; ?>_<?php echo sanitize_title( $method->id ); ?>" value="<?php echo esc_attr( $method->id ); ?>" <?php checked( $method->id, $chosen_method ); ?> class="shipping_method" />
												<label for="shipping_method_<?php echo $index; ?>_<?php echo sanitize_title( $method->id ); ?>"><?php echo wp_kses_post( wc_cart_totals_shipping_method_label( $method ) ); ?></label>
											</li>
										<?php endforeach; ?>
									</ul>
					
								<?php } ?>
					
							<?php } ?>				
						<?php
					}
				?>
			</td>
			<td id="kco-page-shipping-total" class="kco-rightalign">
				<?php echo $woocommerce->cart->get_cart_shipping_total(); ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}


	/**
	 * WooCommerce cart to Klarna cart items.
	 *
	 * Helper functions that format WooCommerce cart items for Klarna order items.
	 * 
	 * @since  2.0
	 **/
	function cart_to_klarna() {
				
		global $woocommerce;
		
		$woocommerce->cart->calculate_shipping();
		$woocommerce->cart->calculate_totals();

		/**
		 * Process cart contents
		 */
		if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {
	
			foreach ( $woocommerce->cart->get_cart() as $cart_item ) {
	
				if ( $cart_item['quantity'] ) {

					$_product = wc_get_product( $cart_item['product_id'] );
	
					// We manually calculate the tax percentage here
					if ( $_product->is_taxable() && $cart_item['line_subtotal_tax'] > 0 ) {
						// Calculate tax percentage
						$item_tax_percentage = round( $cart_item['line_subtotal_tax'] / $cart_item['line_subtotal'], 2 ) * 100;
					} else {
						$item_tax_percentage = 00;
					}
	
					$cart_item_data = $cart_item['data'];
					$cart_item_name = $cart_item_data->post->post_title;
	
					if ( isset( $cart_item['item_meta'] ) ) {
						$item_meta = new WC_Order_Item_Meta( $cart_item['item_meta'] );
						if ( $meta = $item_meta->display( true, true ) ) {
							$item_name .= ' ( ' . $meta . ' )';
						}
					}
						
					// apply_filters to item price so we can filter this if needed
					$klarna_item_price_including_tax = $cart_item['line_subtotal'] + $cart_item['line_subtotal_tax'];
					$item_price = apply_filters( 'klarna_item_price_including_tax', $klarna_item_price_including_tax );
	
					// Get SKU or product id
					$reference = '';
					if ( $_product->get_sku() ) {
						$reference = $_product->get_sku();
					} elseif ( $_product->variation_id ) {
						$reference = $_product->variation_id;
					} else {
						$reference = $_product->id;
					}

					$total_amount = (int) ( $cart_item['line_total'] + $cart_item['line_tax'] ) * 100;
		
					$item_price = number_format( $item_price * 100, 0, '', '' ) / $cart_item['quantity'];
					// Check if there's a discount applied

					if ( $cart_item['line_subtotal'] > $cart_item['line_total'] ) {
						$item_discount_rate = round( 1 - ( $cart_item['line_total'] / $cart_item['line_subtotal'] ), 2 ) * 10000;
						$item_discount = ( $item_price * $cart_item['quantity'] - $total_amount );
					} else {
						$item_discount_rate = 0;
						$item_discount = 0;
					}

					if ( $this->is_rest() ) {
						$klarna_item = array(
							'reference'             => strval( $reference ),
							'name'                  => strip_tags( $cart_item_name ),
							'quantity'              => (int) $cart_item['quantity'],
							'unit_price'            => (int) $item_price,
							'tax_rate'              => intval( $item_tax_percentage . '00' ),
							'total_amount'          => $total_amount,
							'total_tax_amount'      => $cart_item['line_subtotal_tax'] * 100,
							'total_discount_amount' => $item_discount
						);
					} else {
						$klarna_item = array(
							'reference'      => strval( $reference ),
							'name'           => strip_tags( $cart_item_name ),
							'quantity'       => (int) $cart_item['quantity'],
							'unit_price'     => (int) $item_price,
							'tax_rate'       => intval( $item_tax_percentage . '00' ),
							'discount_rate'  => $item_discount_rate
						);					
					}

					$cart[] = $klarna_item;
	
				} // End if qty
	
			} // End foreach
	
		} // End if sizeof get_items()
	
	
		/**
		 * Process shipping
		 */
		if ( $woocommerce->cart->shipping_total > 0 ) {
			// We manually calculate the tax percentage here
			if ( $woocommerce->cart->shipping_tax_total > 0 ) {
				// Calculate tax percentage
				$shipping_tax_percentage = round( $woocommerce->cart->shipping_tax_total / $woocommerce->cart->shipping_total, 2 ) * 100;
			} else {
				$shipping_tax_percentage = 00;
			}
	
			$shipping_price = number_format( ( $woocommerce->cart->shipping_total + $woocommerce->cart->shipping_tax_total ) * 100, 0, '', '' );
	
			// Get shipping method name				
			$shipping_packages = $woocommerce->shipping->get_packages();
			foreach ( $shipping_packages as $i => $package ) {
				$chosen_method = isset( $woocommerce->session->chosen_shipping_methods[ $i ] ) ? $woocommerce->session->chosen_shipping_methods[ $i ] : '';
				if ( '' != $chosen_method ) {
				
					$package_rates = $package['rates'];
					foreach ( $package_rates as $rate_key => $rate_value ) {
						if ( $rate_key == $chosen_method ) {
							$klarna_shipping_method = $rate_value->label;
						}
					}
				}
			}
			if ( ! isset( $klarna_shipping_method ) ) {
				$klarna_shipping_method = __( 'Shipping', 'klarna' );
			}
	
			$shipping = array(  
				'type'       => 'shipping_fee',
				'reference'  => 'SHIPPING',
				'name'       => $klarna_shipping_method,
				'quantity'   => 1,
				'unit_price' => (int) $shipping_price,
				'tax_rate'   => intval( $shipping_tax_percentage . '00' )
			);
			if ( $this->is_rest() ) {
				$shipping['total_amount'] = (int) $shipping_price;
				$shipping['total_tax_amount'] = $woocommerce->cart->shipping_tax_total * 100;
			}
			$cart[] = $shipping;
	
		}
	
		return $cart;		
	}



	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	function init_form_fields() {    
		$this->form_fields = include( KLARNA_DIR . 'includes/settings-checkout.php' );
	}
	
	
	
	/**
	 * Admin Panel Options 
	 *
	 * @since 1.0.0
	 */
	 public function admin_options() { ?>

		<h3><?php _e( 'Klarna Checkout', 'klarna' ); ?></h3>

		<p>
			<?php printf(__( 'With Klarna Checkout your customers can pay by invoice or credit card. Klarna Checkout works by replacing the standard WooCommerce checkout form. Documentation <a href="%s" target="_blank">can be found here</a>.', 'klarna'), 'http://wcdocs.woothemes.com/user-guide/extensions/klarna/' ); ?>
		</p>

		<?php
		// If the WooCommerce terms page isn't set, do nothing.
		$klarna_terms_page = get_option( 'woocommerce_terms_page_id' );
		if ( empty( $klarna_terms_page ) && empty( $this->terms_url ) ) {
			echo '<strong>' . __( 'You need to specify a Terms Page in the WooCommerce settings or in the Klarna Checkout settings in order to enable the Klarna Checkout payment method.', 'klarna' ) . '</strong>';
		}

		// Check if Curl is installed. If not - display message to the merchant about this.
		if( function_exists( 'curl_version' ) ) {
			// Do nothing
		} else {
			echo '<div id="message" class="error"><p>' . __( 'The PHP library cURL does not seem to be installed on your server. Klarna Checkout will not work without it.', 'klarna' ) . '</p></div>';
		}
		?>

		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table><!--/.form-table-->

	<?php }
			
		
	/**
	 * Disabled KCO on regular checkout page
	 *
	 * @since 1.0.0
	 */
	function is_available() {
		 return false;
	}


	/**
	 * Set up Klarna configuration.
	 * 
	 * @since  2.0
	 **/
	function configure_klarna( $klarna, $country ) {
		// Country and language
		switch ( $country ) {
			case 'NO' :
			case 'NB' :
				$klarna_country = 'NO';
				$klarna_language = 'nb-no';
				$klarna_currency = 'NOK';
				$klarna_eid = $this->eid_no;
				$klarna_secret = $this->secret_no;
				break;
			case 'FI' :
				$klarna_country 			= 'FI';
				// Check if WPML is used and determine if Finnish or Swedish is used as language
				if ( class_exists( 'woocommerce_wpml' ) && defined('ICL_LANGUAGE_CODE') && strtoupper(ICL_LANGUAGE_CODE) == 'SV') {
					$klarna_language = 'sv-fi'; // Swedish
				} else {
					$klarna_language = 'fi-fi'; // Finnish
				}				
				$klarna_currency = 'EUR';
				$klarna_eid = $this->eid_fi;
				$klarna_secret = $this->secret_fi;
				break;
			case 'SE' :
			case 'SV' :
				$klarna_country = 'SE';
				$klarna_language = 'sv-se';
				$klarna_currency = 'SEK';
				$klarna_eid = $this->eid_se;
				$klarna_secret = $this->secret_se;
				break;
			case 'DE' :
				$klarna_country = 'DE';
				$klarna_language = 'de-de';
				$klarna_currency = 'EUR';
				$klarna_eid = $this->eid_de;
				$klarna_secret = $this->secret_de;
				break;
			case 'AT' :
				$klarna_country = 'AT';
				$klarna_language = 'de-at';
				$klarna_currency = 'EUR';
				$klarna_eid = $this->eid_at;
				$klarna_secret = $this->secret_at;
				break;
			case 'GB' :
				$klarna_country = 'gb';
				$klarna_language = 'en-gb';
				$klarna_currency = 'gbp';
				$klarna_eid = $this->eid_uk;
				$klarna_secret = $this->secret_uk;
				break;
			default:
				$klarna_country = '';
				$klarna_language = '';
				$klarna_currency = '';
				$klarna_eid = '';
				$klarna_secret = '';
		}

		$klarna->config(
			$eid = $klarna_eid,
			$secret = $klarna_secret,
			$country = $country,
			$language = $klarna_language,
			$currency = $klarna_currency,
			$mode = $this->klarna_mode,
			$pcStorage = 'json',
			$pcURI = '/srv/pclasses.json',
			$ssl = $this->klarna_ssl,
			$candice = false
		);
	}

	
	/**
	 * Render checkout page
	 *
	 * @since 1.0.0
	 */
	function get_klarna_checkout_page() {	
		global $woocommerce;
		global $current_user;
		get_currentuserinfo();

		// Debug
		if ( $this->debug=='yes' ) {
			$this->log->add( 'klarna', 'KCO page about to render...' );
		}

		require_once( KLARNA_LIB . '/src/Klarna/Checkout.php' );

		// Check if Klarna order exists, if it does display thank you page
		// otherwise display checkout page
		if ( isset( $_GET['klarna_order'] ) ) { // Display Order response/thank you page via iframe from Klarna

			ob_start();
			include( KLARNA_DIR . 'includes/checkout/thank-you.php' );
			return ob_get_clean();

		} else { // Display Checkout page

			ob_start();
			include( KLARNA_DIR . 'includes/checkout/checkout.php' );
			return ob_get_clean();

		} // End if isset($_GET['klarna_order'])
	} // End Function


	/**
	 * Creates a WooCommerce order, or updates if already created
	 *
	 * @since 1.0.0
	 */
	function update_or_create_local_order( $customer_email = null ) {
		if ( is_user_logged_in() ) {
			global $current_user;
			$customer_email = $current_user->user_email;
		}

		if ( ! is_email( $customer_email ) )
			return;

		// Check quantities
		global $woocommerce;
		$result = $woocommerce->cart->check_cart_item_stock();
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		// Update the local order
		include_once( KLARNA_DIR . 'classes/class-klarna-to-wc.php' );
		$klarna_to_wc = new WC_Gateway_Klarna_K2WC();
		$klarna_to_wc->set_rest( $this->is_rest() );
		$klarna_to_wc->set_eid( $this->klarna_eid );
		$klarna_to_wc->set_secret( $this->klarna_secret );
		$klarna_to_wc->set_klarna_log( $this->log );
		$klarna_to_wc->set_klarna_debug( $this->debug );
		$klarna_to_wc->set_klarna_server( $this->klarna_server );
		if ( $customer_email ) {
			$klarna_to_wc->prepare_wc_order( $customer_email );
		} else {
			$klarna_to_wc->prepare_wc_order();
		}
	}


	/**
     * Order confirmation via IPN
	 *
	 * @since 1.0.0
     */
	function check_checkout_listener() {
		if ( isset( $_GET['validate'] ) ) { 
			exit;
		}

		switch ( $_GET['scountry'] ) {
			case 'SE':
				$klarna_secret = $this->secret_se;
				$klarna_eid = $this->eid_se;
				break;
			case 'FI' :
				$klarna_secret = $this->secret_fi;
				$klarna_eid = $this->eid_se;
				break;
			case 'NO' :
				$klarna_secret = $this->secret_no;
				$klarna_eid = $this->eid_no;
				break;
			case 'DE' :
				$klarna_secret = $this->secret_de;
				$klarna_eid = $this->eid_de;
				break;
			case 'AT' :
				$klarna_secret = $this->secret_at;
				$klarna_eid = $this->eid_at;
				break;
			case 'gb' :
				$klarna_secret = $this->secret_uk;
				$klarna_eid = $this->eid_uk;
				break;
			default:
				$klarna_secret = '';
		}

		// Process cart contents and prepare them for Klarna
		if ( isset( $_GET['klarna_order'] ) ) {
			include_once( KLARNA_DIR . 'classes/class-klarna-to-wc.php' );
			$klarna_to_wc = new WC_Gateway_Klarna_K2WC();
			$klarna_to_wc->set_rest( $this->is_rest() );
			$klarna_to_wc->set_eid( $klarna_eid );
			$klarna_to_wc->set_secret( $klarna_secret );
			$klarna_to_wc->set_klarna_order_uri( $_GET['klarna_order'] );
			$klarna_to_wc->set_klarna_log( $this->log );
			$klarna_to_wc->set_klarna_debug( $this->debug );
			$klarna_to_wc->listener();
		}
	} // End function check_checkout_listener

	
	/**
	 * Helper function get_enabled
	 *
	 * @since 1.0.0
	 */	 
	function get_enabled() {
		return $this->enabled;
	}
	
	/**
	 * Helper function get_modify_standard_checkout_url
	 *
	 * @since 1.0.0
	 */
	function get_modify_standard_checkout_url() {
		return $this->modify_standard_checkout_url;
	}

	/**
	 * Helper function get_klarna_checkout_page
	 *
	 * @since 1.0.0
	 */
	function get_klarna_checkout_url() {
 		return $this->klarna_checkout_url;
 	}
 	
 	
 	/**
	 * Helper function get_klarna_country
	 *
	 * @since 1.0.0
	 */
	function get_klarna_country() {
		return $this->klarna_country;
	}
	
	
	/**
	 * Helper function - get correct currency for selected country
	 *
	 * @since 1.0.0
	 */
	function get_currency_for_country( $country ) {		
		switch ( $country ) {
			case 'DK':
				$currency = 'DKK';
				break;
			case 'DE' :
				$currency = 'EUR';
				break;
			case 'NL' :
				$currency = 'EUR';
				break;
			case 'NO' :
				$currency = 'NOK';
				break;
			case 'FI' :
				$currency = 'EUR';
				break;
			case 'SE' :
				$currency = 'SEK';
				break;
			case 'AT' :
				$currency = 'EUR';
				break;
			default:
				$currency = '';
		}
		
		return $currency;
	}
	
	
	/**
	 * Helper function - get Account Signup Text
	 *
	 * @since 1.0.0
	 */
 	public function get_account_signup_text() {
	 	return $this->account_signup_text;
 	}


 	/**
	 * Helper function - get Account Login Text
	 *
	 * @since 1.0.0
	 */
 	public function get_account_login_text() {
	 	return $this->account_login_text;
 	}
 	
 	
 	/**
	 * Helper function - get Subscription Product ID
	 *
	 * @since 2.0.0
	 */
 	public function get_subscription_product_id() {
		global $woocommerce;
		$subscription_product_id = false;
		if ( ! empty( $woocommerce->cart->cart_contents ) ) {
			foreach ( $woocommerce->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['product_id'] ) ) {
					$subscription_product_id = $cart_item['product_id'];
					break;
				}
			}
		}
		return $subscription_product_id;
	}
 	

	/**
	 * Can the order be refunded via Klarna?
	 * 
	 * @param  WC_Order $order
	 * @return bool
	 * @since  2.0.0
	 */
	public function can_refund_order( $order ) {
		if ( get_post_meta( $order->id, '_klarna_invoice_number', true ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Refund order in Klarna system
	 * 
	 * @param  integer $orderid
	 * @param  integer $amount
	 * @param  string  $reason
	 * @return bool
	 * @since  2.0.0
	 */
	public function process_refund( $orderid, $amount = NULL, $reason = '' ) {
		// Check if order was created using this method
		if ( $this->id == get_post_meta( $orderid, '_payment_method', true ) ) {
			$order = wc_get_order( $orderid );
			if ( ! $this->can_refund_order( $order ) ) {
				$this->log->add( 'klarna', 'Refund Failed: No Klarna invoice ID.' );
				$order->add_order_note( __( 'This order cannot be refunded. Please make sure it is activated.', 'klarna' ) );
				return false;
			}

			$country = get_post_meta( $orderid, '_billing_country', true );

			$klarna = new Klarna();
			$this->configure_klarna( $klarna, $country );
			$invNo = get_post_meta( $order->id, '_klarna_invoice_number', true );

			$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
			$refund_order = $klarna_order->refund_order( $amount, $reason = '', $invNo );

			if ( $refund_order ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Activate order in Klarna system
	 * 
	 * @param  integer $orderid
	 * @since  2.0.0
	 */
	function activate_klarna_order( $orderid ) {
		// Check if auto activation is enabled
		if ( 'yes' == $this->push_completion ) {
			// Check if order was created using this method
			if ( $this->id == get_post_meta( $orderid, '_payment_method', true ) ) {
				$order = wc_get_order( $orderid );

				// If this is a subscription order
				if ( class_exists( 'WC_Subscriptions_Renewal_Order' ) && WC_Subscriptions_Renewal_Order::is_renewal( $order ) ) {
					if ( get_post_meta( $orderid, '_klarna_order_reservation_recurring', true ) && get_post_meta( $orderid, '_billing_country', true ) ) {
						if ( ! get_post_meta( $orderid, '_klarna_invoice_number', true ) ) {
							$rno = get_post_meta( $orderid, '_klarna_order_reservation_recurring', true );
							$country = get_post_meta( $orderid, '_billing_country', true );

							$klarna = new Klarna();
							$this->configure_klarna( $klarna, $country );

							$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
							$klarna_order->activate_order( $rno );
						}
					}
				// Klarna reservation number and billing country must be set
				} else {
					// Check if order was created using old API
					if ( 'v2' == get_post_meta( $order->id, '_klarna_api', true ) ) {
						if ( get_post_meta( $orderid, '_klarna_order_reservation', true ) && get_post_meta( $orderid, '_billing_country', true ) ) {
							// Check if this order hasn't been activated already
							if ( ! get_post_meta( $orderid, '_klarna_invoice_number', true ) ) {
								$rno = get_post_meta( $orderid, '_klarna_order_reservation', true );
								$country = get_post_meta( $orderid, '_billing_country', true );

								$klarna = new Klarna();
								$this->configure_klarna( $klarna, $country );

								$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
								$klarna_order->activate_order( $rno );
							}
						}
					// Check if order was created using Rest API
					} elseif ( 'rest' == get_post_meta( $order->id, '_klarna_api', true ) ) {
						// Check if this order hasn't been activated already
						if ( ! get_post_meta( $orderid, '_klarna_invoice_number', true ) ) {
							/**
							 * Need to send local order to constructor and Klarna order to method
							 */
							require_once( KLARNA_LIB . 'vendor/autoload.php' );
							$connector = Klarna\Rest\Transport\Connector::create(
								$this->eid_uk,
								$this->secret_uk,
								Klarna\Rest\Transport\ConnectorInterface::TEST_BASE_URL
							);
							$klarna_order_id = get_post_meta( $orderid, '_klarna_order_id', true );
							$k_order = new Klarna\Rest\OrderManagement\Order(
								$connector,
								$klarna_order_id
							);
							$k_order->fetch();

							// $this->log->add( 'klarna', var_export( $k_order, true ) );

							$klarna_order = new WC_Gateway_Klarna_Order( $order );
							$klarna_order->activate_order_rest( $k_order );
						}
					}
				}
			}
		}
	}


	/**
	 * Cancel order in Klarna system
	 * 
	 * @param  integer $orderid
	 * @since  2.0.0
	 */
	function cancel_klarna_order( $orderid ) {
		// Check if auto cancellation is enabled
		if ( 'yes' == $this->push_cancellation ) {
			$order = wc_get_order( $orderid );

			$this->log->add( 'klarna', 'BEFORE' );
			// Check if order was created using this method
			if ( $this->id == get_post_meta( $orderid, '_payment_method', true ) ) {
				$this->log->add( 'klarna', 'AFTER' );
				// Check if order was created using old API
				if ( 'v2' == get_post_meta( $order->id, '_klarna_api', true ) ) {
					// Klarna reservation number and billing country must be set
					if ( get_post_meta( $orderid, '_klarna_order_reservation', true ) && get_post_meta( $orderid, '_billing_country', true ) ) {
						// Check if this order hasn't been cancelled already
						if ( ! get_post_meta( $orderid, '_klarna_order_cancelled', true ) ) {
							$rno = get_post_meta( $orderid, '_klarna_order_reservation', true );
							$country = get_post_meta( $orderid, '_billing_country', true );

							$order = wc_get_order( $orderid );

							$klarna = new Klarna();
							$this->configure_klarna( $klarna, $country );

							// $this->log->add( 'klarna', 'CANCELKLARNA: ' . var_export( $klarna, true ) );

							$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
							$klarna_order->cancel_order( $rno );
						}
					}
				// Check if order was created using Rest API
				} elseif ( 'rest' == get_post_meta( $order->id, '_klarna_api', true ) ) {
					// Check if this order hasn't been cancelled already
					if ( ! get_post_meta( $orderid, '_klarna_order_cancelled', true ) ) {
						/**
						 * Need to send local order to constructor and Klarna order to method
						 */
						require_once( KLARNA_LIB . 'vendor/autoload.php' );
						$connector = Klarna\Rest\Transport\Connector::create(
							$this->eid_uk,
							$this->secret_uk,
							Klarna\Rest\Transport\ConnectorInterface::TEST_BASE_URL
						);
						$klarna_order_id = get_post_meta( $orderid, '_klarna_order_id', true );
						$k_order = new Klarna\Rest\OrderManagement\Order(
							$connector,
							$klarna_order_id
						);
						$k_order->fetch();

						$this->log->add( 'klarna', var_export( $k_order, true ) );

						$klarna_order = new WC_Gateway_Klarna_Order( $order );
						$klarna_order->cancel_order_rest( $k_order );
					}
				}
			}
		}
	}


	/**
	 * Update order in Klarna system, add new item
	 * 
	 * @param  integer $orderid
	 * @since  2.0.0
	 */
	function update_klarna_order_add_item( $itemid, $item ) {
		// Check if auto cancellation is enabled
		if ( 'yes' == $this->push_update ) {
			// Get item row from the database table, needed for order id
			global $wpdb;
			$item_row = $wpdb->get_row( $wpdb->prepare( "
				SELECT      order_id
				FROM        {$wpdb->prefix}woocommerce_order_items
				WHERE       order_item_id = %d
			", $itemid ) );

			$orderid = $item_row->order_id;
			$order = wc_get_order( $orderid );

			// Check if order was created using this method
			if ( $this->id == get_post_meta( $orderid, '_payment_method', true ) && 'on-hold' == $order->get_status() ) {
				// Check if this order hasn't been cancelled or activated
				if ( ! get_post_meta( $orderid, '_klarna_order_cancelled', true ) && ! get_post_meta( $orderid, '_klarna_order_activated', true ) ) {
					$rno = get_post_meta( $orderid, '_klarna_order_reservation', true );
					$country = get_post_meta( $orderid, '_billing_country', true );

					$_product = $order->get_product_from_item( $item );
					$this->log->add( 'klarna', 'Product: ' . var_export( $_product, true ) );

					$klarna = new Klarna();
					$this->configure_klarna( $klarna, $country );

					$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
					$klarna_order->add_addresses( $this->klarna_secret, $this->klarna_server );
					$klarna_order->process_cart_contents();
					$klarna_order->process_shipping();
					$klarna_order->process_discount();
					$klarna_order->update_order( $rno );
				}		
			}
		}	
	}


	/**
	 * Update order in Klarna system, add new item
	 * 
	 * @param  integer $orderid
	 * @since  2.0.0
	 */
	function update_klarna_order_delete_item( $itemid ) {
		// Check if auto cancellation is enabled
		if ( 'yes' == $this->push_update ) {
			// Get item row from the database table, needed for order id
			global $wpdb;
			$item_row = $wpdb->get_row( $wpdb->prepare( "
				SELECT      order_id
				FROM        {$wpdb->prefix}woocommerce_order_items
				WHERE       order_item_id = %d
			", $itemid ) );

			$orderid = $item_row->order_id;
			$order = wc_get_order( $orderid );

			// Check if order was created using this method
			if ( $this->id == get_post_meta( $orderid, '_payment_method', true ) && 'on-hold' == $order->get_status() ) {
				// Check if this order hasn't been cancelled or activated
				if ( ! get_post_meta( $orderid, '_klarna_order_cancelled', true ) && ! get_post_meta( $orderid, '_klarna_order_activated', true ) ) {
					$rno = get_post_meta( $orderid, '_klarna_order_reservation', true );
					$country = get_post_meta( $orderid, '_billing_country', true );

					$klarna = new Klarna();
					$this->configure_klarna( $klarna, $country );

					$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
					$klarna_order->add_addresses( $this->klarna_secret, $this->klarna_server );
					$klarna_order->process_cart_contents( $itemid );
					$klarna_order->process_shipping();
					$klarna_order->process_discount();
					$klarna_order->update_order( $rno );
				}		
			}
		}
	}


	/**
	 * Update order in Klarna system, add new item
	 * 
	 * @param  integer $orderid
	 * @since  2.0.0
	 */
	function update_klarna_order_edit_item( $orderid, $items ) {
		$order = wc_get_order( $orderid );

		// Only do this if in an AJAX call (not when saving the entire order)
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			// Check if auto cancellation is enabled and order is on hold so it can be edited
			if ( 'yes' == $this->push_update && 'on-hold' == $order->get_status() ) {
				// Check if order was created using this method
				if ( $this->id == get_post_meta( $orderid, '_payment_method', true ) ) {
					// Check if this order hasn't been cancelled or activated
					if ( ! get_post_meta( $orderid, '_klarna_order_cancelled', true ) && ! get_post_meta( $orderid, '_klarna_order_activated', true ) ) {
						$rno = get_post_meta( $orderid, '_klarna_order_reservation', true );
						$country = get_post_meta( $orderid, '_billing_country', true );

						$klarna = new Klarna();
						$this->configure_klarna( $klarna, $country );

						$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
						$klarna_order->add_addresses( $this->klarna_secret, $this->klarna_server );
						$klarna_order->process_cart_contents();
						$klarna_order->process_shipping();
						$klarna_order->process_discount();
						$klarna_order->update_order( $rno );
						
					}		
				}	
			}
		}
	}


	/**
	 * Determines which version of Klarna API should be used
	 * 
	 * @param  integer $orderid
	 * @since  2.0.0
	 */
	function is_rest() {
		if ( 'GB' == $this->klarna_country || 'gb' == $this->klarna_country ) {
			return true;
		}

		return false;
	}


	/**
	 * Determines if KCO checkout page should be displayed.
	 * 
	 * @return boolean
	 * @since  2.0.0
	 */
	function show_kco() {
		// Don't render the Klarna Checkout form if the payment gateway isn't enabled.
		if ( $this->enabled != 'yes' ) {
			return false;
		}

		// If no Klarna country is set - return.
		if ( empty( $this->klarna_country ) ) {
			echo apply_filters(
				'klarna_checkout_wrong_country_message', 
				sprintf( 
					__( 'Sorry, you can not buy via Klarna Checkout from your country or currency. Please <a href="%s">use another payment method</a>. ', 'klarna' ),
					get_permalink( get_option( 'woocommerce_checkout_page_id' ) )
				) 
			);

			return false;
		}

		// If checkout registration is disabled and not logged in, the user cannot checkout
		global $woocommerce;
		$checkout = $woocommerce->checkout();
		if ( ! $checkout->enable_guest_checkout && ! is_user_logged_in() ) {
			echo apply_filters( 
				'klarna_checkout_must_be_logged_in_message',
				sprintf(
					__( 'You must be logged in to checkout. %s or %s.', 'woocommerce' ),
					'<a href="' . wp_login_url() . '" title="Login">Login</a>',
					'<a href="' . wp_registration_url() . '" title="Create an account">create an account</a>'
				)
			);
			return false;
		}


		// If the WooCommerce terms page or the Klarna Checkout settings field 
		// Terms Page isn't set, do nothing.
		if ( empty( $this->terms_url ) ) {
			return false;
		}

		return true;
	}

    
} // End class WC_Gateway_Klarna_Checkout

	
// Extra Class for Klarna Checkout
class WC_Gateway_Klarna_Checkout_Extra {
	
	public function __construct() {

		add_action( 'init', array( $this, 'start_session' ), 1 );
		// add_action( 'wp_head', array( $this, 'klarna_checkout_css' ) );
		
		add_filter( 'woocommerce_get_checkout_url', array( $this, 'change_checkout_url' ), 20 );
		
		add_action( 'woocommerce_register_form_start', array( $this, 'add_account_signup_text' ) );
		add_action( 'woocommerce_login_form_start', array( $this, 'add_account_login_text' ) );

		add_action( 'woocommerce_checkout_after_order_review', array( $this, 'klarna_add_link_to_kco_page' ) );		
		
		// Filter Checkout page ID, so WooCommerce Google Analytics integration can
		// output Ecommerce tracking code on Klarna Thank You page
		add_filter( 'woocommerce_get_checkout_page_id', array( $this, 'change_checkout_page_id' ) );

		// Change is_checkout to true on KCO page
		add_filter( 'woocommerce_is_checkout', array( $this, 'change_is_checkout_value' ) );
		
	}


	/**
	 * Add link to KCO page from standard checkout page.
	 * Initiated here because KCO class is instantiated multiple times
	 * making the hook fire multiple times as well.
	 * 
	 * @since  2.0
	 */
	function klarna_add_link_to_kco_page() {
		global $klarna_checkout_url;

		$checkout_settings   = get_option( 'woocommerce_klarna_checkout_settings' );

		if ( 'yes' == $checkout_settings['enabled'] && 
			'' != $checkout_settings['klarna_checkout_button_label'] && 
			'yes' == $checkout_settings['add_klarna_checkout_button'] ) {
			echo '<div class="woocommerce"><a style="margin-top:1em" href="' . $klarna_checkout_url . '" class="button std-checkout-button">' . $checkout_settings['klarna_checkout_button_label'] . '</a></div>';
		}
	}

		
	// Set session
	function start_session() {		
		// if ( ! is_admin() || defined( 'DOING_AJAX' ) ) {
		$data = new WC_Gateway_Klarna_Checkout;
		$enabled = $data->get_enabled();

		if ( ! session_id() && 'yes' == $enabled ) {
		session_start();
		}
		// }
    }

	
	function klarna_checkout_css() {
		global $post;
		global $klarna_checkout_url;

		$checkout_page_id   = url_to_postid( $klarna_checkout_url );
		$checkout_settings  = get_option( 'woocommerce_klarna_checkout_settings' );

		if ( $post->ID == $checkout_page_id ) {
			if ( '' != $checkout_settings['color_button'] || '' != $checkout_settings['color_button_text'] ) { ?>
				<style>
					a.std-checkout-button,
					.klarna_checkout_coupon input[type="submit"] { 
						background: <?php echo $checkout_settings['color_button']; ?> !important;
						border: none !important;
						color: <?php echo $checkout_settings['color_button_text']; ?> !important; 
					}
				</style>
			<?php }
		}
	}


	function set_cart_constant() {
		global $post;
		global $klarna_checkout_thanks_url;

		$checkout_page_id = url_to_postid( $klarna_checkout_thanks_url );

		if ( $post->ID == $checkout_page_id ) {
			
			if ( has_shortcode( $post->post_content, 'woocommerce_klarna_cart' ) ) {

				remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cross_sell_display' );
				remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 10 );

				if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
					define( 'WOOCOMMERCE_CART', true );
				}

			}

		}
	}

	
	/**
	 *  Change Checkout URL
	 *
	 *  Triggered from the 'woocommerce_get_checkout_url' action.
	 *  Alter the checkout url to the custom Klarna Checkout Checkout page.
	 *
	 **/	 
	function change_checkout_url( $url ) {
		global $woocommerce;
		global $klarna_checkout_url;

		$data = new WC_Gateway_Klarna_Checkout;

		$checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );
		$enabled = $checkout_settings['enabled'];
		$modify_standard_checkout_url = $checkout_settings['modify_standard_checkout_url'];
		// $klarna_checkout_url = $data->get_klarna_checkout_url();
		// $modify_standard_checkout_url = $data->get_modify_standard_checkout_url();
		$klarna_country = $data->get_klarna_country();
		$available_countries = $data->authorized_countries;

		// Change the Checkout URL if this is enabled in the settings
		if ( 
			$modify_standard_checkout_url == 'yes' && 
			$enabled == 'yes' && 
			! empty( $klarna_checkout_url ) && 
			in_array( strtoupper( $klarna_country ), $available_countries ) 
		) {
			$url = $klarna_checkout_url;
		}
		
		return $url;
	}
	
	/**
	 *  Function Add Account signup text
	 *
	 *  @since version 1.8.9
	 * 	Add text above the Account Registration Form. 
	 *  Useful for legal text for German stores. See documentation for more information. Leave blank to disable.
	 *
	 **/
	public function add_account_signup_text() {
		$checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );
		$account_signup_text = ( isset( $checkout_settings['account_signup_text'] ) ) ? $checkout_settings['account_signup_text'] : '';

		// Change the Checkout URL if this is enabled in the settings
		if( ! empty( $account_signup_text ) ) {
			echo $account_signup_text;
		}
	}
	
	
	/**
	 *  Function Add Account login text
	 *
	 *  @since version 1.8.9
	 * 	Add text above the Account Login Form. 
	 *  Useful for legal text for German stores. See documentation for more information. Leave blank to disable.
	 **/
	public function add_account_login_text() {
		$checkout_settings = get_option( 'woocommerce_klarna_checkout_settings' );
		$account_login_text = ( isset( $checkout_settings['account_login_text'] ) ) ? $checkout_settings['account_login_text'] : '';
	
		// Change the Checkout URL if this is enabled in the settings
		if ( ! empty( $account_login_text ) ) {
			echo $account_login_text;
		}
	}

	/**
	 * Change checkout page ID to Klarna Thank You page, when in Klarna Thank You page only
	 */
	public function change_checkout_page_id( $checkout_page_id ) {
		global $post;
		global $klarna_checkout_thanks_url;

		if ( is_page() ) {
			$current_page_url = get_permalink( $post->ID );
			// Compare Klarna Thank You page URL to current page URL
			if ( esc_url( trailingslashit( $klarna_checkout_thanks_url ) ) == esc_url( trailingslashit( $current_page_url ) ) ) {
				$checkout_page_id = $post->ID;
			}
		}

		return $checkout_page_id;
	}


	/**
	 * Set is_checkout to true on KCO page
	 */
	function change_is_checkout_value( $bool ) {
		global $post;
		global $klarna_checkout_url;

		if ( is_page() ) {
			$current_page_url = get_permalink( $post->ID );
			// Compare Klarna Checkout page URL to current page URL
			if ( esc_url( trailingslashit( $klarna_checkout_url ) ) == esc_url( trailingslashit( $current_page_url ) ) ) {
				return true;
			}
		}

		return false;
	}
		

} // End class WC_Gateway_Klarna_Checkout_Extra

$wc_klarna_checkout_extra = new WC_Gateway_Klarna_Checkout_Extra;