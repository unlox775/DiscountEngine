<?php




///  Why does this have more than one class it it??
///
///    ANSWER: There are dozens of attributes and one class for each
///      we are avoiding dozens of PHP require() calls which are expensive






/// ATTR: ORDER Level Discount
class DiscountEngine__OrderDiscountAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;

	public static function defaultValue() { return (object) array('type' => 'dollar', 'value' => 0); }

	public function dataPassesValidation() {
		if ( ! isset(   $this->attr_data->type ) 
			|| ! isset( $this->attr_data->value ) || ! is_numeric( $this->attr_data->value)
			) { 
			return false;
		}
		return true;
	}

	public function deferralCheck() {
		if ( $defer = $this->engine->deferUntilSamePriority('order_level_discounts', $this->discount_id) ) { return $defer; }
		return false; // Don't defer now
	}
	public function optInToCalculate($line_id, $line) {
		///  Are we really discounting???
		if ( $this->attr_data->type == 'dollar' ) { 
			$discounting = \Models\DiscountEngine::price_round($this->attr_data->value);
		}
		else { // percent
			$discounting = \Models\DiscountEngine::price_round($this->engine->getLineExtTotalWithoutDiscount($line_id) * ($this->attr_data->value / 100));
		}
		if ( $discounting == 0 ) { return false; }

		if ( $this->attr_data->type == 'dollar' ) { 
			return self::$LOCAL_CALC_OPTIN_DOLLAR_DISCOUNT;
		}
		else { // percent
			return $this->attr_data->value == 100 ? self::$LOCAL_CALC_OPTIN_100_PERCENT_OFF : self::$LOCAL_CALC_OPTIN_PERCENT_DISCOUNT;
		}
	}
	///  For ability to override for another child-attr
	protected function __getOrderSubtotalWithDiscount() {
		return $this->engine->getOrderSubtotalWithDiscount();
	}
	public function initCalculate($lines_included_in_calculate) {
		$this->workArea()->line_discount_ratios = array();
		$this->workArea()->largest_line_id = null;
		$largest_total = null;
		$order_subtotal = $this->__getOrderSubtotalWithDiscount();
		foreach( $lines_included_in_calculate as $line_id ) {
			$line_total = $this->engine->getLineExtTotalWithDiscount($line_id);
			$this->workArea()->line_discount_ratios[$line_id] = $line_total / $order_subtotal;
			///  Type=DOLLAR : Prepare for end sum total
			if ( $this->attr_data->type == 'dollar' && is_null($largest_total) || $line_total > $largest_total ) { 
				$this->workArea()->largest_line_id = $line_id;
				$largest_total = $line_total;
			}
		}
	}
	public function calculateLine($line_id, $line) {
		if ( $this->attr_data->type == 'dollar' ) { 
			$this->engine->setLineDiscount(
				$line_id,
				$this->discount_id,
				\Models\DiscountEngine::price_round($this->attr_data->value * $this->workArea()->line_discount_ratios[$line_id])
				);
		}
		else { // percent
			$this->engine->setLineDiscount(
				$line_id,
				$this->discount_id,
				\Models\DiscountEngine::price_round($this->engine->getLineExtTotalWithDiscount($line_id) * ($this->attr_data->value / 100))
				);
		}
		return 'PROCESSED';
	}

	protected function __getEndSumShouldEqualAmount() {
		return $this->attr_data->value;
	}
	public function cleanupCalculate() {
 		///  Type=DOLLAR : Prepare for end sum total
		if ( $this->attr_data->type == 'dollar'
			&& ! empty( $this->workArea()->largest_line_id ) 
			) { 
			$order = $this->engine->getOrderDiscount($this->discount_id);
			$us = \Models\DiscountEngine::price_round($this->__getEndSumShouldEqualAmount());
			if ( $order != $us ) {
				$this->engine->setLineDiscount(
					$this->workArea()->largest_line_id,
					$this->discount_id,
					\Models\DiscountEngine::price_round(
						$this->engine->getLineDiscount($this->workArea()->largest_line_id,$this->discount_id)
						+ ($us - $order)
						)
					);
			}
		}
	}
}


/// ATTR: ORDER Level Discount
class DiscountEngine__MasterOrderDiscountAttribute extends DiscountEngine__OrderDiscountAttribute {
	///  For ability to override for another child-attr
	protected function __getOrderSubtotalWithDiscount() {
		return $this->engine->getEnvironment()->master_order_subtotal;
	}
	protected function __getEndSumShouldEqualAmount() {
		//  If Last Recipient, use the rest...
		if ( $this->engine->getEnvironment()->is_last_recipient ) {
			return \Models\DiscountEngine::price_round(
				$this->attr_data->value
				- $this->engine->getEnvironment()->master_order_discount
				);
		}

		return \Models\DiscountEngine::price_round(
			$this->attr_data->value
			* (
				$this->engine->getOrderSubtotalWithoutDiscount()
				/ $this->engine->getEnvironment()->master_order_subtotal
				)
			);
	}
}
