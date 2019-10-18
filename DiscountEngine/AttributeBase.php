<?php

class Stark__DiscountEngine__AttributeBase {
	protected $discount_id = null;
	protected $discount_obj = null;
	public $attr_data = null;
	public $engine = null;
	protected $work_area = null;
	///  IGNORE this attr when in quote mode
	public $ignore_attr_in_quote_mode = false;

	///  Calculate-Opt-In Sequences
	public static $APP_CALC_OPTIN_100_PERCENT_OFF = 1;
	public static $APP_CALC_OPTIN_DOLLAR_DISCOUNT = 2;
	public static $APP_CALC_OPTIN_PERCENT_DISCOUNT = 3;

	public function __construct($discount_id, stdClass $discount_obj, stdClass $attr_data, Stark__DiscountEngine $engine, stdClass $work_area) {
		$this->discount_id = $discount_id;
		$this->discount_obj = $discount_obj;
		$this->attr_data = $attr_data;
		$this->engine = $engine;
		$this->work_area = $work_area;
	}

	public function workArea($auto_jail = true) {
		$disc_key = 'discount_'.$this->discount_id;
		if ( ! isset( $this->work_area->__work_area->$disc_key ) ) { $this->work_area->__work_area->$disc_key = (object) array(); }
		if ( ! $auto_jail ) { return $this->work_area->__work_area->$disc_key; }

		$class = get_class($this);
		if ( ! isset( $this->work_area->__work_area->$disc_key->$class ) ) { $this->work_area->__work_area->$disc_key->$class = (object) array(); }
		return        $this->work_area->__work_area->$disc_key->$class;
	}


	/////////////////////
	///  Stubs and Defaults

	public static function getDisplayLabel() {
		$tmp = preg_replace('/(^(Stark__)?DiscountEngine__|Attribute$)/','',get_called_class());
		return ltrim(preg_replace('/([A-Z])/',' $1',$tmp),' ');
	}
	public static function defaultValue() { throw new DiscountEngineException("Attribute ". get_called_class() ." forgot to override the defaultValue() method"); }

	///  Defer to a later loop (once non-related stuff as processed)
	public function deferralCheck()                             { return 'IGNORED'; } // return false, or ENG->deferUntilSamePriority('code') (call again next loop)
	///  OPT-IN: We INTEND to make a calculation on this line (needed for stacking logic)
	public function optInToCalculate($line_id, $line)           { return 'IGNORED'; } // return false if not discounting, or one of self::$APP_CALC_OPTIN_ ...
	///  Prepare for the CalculateLine call
	public function initCalculate($lines_included_in_calculate) { return 'IGNORED'; } // return 'DONE' or, 'SKIPPED'
	///  Perform actual calculations, modifying the line
	public function calculateLine($line_id, $line)              { return 'IGNORED'; } // return 'PROCESSED' or, 'SKIPPED'
	///  After calculations are done for all lines, run this.
	public function cleanupCalculate()                          { return 'IGNORED'; } // return 'DONE'
	///  Checks Environmenal stuff, order properties and can reject a discount if it doesn't pass
	public function passesOrderEnvironmentLimits($order_data)   { return 'IGNORED'; } // return 'PASSED' or false (if it does not pass)
	///  Checks SIMPLE line properties and can reject a discount if it doesn't pass.
	///     NOTE: This can NOT cross-compare to other lines or the order itself.  It is cached for speed as 
	///           well as being used pre-order such as calculating what produdcts match what discounts
	public function passesSimpleLineLimits($line_id, $line)     { return 'IGNORED'; } // return 'PASSED' or false (if it does not pass)
	///  Checks ADVANCED line properties and can reject a discount if it doesn't pass.
	///     NOTE: This SHOULD involve cross-comparison to other lines or the order itself.  If it
	///           does not, consider using the SIMPLE form above
	public function passesFullLineLimits($line_id, $line)       { return 'IGNORED'; } // return 'PASSED' or false (if it does not pass)

	///  Prepare for the displayFilterLine call
	public function initDisplayFilter($lines_with_discount )    { return 'IGNORED'; } // return 'DONE' or, 'SKIPPED'
	///  Perform actual display adjustments, modifying the line
	public function displayFilterLine($line_id, $line)          { return 'IGNORED'; } // return 'PROCESSED' or, 'SKIPPED'

	///  Checks that the attr_data is what we expect.  Returing false will generate a warning and skip this attr as if it was not set.
	///    NOTE: you can ALWAYS assume the attr_data is an OBJECT, because it's checked before the object is created
	public function dataPassesValidation() { return true; }
	//public static function setincompatibleWithAttributes() { return array(); }

	///  Dependencies
	public static function requiresAttributes()         { return array(); } // values are OR.  For AND, use a sub-array 
	public static function incompatibleWithAttributes() { return array(); }


	//////////////////////
	///  Admin Display Helpers

	public static function addToInfoForAdminDisplay(&$info_ary) { }
}
