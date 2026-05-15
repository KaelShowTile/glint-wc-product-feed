<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GW_Feed_Batch {

    public static function init() {
        add_action( 'gw_feed_start_generation', array( __CLASS__, 'start_generation' ), 10, 1 );
        add_action( 'gw_feed_process_chunk', array( __CLASS__, 'process_chunk' ), 10, 4 );
        add_action( 'gw_feed_finish_generation', array( __CLASS__, 'finish_generation' ), 10, 1 );
        
        add_action( 'gw_feed_scheduled_run', array( __CLASS__, 'scheduled_run' ), 10, 1 );
    }

    public static function schedule_feed( $post_id ) {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) return;
        
        // Unschedule existing recurring action
        as_unschedule_all_actions( 'gw_feed_scheduled_run', array( $post_id ), 'gw-feed' );
        
        $status = get_post_status( $post_id );
        if ( $status !== 'publish' ) return;
        
        $refresh_period = get_post_meta( $post_id, '_gw_refresh_period', true );
        $refresh_time = get_post_meta( $post_id, '_gw_refresh_time', true );
        if ( ! $refresh_time ) $refresh_time = '00:00';
        
        // Calculate timestamp for next run
        // We use current date and the specified time. If the time has passed today, move to tomorrow.
        $time_parts = explode(':', $refresh_time);
        $h = intval($time_parts[0]);
        $m = intval($time_parts[1]);
        
        $now = current_time('timestamp');
        $next_run = mktime($h, $m, 0, date('m', $now), date('d', $now), date('Y', $now));
        
        if ( $next_run <= $now ) {
            // Already passed today, so add the interval first
            if ( $refresh_period == 'Daily' ) {
                $next_run = strtotime('+1 day', $next_run);
            } elseif ( $refresh_period == 'Weekly' ) {
                $next_run = strtotime('+1 week', $next_run);
            } elseif ( $refresh_period == 'Monthly' ) {
                $next_run = strtotime('+1 month', $next_run);
            }
        }
        
        // Convert to UTC timestamp for Action Scheduler
        $gmt_offset = get_option('gmt_offset') * HOUR_IN_SECONDS;
        $next_run_utc = $next_run - $gmt_offset;
        
        $interval_seconds = DAY_IN_SECONDS;
        if ( $refresh_period == 'Weekly' ) $interval_seconds = WEEK_IN_SECONDS;
        if ( $refresh_period == 'Monthly' ) $interval_seconds = 30 * DAY_IN_SECONDS; // approximate
        
        as_schedule_recurring_action( $next_run_utc, $interval_seconds, 'gw_feed_scheduled_run', array( $post_id ), 'gw-feed' );
    }

    public static function scheduled_run( $post_id ) {
        if ( get_post_status( $post_id ) === 'publish' ) {
            as_enqueue_async_action( 'gw_feed_start_generation', array( $post_id ), 'gw-feed' );
        }
    }

    public static function start_generation( $post_id ) {
        update_post_meta( $post_id, '_gw_feed_status', 'processing' );
        
        $exclude_cats = get_post_meta( $post_id, '_gw_exclude_categories', true );
        if ( ! is_array( $exclude_cats ) ) $exclude_cats = array();

        $args = array(
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        );

        if ( ! empty( $exclude_cats ) ) {
            $args['category'] = array();
            foreach ( $exclude_cats as $cat_id ) {
                $term = get_term_by( 'id', $cat_id, 'product_cat' );
                if ( $term ) {
                    // wc_get_products exclude category expects array of slugs if using tax_query, 
                    // actually wc_get_products 'category' param includes, not excludes.
                    // We need a custom tax query to EXCLUDE.
                    // Wait, wc_get_products doesn't have an easy 'exclude_category'. We use tax_query directly.
                }
            }
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $exclude_cats,
                    'operator' => 'NOT IN',
                ),
            );
        }

        // Exclude variations as per requirement: "只导出父产品"
        $args['type'] = array('simple', 'variable', 'grouped', 'external');

        $product_ids = wc_get_products( $args );
        $total_products = count( $product_ids );
        
        update_post_meta( $post_id, '_gw_feed_total', $total_products );
        update_post_meta( $post_id, '_gw_feed_processed', 0 );
        
        if ( $total_products == 0 ) {
            update_post_meta( $post_id, '_gw_feed_status', 'completed' );
            return;
        }

        // Delete old temp file
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/glint-wc-product-feed/feed-' . $post_id . '-temp.xml';
        if ( file_exists( $temp_file ) ) {
            unlink( $temp_file );
        }

        // Split into chunks of 20
        $chunks = array_chunk( $product_ids, 20 );
        $total_chunks = count( $chunks );

        foreach ( $chunks as $index => $chunk ) {
            as_enqueue_async_action( 'gw_feed_process_chunk', array( $post_id, $chunk, $index + 1, $total_chunks ), 'gw-feed' );
        }
        
        // Enqueue finish action after all chunks
        as_enqueue_async_action( 'gw_feed_finish_generation', array( $post_id ), 'gw-feed' );
    }

    public static function process_chunk( $post_id, $product_ids, $chunk_index, $total_chunks ) {
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/glint-wc-product-feed/feed-' . $post_id . '-temp.xml';
        
        $xml_content = '';
        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            
            $xml_content .= GW_Feed_Generator::generate_product_xml( $product, $post_id );
        }
        
        // Append to temp file
        file_put_contents( $temp_file, $xml_content, FILE_APPEND );
        
        // Update processed count
        $processed = (int) get_post_meta( $post_id, '_gw_feed_processed', true );
        $processed += count( $product_ids );
        update_post_meta( $post_id, '_gw_feed_processed', $processed );
    }

    public static function finish_generation( $post_id ) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/glint-wc-product-feed';
        $temp_file = $base_dir . '/feed-' . $post_id . '-temp.xml';
        $final_file = $base_dir . '/feed-' . $post_id . '.xml';
        
        $site_title = get_bloginfo( 'name' );
        $site_url = get_bloginfo( 'url' );
        $site_desc = get_bloginfo( 'description' );
        
        $header = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $header .= '<rss xmlns:g="http://base.google.com/ns/1.0" xmlns:c="http://base.google.com/cns/1.0" version="2.0">' . "\n";
        $header .= '  <channel>' . "\n";
        $header .= '    <title><![CDATA[' . esc_html( $site_title ) . ']]></title>' . "\n";
        $header .= '    <link><![CDATA[' . esc_url( $site_url ) . ']]></link>' . "\n";
        $header .= '    <description><![CDATA[' . esc_html( $site_desc ) . ']]></description>' . "\n";
        
        $footer = '  </channel>' . "\n";
        $footer .= '</rss>';
        
        if ( file_exists( $temp_file ) ) {
            $items = file_get_contents( $temp_file );
            file_put_contents( $final_file, $header . $items . $footer );
            unlink( $temp_file );
        } else {
            // Empty feed
            file_put_contents( $final_file, $header . $footer );
        }
        
        update_post_meta( $post_id, '_gw_feed_status', 'completed' );
    }
}
