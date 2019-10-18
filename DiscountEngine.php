<?php

require_once(__DIR__ .'/DiscountEngine/AttributeBase.php');

class DiscountEngine {
	public $debug = 0;
	protected $__order_data = null;
	protected $__discounts = null;
	protected $__quote_mode = false;
	protected $__attribute_cache = array();
	protected $__attribute_work_area = null;
	protected $__work_area = null;
	protected $__order_derived_cache = array();
	protected $__cacheable_limit_checks = array();
	protected static $__attribute_require_matrix = array(
//		'models/DiscountPromo/BogoAttribute.php' => array('is_bogo'),
		);
	protected static $__priority_codes = array(
		// Code                    // Priority (higher means it will wait longest, until after lower priorities have run)
		'stacking_restricted' => 5,
		'order_level_discounts' => 10,
		);


	// public function getView(){
	// 	return $this->__order_data->view ;
	// }

	protected function resetState() {
		$this->__order_data = null;
		$this->__discounts = null;
		$this->__attribute_cache = array();
		$this->__cacheable_limit_checks = array();
		$this->resetWorkAreas();
	}
	protected function resetWorkAreas() {
		$this->__work_area = (object) array();
		$this->__work_area->defer_until_phase = null;

		if ( is_null($this->__attribute_work_area) ) {			
			$this->__attribute_work_area = (object) array('__work_area' => (object) array()); // sub-array so that we can reset key and people with the parent object will get the change\
		}
		else { $this->__attribute_work_area->__work_area = (object) array(); }

		$this->clearOrderDerivedCache();
	}
	protected function clearOrderDerivedCache() {
		$this->__order_derived_cache = array();
	}
	protected function resetCalculatedData() {
		foreach ( $this->__order_data->lines as $line_id => $line ) {
			$this->__order_data->lines[$line_id]['discounts'] = array();
			$this->__order_data->lines[$line_id]['discount'] = 0;
		}
	}
	public function setQuoteMode($new_mode) {
		$this->__quote_mode = $new_mode;
	}


	public function run($order_data,$discounts,$stop_at_phase = 'calculated') {
		$this->workOverDataTypeIssues($order_data,$discounts);
		$ordered_phases = array(
			'stacked' => '__prepareStacked',
			'calculated' => '__prepareCalculated',
			'display_filtered' => '__prepareDisplayFiltered',
			);
		$this->resetState();
 		///  Store where prepare funcs can get at it


		$this->__order_data = (object) $order_data;
		$this->__discounts = $discounts;
		///  Test some required
		if ( empty($this->__order_data->lines) || ! is_array($this->__order_data->lines)) {
			return $this->__order_data;
		}
		$this->assert("Discounts is an array of objects", (is_array($this->__discounts) && (empty($this->__discounts) || is_object(reset($this->__discounts)) ) ));

		///  Run Phases
		$start_after_phase = isset($this->__order_data->__engine_phase) && isset( $ordered_phases[$this->__order_data->__engine_phase] ) ? $this->__order_data->__engine_phase : 'clean';
		$passed_start_after = $start_after_phase != 'clean' ? false : true;
		foreach ( $ordered_phases as $phase => $prepare_func ) {
			if ( ! $passed_start_after ) {
				if ( $phase == $start_after_phase ) { $passed_start_after = true; }
				continue;
			}

			///  Call prepare Function
			$this->$prepare_func();

			if ( $phase == $stop_at_phase ) { break; }
		}

		return $this->__order_data;
	}

	protected function workOverDataTypeIssues(&$order_data,&$discounts) {
		///  All Lines should be arrays, not objects
		foreach ( $order_data->lines as $k => $x ) {
			$order_data->lines[$k] = (array) $order_data->lines[$k];

			foreach (array('tax','shipping','discount','qty') as $col ) { if ( ! isset( $order_data->lines[$k][$col] ) ) { $order_data->lines[$k][$col] = 0; } }
			if ( $order_data->lines[$k]['qty'] <= 0 ) { $order_data->lines[$k]['qty'] = 1; }
		}

		///  All discounts should be objects
		foreach ( $discounts as $k => $x ) {
			$discounts[$k] = (object) $discounts[$k];
		}

		if ( empty( $order_data->shipping ) ) { $order_data->shipping = []; }
		if ( empty( $order_data->shipping['total'] ) ) { $order_data->shipping['total'] = 0; }
		if ( empty( $order_data->shipping['discount'] ) ) { $order_data->shipping['discount'] = 0; }
		if ( empty( $order_data->shipping['discounts'] ) ) { $order_data->shipping['discounts'] = []; }
	}

	public function productPassesDiscountLimits($product_line_data,$discounts) {
		$this->resetState();

 		///  Store where prepare funcs can get at it
		$this->__order_data = (object) array(
			'lines' => array( 1 => $product_line_data ),
			);
		$this->__discounts = $discounts;

		$discount_passed = array();
		foreach ( $this->__discounts as $discount_id => $discount ) {
			$discount_passed[$discount_id] = $this->passesSimpleLineLimits($discount_id, 1);
		}
		return $discount_passed;
	}


	///////////////////
	///  Engine Phases

	/*** __prepareStacked()
	   *
	   * The purpose is: define one or more:
	   *    __order_data->__stacked_discount_sets = 
	   *    array(
	   *        'min' => array(...)
	   *        'max' => array(...)
	   *        'foo' => array(...)
	   *        )
	   *
	   * Each set is an array of discount dcnt_ids in order to be applied, 
	   *
	   *
	   *
	   */

	protected function __prepareStacked() {
		$this->__order_data->__engine_phase = 'stacked';

		$this->__order_data->__stacked_discount_sets = array(
			'default' => array(),
			);
		foreach ($this->__discounts as $discount_id => $d_obj ) {
			$this->__order_data->__stacked_discount_sets['default'][] = $discount_id;
		}

		if ( ! $this->__quote_mode ) {
			$this->__order_data->__stacked_discount_sets['reverse'] = array_reverse($this->__order_data->__stacked_discount_sets['default']);

			$this->__order_data->__stacked_discount_sets['one-less'] = $this->__order_data->__stacked_discount_sets['default'];
			array_shift($this->__order_data->__stacked_discount_sets['one-less']);

			$this->__order_data->__stacked_discount_sets['one-less-rev'] = $this->__order_data->__stacked_discount_sets['reverse'];
			array_shift($this->__order_data->__stacked_discount_sets['one-less-rev']);
		}

		return true;
	}

	protected function __prepareCalculated() {
		$self = get_class($this);
		$this->assert("Stacked sets set before Calculated Phase", (! empty($this->__order_data->__stacked_discount_sets)));
		$this->__order_data->__engine_phase = 'calculated';

		$this->resetCalculatedData();

		///  Backup the original __order_data (below calc will edit as it runs)
		$original_order_data = serialize($this->__order_data);

		///  Run each potential set and record the outputs
		$discount_sets = $this->__order_data->__stacked_discount_sets;
		$set_results = array();
		foreach ( $discount_sets as $set_label => $disc_set ) {
			$this->setDebugClass($set_label);
			$this->debug(2,0,'DISCOUNT TRIAL: '. $set_label);
			///  Restore original order data
			$this->__order_data = unserialize($original_order_data);

			///  Simulate a single-run
			unset($this->__order_data->__stacked_discount_sets);
			$this->__order_data->__stacked_discount_set = $disc_set;

			$success = $this->__prepareCalculatedForDiscountSet();
			$this->assert("Calculating Discount Set was successful", $success);

			$set_results[$set_label] = $this->__order_data;
			$this->__order_data = null; // in case some wires get crossed.  This will be re-set below
			$this->rotateDebugColor();
		}

		///  Sum the totals and choose the winner (lowest final order_total wins)
		$winner_set = null;
		foreach ( $set_results as $set_label => $set_result ) {
			$set_result->sub_total = 0;
			$set_result->tax = 0;
			$set_result->discount = 0;
			$set_result->shipping['total'] = 0;
			$set_result->total = 0;

			///  Sum each line's totals
			foreach ( $set_result->lines as $line ) {
				$line['line_total']
					= $self::price_round( ($line['price']*$line['qty'])
										  + $line['tax']
										  + $line['shipping']
										  - $line['discount']
										 );

				$set_result->sub_total = $self::price_round( $set_result->sub_total + ($line['price']*$line['qty']) );
				$set_result->tax       = $self::price_round( $set_result->tax       + $line['tax'] );
				$set_result->discount  = $self::price_round( $set_result->discount  + $line['discount'] );
				$set_result->shipping['total']  = $self::price_round( $set_result->shipping['total']  + $line['shipping'] );
				$set_result->total     = $self::price_round( $set_result->total     + $line['line_total'] );
			}

			///  Update the winner
			if ( is_null($winner_set) || $winner_set[1] > $set_result->total ) {
				$winner_set = array($set_label, $set_result->total);
			}
		}

		///  Use the Winner's order_data
		$this->__order_data = $set_results[$winner_set[0]];
		$this->__order_data->__winner_trial = $winner_set[0];
		$this->__order_data->__debug_discount_trials = $set_results;

		return true;
	}
	protected function __prepareCalculatedForDiscountSet() {

		///  Reset the work Area that all the attributes will use
		$this->resetWorkAreas();

		$deferred_discounts = $this->__order_data->__stacked_discount_set;
		$max_deferred_loops = 10;
		$this->__work_area->defer_loop = 0;
		while( ! empty($deferred_discounts) && $this->__work_area->defer_loop < $max_deferred_loops ) {
			$this->debug(2,1,'DEFER LOOP '. $this->__work_area->defer_loop .', discount count: '. count($deferred_discounts));

			$this->__work_area->defer_loop++;

			///  Init and pre-filter discounts
			$this->__discounts_this_pass = $deferred_discounts;
			$discounts_kept = array();
			$discounts_to_defer = array();
			foreach ( $this->__discounts_this_pass as $discount_id ) {
				$this->assert("Discount is in engine's discounts array",isset($this->__discounts[$discount_id]));
				$discount = $this->__discounts[$discount_id];
				$this->debug(2,2,'DEFER LOOP : Discount: '. $discount->promo_name .'('. $discount_id .')');

				///  Check (CACHE-ABLE) "Applies to Line" SIMPLE (no-cross-checking of other lines or order context)
				$simple_limit_pass = $this->passesOrderEnvironmentLimits($discount_id);
				if ( ! $simple_limit_pass ) {
					$this->debug(3,3,'SKIPPED by passesOrderEnvironmentLimits!',null,'red');
					$this->recordLineDebugLog($discount_id, 'SKIPPED', $this->__cacheable_limit_checks['passesOrderEnvironmentLimits_fail_attr'][$discount_id] .'[CalcOrderEnv]');
					continue;
				}

				///  Each ATTR: Init or DEFER
				foreach ($discount->attributes as $attr => $attr_data ) {
					$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
					if ( ! $attr_obj ) { continue; }

					$deferral_response = $attr_obj->deferralCheck();
					if ( $deferral_response == 'DEFERRED' ) {
						$discounts_to_defer[] = array($discount_id,$attr);
						continue 2; // skip this discount for this pass
					}
				}
				$discounts_kept[] = $discount_id;
			}

			$this->debug(4,1,'DISCOUNTS TO DEFER',array( 'to_defer' => $discounts_to_defer, 'until_phase' => $this->__work_area->defer_until_phase ));

			///  Defer the requested ones
			$deferred_discounts = array();
			foreach ( $discounts_to_defer as $discount_deferral ) {
				list($discount_id,$attr) = $discount_deferral;
				///  Don't log 100% deferrals
				if ( count( $discounts_kept ) > 0 ) {
					$this->recordLineDebugLog($discount_id, 'DEFERRED', $attr .'[CalcDefer-Init]');
				}
				$deferred_discounts[] = $discount_id;
			}

			///  Loop through the remaining discounts
			$opted_into_calculate = array(); // [ sequence ][ discount ][ line ] = true;
			foreach ( $discounts_kept as $discount_id ) {
				$this->clearDeferrals($discount_id);
				$this->assert("Discount is in engine's discounts array",isset($this->__discounts[$discount_id]));
				$discount = $this->__discounts[$discount_id];
				$this->debug(2,2,'CALC-OPT-IN : Discount: '. $discount->promo_name .'('. $discount_id .')');

				///  Loop thru lines
				foreach ( $this->__order_data->lines as $line_id => $line ) {
					$this->debug(2,3,'Line: '. $line['product_name'] .'('. $line_id .') [passesSimpleLineLimits]');

					///  Check (CACHE-ABLE) "Applies to Line" SIMPLE (no-cross-checking of other lines or order context)
					$simple_limit_pass = $this->passesSimpleLineLimits($discount_id, $line_id);
					if ( ! $simple_limit_pass ) {
						$this->debug(3,4,'SKIPPED by passesSimpleLineLimits!',null,'red');
						$this->recordLineDebugLog($discount_id, 'SKIPPED', $this->__cacheable_limit_checks['passesSimpleLineLimits_fail_attr'][$discount_id] .'[CalcOptIn-SimpleLine]', $line_id);
						continue;
					}

					///  Loop thru for Opt-Into-Calculate
					foreach ($discount->attributes as $attr => $attr_data ) {
						$this->debug(2,4,'Attr: '. $attr);
						$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
						if ( ! $attr_obj ) { continue; }

						///  Check (NON-CACHE-ABLE) "Applies to Line" FULL ( DOES DO cross-checking of other lines or order context)
						$opt_in_seq = $attr_obj->optInToCalculate($line_id, $line);
						if ( $opt_in_seq !== false && is_numeric($opt_in_seq) ) {
				 			$opted_into_calculate[ (int) $opt_in_seq ][ $discount_id ][$line_id][$attr] = true;
				 		}
					}
				}
			}

			///  Sort to make sure we go through sequnces in order
			ksort($opted_into_calculate, SORT_NUMERIC);

			///  Compile List of all Discount IDs included for this line, for otherDiscountsInCalculate() ...
			$calc_this_line = array();
			foreach ( $opted_into_calculate as $seq => $opted_discount_ids ) {
				foreach ( $opted_discount_ids as $discount_id => $lines ) {
					foreach ( $lines as $line_id => $x ) {
						$calc_this_line[$discount_id][$line_id] = true;
					}
				}
			}
			$this->__work_area->discounts_in_calculate[$this->__work_area->defer_loop] = $calc_this_line;

			///  Calculate
			$seen_discounts = array();
			foreach ( $opted_into_calculate as $seq => $opted_discount_ids ) {
				$this->debug(2,2,'CALC-LOOP : Sequence: '. $seq);
				foreach ( $opted_discount_ids as $discount_id => $opted_line_ids ) {
					// ///  It's possible for one discount to hit 2 sequences.  SKIP!
					// ///    Discounts should only go through this once!
					// if ( isset($seen_discounts[ $discount_id ]) ) { continue; }
					// $seen_discounts[ $discount_id ] = true;

					$this->assert("Discount is in engine's discounts array",isset($this->__discounts[$discount_id]));
					$discount = $this->__discounts[$discount_id];
					$this->debug(2,3,'Discount: '. $discount->promo_name .'('. $discount_id .')');

					$lines_included = array();
					$attrs_opted_in = array();
					foreach ( $this->__order_data->lines as $line_id => $line ) {
						if ( ! isset( $opted_line_ids[ $line_id ] ) ) { continue; }
						else { $attrs_opted_in = array_merge($attrs_opted_in, $opted_line_ids[ $line_id ]); }

						$this->debug(2,4,'Pass-Check Line: '. $line['product_name'] .'('. $line_id .') [passesSimpleLineLimits AND passesFullLineLimits]');
						///  Check (CACHE-ABLE) "Applies to Line" SIMPLE (no-cross-checking of other lines or order context)
						$simple_limit_pass = $this->passesSimpleLineLimits($discount_id, $line_id);
						if ( ! $simple_limit_pass ) {
							$this->debug(3,5,'SKIPPED by passesSimpleLineLimits!',null,'red');
							$this->recordLineDebugLog($discount_id, 'SKIPPED', $this->__cacheable_limit_checks['passesSimpleLineLimits_fail_attr'][$discount_id] .'[Calc-SimpleLine]', $line_id);
							continue;
						}

						///  Loop thru for Full Line Limits Filter
						foreach ($discount->attributes as $attr => $attr_data ) {
							$this->debug(2,5,'FullLine, Attr: '. $attr);
							$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
							if ( ! $attr_obj ) { continue; }

							///  Check (NON-CACHE-ABLE) "Applies to Line" FULL ( DOES DO cross-checking of other lines or order context)
							$full_limit_pass = $attr_obj->passesFullLineLimits($line_id, $line);
							if ( ! $full_limit_pass || $full_limit_pass == 'SKIPPED' ) {
								$this->debug(3,6,'SKIPPED by passesFullLineLimits!',null,'red');
								$this->recordLineDebugLog($discount_id, 'SKIPPED', $attr .'[Calc-FullLine]', $line_id);
								continue 2;
							}
						}
						$lines_included[] = $line_id;
					}

					///  INIT Calculate -- ONLY for the ATTRs that did the opt-in
					foreach ($attrs_opted_in as $attr => $attr_data ) {
						$attr_data = $discount->attributes[$attr];
						$this->debug(2,4,'InitCALC Attr: '. $attr);
						$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
						if ( ! $attr_obj ) { continue; }

						$init_result = $attr_obj->initCalculate($lines_included);
						if ( $init_result == 'SKIPPED' ) {
							$this->debug(3,5,'SKIPPED by initCalculate!',null,'red');
							$this->recordLineDebugLog($discount_id, 'SKIPPED', $attr .'[Calc-Init]');
							continue 2;
						}
					}

 					///  Loop thru lines
					foreach ( $lines_included as $line_id ) {
						$this->assert("Line is in __order_data  [calcLoop]",isset($this->__order_data->lines[$line_id]));
						$line = $this->__order_data->lines[$line_id];
						$this->debug(2,4,'CALC Line: '. $line['product_name'] .'('. $line_id .')');

						$attrs_opted_in = $opted_line_ids[ $line_id ];
						foreach ($attrs_opted_in as $attr => $attr_data ) {
							$attr_data = $discount->attributes[$attr];
							$this->debug(2,5,'CALC Attr: '. $attr);
							$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
							if ( ! $attr_obj ) { continue; }

							$resolution = $attr_obj->calculateLine($line_id, $line);
							switch($resolution) {
								case 'PROCESSED':
									$this->recordLineDebugLog($discount_id, 'PROCESSED', $attr .'[Calculate]', $line_id);
									break;
								case 'SKIPPED':
									$this->debug(3,6,'SKIPPED by calculateLine!',null,'red');
									$this->recordLineDebugLog($discount_id, 'SKIPPED', $attr .'[Calculate]', $line_id);
									continue 2; // skip this discount
									break;
								case 'IGNORED':
									break;
							}
						}
					}
				}
			}

			///  Each ATTR: Cleanup after
			foreach ( $discounts_kept as $discount_id ) {
				$this->assert("Discount is in engine's discounts array",isset($this->__discounts[$discount_id]));
				$discount = $this->__discounts[$discount_id];
				$this->debug(2,2,'CLEANUP Discount: '. $discount->promo_name .'('. $discount_id .')');

				foreach ($discount->attributes as $attr => $attr_data ) {
					$this->debug(2,3,'CALC Attr: '. $attr);
					$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
					if ( ! $attr_obj ) { continue; }

					$attr_obj->cleanupCalculate();
				}
			}
		}

		return true;
	}

	protected function __prepareDisplayFiltered() {
		$self = get_class($this);
		$this->__order_data->__engine_phase = 'display_filtered';

		$this->__debug_color = 3;
		$this->setDebugClass('display-filter');

		///  Create subtotal lines
		$this->__order_data->discount_subtotal_lines = array();
		foreach ( $this->__discounts as $discount_id => $x ) {
			$this->initDiscountSubtotalLine($discount_id);
		}
		foreach ( $this->__order_data->lines as $line ) {
			if ( empty( $line['discounts'] ) ) { continue; }
			foreach ( $line['discounts'] as $discount_id => $amount ) {
				$this->__order_data->discount_subtotal_lines[ $discount_id ]->discount += $amount;
				$this->__order_data->discount_subtotal_lines[ $discount_id ]->real_discount += $amount;
			}
		}
		//bug($this->__order_data);

		/// Run displayFilter loop
		foreach ( $this->__discounts as $discount_id => $discount ) {
			$this->debug(2,0,'DISPLAY LOOP: '. $discount->promo_name);

			///  Check (CACHE-ABLE) "Applies to Line" SIMPLE (no-cross-checking of other lines or order context)
			$simple_limit_pass = $this->passesOrderEnvironmentLimits($discount_id);
			if ( ! $simple_limit_pass ) {
				$this->recordLineDebugLog($discount_id, 'SKIPPED', $this->__cacheable_limit_checks['passesOrderEnvironmentLimits_fail_attr'][$discount_id] .'[DisplayOrderEnv]');
				continue;
			}

			/// INIT
			$lines_with_discount = array();
			foreach ( $this->__order_data->lines as $line_id => $line ) {
				if ( $this->getLineDiscount($line_id,$discount_id) > 0 ) {
					$lines_with_discount[] = $line_id;
				}
			}
			foreach ($discount->attributes as $attr => $attr_data ) {
				$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
				if ( ! $attr_obj ) { continue; }

				$init_result = $attr_obj->initDisplayFilter($lines_with_discount);
				if ( $init_result == 'SKIPPED' ) {
					$this->debug(3,2,'SKIPPED by initDisplayFilter!',null,'red');
					$this->recordLineDebugLog($discount_id, 'SKIPPED', $attr .'[DisplayFilter-Init]');
					continue 2;
				}
			}

			///  Display Filter
			foreach ($discount->attributes as $attr => $attr_data ) {
				$this->debug(2,1,'Attr: '. $attr);
				$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
				if ( ! $attr_obj ) { continue; }

				foreach ( $this->__order_data->lines as $line_id => $line ) {
					$this->debug(2,2,'Line: '. $line['product_name'] .'('. $line_id .')');

					///  Check (CACHE-ABLE) "Applies to Line" SIMPLE (no-cross-checking of other lines or order context)
					$simple_limit_pass = $this->passesSimpleLineLimits($discount_id, $line_id);
					if ( ! $simple_limit_pass ) {
						$this->debug(3,3,'SKIPPED by passesSimpleLineLimits!',null,'red');
						$this->recordLineDebugLog($discount_id, 'SKIPPED', $this->__cacheable_limit_checks['passesSimpleLineLimits_fail_attr'][$discount_id] .'[Display-SimpleLine]', $line_id);
						continue;
					}

					$resolution = $attr_obj->displayFilterLine($line_id, $line);
					switch($resolution) {
						case 'PROCESSED':
							$this->recordLineDebugLog($discount_id, 'PROCESSED', $attr .'[DisplayFilter]', $line_id);
							break;
						case 'SKIPPED':
							$this->debug(3,3,'SKIPPED by displayFilterLine!',null,'red');
							$this->recordLineDebugLog($discount_id, 'SKIPPED', $attr .'[DisplayFilter]', $line_id);
							continue 2; // skip this discount
							break;
						case 'IGNORED':
							break;
					}
				}
			}
		}


		///  Sum the totals and choose the winner (lowest final order_total wins)
		$this->__order_data->sub_total = 0;
		$this->__order_data->tax = 0;
		$this->__order_data->discount = 0;
		$this->__order_data->shipping = 0;
		$this->__order_data->total = 0;

		///  Sum each line's totals
		foreach ( $this->__order_data->lines as $line ) {
			$line['line_total']
				= $self::price_round( ($line['price']*$line['qty'])
									  + $line['tax']
									  + $line['shipping']
									  - $line['discount']
									 );

			$this->__order_data->sub_total = $self::price_round( $this->__order_data->sub_total + ($line['price']*$line['qty']) );
			$this->__order_data->tax       = $self::price_round( $this->__order_data->tax       + $line['tax'] );
			$this->__order_data->discount  = $self::price_round( $this->__order_data->discount  + $line['discount'] );
			$this->__order_data->shipping  = $self::price_round( $this->__order_data->shipping  + $line['shipping'] );
			$this->__order_data->total     = $self::price_round( $this->__order_data->total     + $line['line_total'] );
		}

		///  As this data is passed to the front-end, clean out internals
		$this->cleanOrderDataInternalKeys();
		$this->__order_data->__engine_phase = 'display_filtered'; // re-set, because it just got whacked
		
		return true;
	}

	protected function initDiscountSubtotalLine($discount_id) {
		///  Stub to be overridden
	}
	protected function cleanOrderDataInternalKeys() {
		foreach ( (array) $this->__order_data as $key => $val ) {
			if ( $key[0] == '_' ) {
				unset($this->__order_data->$key);
			}
		}
		foreach ( $this->__order_data->lines as $line_id => $line ) {
			if ( isset( $this->__order_data->lines[ $line_id ]['discounts'] ) ) {
				unset( $this->__order_data->lines[ $line_id ]['discounts'] );
			}

			foreach ( (array) $line as $key => $val ) {
				if ( $key[0] == '_' ) {
					unset($this->__order_data->lines[$line_id][$key]);
				}
			}
		}
	}


	///////////////////
	///   Attribute Method Caching

	public function loadDiscountAttributeSingle($discount_data,$attr_name) {
		if ( ! isset( $discount_data->attributes[$attr_name] ) ) { return false; }
		$this->resetState();
		$this->__discounts = array(1 => $discount_data);
		$return = $this->loadDiscountAttribute(1, $attr_name);
		$this->resetState();
		return $return;
	}
	protected function loadDiscountAttribute($discount_id, $attr) {
		$self = get_class($this);
		if ( isset( $this->__attribute_cache[$discount_id] ) && isset( $this->__attribute_cache[$discount_id][$attr] ) ) { return $this->__attribute_cache[$discount_id][$attr]; }
		$this->assert("Discount is in engine's discounts array [loadDiscountAttribute]",isset($this->__discounts[$discount_id]));
		$discount = $this->__discounts[$discount_id];
		$this->assert("Attribute is in Discount attributes list [loadDiscountAttribute]",isset($discount->attributes[$attr]));
		$attr_data = $discount->attributes[$attr];
		if ( ! is_object($attr_data) ) {
			if ( ! isset( $this->__cacheable_limit_checks['badAttrWarning'] ) || empty( $this->__cacheable_limit_checks['badAttrWarning'][$attr] ) ) {
				bug("DiscountEngine Error: Discount had an invalid Attribute: $attr",$discount_id,$discount);
				$this->__cacheable_limit_checks['badAttrWarning'][$attr] = true;
			}
			$this->__attribute_cache[$discount_id][$attr] = false;
			return false;
		}

		///  Load the class if needed
		$attr_class = $self::attrNameToClass($attr);
		if (! class_exists($attr_class) ) {
			///  Not currently loaded, look at the require_matrix and load 
			foreach ( $self::$__attribute_require_matrix as $require_file => $included_attrs ) {
				if ( in_array($attr, $included_attrs) ) { require_once($require_file); }
			}
			///  If still can't find it, silent fail (ignore bad or old user-entered attrs)
			if (! class_exists($attr_class) ) {
				if ( ! isset( $this->__cacheable_limit_checks['badAttrWarning'] ) || empty( $this->__cacheable_limit_checks['badAttrWarning'][$attr] ) ) {
					bug("DiscountEngine Error: Discount had an invalid Attribute: $attr",$discount_id,$discount);
					$this->__cacheable_limit_checks['badAttrWarning'][$attr] = true;
				}
			}
		}

		$attr_obj = new $attr_class($discount_id, $discount, $attr_data, $this, $this->__attribute_work_area);

		///  Run validation phase to see if the attribute rejects this data
		if ( $attr_obj->dataPassesValidation()
			&& ( ! $this->__quote_mode
				|| ! $attr_obj->ignore_attr_in_quote_mode
				)
			) {
			$this->__attribute_cache[$discount_id][$attr] = $attr_obj;
		}
		else { $this->__attribute_cache[$discount_id][$attr] = false; }

		return $this->__attribute_cache[$discount_id][$attr];
	}
	protected function passesOrderEnvironmentLimits($discount_id) {
		if ( isset( $this->__cacheable_limit_checks['passesOrderEnvironmentLimits'][$discount_id] ) ) { return $this->__cacheable_limit_checks['passesOrderEnvironmentLimits'][$discount_id]; }
		$this->assert("Discount is in engine's discounts array [passesOrderEnvironmentLimits]",isset($this->__discounts[$discount_id]));
		$discount = $this->__discounts[$discount_id];

		$this->__cacheable_limit_checks['passesOrderEnvironmentLimits'][$discount_id] = true;

		///  Loop thru discount attributes
		foreach ($discount->attributes as $attr => $attr_data ) {
			$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
			if ( ! $attr_obj ) { continue; }

			///  Check (NON-CACHE-ABLE) "Applies to Line" FULL ( DOES DO cross-checking of other lines or order context)
			$order_env_pass = $attr_obj->passesOrderEnvironmentLimits($this->__order_data);
			if ( ! $order_env_pass ) {
				$this->__cacheable_limit_checks['passesOrderEnvironmentLimits'][$discount_id] = false;
				$this->__cacheable_limit_checks['passesOrderEnvironmentLimits_fail_attr'][$discount_id] = $attr;
				break;
			}
		}

		return $this->__cacheable_limit_checks['passesOrderEnvironmentLimits'][$discount_id];
	}
	protected function passesSimpleLineLimits($discount_id, $line_id) {
		if ( isset( $this->__cacheable_limit_checks['passesSimpleLineLimits'][$discount_id][$line_id] ) ) { return $this->__cacheable_limit_checks['passesSimpleLineLimits'][$discount_id][$line_id]; }
		$this->assert("Discount is in engine's discounts array [passesSimpleLineLimits]",isset($this->__discounts[$discount_id]));
		$discount = $this->__discounts[$discount_id];
		$this->assert("Line is in __order_data  [passesSimpleLineLimits]",isset($this->__order_data->lines[$line_id]));
		$line = $this->__order_data->lines[$line_id];

		$this->__cacheable_limit_checks['passesSimpleLineLimits'][$discount_id][$line_id] = true;

		///  Loop thru discount attributes
		foreach ($discount->attributes as $attr => $attr_data ) {
			$attr_obj = $this->loadDiscountAttribute($discount_id, $attr);
			if ( ! $attr_obj ) { continue; }

			///  Check (NON-CACHE-ABLE) "Applies to Line" FULL ( DOES DO cross-checking of other lines or order context)
			$simple_line_pass = $attr_obj->passesSimpleLineLimits($line_id, $line);
			if ( ! $simple_line_pass ) {
				$this->__cacheable_limit_checks['passesSimpleLineLimits'][$discount_id][$line_id] = false;
				$this->__cacheable_limit_checks['passesSimpleLineLimits_fail_attr'][$discount_id] = $attr;
				break;
			}
		}

		return $this->__cacheable_limit_checks['passesSimpleLineLimits'][$discount_id][$line_id];
	}


	///////////////////
	///  Any Phase

	public function deferUntilSamePriority($priority_code, $discount_id) {
		$called_class = get_class($this);
		$this->assert("Calling ". __FUNCTION__ ."(), passed priority code is valid", isset($called_class::$__priority_codes[$priority_code]), $priority_code);
		$prio = $called_class::$__priority_codes[$priority_code];

		if ( ! isset( $this->__work_area->defer_until_phase ) ) { $this->__work_area->defer_until_phase = array(); }
		$defer =& $this->__work_area->defer_until_phase;

		///  If we are already deferred, and deferred for something later than this...
		///    Then we'll let the other deferring-attr be the judge of when to defer or not
		if ( ! empty( $defer[$discount_id] )
			&& $defer[$discount_id] > $prio
		     ) {
			return false;
		}

		///  If something else this loop at the same level was already deferred, defer this one too.
		if ( isset( $this->__work_area->already_deferred[ $this->__work_area->defer_loop ][ $prio ] ) ) {
			$defer[$discount_id] = $prio;
			return 'DEFERRED';
		}

		///  Check all the discounts this pass
		foreach ( $this->__discounts_this_pass as $pass_discount_id ) {
			if ( $pass_discount_id == $discount_id ) { continue; } // Skip ourself
			if ( empty( $defer[$pass_discount_id] ) || $defer[$pass_discount_id] < $prio ) {
				$defer[$discount_id] = $prio;
				$this->__work_area->already_deferred[ $this->__work_area->defer_loop ][ $prio ] = true;
				return 'DEFERRED';
			}
		}

		///  NOW DON'T DEFER: we know the only discounts left are ones with our same priority
		return false;
	}
	public function markDiscountAsStackingSensitive($discount_id) {
		$this->__work_area->stacking_sensitive_discounts[$discount_id] = true;
	}
	public function otherStackingInsensitiveDiscountsOnThisLine($discount_id, $line_id) {
		$this->assert("otherStackingInsensitiveDiscountsOnThisLine() is called from calculate loop functions ONLY",isset($this->__work_area->discounts_in_calculate[$this->__work_area->defer_loop]));

		foreach ( $this->__work_area->discounts_in_calculate[$this->__work_area->defer_loop] as $other_discount_id => $line_ids ) {
	  		///  If this discounts doesn't apply to this line then skip
			if ( ! isset( $line_ids[ $line_id ] ) ) { continue; }

			if ( $discount_id != $other_discount_id && ! isset($this->__work_area->stacking_sensitive_discounts[$other_discount_id]) ) {
				return true;
			}
		}
		return false;
	}
	public function clearDeferrals($discount_id) {
		if ( ! isset( $this->__work_area->defer_until_phase ) ) { $this->__work_area->defer_until_phase = array(); }
		$defer =& $this->__work_area->defer_until_phase;

		unset($defer[$discount_id]);
	}
	public function getEnvironment() { return $this->__order_data->environment; }
	public function getOrderSubtotalWithoutDiscount() {
		if ( isset($this->__order_derived_cache['getOrderSubtotalWithoutDiscount']) ) { $this->__order_derived_cache['getOrderSubtotalWithoutDiscount']; }
		return(    $this->__order_derived_cache['getOrderSubtotalWithoutDiscount'] =        $this->__orderSumWithFunc('getLineExtTotalWithoutDiscount') );
	}
	public function getOrderSubtotalWithDiscount() {
		if ( isset($this->__order_derived_cache['getOrderSubtotalWithDiscount']) ) { $this->__order_derived_cache['getOrderSubtotalWithDiscount']; }
		return(    $this->__order_derived_cache['getOrderSubtotalWithDiscount'] =        $this->__orderSumWithFunc('getLineExtTotalWithDiscount') );
	}
	private function __orderSumWithFunc($func) {
		$self = get_class($this);
		$sub_total = 0;
		foreach ( $this->__order_data->lines as $line_id => $line ) { $sub_total = $self::price_round( $sub_total + $this->$func($line_id) ); }
		return $sub_total;
	}
	public function getAllLineIds() {
		$line_ids = array();
		foreach ( $this->__order_data->lines as $line_id => $line ) { $line_ids[] = $line_id; }
		return $line_ids;
	}
	public function getLineById($line_id) {
		$this->assert("Line is in __order_data [getLineById]",isset($this->__order_data->lines[$line_id]));
		return $this->__order_data->lines[ $line_id ];
	}
	public function getLineExtTotalWithoutDiscount($line_id) {
		if ( isset($this->__order_derived_cache["getLineExtTotalWithoutDiscount-$line_id"]) ) { $this->__order_derived_cache["getLineExtTotalWithoutDiscount-$line_id"]; }
		$this->assert("Line is in __order_data  [getLineExtTotalWithoutDiscount]",isset($this->__order_data->lines[$line_id]));
		$line = $this->__order_data->lines[$line_id];
		return(    $this->__order_derived_cache["getLineExtTotalWithoutDiscount-$line_id"] = ($line['price']*$line['qty']) );
	}
	public function getLineExtTotalWithDiscount($line_id) {
		if ( isset($this->__order_derived_cache["getLineExtTotalWithoutDiscount-$line_id"]) ) { $this->__order_derived_cache["getLineExtTotalWithoutDiscount-$line_id"]; }
		$this->assert("Line is in __order_data  [getLineExtTotalWithoutDiscount]",isset($this->__order_data->lines[$line_id]));
		$line = $this->__order_data->lines[$line_id];
		return(    $this->__order_derived_cache["getLineExtTotalWithoutDiscount-$line_id"] = ($line['price']*$line['qty']) - $line['discount'] );
	}
	public function getLineDiscount($line_id,$discount_id) {
		$this->assert("Line is in __order_data  [getLineExtTotalWithoutDiscount]",isset($this->__order_data->lines[$line_id]));
		$line = $this->__order_data->lines[$line_id];
		return (isset($line['discounts']) && isset($line['discounts'][$discount_id])) ? $line['discounts'][$discount_id] : 0;
	}
	public function isLineDiscounted($line_id) {
		$this->assert("Line is in __order_data  [getLineExtTotalWithoutDiscount]",isset($this->__order_data->lines[$line_id]));
		$line = $this->__order_data->lines[$line_id];
		return ( isset($line['discounts']) && ! empty($line['discount']) );
	}
	public function getOrderDiscount($discount_id) {
		$self = get_class($this);
		$discount_total = 0;
		foreach ( $this->__order_data->lines as $line_id => $line ) { $discount_total = $self::price_round( $discount_total + $this->getLineDiscount($line_id, $discount_id) ); }
		return $discount_total;
	}


	///////////////////
	///  Calculated Phase

	public function setLineDiscount($line_id,$discount_id,$amount) {
		$self = get_class($this);
		$this->assert("Calling ". __FUNCTION__ ."() in calculate phase", ($this->__order_data->__engine_phase == 'calculated'), $this->__order_data->__engine_phase);
		$this->assert("Line is in __order_data [setLineDiscount]",isset($this->__order_data->lines[$line_id]));
		$line =& $this->__order_data->lines[$line_id];
		$this->debug(3,10,'setLineDiscount() '. $line['product_name'] .'('. $line_id .')',array('discount_id' => $discount_id, 'amount' => $amount),'#CE6DAE');

		$line_total = $this->getLineExtTotalWithoutDiscount($line_id);
		$other_discounts = 0;
		foreach ( (array) $line['discounts'] as $cur_discount_id => $cur_disc_amt ) {
			if ( $cur_discount_id != $discount_id ) { $other_discounts += $cur_disc_amt; } // trust they are rounded
		}
		$total_with_other_discounts = $self::price_round($line_total - $other_discounts);

		///  Make sure we don't over-discount
		if ( $total_with_other_discounts <= 0 ) { $amount = 0; }
		else if ( $amount > $total_with_other_discounts ) { $amount = $total_with_other_discounts; }
		else { $amount = $self::price_round($amount); }

		///  Set the line values
		$line['discounts'][$discount_id] = $amount;
		$line['discount'] = $other_discounts + $amount; // they are already rounded

		$this->clearOrderDerivedCache(); // we changed the lines
	}

	public function setLineDiscountFlag($line_id,$discount_id,$flag_name,$flag_value) {
		$this->assert("Calling ". __FUNCTION__ ."() in calculate phase", ($this->__order_data->__engine_phase == 'calculated'), $this->__order_data->__engine_phase);
		$this->assert("Line is in __order_data [setLineDiscount]",isset($this->__order_data->lines[$line_id]));
		$line =& $this->__order_data->lines[$line_id];
		$this->debug(3,10,'setLineDiscountFlag() '. $line['product_name'] .'('. $line_id .')',array('discount_id' => $discount_id, 'flag_name' => $flag_name, 'flag_value' => $flag_value),'#CE6DAE');

		$line['discount_flags_detail'][$discount_id][$flag_name] = $flag_value;
		$line['discount_flags'][$flag_name] = $flag_value;
	}


	public function constrainShippingOptions($new_shipping_options) {
		$this->assert("Calling ". __FUNCTION__ ."() in calculate phase", ($this->__order_data->__engine_phase == 'calculated'), $this->__order_data->__engine_phase);
		$this->assert("Shipping options are not empty", ! empty($new_shipping_options), $new_shipping_options);

		$this->__order_data->shipping_options = $new_shipping_options;

		///  Make sure the selected ID is in the options list
		$found_id = false;

		foreach ( $this->__order_data->shipping_options as $shipping_id => $option ) {
			if ( $shipping_id == $this->__order_data->selected_shipping_option ) {
				$found_id = true;
			}
		}
		if ( ! $found_id ) {
			$ids = array_keys( $this->__order_data->shipping_options );
			$this->__order_data->selected_shipping_option = $ids[0];
		}

		$this->clearOrderDerivedCache(); // we changed the lines
	}

	public function setHasPotentialToDiscountShipping($discount_id) {
		$self = get_class($this);
		$this->assert("Calling ". __FUNCTION__ ."() in calculate phase", ($this->__order_data->__engine_phase == 'calculated'), $this->__order_data->__engine_phase);
		$this->debug(3,10,'setHasPotentialToDiscountShipping() ',array('discount_id' => $discount_id),'#CE6DAE');

		///  Set the line values
		$this->__order_data->shipping['discount_potentials'][$discount_id] = true;
		$this->__order_data->shipping['discount_potential'] = true;
	}

	public function setShippingDiscount($discount_id,$amount) {
		$self = get_class($this);
		$this->assert("Calling ". __FUNCTION__ ."() in calculate phase", ($this->__order_data->__engine_phase == 'calculated'), $this->__order_data->__engine_phase);
		$this->debug(3,10,'setShippingDiscount() ',array('discount_id' => $discount_id, 'amount' => $amount),'#CE6DAE');

		$shipping_total = $this->__order_data->shipping['total'];
		$other_discounts = 0;
		foreach ( (array) $this->__order_data->shipping['discounts'] as $cur_discount_id => $cur_disc_amt ) {
			if ( $cur_discount_id != $discount_id ) { $other_discounts += $cur_disc_amt; } // trust they are rounded
		}
		$total_with_other_discounts = $self::price_round($shipping_total - $other_discounts);

		///  Make sure we don't over-discount
		if ( $total_with_other_discounts <= 0 ) { $amount = 0; }
		else if ( $amount > $total_with_other_discounts ) { $amount = $total_with_other_discounts; }
		else { $amount = $self::price_round($amount); }

		///  Set the line values
		$this->__order_data->shipping['discounts'][$discount_id] = $amount;
		$this->__order_data->shipping['discount'] = $other_discounts + $amount; // they are already rounded
	}
	

	///////////////////
	///  Display Filtered Phase

	public function setOrderSummaryMessage($message){
		$this->assert("Calling ". __FUNCTION__ ."() in display_filter phase", ($this->__order_data->__engine_phase == 'display_filtered'), $this->__order_data->__engine_phase);
		$this->__order_data->custom_subtotal_label = $message;
	}
	public function setLineMessage($line_id,$message) {
	 	$this->assert("Calling ". __FUNCTION__ ."() in display_filter phase", ($this->__order_data->__engine_phase == 'display_filtered'), $this->__order_data->__engine_phase);
	 	$line =& $this->__order_data->lines[$line_id];
		
	 	$line['line_message'][] = $message;
	 }

	public function moveSubtotalDiscountToLine($line_id, $discount_id, $amount /* positive means subtotal -> line */) {
		$self = get_class($this);
		$this->assert("Calling ". __FUNCTION__ ."() in display_filter phase", ($this->__order_data->__engine_phase == 'display_filtered'), $this->__order_data->__engine_phase);
		$amount = $self::price_round($amount);

		$this->assert("Line is in __order_data  [getLineExtTotalWithoutDiscount]",isset($this->__order_data->lines[$line_id]));
		$line =& $this->__order_data->lines[$line_id];

		$this->assert("Not trying to move more discount than there is", (! empty($line['discounts'][$discount_id]) && $line['discounts'][$discount_id] >= abs($amount)), array('amount' => $amount, 'line_discount' => $line['discounts'][$discount_id]));
		$this->assert("Not trying to move from a non-existant subtotal line", ( ! empty($this->__order_data->discount_subtotal_lines[ $discount_id ])) );

		///  Move disocunt and protect from overflow
		if ( empty( $line['__line_displayed_discount'] ) || empty( $line['__line_displayed_discount'][ $discount_id ] ) ) {
			$line['__line_displayed_discount'][ $discount_id ] = 0;
		}
		$old_discount = $line['__line_displayed_discount'][ $discount_id ];
		$line['__line_displayed_discount'][ $discount_id ] += $amount;
		if ( $line['__line_displayed_discount'][ $discount_id ] < 0 ) { $line['__line_displayed_discount'][ $discount_id ] = 0; }
		if ( $line['__line_displayed_discount'][ $discount_id ] > $line['discounts'][$discount_id] ) { $line['__line_displayed_discount'][ $discount_id ] = $line['discounts'][$discount_id]; }
		$difference = ($line['__line_displayed_discount'][ $discount_id ] - $old_discount);

		///  Clean up...
		if ( $self::price_round($line['__line_displayed_discount'][ $discount_id ]) == 0 ) {
			unset($line['__line_displayed_discount'][ $discount_id ]);
		}

		///  Adjust subtotal line
		$this->__order_data->discount_subtotal_lines[ $discount_id ]->discount -= $difference;

		///  Adjust line 'reg_price'/'price' values
		$reg_price = ( isset($line['reg_price']) ? $line['reg_price'] : $line['price'] );
		$new_line_displayed_discount = 0;
		foreach ( $line['__line_displayed_discount'] as $discount_id => $discount ) {
			$new_line_displayed_discount += $discount;
		}
		if ( $self::price_round($new_line_displayed_discount) != 0 ) {
			$line['reg_price'] = $reg_price;
			$line['price'] = $self::price_round($reg_price - $new_line_displayed_discount);
		}
		else {
			if ( isset($line['reg_price']) ) { unset($line['reg_price']); }
			$line['price'] = $reg_price;
		}

		$this->clearOrderDerivedCache(); // we changed the lines
	}

	///////////////////
	///  Utility functions

	public static $last_failed_assertion = null;
	public function assert($assertion_descr, $test, $debug = null) {
		$self = get_class($this);
		if ( $test ) { return true; }
		$self::$last_failed_assertion = "Assertion Failed.  Assert: ". $assertion_descr .(! is_null($debug) ? "\n\n --> Debug:\n". var_export($debug,true) : '');
		throw new DiscountEngineException($self::$last_failed_assertion);
		return false;
	}
	private $__debug_colors = array('#406F9A','#80A916','#891262','#B37217');
	private $__debug_color = 0;
	private $__debug_class = 'root';
	public function debug($lvl, $indent, $message, $detail = null, $color_override = false) {
		if ( $this->debug != true || $this->debug < $lvl ) { return; }
		$tr = debug_backtrace();
		$level = 0;

		echo (
			'<div style="clear:both; display: none" class="discount-debug--'. $this->__debug_class .'" title="File: '. $tr[$level]['file'] .", line ". $tr[$level]['line'] .'">'
			. '<span style="float: left; font-weight: bold; color: '. ( $color_override ?: $this->rotateDebugColor(true)) .'">DISCOUNT-ENGINE:</span>'
			. ' <pre style="float: left; background: #ddd; padding: 3px; margin: -3px 0 5px '. ($indent * 20) .'px;">'. $message . ($detail ? "\n\n".var_export($detail,true) : '') .'</pre>'
			. '</div><div style="clear:both;"></div>'
			);
	}
	public function rotateDebugColor($just_return = false) { if ( ! $just_return ) { $this->__debug_color = ($this->__debug_color+1) % count( $this->__debug_colors ); } return $this->__debug_colors[ $this->__debug_color ]; }
	public function setDebugClass($newclass) { $this->__debug_class = $newclass; }

	public static function price_round($num) { return sprintf("%.2f",$num); }

	public static function attrNameToClass($attr) {
		return 'DiscountEngine__'. preg_replace_callback('/(?:^|_)(.?)/',function($s){ return strtoupper($s[1]); },$attr) .'Attribute';
	}
	public static function classToAttrName($class) {
		$tmp = preg_replace('/(^DiscountEngine__|Attribute$)/','',$class);
		return ltrim(strtolower(preg_replace('/([A-Z])/','_$1',$tmp)),'_');
	}

	public static function getAllAttributeInfo() {
		$self = get_class($this);
		//  Load all Attrs
		foreach ( $self::$__attribute_require_matrix as $require_file => $included_attrs ) {
			require_once($require_file);
		}

		$all_info = array();
		foreach ( get_declared_classes() as $class_name ) {
			if ( preg_match('/DiscountEngine__\w+?Attribute/i', $class_name) ) {
				$attr_name = $self::classToAttrName($class_name);

				$attr_info = array(
					'name' => $attr_name,
					'label' => $class_name::getDisplayLabel(),
					'requires' => $class_name::requiresAttributes(),
					'incompatible_with' => $class_name::incompatibleWithAttributes(),
					'default' => $class_name::defaultValue(),
					);
				$class_name::addToInfoForAdminDisplay($attr_info);
				$all_info[] = $attr_info;
			}
		}

		return $all_info;
	}

	public function recordLineDebugLog($discount_id, $action, $by_what, $single_line_id = null) {
		$line_ids = is_null($single_line_id) ? array_keys($this->__order_data->lines) : array( $single_line_id );
		foreach ($line_ids as $line_id) {
			if ( ! isset($this->__order_data->__line_debug_log[$line_id]) || ! isset($this->__order_data->__line_debug_log[$line_id][$discount_id]) ) {
				$this->__order_data->__line_debug_log[$line_id][$discount_id] = array();
			}
			$this->__order_data->__line_debug_log[$line_id][$discount_id][] = $action .' by '. $by_what .' (loop '. $this->__work_area->defer_loop .')';
			$this->__order_data->__line_debug_last_action[$line_id][$discount_id] = $action;
		}
	}

}

class DiscountEngineException extends \Exception { }
