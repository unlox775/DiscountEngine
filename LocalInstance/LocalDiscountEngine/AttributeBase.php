<?php

require_once('stark/DiscountEngine/AttributeBase.php');

class LocalDiscountEngine__AttributeBase extends Stark__DiscountEngine__AttributeBase {

	///  Calculate-Opt-In Sequences
	public static $LOCAL_CALC_OPTIN_100_PERCENT_OFF = 1;
	public static $LOCAL_CALC_OPTIN_DOLLAR_DISCOUNT = 2;
	public static $LOCAL_CALC_OPTIN_PERCENT_DISCOUNT = 3;
	public static $LOCAL_CALC_OPTIN_FREEGIFT_DISCOUNT = 4;

}
