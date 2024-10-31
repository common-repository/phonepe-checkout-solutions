<?php
function ppec_createCart($order){
    global $woocommerce;
    $woocommerce->cart->empty_cart();

    $variationAttributes = [];
    foreach ($order->get_items() as $item_id => $item) {
        $productId   = $item->get_product_id();
        $variationId = $item->get_variation_id();
        $quantity    = $item->get_quantity();

        $customData['item_id'] = $item_id;
        $product               = $item->get_product();
        if ($product->is_type('variation')) {
            $variation_attributes = $product->get_variation_attributes();
            foreach ($variation_attributes as $attribute_taxonomy => $term_slug) {
                $taxonomy                                 = str_replace('attribute_', '', $attribute_taxonomy);
                $value                                    = wc_get_order_item_meta($item_id, $taxonomy, true);
                $variationAttributes[$attribute_taxonomy] = $value;
            }
        }
        $woocommerce->cart->add_to_cart($productId, $quantity, $variationId, $variationAttributes, $customData);
    }
}