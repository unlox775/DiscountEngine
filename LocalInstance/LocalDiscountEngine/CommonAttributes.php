<?php




///  Why does this have more than one class it it??
///
///    ANSWER: There are dozens of attributes and one class for each
///      we are avoiding dozens of PHP require() calls which are expensive






/// ATTR: Can Be Discounted Limit:
///   Things like gift cards cannot be discounted
class LocalDiscountEngine__CanBeDiscountedAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function defaultValue() { return (object) array(); }
	public static function getDisplayLabel() { return "Can Be Discounted (Not Shown in Admin, Auto-added to all discounts)"; }
	public function dataPassesValidation() { return true; }
	public function passesSimpleLineLimits($line_id, $line) {
		if ( ! $line['can_be_discounted'] ) { return false; }
		return 'PASSED';
	}
}


/// ATTR: Min Subotal:
///   Ignores discount until subtotal goes over the given amount
class LocalDiscountEngine__LimitMinSubtotalAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function defaultValue() { return (object) array('value' => 150); }
	public static function getDisplayLabel() { return "Minimum Subtotal"; }
	public function dataPassesValidation() {
		if ( isset( $this->attr_data->value ) && is_numeric( $this->attr_data->value ) ) {
			return true;
		}else{
			return false;
		}
	}
	public function passesOrderEnvironmentLimits($order_data) {
		if ( $this->engine->getOrderSubtotalWithoutDiscount() >= $this->attr_data->value ) {
			return 'PASSED';
		}
		return false;
	}
}


/// ATTR: Min Subotal:
///   Ignores discount until subtotal goes over the given amount
class LocalDiscountEngine__MasterOrderLimitMinSubtotalAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function defaultValue() { return (object) array('value' => 150); }
	public static function getDisplayLabel() { return "Minimum Subtotal on Master Order"; }
	public function dataPassesValidation() {
		if ( isset( $this->attr_data->value ) && is_numeric( $this->attr_data->value ) ) {
			return true;
		}else{
			return false;
		}
	}
	public function passesOrderEnvironmentLimits($order_data) {
		if ( $this->engine->getEnvironment()->master_order_subtotal >= $this->attr_data->value ) {
			return 'PASSED';
		}
		return false;
	}
}


/// ATTR: Order Has Item Limit:
///   Ignores discount unless order has the given item in cart
class LocalDiscountEngine__OrderHasItemLimitAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function defaultValue() { return (object) array('value' => 9001); }
	public static function getDisplayLabel() { return "Order Has Item in Cart"; }
	public function dataPassesValidation() {
		if ( isset( $this->attr_data->value ) && is_numeric( $this->attr_data->value ) ) {
			return true;
		}else{
			return false;
		}
	}
	public function passesOrderEnvironmentLimits($order_data) {
		foreach ( $this->engine->getAllLineIds() as $line_id ) {
			$line = $this->engine->getLineById($line_id);

			if ( $line['item_id'] == $this->attr_data->value ) {
				return 'PASSED';
			}
		}
		return false;
	}
}


/// ATTR: Order Has Item CATEGORY Limit:
///   Ignores discount unless order has the given item from This Category in cart
class LocalDiscountEngine__OrderHasItemCategoryLimitAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function defaultValue() { return (object) array('value' => 9001); }
	public static function getDisplayLabel() { return "Order Has Item in Cart"; }
	public function dataPassesValidation() {
		if ( isset( $this->attr_data->value ) ) {
			return true;
		}else{
			return false;
		}
	}
	public function passesOrderEnvironmentLimits($order_data) {
		foreach ( $this->engine->getAllLineIds() as $line_id ) {
			$line = $this->engine->getLineById($line_id);

			if ( in_array($this->attr_data->value, $line['categories']) ) {
				return 'PASSED';
			}
		}
		return false;
	}
}


/// ATTR: Item SKU has CATEGORY Limit:
///   Only applies to lines from this category
class LocalDiscountEngine__ItemSkuHasCategoryLimitAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function defaultValue() { return (object) array('value' => 9001); }
	public static function getDisplayLabel() { return "Order Has Item in Cart"; }
	public function dataPassesValidation() {
		if ( isset( $this->attr_data->value ) ) {
			return true;
		}else{
			return false;
		}
	}
	public function passesSimpleLineLimits($line_id, $line)     {
		if ( ! empty($line['categories'] )
			&& ( ( is_array($line['categories']) &&  in_array($this->attr_data->value, $line['categories']) )
				|| ( is_scalar($line['categories']) && $line['categories'] == $this->attr_data->value )
				)
			) {
			return 'PASSED';
		}
		return false;
	}
}



/// ATTR: LINE Level Discount
class LocalDiscountEngine__LineDiscountAttribute extends DiscountEngine__AttributeBase {
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
		if ( $defer = $this->engine->deferUntilSamePriority('line_level_discounts', $this->discount_id) ) { return $defer; }
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
	public function calculateLine($line_id, $line) {
		if ( $this->attr_data->type == 'dollar' ) {
			$this->engine->setLineDiscount(
				$line_id,
				$this->discount_id,
				\Models\DiscountEngine::price_round($this->attr_data->value * $line['qty'])
				);
		}
		else { // percent
			$this->engine->setLineDiscount(
				$line_id,
				$this->discount_id,
				\Models\DiscountEngine::price_round($line['price'] * ($this->attr_data->value / 100)) * $line['qty']
				// \Models\DiscountEngine::price_round($this->engine->getLineExtTotalWithDiscount($line_id) * ($this->attr_data->value / 100))
				);
		}
		return 'PROCESSED';
	}
}



/// ATTR: Stacking Restrictions:
///   Stops one discount to work on a line that is already discounted by another
class LocalDiscountEngine__NoDiscountStackingAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function requiresAttributes() { return array('frame_discount','order_discount'); } // values are OR.  For AND, use a sub-array
	public static function incompatibleWithAttributes() { return array('limit_platform[value=gson]' /*,'order_discount' */); }
	public static function defaultValue() { return (object) array(); }

	public function optInToCalculate($line_id, $line) {
		$this->engine->markDiscountAsStackingSensitive($this->discount_id);
		return 'IGNORED';
	}

	public function passesFullLineLimits($line_id, $line) {
		if ( $this->engine->otherStackingInsensitiveDiscountsOnThisLine($discount_id, $line_id) ) {
			// bug('SKIPPED BAD-OTHERS',$this->engine->otherStackingInsensitiveDiscountsOnThisLine($discount_id, $line_id));
			return false;
		}
		if ( $this->engine->isLineDiscounted($line_id) ) {
			// bug('SKIPPED IS-DISCOUNTED',$this->engine->isLineDiscounted($line_id));
			return false;
		}

		return 'PASSED';
	}
}


/// ATTR: $X off shipping cost:
class LocalDiscountEngine__ShippingCostDiscountAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function requiresAttributes() { return array('limit_platform[value=web]'); }
	public static function incompatibleWithAttributes() { return array('limit_platform[value=gson]'); }
	public static function defaultValue() { return (object) array('value' => 0); }

	public function dataPassesValidation() {
		if ( ! isset( $this->attr_data->value ) || ! is_numeric( $this->attr_data->value) ) {
			return false;
		}
		return true;
	}

	///  OPT-IN: We INTEND to make a calculation on this line (needed for stacking logic)
	public function optInToCalculate($line_id, $line) {
		if ( $this->engine->getEnvironment()->recipient_has_freeship_item ) { return false; }

		$this->engine->setHasPotentialToDiscountShipping($this->discount_id);

		return self::$LOCAL_CALC_OPTIN_DOLLAR_DISCOUNT;
	}
	///  Prepare for the CalculateLine call
	public function initCalculate($lines_included_in_calculate) {
		$this->engine->setShippingDiscount($this->discount_id, $this->attr_data->value);

	   return 'DONE';
	}
}




/// ATTR: Set Base Shipping Cost:
///   Subtract X amount, to simulate that base shipping cost was [value]
class LocalDiscountEngine__SetBaseShippingCostAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;
	public static function requiresAttributes() { return array('limit_platform[value=web]'); }
	public static function incompatibleWithAttributes() { return array('limit_platform[value=gson]'); }
	public static function defaultValue() { return (object) array('value' => 0); }

	public function dataPassesValidation() {
		if ( ! isset( $this->attr_data->value ) || ! is_numeric( $this->attr_data->value) ) {
			return false;
		}
		return true;
	}

	///  OPT-IN: We INTEND to make a calculation on this line (needed for stacking logic)
	public function optInToCalculate($line_id, $line) {
		if ( $this->engine->getEnvironment()->recipient_has_freeship_item ) { return false; }

		$this->engine->setHasPotentialToDiscountShipping($this->discount_id);

		return self::$LOCAL_CALC_OPTIN_DOLLAR_DISCOUNT;
	}
	///  Prepare for the CalculateLine call
	public function initCalculate($lines_included_in_calculate) {
		$discount_amount = $this->engine->getEnvironment()->base_shipping_cost - $this->attr_data->value;

		$this->engine->setShippingDiscount($this->discount_id, $discount_amount);

	   return 'DONE';
	}
}



/// ATTR: Free FIXED Gift with Purchase (Added to Order on a random line)
class LocalDiscountEngine__FreeGiftFixedAttribute extends DiscountEngine__AttributeBase {
	public $ignore_attr_in_quote_mode = true;

	public static function defaultValue() { return (object) array('free_gift_item_ids' => []); }

	public function dataPassesValidation() {
		if ( empty( $this->attr_data->free_gift_item_ids ) ) {
			return false;
		}
		return true;
	}

	public function optInToCalculate($line_id, $line) {
		if($line['cart_type'] != "P"){ return false;}
		return self::$LOCAL_CALC_OPTIN_FREEGIFT_DISCOUNT;
	}
	public function calculateLine($line_id, $line) {
		if ( empty( $this->workArea()->added_to_a_line ) ) {
			$this->engine->setLineDiscountFlag(
				$line_id,
				$this->discount_id,
				'fixed_free_gift_item_ids',
				$this->attr_data->free_gift_item_ids
				);
			$this->workArea()->added_to_a_line = true;
			return 'PROCESSED';
		}
		return 'SKIPPED';
	}
}
