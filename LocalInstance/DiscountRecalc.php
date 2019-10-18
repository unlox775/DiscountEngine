<?php
namespace Models\Order;

class DiscountRecalc {

	public static function orderDetailRecipientRecalc($order_detail, $order_recipient, $recalc, $master_order_subtotal, &$master_order_discount, $recalc_group_by_guid, $is_last_recipient, $recipient_has_freeship_item) {
		$guid = $order_recipient->recipient_guid;

		$discount_cost = 0;

	    ///  Run DiscountEngine
		if ( ! empty($order_detail->discount_code) && $order_recipient->sub_total != 0 ) {


			list($discount_cost, $shipping, $discount_recalc_detail ) = \Models\Order\DiscountRecalc::recalculateRecipientDiscounts(
				$order_recipient,
				$order_detail,
				$master_order_subtotal,
				$master_order_discount,
				$is_last_recipient,
				$recipient_has_freeship_item
				);

			$order_recipient->shipping = $recalc->shipping->$guid = \App::priceRound($shipping);

		    ///  Apply Free Gift Items
			if ( ! empty($discount_recalc_detail['lines']) ) {
				foreach ( $discount_recalc_detail['lines'] as $line_id => $discount_out_lineitem ) {
					if ( isset( $discount_out_lineitem['discount_flags'] ) && ! empty( $discount_out_lineitem['discount_flags']['fixed_free_gift_item_ids'] ) ) {
						$free_gift_lineitems = [];
						foreach ( (array) $discount_out_lineitem['discount_flags']['fixed_free_gift_item_ids'] as $item_id ) {
							$sku = \Models\SKU::get_where(array('item_id' => $item_id, 'not_deleted' => 1),true);
							if ( $sku ) {
								$added_line = (object) array(
									'display_name'              => $sku->description,
									'item_id'                   => $sku->item_id,
									'sku_id'                    => $sku->sku_id,
									'price'                     => 0,
									'retail_price'              => $sku->getPrice(),
									'qty'                       => 1,
									'is_addon'                  => ($sku->is_addon ? true : false),
									'sku_name'                  => $sku->sku_name,
									'description'               => $sku->getDescription(),
									'unit_count'                => $sku->unit_count,
									'size_ounces'               => $sku->size_ounces,
									'does_ship'                 => $sku->doesShip()?1:0,
									'detail'                    => null,
									'cost_snapshot'             => $sku->cost,
									'does_ship'                 => $sku->doesShip(),
									'is_club_item'              => false,
									'club_validated'            => true,
									'is_perpetual_subscription' => false,
									);

								$product_page = $sku->getDefaultProductPage();
								if ( $product_page ) {
									// if ( ! $product_page ) { throw new \Exception("Error in orderDetailRecipientRecalc() for free gift : ProductPage not found"); }
									$added_line->product_name = $product_page->name;
									$added_line->product_prod_id = $product_page->prod_id;
									$added_line->product_image = $product_page->primary_image;
				                    $added_line->product_url_id = $product_page->url_id;
				                    if ( ! empty($product_page->SEOData()->description) ) { $added_line->product_short_description = $product_page->SEOData()->description; }
								}
								$free_gift_lineitems[] = $added_line;
							}
						}
						$recalc->free_gift_fixed->$recalc_group_by_guid = $free_gift_lineitems;
					}
				}
			}

			$recalc->discount_detail->$guid = $discount_recalc_detail;
			if ( $discount_recalc_detail['has_potential_for_shipping_discount'] ) {
				if ( empty( $recalc->has_potential_for_shipping_discount ) ) { $recalc->has_potential_for_shipping_discount = $discount_recalc_detail['has_potential_for_shipping_discount']; }
				else {
					$recalc->has_potential_for_shipping_discount = array_merge($recalc->has_potential_for_shipping_discount,$discount_recalc_detail['has_potential_for_shipping_discount']);
				}
			}
		}

		$recalc->discount->$guid = \App::priceRound($discount_cost);
		$master_order_discount = \App::priceRound($master_order_discount + $discount_cost);
	}

	///  Called whether we just recalc'd or Not...
	public static function recipientPostRecalcHook($order_detail, $order_recipient, $recalc, $recalc_group_by_guid) {
		///  Add the Free Gift FIXED lines to the actual order
		if ( isset( $recalc->free_gift_fixed->$recalc_group_by_guid ) ) {
			foreach ( $recalc->free_gift_fixed->$recalc_group_by_guid as $added_line ) {
				$order_recipient->lineitems[] = $added_line;
			}
		}
	}

    public static $__last_recaclc_totals_discount_data = null;
    public static function recalculateRecipientDiscounts($order_recipient, $order_detail, $master_order_subtotal, $master_order_discount, $is_last_recipient, $recipient_has_freeship_item) {

		///  debug mode
		$debug = (int) \App::getConfig('debug')->discount_system_debug;

		if (!( ( ! isset( $_SERVER['HTTP_ACCEPT'] )
	                   || ( strpos(    $_SERVER['HTTP_ACCEPT'], 'application/json') === false
							&& strpos( $_SERVER['HTTP_ACCEPT'], 'text/javascript')  === false
							)
	                   )
					 && ( ! isset ($_REQUEST['__this_is_ajax__']) )
					 && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']))	//for jquery support
					 ) && ! \App::getConfig('debug')->discount_system_debug_force) { $debug = 0; }



    	list($discount_cost, $shipping, $discount_recalc_detail) = [0, $order_recipient->shipping, []];

		$order_data = (object) array(
				'environment' => (object) array(
					'base_shipping_cost'          => \Models\ShipMethod::getBaseShippingCost($order_detail->billing_info->customer_num),
					'master_order_subtotal'       => $master_order_subtotal,
					'master_order_discount'       => $master_order_discount,
					'is_last_recipient'           => $is_last_recipient,
					'recipient_has_freeship_item' => $recipient_has_freeship_item,
					),
				'lines' => [],
				'order_id' => 'PRE-QUOTE',
				'selected_shipping_option' => $order_recipient->ship_id,
				'shipping' => array(
					'total' => $order_recipient->shipping,
					'discount' => 0,
					),
			);
		foreach ( $order_recipient->lineitems as $i => $line ) {
			if(isset($line->cart_type) && $line->cart_type == "G"){ continue; }
			$line_data = (array) $line;
			$line_data['product_name'] = $line_data['description'];

			///  For category discounts, looks up what product categories
			$sql = "SELECT category FROM erp_category_item WHERE item_id = ". \App::dbQuote($line_data['item_id']);
			$line_data['categories'] = [];
			foreach (\App::getDB()->fetchAll($sql, \Phalcon\Db::FETCH_ASSOC) as $row ) {
				$line_data['categories'][] = $row['category'];
			}

			unset($line_data['description']);

			$order_data->lines[$i + 1001] = $line_data;
		}

		///  Look up ERP Discount

		$discount = false; # \Models\ERPDiscount::get_where(['discount_code' => $order_detail->discount_code],true);
		if ( $discount ) { $discount_data = $discount->getEngineData(); }

		///  Look up ERP Discount
		if ( ! $discount || empty($discount_data) ) {
			$discount = \Models\ERPKeyCode::get_where(['key_code' => $order_detail->discount_code],true);
			if ( $discount ) { $discount_data = $discount->getEngineData(); }
		}

		//if discount is expired skip out
		///  If discount didn't translate, then skip out.
		if(! $discount || empty($discount_data)){
			if($debug){ bug('discount not found: ' . $order_detail->discount_code); }
			return [$discount_cost, $shipping, $discount_recalc_detail];
		}
		/* discount has not started*/ 
		if(!empty($discount_data->date_available) && strtotime($discount_data->date_available) > time()){
			if($debug){ bug('discount has not started: ' . $order_detail->discount_code); }
			return [$discount_cost, $shipping, $discount_recalc_detail];
		}
		/* Discount is expired*/ 
		if(!empty($discount_data->expiration) && strtotime($discount_data->expiration) < (time() - 86400)){
			if($debug){ bug('discount is expired: ' . $order_detail->discount_code); }
			return [$discount_cost, $shipping, $discount_recalc_detail];
		}

		$discount_data = [ $discount_data->code => $discount_data ];


		///  Run the Engine
		$engine = new \Models\DiscountEngine();
		$engine->debug = $debug;

		START_TIMER('DiscountEngine->run()',\App::isProf('discount_system'));
		$new_order_data = $engine->run($order_data, $discount_data, 'calculated');
		// bug($new_order_data);
		END_TIMER('DiscountEngine->run()',\App::isProf('discount_system'));

		self::$__last_recaclc_totals_discount_data = array($new_order_data,$discount_data);
		self::debugLastDiscountRecalc();

		///  Apply Order Data
		foreach ( $new_order_data->lines as $line_id => $calculated_line ) {
			$discount_cost += $calculated_line['discount'];
		}
		$discount_recalc_detail = array(
			'lines' => $new_order_data->lines,
			'shipping_discount' => null,
			'has_potential_for_shipping_discount' => false,
			);

		///  Catch shipping discounts
		if ( ! empty( $new_order_data->shipping['discount'] ) && $new_order_data->shipping['discount'] != 0 ) {
			$discount_cost += $new_order_data->shipping['discount'];
			$discount_recalc_detail['shipping_discount'] = $new_order_data->shipping['discount'];
			// $shipping = $new_order_data->shipping['total'];
		}
		if ( ! empty( $new_order_data->shipping['discount_potentials'] ) ) {
			$discount_recalc_detail['has_potential_for_shipping_discount'] = $new_order_data->shipping['discount_potentials'];
		}

    	return [$discount_cost, $shipping, $discount_recalc_detail];
    }

    public static function debugLastDiscountRecalc($force_debug = false) {
		if ( ! $force_debug ) {
			if ( \App::isDebug('discount_system_debug')
				 ///  NOT AJAX calls, (copied from stark)
				 &&( ( ! isset( $_SERVER['HTTP_ACCEPT'] )
	                   || ( strpos(    $_SERVER['HTTP_ACCEPT'], 'application/json') === false
							&& strpos( $_SERVER['HTTP_ACCEPT'], 'text/javascript')  === false
							)
	                   )
					 && ( ! isset ($_REQUEST['__this_is_ajax__']) )
					 && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']))	//for jquery support
					 )
				 ) {
				$force_debug = true;
			}
		}

		if ( ! $force_debug || empty(self::$__last_recaclc_totals_discount_data) ) { return; }

		list($order_data,$discount_data) = self::$__last_recaclc_totals_discount_data;

		echo "<h2 style='font-weight: bold; font-size:20px; text-decoration: underline'>Recalc Totals Discount Debugger</h2>";
		ob_flush();
		// exit;
		foreach ( $order_data->__debug_discount_trials as $trial_label => $trial ) {

			$trial_discounts = array(); foreach ( $trial->__stacked_discount_set as $dcnt_id ) { $trial_discounts[$dcnt_id] = $discount_data[$dcnt_id]; }

			//  Header Row
			echo "<h2 style='font-weight: bold; font-size:16px;margin-top: 15px;margin-bottom: -17px;text-decoration: underline;' onclick=\"$('.discount-debug--{$trial_label}').toggle()\">{$trial_label}</h2>";
			echo "<table width=100%><tr><th>&nbsp;</th>";
			foreach ( $trial_discounts as $dcnt_id => $d ) { echo "<th>". $d->promo_name ."(". $dcnt_id .")". (isset($d->apply_to_already_discounted) ? ' (AAD)' : '') ."</th>"; }
			echo "<td>Total</td>";
			echo "</tr>";

			$__disc_sum = array();

			$sub_by_p = array();  foreach ( $trial->lines as $line_id => $line ) { if ( isset($line['parent_line_id']) ) { $sub_by_p[$line['parent_line_id']][$line_id] = $line; } }

			$echo_discount_cell = function ($line_id,$line,$dcnt_id,$trial,&$__disc_sum) {
				echo 'title="Log of Actions:'. "\n";
				if ( isset($trial->__line_debug_log[$line_id]) && isset($trial->__line_debug_log[$line_id][$dcnt_id]) ) {
					echo join("\n", $trial->__line_debug_log[$line_id][$dcnt_id]);
				}
				echo '">';
				if (isset($line['discounts'][$dcnt_id]) && $line['discounts'][$dcnt_id] != 0) {
					echo $line['discounts'][$dcnt_id];
					$__disc_sum[$dcnt_id] += $line['discounts'][$dcnt_id];
				}
				else {
					if ( isset($trial->__line_debug_last_action[$line_id]) && isset($trial->__line_debug_last_action[$line_id][$dcnt_id]) ) {
						if ( $trial->__line_debug_last_action[$line_id][$dcnt_id] == 'PROCESSED' ) {
							echo 'PROCESSED-BUT-DIDNT-DISCOUNT';
						}
						else {
							echo $trial->__line_debug_last_action[$line_id][$dcnt_id];
						}
					}
					else if ($line['price'] == 0) { echo 'NOTHING-TO-DISCOUNT'; }
					else { echo 'NON-DISCOUNTING'; }
				}
			};

			// Each Frame Line Item
			foreach ( $trial->lines as $line_id => $line ) {
				if ( isset($line['parent_line_id']) ) continue;

				echo "<tr><td>{$line['product_name']}({$line_id})</td>";
				foreach ( $trial_discounts as $dcnt_id => $d ) {
					echo "<td style='background-color: palegreen; border: 1px solid gray'";
					$echo_discount_cell($line_id,$line,$dcnt_id,$trial,$__disc_sum);
					echo "</td>";
				}
				echo "<td>". sprintf("%.2f",$line['discount']) ."</td>";
				echo "</tr>";

		 		// Each Sub-Line Item
				foreach ( (array) $sub_by_p[$line_id] as $sub_line_id => $sub_line ) {
					echo "<tr><td>{$sub_line['product_name']}({$sub_line_id})</td>";
					foreach ( $trial_discounts as $dcnt_id => $d ) {
						echo "<td style='background-color: darksalmon; border: 1px solid gray'";
						$echo_discount_cell($sub_line_id,$sub_line,$dcnt_id,$trial,$__disc_sum);
						echo "</td>";
					}
					echo "<td>". sprintf("%.2f",$sub_line['discount']) ."</td>";
					echo "</tr>";
				}
				unset($sub_by_p[$line_id]);
			}
			echo "<tr><td>Order Totals ({$trial->order_id}):</td>";
			foreach ( $trial_discounts as $dcnt_id => $d ) { echo "<td style='border: 1px solid gray'>". sprintf("%.2f",$__disc_sum[ $dcnt_id ]) ."</td>"; }
			echo "<td>{$trial->discount}</td>";
			echo "</tr>";
			echo "</table>";

			if ( ! empty( $sub_by_p ) ) { echo '<p>Discount SYSTEM ERROR: Some lines were marked as having a parent, but that parent line was not in the list:</p>'; bug($sub_by_p); }
		}

		echo "<br/><br/><span style='font-weight: bold'>FINAL OPTION WINNER:</span> ". $order_data->__winner_trial ." at \$". $order_data->discount;
		echo "<h2 style='font-weight: bold; font-size:16px;margin-top: 15px;margin-bottom: -17px;text-decoration: underline;' onclick=\"$('.discount-debug--display-filter').toggle()\">Show Display Filter Debug</h2><div style=\"clear:both\"></div><br/><br/>";
    }
}
