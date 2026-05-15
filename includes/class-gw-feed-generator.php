<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GW_Feed_Generator {

    public static function generate_product_xml( $product, $post_id ) {
        $mappings_json = get_post_meta( $post_id, '_gw_field_mappings', true );
        $mappings = json_decode( $mappings_json, true );
        
        if ( ! $mappings ) return '';

        $xml = "    <item>\n";

        // 1. Fixed Fields
        if ( ! empty( $mappings['fixed'] ) ) {
            foreach ( $mappings['fixed'] as $field ) {
                $value = self::get_field_value( $product, $field );
                $xml .= self::format_xml_node( $field['name'], $value );
            }
        }

        // 2. Custom Fields
        if ( ! empty( $mappings['custom'] ) ) {
            foreach ( $mappings['custom'] as $field ) {
                if ( empty( $field['name'] ) ) continue;
                $value = self::get_field_value( $product, $field );
                $xml .= self::format_xml_node( $field['name'], $value );
            }
        }

        // 3. Additional Images
        if ( ! empty( $mappings['additional_images'] ) ) {
            $added_images = 0;
            foreach ( $mappings['additional_images'] as $field ) {
                $value = self::get_field_value( $product, $field );
                // value could be an array of URLs or a single comma separated string
                if ( ! is_array( $value ) ) {
                    $value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
                }
                foreach ( $value as $img_url ) {
                    if ( $added_images >= 10 ) break;
                    $xml .= self::format_xml_node( 'g:additional_image_link', $img_url );
                    $added_images++;
                }
                if ( $added_images >= 10 ) break;
            }
        }

        // 4. Product Details
        if ( ! empty( $mappings['product_details'] ) ) {
            foreach ( $mappings['product_details'] as $detail ) {
                $section_name = self::get_field_value( $product, $detail['section_name'] );
                $attribute_name = self::get_field_value( $product, $detail['attribute_name'] );
                $attribute_value = self::get_field_value( $product, $detail['attribute_value'] );
                
                // Only output if attribute_value is not empty
                if ( ! empty( $attribute_value ) ) {
                    $xml .= "    <g:product_detail>\n";
                    $xml .= self::format_xml_node( 'g:section_name', $section_name, 2 );
                    $xml .= self::format_xml_node( 'g:attribute_name', $attribute_name, 2 );
                    $xml .= self::format_xml_node( 'g:attribute_value', $attribute_value, 2 );
                    $xml .= "    </g:product_detail>\n";
                }
            }
        }

        $xml .= "    </item>\n";
        return $xml;
    }

    private static function get_field_value( $product, $field ) {
        $type = isset($field['type']) ? $field['type'] : '';
        $source = isset($field['source']) ? $field['source'] : '';
        
        if ( empty( $type ) || $source === '' ) return '';

        switch ( $type ) {
            case 'static':
                return $source;

            case 'custom_meta':
                return get_post_meta( $product->get_id(), $source, true );

            case 'acf':
                if ( function_exists('get_field') ) {
                    return get_field( $source, $product->get_id() );
                }
                return '';

            case 'taxonomy':
                $terms = wp_get_post_terms( $product->get_id(), $source, array('fields' => 'names') );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    return implode( ', ', $terms );
                }
                return '';

            case 'category':
                // $source is the parent category ID.
                // We need to find if product has any child category of this parent.
                $parent_id = intval( $source );
                $terms = wp_get_post_terms( $product->get_id(), 'product_cat' );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    foreach ( $terms as $term ) {
                        // Check if this term's parent is the selected parent
                        // To be thorough, check if it's a descendant
                        $ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
                        if ( in_array( $parent_id, $ancestors ) || $term->parent == $parent_id ) {
                            return $term->name;
                        }
                    }
                }
                return '';

            case 'wc':
                // Check if it's an attribute
                if ( strpos( $source, 'pa_' ) === 0 ) {
                    return $product->get_attribute( $source );
                }
                
                switch ( $source ) {
                    case 'id': return $product->get_id();
                    case 'title': return $product->get_name();
                    case 'description': return $product->get_description();
                    case 'short_description': return $product->get_short_description();
                    case 'link': return $product->get_permalink();
                    case 'checkout_url': 
                        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : site_url('/checkout/');
                        $checkout_url = trailingslashit($checkout_url);
                        return $checkout_url . '?&add-to-cart=' . $product->get_id();
                    case 'price': return $product->get_price();
                    case 'regular_price': return $product->get_regular_price();
                    case 'sale_price': return $product->get_sale_price();
                    case 'sku': return $product->get_sku();
                    case 'stock_status': return $product->get_stock_status();
                    case 'stock_quantity': return $product->get_stock_quantity();
                    case 'weight': return $product->get_weight();
                    case 'length': return $product->get_length();
                    case 'width': return $product->get_width();
                    case 'height': return $product->get_height();
                    case 'image_link': 
                        $img_id = $product->get_image_id();
                        return $img_id ? wp_get_attachment_url( $img_id ) : '';
                    case 'additional_images':
                        $gallery_ids = $product->get_gallery_image_ids();
                        $urls = array();
                        if ( is_array( $gallery_ids ) ) {
                            foreach ( $gallery_ids as $id ) {
                                $url = wp_get_attachment_url( $id );
                                if ( $url ) {
                                    $urls[] = $url;
                                }
                            }
                        }
                        return $urls;
                    case 'condition': return 'new';
                    case 'product_type':
                        // Typically product_type in GMC is the category path
                        $terms = wp_get_post_terms( $product->get_id(), 'product_cat' );
                        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                            // get the deepest term
                            $deepest = $terms[0];
                            $max_depth = 0;
                            foreach ( $terms as $term ) {
                                $ancestors = get_ancestors( $term->term_id, 'product_cat' );
                                if ( count($ancestors) > $max_depth ) {
                                    $deepest = $term;
                                    $max_depth = count($ancestors);
                                }
                            }
                            $path = array( $deepest->name );
                            $ancestors = get_ancestors( $deepest->term_id, 'product_cat' );
                            foreach ( $ancestors as $anc_id ) {
                                $anc = get_term( $anc_id, 'product_cat' );
                                array_unshift( $path, $anc->name );
                            }
                            return implode( ' > ', $path );
                        }
                        return '';
                    default: return '';
                }
        }
        return '';
    }

    private static function format_xml_node( $node_name, $value, $indent_level = 1 ) {
        if ( $value === '' || $value === null ) return '';
        
        $indent = str_repeat( '    ', $indent_level );
        
        if ( is_string( $value ) ) {
            // Remove shortcodes
            $value = strip_shortcodes( $value );
            // Remove HTML tags and replace line breaks with spaces
            $value = wp_strip_all_tags( $value, true );
            // Clean up multiple spaces
            $value = preg_replace( '/\s+/', ' ', $value );
            $value = trim( $value );
        }
        
        // CDATA for string that might contain HTML or special chars, but for safety, just encode
        $value = htmlspecialchars( $value, ENT_XML1, 'UTF-8' );
        
        return "{$indent}<{$node_name}>{$value}</{$node_name}>\n";
    }
}
