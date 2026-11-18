<?php
/**
 * Plugin Name: Chain2Pay for Easy Digital Downloads
 * Plugin URI: https://chain2pay.is/
 * Description: Accept card payments in Easy Digital Downloads through Chain2Pay. Buyers pay by card through an on-ramp provider and you receive the payment in crypto in your own wallet.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Chain2Pay
 * Author URI: https://chain2pay.is/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: chain2pay-edd
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-chain2pay-edd-core.php';

/**
 * One Easy Digital Downloads gateway per Chain2Pay provider. The buyer is sent to the
 * Chain2Pay checkout; the order completes when Chain2Pay calls back and its API confirms
 * that the payment is paid.
 */
final class Chain2Pay_EDD {

    const VERSION  = '1.0.0';
    const NAME     = 'Chain2Pay for Easy Digital Downloads';
    const SECTION  = 'chain2pay';
    const LISTENER = 'chain2pay';
    const PREFIX   = 'chain2pay_';
    const SECRET   = 'chain2pay_edd_secret';

    /** Order statuses a payment can still complete. */
    const OPEN_STATUSES = array( 'pending', 'abandoned', 'failed', 'processing' );

    public static function boot() {
        if ( ! self::edd_ready() ) {
            add_action( 'admin_notices', array( __CLASS__, 'missing_edd_notice' ) );

            return;
        }
        add_filter( 'edd_payment_gateways', array( __CLASS__, 'register' ) );
        add_filter( 'edd_settings_sections_gateways', array( __CLASS__, 'section' ) );
        add_filter( 'edd_settings_gateways', array( __CLASS__, 'settings' ) );
        foreach ( array_keys( Chain2Pay_EDD_Core::providers() ) as $provider ) {
            // Without this, Easy Digital Downloads prints its own card number form.
            add_action( 'edd_' . self::PREFIX . $provider . '_cc_form', array( __CLASS__, 'checkout_note' ) );
            add_action(
                'edd_gateway_' . self::PREFIX . $provider,
                static function ( $purchase_data ) use ( $provider ) {
                    Chain2Pay_EDD::process_purchase( $purchase_data, $provider );
                }
            );
        }
        add_action( 'init', array( __CLASS__, 'listen' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
    }

    public static function register( $gateways ) {
        foreach ( Chain2Pay_EDD_Core::providers() as $id => $provider ) {
            $label = $provider['label'];
            if ( 'custom' === $id && '' !== (string) self::opt( 'custom_label' ) ) {
                $label = (string) self::opt( 'custom_label' );
            }
            $gateways[ self::PREFIX . $id ] = array(
                'admin_label'    => 'Chain2Pay: ' . $provider['admin'],
                'checkout_label' => $label,
                'supports'       => array(),
            );
        }

        return $gateways;
    }

    public static function section( $sections ) {
        $sections[ self::SECTION ] = __( 'Chain2Pay', 'chain2pay-edd' );

        return $sections;
    }

    public static function settings( $settings ) {
        $settings[ self::SECTION ] = array(
            self::PREFIX . 'header'       => array(
                'id'   => self::PREFIX . 'header',
                'name' => '<strong>' . __( 'Chain2Pay', 'chain2pay-edd' ) . '</strong>',
                'desc' => '',
                'type' => 'header',
            ),
            self::PREFIX . 'api_key'      => array(
                'id'   => self::PREFIX . 'api_key',
                'name' => __( 'Chain2Pay API key', 'chain2pay-edd' ),
                'desc' => __( 'Your key from the Chain2Pay dashboard (c2p_live_... or, for tests, c2p_sandbox_...). The same key is used by every Chain2Pay payment method. Then tick the Chain2Pay methods you want under Active Gateways, in the General section of this page.', 'chain2pay-edd' ),
                'type' => 'password',
                'size' => 'regular',
            ),
            self::PREFIX . 'custom_slug'  => array(
                'id'   => self::PREFIX . 'custom_slug',
                'name' => __( 'Custom provider slug (optional)', 'chain2pay-edd' ),
                'desc' => __( 'Only for the "Chain2Pay: Custom provider" method: the Chain2Pay provider it sends buyers to, in lowercase.', 'chain2pay-edd' ),
                'type' => 'text',
                'size' => 'regular',
            ),
            self::PREFIX . 'custom_label' => array(
                'id'   => self::PREFIX . 'custom_label',
                'name' => __( 'Custom provider label (optional)', 'chain2pay-edd' ),
                'desc' => __( 'Name of the "Custom provider" method at checkout.', 'chain2pay-edd' ),
                'type' => 'text',
                'size' => 'regular',
            ),
        );

        return $settings;
    }

    public static function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=' . self::SECTION ) ) . '">' . esc_html__( 'Settings', 'chain2pay-edd' ) . '</a>' );

        return $links;
    }

    public static function checkout_note() {
        echo '<p class="chain2pay-edd-note">' . esc_html__( 'You will complete the payment on the secure Chain2Pay checkout page.', 'chain2pay-edd' ) . '</p>';
    }

    /**
     * Checkout: records the pending order, creates the Chain2Pay payment and sends the buyer to it.
     */
    public static function process_purchase( $purchase_data, $provider ) {
        $gateway = self::PREFIX . $provider;
        if ( ! isset( $purchase_data['gateway_nonce'] ) || ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
            wp_die( esc_html__( 'Nonce verification has failed', 'chain2pay-edd' ), esc_html__( 'Error', 'chain2pay-edd' ), array( 'response' => 403 ) );
        }
        $api_key  = (string) self::opt( 'api_key' );
        $slug     = Chain2Pay_EDD_Core::providerSlug( $provider, (string) self::opt( 'custom_slug' ) );
        $currency = strtoupper( (string) edd_get_currency() );
        if ( '' === $api_key || '' === $slug ) {
            edd_set_error( 'chain2pay_not_configured', __( 'This payment method is not available right now.', 'chain2pay-edd' ) );
            edd_send_back_to_checkout( '?payment-mode=' . $gateway );

            return;
        }
        $problem = Chain2Pay_EDD_Core::precheck( $provider, $purchase_data['price'], $currency );
        if ( '' !== $problem ) {
            edd_set_error( 'chain2pay_precheck', $problem );
            edd_send_back_to_checkout( '?payment-mode=' . $gateway );

            return;
        }

        $order_id = self::build_order(
            array(
                'price'        => $purchase_data['price'],
                'date'         => isset( $purchase_data['date'] ) ? $purchase_data['date'] : null,
                'user_email'   => $purchase_data['user_email'],
                'purchase_key' => $purchase_data['purchase_key'],
                'currency'     => edd_get_currency(),
                'downloads'    => $purchase_data['downloads'],
                'user_info'    => $purchase_data['user_info'],
                'cart_details' => $purchase_data['cart_details'],
                'gateway'      => $gateway,
                'status'       => 'pending',
            )
        );
        $order = $order_id ? edd_get_order( $order_id ) : false;
        if ( ! $order ) {
            edd_record_gateway_error( __( 'Payment Error', 'chain2pay-edd' ), __( 'The order could not be created before sending the buyer to Chain2Pay.', 'chain2pay-edd' ) );
            edd_send_back_to_checkout( '?payment-mode=' . $gateway );

            return;
        }
        EDD()->session->set( 'edd_resume_payment', $order_id );

        $total    = (float) $order->total;
        $currency = strtoupper( (string) $order->currency );
        $ref      = Chain2Pay_EDD_Core::orderRef( 'edd', $order_id, $total, $currency );
        $callback = home_url( 'index.php' )
            . '?edd-listener=' . self::LISTENER
            . '&order=' . (int) $order_id
            . '&amt=' . rawurlencode( Chain2Pay_EDD_Core::amount( $total ) )
            . '&cur=' . rawurlencode( $currency )
            . '&c2p_token=' . Chain2Pay_EDD_Core::token( self::secret(), $ref );

        $created = Chain2Pay_EDD_Core::createPayment(
            $api_key,
            $total,
            $currency,
            $slug,
            (string) $purchase_data['user_email'],
            $callback,
            array(
                'order_ref' => $ref,
                'platform'  => 'edd',
                'order_id'  => (string) $order_id,
            ),
            'EDD/' . self::VERSION
        );
        if ( empty( $created['success'] ) ) {
            edd_insert_payment_note( $order_id, 'Chain2Pay: the payment could not be started. ' . $created['error'] );
            edd_debug_log( 'Chain2Pay: payment for order ' . $order_id . ' could not be started. ' . $created['error'] );
            edd_set_error( 'chain2pay_unavailable', __( 'Payment could not be started. Please try again in a moment or choose another payment method.', 'chain2pay-edd' ) );
            edd_send_back_to_checkout( '?payment-mode=' . $gateway );

            return;
        }
        edd_add_order_meta( $order_id, '_chain2pay_payment_id', $created['payment_id'] );
        edd_insert_payment_note( $order_id, 'Chain2Pay: payment ' . $created['payment_id'] . ' created (provider ' . $slug . '). Waiting for the buyer to pay.' );

        wp_redirect( $created['checkout_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Chain2Pay checkout, URL returned by the Chain2Pay API
        exit;
    }

    /**
     * Chain2Pay's callback (?edd-listener=chain2pay). The signed URL identifies the order and the
     * amount; the Chain2Pay API then confirms that the payment is paid before the order completes.
     */
    public static function listen() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server call, authenticated by the signed token below
        if ( ! isset( $_GET['edd-listener'] ) || self::LISTENER !== $_GET['edd-listener'] ) {
            return;
        }
        $order_id   = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
        $amount     = isset( $_GET['amt'] ) ? sanitize_text_field( wp_unslash( $_GET['amt'] ) ) : '';
        $currency   = isset( $_GET['cur'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['cur'] ) ) ) : '';
        $token      = isset( $_GET['c2p_token'] ) ? sanitize_text_field( wp_unslash( $_GET['c2p_token'] ) ) : '';
        $payment_id = isset( $_GET['chain2pay_order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['chain2pay_order_id'] ) ) : '';
        // phpcs:enable

        if ( $order_id < 1 || ! is_numeric( $amount ) || '' === $currency ) {
            self::respond( 400, 'bad request' );
        }
        $ref = Chain2Pay_EDD_Core::orderRef( 'edd', $order_id, $amount, $currency );
        if ( ! Chain2Pay_EDD_Core::checkToken( self::secret(), $ref, $token ) ) {
            self::respond( 403, 'invalid token' );
        }
        $order = edd_get_order( $order_id );
        if ( ! $order || 0 !== strpos( (string) $order->gateway, self::PREFIX ) ) {
            self::respond( 404, 'unknown order' );
        }
        if ( ! self::lock( $order_id ) ) {
            self::respond( 503, 'busy, retry later' );
        }
        register_shutdown_function( array( __CLASS__, 'unlock' ), $order_id );
        $order = self::fresh_order( $order_id ); // inside the lock
        if ( ! $order ) {
            self::respond( 404, 'unknown order' );
        }
        if ( 'complete' === $order->status ) {
            self::respond( 200, '*ok*' );
        }

        $stored = edd_get_order_meta( $order_id, '_chain2pay_payment_id', false );
        $check  = Chain2Pay_EDD_Core::verifyPaid( (string) self::opt( 'api_key' ), $payment_id, $ref, (float) $amount, $currency, is_array( $stored ) ? $stored : array(), 'EDD/' . self::VERSION );

        if ( Chain2Pay_EDD_Core::UNAVAILABLE === $check['result'] ) {
            edd_debug_log( 'Chain2Pay: callback for order ' . $order_id . ' could not be verified yet: ' . $check['reason'] );
            self::respond( 503, 'verification unavailable, retry later' );
        }
        if ( Chain2Pay_EDD_Core::NOT_PAID === $check['result'] ) {
            self::respond( 409, 'payment not paid' );
        }
        if ( Chain2Pay_EDD_Core::MISMATCH === $check['result'] ) {
            self::note_once( $order_id, 'mismatch_' . md5( $payment_id ), 'Chain2Pay: a callback was ignored because ' . $check['reason'] . '. The order was not completed.' );
            self::respond( 400, 'payment does not match this order' );
        }

        $payment = $check['payment'];
        $summary = Chain2Pay_EDD_Core::describe( $payment );
        $txid    = Chain2Pay_EDD_Core::transactionId( $payment, $payment_id );

        if ( (float) $amount + 0.01 < (float) $order->total ) {
            self::note_once( $order_id, 'changed_' . md5( $payment_id ), 'Chain2Pay: ' . $amount . ' ' . $currency . ' was paid, but the order total is now ' . Chain2Pay_EDD_Core::amount( $order->total ) . ' ' . strtoupper( (string) $order->currency ) . '. The order was not completed: check it before releasing it. ' . $summary );
            self::respond( 200, '*ok*' );
        }
        if ( ! in_array( $order->status, self::OPEN_STATUSES, true ) ) {
            self::note_once( $order_id, 'closed_' . md5( $payment_id ), 'Chain2Pay: a payment was received while the order is "' . $order->status . '", so it was not applied. Refund the buyer if needed. ' . $summary );
            self::respond( 200, '*ok*' );
        }

        edd_add_order_transaction(
            array(
                'object_id'      => (int) $order_id,
                'object_type'    => 'order',
                'transaction_id' => $txid,
                'gateway'        => (string) $order->gateway,
                'status'         => 'complete',
                'total'          => (float) $amount,
            )
        );
        edd_insert_payment_note( $order_id, 'Chain2Pay: payment confirmed. ' . $summary );
        edd_update_order_status( $order_id, 'complete' );
        self::respond( 200, '*ok*' );
    }

    /** Easy Digital Downloads 3 is loaded (also covers EDD Pro, whose plugin folder has another name). */
    private static function edd_ready() {
        return function_exists( 'edd_get_order' ) && function_exists( 'edd_add_order_transaction' ) && function_exists( 'edd_build_order' );
    }

    public static function missing_edd_notice() {
        /* translators: %s: plugin name */
        echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( '%s needs Easy Digital Downloads 3.0 or newer.', 'chain2pay-edd' ), self::NAME ) ) . '</p></div>';
    }

    /** On activation: create the secret that signs callback URLs, before any checkout needs it. */
    public static function activate() {
        self::secret();
    }

    private static function opt( $key, $default = '' ) {
        $value = function_exists( 'edd_get_option' ) ? edd_get_option( self::PREFIX . $key, $default ) : $default;

        return is_string( $value ) ? trim( $value ) : $value;
    }

    /** This site's secret for callback tokens, kept in the database and never shown. */
    private static function secret() {
        $secret = get_option( self::SECRET );
        if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
            return $secret;
        }
        // add_option() does nothing when another request has just stored a secret, so both use the same one.
        add_option( self::SECRET, bin2hex( random_bytes( 32 ) ), '', 'no' );
        wp_cache_delete( self::SECRET, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        $secret = get_option( self::SECRET );
        if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( self::SECRET, $secret, false );
        }

        return $secret;
    }

    /** The order as stored now: this request's cached copies are dropped first. */
    private static function fresh_order( $order_id ) {
        wp_cache_delete( (int) $order_id, 'edd-orders' );
        wp_cache_delete( 'last_changed', 'edd-orders' );
        wp_cache_delete( 'last_changed', 'edd-order_transactions' );
        wp_cache_delete( (int) $order_id, 'edd_order_meta' );

        return edd_get_order( $order_id );
    }

    /** Adds an order note once, however many times Chain2Pay repeats the same callback. */
    private static function note_once( $order_id, $key, $note ) {
        $meta = '_chain2pay_note_' . $key;
        if ( '' !== (string) edd_get_order_meta( $order_id, $meta, true ) ) {
            return;
        }
        edd_update_order_meta( $order_id, $meta, '1' );
        edd_insert_payment_note( $order_id, $note );
    }

    private static function respond( $code, $text ) {
        status_header( $code );
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo esc_html( $text );
        exit;
    }

    /** One callback (or checkout resuming the order) per order at a time. */
    private static function lock_name( $order_id ) {
        global $wpdb;

        return 'chain2pay_edd_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . '_' . (int) $order_id;
    }

    private static function lock( $order_id ) {
        global $wpdb;

        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 20)', self::lock_name( $order_id ) ) );
    }

    public static function unlock( $order_id ) {
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $order_id ) ) );
    }

    /**
     * Checkout: builds the order. When the buyer resumes an earlier order, it is rebuilt only while no
     * callback for it is being processed, so a payment is never checked against a changing cart.
     */
    private static function build_order( array $order_data ) {
        $resume = (int) EDD()->session->get( 'edd_resume_payment' );
        if ( $resume > 0 && ! self::lock( $resume ) ) {
            edd_set_error( 'chain2pay_busy', __( 'A payment for this order is being confirmed. Please wait a moment and refresh the page.', 'chain2pay-edd' ) );

            return 0;
        }
        $order_id = edd_build_order( $order_data );
        if ( $resume > 0 ) {
            self::unlock( $resume );
        }

        return (int) $order_id;
    }
}

register_activation_hook( __FILE__, array( 'Chain2Pay_EDD', 'activate' ) );
add_action( 'plugins_loaded', array( 'Chain2Pay_EDD', 'boot' ) );
