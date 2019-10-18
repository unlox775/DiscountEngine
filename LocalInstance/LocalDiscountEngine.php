<?php

namespace Models;

require_once('stark/DiscountEngine.php');
require_once('models/LocalDiscountEngine/AttributeBase.php');
require_once('models/LocalDiscountEngine/CommonAttributes.php');

class LocalDiscountEngine extends \Stark__DiscountEngine {
	protected static $__attribute_require_matrix = array(
		// 'models/LocalDiscountEngine/CommonAttributes.php' => array('is_bogo'),

		'models/LocalDiscountEngine/OrderDiscount.php' => array('order_discount','master_order_discount'),
		'models/LocalDiscountEngine/TieredOrderDiscount.php' => array('tiered_order_discount','tiered_master_order_discount'),
		);
	protected static $__priority_codes = array(
		// Code                    // Priority (higher means it will wait longest, until after lower priorities have run)
		'stacking_restricted' => 5,
		'line_level_discounts' => 7,
		'order_level_discounts' => 10,
		);

	protected function __prepareStacked() {
		$this->__order_data->__engine_phase = 'stacked';

		$this->__order_data->__stacked_discount_sets = array(
			'default' => array(),
			);
		foreach ($this->__discounts as $discount_id => $d_obj ) {
			$this->__order_data->__stacked_discount_sets['default'][] = $discount_id;
		}

		// if ( ! $this->__quote_mode ) {
		// 	$this->__order_data->__stacked_discount_sets['reverse'] = array_reverse($this->__order_data->__stacked_discount_sets['default']);

		// 	$this->__order_data->__stacked_discount_sets['one-less'] = $this->__order_data->__stacked_discount_sets['default'];
		// 	array_shift($this->__order_data->__stacked_discount_sets['one-less']);

		// 	$this->__order_data->__stacked_discount_sets['one-less-rev'] = $this->__order_data->__stacked_discount_sets['reverse'];
		// 	array_shift($this->__order_data->__stacked_discount_sets['one-less-rev']);
		// }

		return true;
	}


	public static function price_round($num) { return \App::priceRound($num); }

	public function debug($lvl, $indent, $message, $detail = null, $color_override = false) {
		if ( $this->debug != true || $this->debug < $lvl ) { return; }
		$tr = debug_backtrace();
		$level = 0;

		echo (
			'<div style="clear:both;" class="discount-debug--'. $this->__debug_class .'" title="File: '. $tr[$level]['file'] .", line ". $tr[$level]['line'] .'">'
			. '<span style="float: left; font-weight: bold; color: '. ( $color_override ?: $this->rotateDebugColor(true)) .'">DISCOUNT-ENGINE:</span>'
			. ' <pre style="float: left; background: #ddd; padding: 3px; margin: -3px 0 5px '. ($indent * 20) .'px;">'. $message . ($detail ? "\n\n".var_export($detail,true) : '') .'</pre>'
			. '</div><div style="clear:both;"></div>'
			);
	}

}
