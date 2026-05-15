<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GW_Feed_Admin {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_gw_product_feed', array( __CLASS__, 'save_meta_boxes' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_gw_feed_get_wc_fields', array( __CLASS__, 'ajax_get_wc_fields' ) );
        add_action( 'wp_ajax_gw_feed_get_categories', array( __CLASS__, 'ajax_get_categories' ) );
        add_action( 'wp_ajax_gw_feed_get_taxonomies', array( __CLASS__, 'ajax_get_taxonomies' ) );
        add_action( 'wp_ajax_gw_feed_get_acf_fields', array( __CLASS__, 'ajax_get_acf_fields' ) );
        add_action( 'wp_ajax_gw_feed_generate_now', array( __CLASS__, 'ajax_generate_now' ) );
        add_action( 'wp_ajax_gw_feed_check_progress', array( __CLASS__, 'ajax_check_progress' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'gw_feed_settings',
            __( 'Feed Settings', 'gw-feed' ),
            array( __CLASS__, 'render_settings_meta_box' ),
            'gw_product_feed',
            'normal',
            'high'
        );
    }

    public static function render_settings_meta_box( $post ) {
        wp_nonce_field( 'gw_feed_save_data', 'gw_feed_nonce' );

        $country = get_post_meta( $post->ID, '_gw_country', true ) ?: 'AU';
        $exclude_categories = get_post_meta( $post->ID, '_gw_exclude_categories', true ) ?: array();
        $refresh_period = get_post_meta( $post->ID, '_gw_refresh_period', true ) ?: 'Daily';
        $refresh_time = get_post_meta( $post->ID, '_gw_refresh_time', true ) ?: '00:00';
        $field_mappings = get_post_meta( $post->ID, '_gw_field_mappings', true ) ?: '{}';

        // Get upload dir for feed url
        $upload_dir = wp_upload_dir();
        $feed_url = $upload_dir['baseurl'] . '/glint-wc-product-feed/feed-' . $post->ID . '.xml';
        
        $categories = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );

        ?>
        <div class="gw-feed-settings-wrap">
            <div class="gw-feed-info">
                <p><strong>Feed URL:</strong> <a href="<?php echo esc_url($feed_url); ?>" target="_blank"><?php echo esc_url($feed_url); ?></a></p>
                <p>
                    <button type="button" class="button button-primary" id="gw-generate-now" data-post-id="<?php echo $post->ID; ?>">Generate Now</button>
                    <span id="gw-generate-status" style="margin-left: 10px; font-weight: bold;"></span>
                </p>
            </div>

            <table class="form-table">
                <tr>
                    <th><label for="gw_country">Country</label></th>
                    <td>
                        <select name="gw_country" id="gw_country">
                            <option value="AU" <?php selected( $country, 'AU' ); ?>>Australia</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="gw_exclude_categories">Exclude Category</label></th>
                    <td>
                        <select name="gw_exclude_categories[]" id="gw_exclude_categories" multiple="multiple" style="width: 100%; max-width: 400px; height: 100px;">
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php echo in_array( $cat->term_id, (array)$exclude_categories ) ? 'selected' : ''; ?>>
                                    <?php echo esc_html( $cat->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="gw_refresh_period">Auto-refresh period</label></th>
                    <td>
                        <select name="gw_refresh_period" id="gw_refresh_period">
                            <option value="Daily" <?php selected( $refresh_period, 'Daily' ); ?>>Daily</option>
                            <option value="Weekly" <?php selected( $refresh_period, 'Weekly' ); ?>>Weekly</option>
                            <option value="Monthly" <?php selected( $refresh_period, 'Monthly' ); ?>>Monthly</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="gw_refresh_time">Auto-refresh time</label></th>
                    <td>
                        <select name="gw_refresh_time_h" id="gw_refresh_time_h">
                            <?php 
                            $time_parts = explode(':', $refresh_time);
                            $h = isset($time_parts[0]) ? $time_parts[0] : '00';
                            $m = isset($time_parts[1]) ? $time_parts[1] : '00';
                            for($i=0; $i<24; $i++) {
                                $val = str_pad($i, 2, '0', STR_PAD_LEFT);
                                echo '<option value="'.$val.'" '.selected($h, $val, false).'>'.$val.'</option>';
                            }
                            ?>
                        </select> :
                        <select name="gw_refresh_time_m" id="gw_refresh_time_m">
                            <?php 
                            for($i=0; $i<60; $i+=5) { // maybe every 5 min? requirement says hour + minute, let's do all
                                $val = str_pad($i, 2, '0', STR_PAD_LEFT);
                                echo '<option value="'.$val.'" '.selected($m, $val, false).'>'.$val.'</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>

            <hr>
            <h3>Field Mappings</h3>
            <div id="gw-field-mappings-ui"></div>
            <textarea name="gw_field_mappings" id="gw_field_mappings_input" style="display:none;"><?php echo esc_textarea( is_string($field_mappings) ? $field_mappings : wp_json_encode($field_mappings) ); ?></textarea>
        </div>
        <?php
    }

    public static function save_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['gw_feed_nonce'] ) || ! wp_verify_nonce( $_POST['gw_feed_nonce'], 'gw_feed_save_data' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['gw_country'] ) ) {
            update_post_meta( $post_id, '_gw_country', sanitize_text_field( $_POST['gw_country'] ) );
        }
        if ( isset( $_POST['gw_exclude_categories'] ) ) {
            $exclude_cats = array_map( 'intval', $_POST['gw_exclude_categories'] );
            update_post_meta( $post_id, '_gw_exclude_categories', $exclude_cats );
        } else {
            delete_post_meta( $post_id, '_gw_exclude_categories' );
        }
        if ( isset( $_POST['gw_refresh_period'] ) ) {
            update_post_meta( $post_id, '_gw_refresh_period', sanitize_text_field( $_POST['gw_refresh_period'] ) );
        }
        if ( isset( $_POST['gw_refresh_time_h'] ) && isset( $_POST['gw_refresh_time_m'] ) ) {
            $time = sanitize_text_field( $_POST['gw_refresh_time_h'] ) . ':' . sanitize_text_field( $_POST['gw_refresh_time_m'] );
            update_post_meta( $post_id, '_gw_refresh_time', $time );
        }
        if ( isset( $_POST['gw_field_mappings'] ) ) {
            // Field mappings comes as JSON string from frontend
            $json = stripslashes( $_POST['gw_field_mappings'] );
            $mappings = json_decode( $json, true );
            if ( $mappings ) {
                update_post_meta( $post_id, '_gw_field_mappings', wp_json_encode( $mappings ) );
            }
        }
        
        // Reschedule cron if needed
        GW_Feed_Batch::schedule_feed( $post_id );
    }

    public static function enqueue_scripts( $hook ) {
        global $post;
        if ( $hook == 'post-new.php' || $hook == 'post.php' ) {
            if ( 'gw_product_feed' === $post->post_type ) {
                wp_enqueue_style( 'gw-feed-admin-css', GW_FEED_PLUGIN_URL . 'assets/css/admin.css', array(), GW_FEED_VERSION );
                wp_enqueue_script( 'gw-feed-admin-js', GW_FEED_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), GW_FEED_VERSION, true );
                wp_localize_script( 'gw-feed-admin-js', 'gw_feed_admin', array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'gw_feed_ajax' ),
                ) );
            }
        }
    }

    // AJAX Callbacks
    public static function ajax_get_wc_fields() {
        $fields = array(
            'id' => 'Product ID',
            'title' => 'Product Name',
            'description' => 'Description',
            'short_description' => 'Short Description',
            'link' => 'Product URL',
            'checkout_url' => 'Checkout URL',
            'price' => 'Price',
            'regular_price' => 'Regular Price',
            'sale_price' => 'Sale Price',
            'sku' => 'SKU',
            'stock_status' => 'Stock Status',
            'stock_quantity' => 'Stock Quantity',
            'weight' => 'Weight',
            'length' => 'Length',
            'width' => 'Width',
            'height' => 'Height',
            'image_link' => 'Main Image URL',
            'additional_images' => 'Gallery Images',
            'condition' => 'Condition (Always "new")',
            'product_type' => 'Product Type / Category Path'
        );

        // Add attributes
        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            $attribute_taxonomies = wc_get_attribute_taxonomies();
            if ( $attribute_taxonomies ) {
                foreach ( $attribute_taxonomies as $tax ) {
                    $fields['pa_' . $tax->attribute_name] = 'Attribute: ' . $tax->attribute_label;
                }
            }
        }

        wp_send_json_success( $fields );
    }

    public static function ajax_get_categories() {
        // user requested parent categories only
        $categories = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => 0,
        ) );
        $options = array();
        foreach ( $categories as $cat ) {
            $options[$cat->term_id] = $cat->name;
        }
        wp_send_json_success( $options );
    }

    public static function ajax_get_taxonomies() {
        $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
        $options = array();
        foreach ( $taxonomies as $tax ) {
            $options[$tax->name] = $tax->label;
        }
        wp_send_json_success( $options );
    }

    public static function ajax_get_acf_fields() {
        $options = array();
        if ( function_exists('acf_get_field_groups') ) {
            $groups = acf_get_field_groups();
            foreach ( $groups as $group ) {
                $fields = acf_get_fields( $group['key'] );
                if ( $fields ) {
                    foreach ( $fields as $field ) {
                        $options[$field['name']] = $field['label'] . ' (' . $field['name'] . ')';
                    }
                }
            }
        }
        wp_send_json_success( $options );
    }
    
    public static function ajax_generate_now() {
        check_ajax_referer( 'gw_feed_ajax', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( 'Invalid post ID' );
        }
        
        // Clear previous run data
        update_post_meta( $post_id, '_gw_feed_status', 'starting' );
        update_post_meta( $post_id, '_gw_feed_processed', 0 );
        update_post_meta( $post_id, '_gw_feed_total', 0 );
        
        // Schedule immediate start
        as_enqueue_async_action( 'gw_feed_start_generation', array( $post_id ), 'gw-feed' );
        
        wp_send_json_success( 'Generation started' );
    }
    
    public static function ajax_check_progress() {
        check_ajax_referer( 'gw_feed_ajax', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        $status = get_post_meta( $post_id, '_gw_feed_status', true );
        $processed = (int) get_post_meta( $post_id, '_gw_feed_processed', true );
        $total = (int) get_post_meta( $post_id, '_gw_feed_total', true );
        
        if ( $status === 'completed' ) {
            $msg = "Generation Complete. ({$total} products processed)";
        } else if ( $status === 'failed' ) {
            $msg = "Generation Failed.";
        } else if ( $total > 0 ) {
            $msg = "generating XML...({$processed}/{$total})";
        } else {
            $msg = "Starting...";
        }
        
        wp_send_json_success( array(
            'status' => $status,
            'message' => $msg
        ) );
    }
}
