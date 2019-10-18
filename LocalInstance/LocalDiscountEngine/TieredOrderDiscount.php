<?php




///  Why does this have more than one class it it??
///
///    ANSWER: There are dozens of attributes and one class for each
///      we are avoiding dozens of PHP require() calls which are expensive



/// ATTR: Tiered ORDER Level Discount
class LocalDiscountEngine__TieredOrderDiscountAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;

	public static function defaultValue() { return (object) array('tiers' => [(object)['minimum' => null, 'type' => 'dollar', 'value' => 0]]); }

	public function dataPassesValidation() {
		if ( ! isset($this->attr_data->tiers) || ! is_array($this->attr_data->tiers) ) { return false; }

		foreach ( $this->attr_data->tiers as $tier ) {
			if ( ! isset(   $tier->type )
				|| ! isset( $tier->value ) || ! is_numeric( $tier->value)
				) {
				return false;
			}
		}
		return true;
	}

	public function deferralCheck() {
		if ( $defer = $this->engine->deferUntilSamePriority('order_level_discounts', $this->discount_id) ) { return $defer; }
		return false; // Don't defer now
	}
	public function getTierTypeAndValue() {
		$ord_subtotal = $this->__getOrderSubtotalWithoutDiscount();
		$return = [false,false];
		foreach ( $this->attr_data->tiers as $tier ) {
			if ( empty($tier->minimum) || $ord_subtotal >= $tier->minimum ) {
				$return = [$tier->type,$tier->value];
			}
		}
		return $return;
	}
	public function optInToCalculate($line_id, $line) {
		list($type,$value) = $this->getTierTypeAndValue();
		if ( $type === false ) { return false; }

		///  Are we really discounting???
		if ( $type == 'dollar' ) {
			$discounting = \Models\DiscountEngine::price_round($value);
		}
		else { // percent
			$discounting = \Models\DiscountEngine::price_round($this->engine->getLineExtTotalWithoutDiscount($line_id) * ($value / 100));
		}
		if ( $discounting == 0 ) { return false; }

		if ( $type == 'dollar' ) {
			return self::$LOCAL_CALC_OPTIN_DOLLAR_DISCOUNT;
		}
		else { // percent
			return $value == 100 ? self::$LOCAL_CALC_OPTIN_100_PERCENT_OFF : self::$LOCAL_CALC_OPTIN_PERCENT_DISCOUNT;
		}
	}
	///  For ability to override for another child-attr
	protected function __getOrderSubtotalWithDiscount() { return $this->engine->getOrderSubtotalWithDiscount(); }
	protected function __getOrderSubtotalWithoutDiscount() { return $this->engine->getOrderSubtotalWithoutDiscount(); }
	public function initCalculate($lines_included_in_calculate) {
		list($type,$value) = $this->getTierTypeAndValue();
		if ( $type === false ) { return false; }

		$this->workArea()->line_discount_ratios = array();
		$this->workArea()->largest_line_id = null;
		$largest_total = null;
		$order_subtotal = $this->__getOrderSubtotalWithDiscount();
		foreach( $lines_included_in_calculate as $line_id ) {
			$line_total = $this->engine->getLineExtTotalWithDiscount($line_id);
			$this->workArea()->line_discount_ratios[$line_id] = $line_total / $order_subtotal;
			///  Type=DOLLAR : Prepare for end sum total
			if ( $type == 'dollar' && is_null($largest_total) || $line_total > $largest_total ) {
				$this->workArea()->largest_line_id = $line_id;
				$largest_total = $line_total;
			}
		}
	}
	public function calculateLine($line_id, $line) {
		list($type,$value) = $this->getTierTypeAndValue();
		if ( $type === false ) { return false; }

		if ( $type == 'dollar' ) {
			$this->engine->setLineDiscount(
				$line_id,
				$this->discount_id,
				\Models\DiscountEngine::price_round($value * $this->workArea()->line_discount_ratios[$line_id])
				);
		}
		else { // percent
			$this->engine->setLineDiscount(
				$line_id,
				$this->discount_id,
				\Models\DiscountEngine::price_round($line['price'] * ($value / 100)) * $line['qty']
				);
		}
		return 'PROCESSED';
	}

	protected function __getEndSumShouldEqualAmount() {
		list($type,$value) = $this->getTierTypeAndValue();
		if ( $type === false ) { return false; }

		return $value;
	}
	public function cleanupCalculate() {
		list($type,$value) = $this->getTierTypeAndValue();
		if ( $type === false ) { return false; }

 		///  Type=DOLLAR : Prepare for end sum total
		if ( $type == 'dollar'
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


/// ATTR: Tiered Master ORDER Level Discount
class LocalDiscountEngine__TieredMasterOrderDiscountAttribute extends DiscountEngine__TieredOrderDiscountAttribute {
	///  For ability to override for another child-attr
	protected function __getOrderSubtotalWithDiscount() { return $this->engine->getEnvironment()->master_order_subtotal; }
	protected function __getOrderSubtotalWithoutDiscount() { return $this->engine->getEnvironment()->master_order_subtotal; }
	protected function __getEndSumShouldEqualAmount() {
		list($type,$value) = $this->getTierTypeAndValue();
		if ( $type === false ) { return false; }

		//  If Last Recipient, use the rest...
		if ( $this->engine->getEnvironment()->is_last_recipient ) {
			return \Models\DiscountEngine::price_round(
				$value
				- $this->engine->getEnvironment()->master_order_discount
				);
		}

		return \Models\DiscountEngine::price_round(
			$value
			* (
				$this->engine->getOrderSubtotalWithoutDiscount()
				/ $this->engine->getEnvironment()->master_order_subtotal
				)
			);
	}
}
