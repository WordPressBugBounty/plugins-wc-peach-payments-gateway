<?php

class pluginSupport extends WC_Peach_Payments{

	function __construct() {
		
	}
	
	function sequentialNumbers($order, $reversed){
		if(in_array('woocommerce-sequential-order-numbers-pro/woocommerce-sequential-order-numbers-pro.php', apply_filters('active_plugins', get_option('active_plugins')))){
			if($reversed == 1){
				$reverserd_id = $this->convertSequentialNumber($order, '_order_number_formatted');
				return $reverserd_id;
			}else{
				$formatted_id = $this->peach_formatted_order($order->get_id());
				return $formatted_id;
				//return $order->get_id();
			}

		}else if(in_array('wt-woocommerce-sequential-order-numbers/wt-advanced-order-number.php', apply_filters('active_plugins', get_option('active_plugins')))){
			if($reversed == 1){
				$reverserd_id = $this->convertSequentialNumber($order, '_order_number');
				return $reverserd_id;
			}else{
				$formatted_id = $this->peach_formatted_order($order->get_id());
				return $formatted_id;
				//return $order->get_order_number();
			}

		}else{
			if(isset($order) && is_object($order)){
				return $order->get_id();
			}else{
				return $order;
			}
		}
	}

	function peach_formatted_order($id){
		$formatted_order_id = $id;
		$order_meta = get_post_meta($id);
		
		if(isset($order_meta['_order_number_formatted'])){
			if(is_array($order_meta['_order_number_formatted'])){
				foreach($order_meta['_order_number_formatted'] as $formatted_id){
					$formatted_order_id = $formatted_id;
				}
			}else{
				$formatted_order_id = $order_meta['_order_number_formatted'];
			}
		}else if(isset($order_meta['_order_number'])){
			if(is_array($order_meta['_order_number'])){
				foreach($order_meta['_order_number'] as $formatted_id){
					$formatted_order_id = $formatted_id;
				}
			}else{
				$formatted_order_id = $order_meta['_order_number'];
			}
		}

		return $formatted_order_id;
	}
	
	function convertSequentialNumber($orderNum, $key){
		$query_args = array( 
            'numberposts' => 1,  
            'meta_key' => $key,  
            'meta_value' => $orderNum,  
            'post_type' => 'shop_order',  
            'post_status' => 'any',  
            'fields' => 'ids',  
 		); 
 
        $posts = get_posts( $query_args ); 
        list( $order_id ) = ! empty( $posts ) ? $posts : null; 
 
        // order was found 
        if ( $order_id !== null ) { 
            return $order_id; 
        }else{
			return $orderNum;
		} 
	}
	
}