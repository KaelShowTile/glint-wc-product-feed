<?php
/**
 * Plugin Name: Glint WooCommerce Product Feed
 * Description: Generates Google Merchant Center product feeds from WooCommerce.
 * Version: 1.0.0
 * Author: Glint
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'GW_FEED_VERSION', '1.0.0' );
define( 'GW_FEED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GW_FEED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 */
class Glint_WC_Product_Feed {
    
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once GW_FEED_PLUGIN_DIR . 'includes/class-gw-feed-admin.php';
        require_once GW_FEED_PLUGIN_DIR . 'includes/class-gw-feed-batch.php';
        require_once GW_FEED_PLUGIN_DIR . 'includes/class-gw-feed-generator.php';
    }

    private function init_hooks() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
        
        // Schedule Action Scheduler checks if needed, though WP handles it.
        // Also register activation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
    }

    public function init_classes() {
        GW_Feed_Admin::init();
        GW_Feed_Batch::init();
    }

    public function register_post_type() {
        $labels = array(
            'name'               => _x( 'Product Feeds', 'post type general name', 'gw-feed' ),
            'singular_name'      => _x( 'Product Feed', 'post type singular name', 'gw-feed' ),
            'menu_name'          => _x( 'Product Feeds', 'admin menu', 'gw-feed' ),
            'name_admin_bar'     => _x( 'Product Feed', 'add new on admin bar', 'gw-feed' ),
            'add_new'            => _x( 'Add New', 'feed', 'gw-feed' ),
            'add_new_item'       => __( 'Add New Product Feed', 'gw-feed' ),
            'new_item'           => __( 'New Product Feed', 'gw-feed' ),
            'edit_item'          => __( 'Edit Product Feed', 'gw-feed' ),
            'view_item'          => __( 'View Product Feed', 'gw-feed' ),
            'all_items'          => __( 'All Product Feeds', 'gw-feed' ),
            'search_items'       => __( 'Search Product Feeds', 'gw-feed' ),
            'parent_item_colon'  => __( 'Parent Product Feeds:', 'gw-feed' ),
            'not_found'          => __( 'No product feeds found.', 'gw-feed' ),
            'not_found_in_trash' => __( 'No product feeds found in Trash.', 'gw-feed' )
        );

        $args = array(
            'labels'             => $labels,
            'description'        => __( 'Description.', 'gw-feed' ),
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => 56,
            'menu_icon'          => 'dashicons-rss',
            'supports'           => array( 'title' )
        );

        register_post_type( 'gw_product_feed', $args );
    }

    public function activate() {
        $this->register_post_type();
        flush_rewrite_rules();
        
        // Create upload directory if not exists
        $upload_dir = wp_upload_dir();
        $feed_dir = $upload_dir['basedir'] . '/glint-wc-product-feed';
        if ( ! file_exists( $feed_dir ) ) {
            wp_mkdir_p( $feed_dir );
        }
        
        // Ensure Action Scheduler is active (it's part of WooCommerce)
    }
}

// Initialize the plugin
function glint_wc_product_feed() {
    return Glint_WC_Product_Feed::instance();
}

glint_wc_product_feed();
