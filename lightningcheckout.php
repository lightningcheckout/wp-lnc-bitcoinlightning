<?php

/*
Plugin Name: Lightning Checkout (WooCommerce payment gateway)
Plugin URI: https://lightningcheckout.eu
Description: Accept Bitcoin over Lightning instantly. Brought to you by Lightning Checkout
Version: 1.1.4
Author: Lightning Checkout
Fork of: https://nl.wordpress.org/plugins/lightning-payment-gateway-lnbits/
*/


add_action('plugins_loaded', 'lightningcheckout_init');

define('LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG', 'lightning-checkout');
$site_title =  get_option('blogname');
define('SITE_NAME', $site_title);

require_once(__DIR__ . '/includes/init.php');

use LightningCheckoutPlugin\Utils;
use LightningCheckoutPlugin\LNBitsAPI;


function woocommerce_lightningcheckout_activate() {
    if (!current_user_can('activate_plugins')) return;

    global $wpdb;

    if ( null === $wpdb->get_row( "SELECT post_name FROM {$wpdb->prefix}posts WHERE post_name = '".LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG."'", 'ARRAY_A' ) ) {
        $page = array(
          'post_title'  => __( 'Lightning Checkout' ),
          'post_name' => LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG,
          'post_status' => 'publish',
          'post_author' => wp_get_current_user()->ID,
          'post_type'   => 'page',
          'post_content' => render_template('payment_page.php', array())
        );

        // insert the post into the database
        wp_insert_post( $page );
    }
}

register_activation_hook(__FILE__, 'woocommerce_lightningcheckout_activate');


// Helper to render templates under ./templates.
function render_template($tpl_name, $params) {
    return wc_get_template_html($tpl_name, $params, '', plugin_dir_path(__FILE__).'templates/');
}


add_action( 'wp_enqueue_scripts', 'qr_code_load' );
function qr_code_load(){
  wp_enqueue_script( 'qr-code', plugin_dir_url( __FILE__ ) . 'js/jquery.qrcode.min.js', array( 'jquery' ) );
}


// Generate lightningcheckout_payment page, using ./templates/lightningcheckout_payment.php
function lightningcheckout_payment_shortcode() {
    $check_payment_url = trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_lightningcheckout';

    if (isset($_REQUEST['order_id'])) {
        $order_id = absint($_REQUEST['order_id']);
        $order = wc_get_order($order_id);
        $invoice = $order->get_meta("lightningcheckout_invoice");
        $success_url = $order->get_checkout_order_received_url();
    } else {
        // Likely when editting page with this shortcode, use dummy order.
        $order_id = 1;
        $invoice = "lnbc0000";
        $success_url = "/dummy-success";
    }

    $template_params = array(
        "invoice" => $invoice,
        "check_payment_url" => $check_payment_url,
        'order_id' => $order_id,
        'success_url' => $success_url
    );
    
    return render_template('payment_shortcode.php', $template_params);
}



// This is the entry point of the plugin, where everything is registered/hooked up into WordPress.
function lightningcheckout_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };

    // Register shortcode for rendering Lightning invoice (QR code)
    add_shortcode('lightningcheckout_payment_shortcode', 'lightningcheckout_payment_shortcode');

    // Register the gateway, essentially a controller that handles all requests.
    function add_lightningcheckout_gateway($methods) {
        $methods[] = 'WC_Gateway_LNBits';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_lightningcheckout_gateway');


    // Defined here, because it needs to be defined after WC_Payment_Gateway is already loaded.
    class WC_Gateway_LNBits extends WC_Payment_Gateway {
        public function __construct() {
            global $woocommerce;

            $this->id = 'lightningcheckout';
            $this->icon = plugin_dir_url(__FILE__).'assets/lightning.png';
            $this->has_fields = false;
            $this->method_title = 'Bitcoin Lightning';
            $this->method_description = 'Accept bitcoin lightning payments via Lightning Checkout.';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = 'Bitcoin Lightning';
            $this->description = '';

            $url = 'https://pay.lightningcheckout.eu';
            $api_key = $this->get_option('lightningcheckout_api_key');
            $this->api = new LNBitsAPI($url, $api_key);

            add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_'.$this->id, array($this, 'thankyou'));
            add_action('woocommerce_api_wc_gateway_'.$this->id, array($this, 'check_payment'));
        }

        /**
         * Render admin options/settings.
         */
        public function admin_options() {
            ?>
            <h3><?php _e('Lightning Checkout', 'woothemes'); ?></h3>
            <p><?php _e('Accept Bitcoin instantly through the Lightning network.', 'woothemes'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        /**
         * Generate config form fields, shown in admin->WooCommerce->Settings.
         */
        public function init_form_fields() {
            // echo("init_form_fields");
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable Lightning Checkout', 'woocommerce'),
                    'label' => __('Enable Bitcoin payments via the Lightning network', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'lightningcheckout_api_key' => array(
                    'title' => __('API Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __(get_bloginfo() . ' Your personal Lightning Checkout API Key. You can request it via our support team.', 'woocommerce'),
                    'default' => '',
                ),
            );
        }


        /**
         * ? Output for thank you page.
         */
        public function thankyou() {
            if ($description = $this->get_description()) {
                echo esc_html(wpautop(wptexturize($description)));
            }
        }


        /**
         * Called from checkout page, when "Place order" hit, through AJAX.
         * 
         * Call LNBits API to create an invoice, and store the invoice in the order metadata.
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            // This will be stored in the Lightning invoice (ie. can be used to match orders in LNBits)

            $memo = SITE_NAME." order ".$order->get_id()." (".$order->get_total() ." ".get_woocommerce_currency().")";

            $amount = Utils::convert_to_satoshis($order->get_total(), get_woocommerce_currency());

            $r = $this->api->createInvoice($amount, $memo);

            if ($r['status'] == 201) {
                $resp = $r['response'];
                $order->add_meta_data('lightningcheckout_invoice', $resp['payment_request'], true);
                $order->add_meta_data('lightningcheckout_payment_hash', $resp['payment_hash'], true);
                $order->save();

                // TODO: configurable payment page slug
                $redirect_url = add_query_arg(array("order_id" => $order->get_id()), get_permalink( get_page_by_path( LIGHTNINGCHECKOUT_PAYMENT_PAGE_SLUG ) ));

                return array(
                    "result" => "success",
                    "redirect" => $redirect_url
                );
            } else {
                error_log("Lightning Checkout API failure. Status=".$r['status']);
                error_log($r['response']);
                return array(
                    "result" => "failure",
                    "messages" => array("Failed to create Lightning invoice.")
                );
            }
        }


        /**
         * Called by lightningcheckout_payment page (with QR code), through ajax.
         * 
         * Checks whether given invoice was paid, using LNBits API,
         * and updates order metadata in the database.
         */
        public function check_payment() {
            $order = wc_get_order($_REQUEST['order_id']);
            $payment_hash = $order->get_meta('lightningcheckout_payment_hash');
            $r = $this->api->checkInvoicePaid($payment_hash);

            if ($r['status'] == 200) {
                if ($r['response']['paid'] == true) {
                    $order->add_order_note('Payment is settled and added to your Lightning Checkout balance.');
                    $order->payment_complete();
                    $order->save();
                    error_log("PAID");
                    echo(json_encode(array(
                        'result' => 'success',
                        'redirect' => $order->get_checkout_order_received_url(),
                        'paid' => true
                    )));
                } else {
                    echo(json_encode(array(
                        'result' => 'success',
                        'paid' => false
                    )));
                }
            } else {
                echo(json_encode(array(
                    'result' => 'failure',
                    'paid' => false,
                    'messages' => array('Request to payment provider failed.')
                )));

            }
            die();
        }
    }
}
