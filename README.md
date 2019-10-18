## Stark DiscountEngine

This is a multi-purpose, lightning-fast, and robust discount engine.  This is designed for use in E-commerce applications, but it can be used for any type of price calculation, or accounting applications.

The overall philosophy is to keep the discount calculations entirely segregated from other application logic.  This is for both speed and reliability of calculation.  The method, is to pass the engine the following things, and treat it as a black box:

- The line items of the financial transaction
- An environment object, with any parameters needed by the engine
- The active discounts for this transaction, represented as objects with one or more attributes as keys/values on the object

Then, these are the modular additions you can provide to the engine:

- Define custom discount attriubutes as PHP classes to create custom calculations or behaviors
- Extend the Engine class to define alternate discount fallback behaviors or priorities of which discounts can override others

As a result, once the engine has completed, it's outputs are:

1. A set of transaction-level totals: `total`, `sub_total`, `discount`, `tax`, and `shipping`
2. The line items, where each line has:
   - line-level totals: `line_total`, `discount`, `tax`, and `shipping`
   - a `discounts` associative array, where keys are the ID of discounts applied to that line, and the values are the amount applied


## What DiscountEngine is Not

The discount engine in order to stay as minimal, fast and abstract as possible does ***NOT handle any of the following***:

1. Any User Interface for user's to type in a discount code
2. Any stateful storing of user's sessions, and how you determine *which* discounts they have
3. Any detection of global running discounts (i.e. for a date range when a discount is active, you need to check this yourself, and pass that discount to the engine if you detect that it is active)
4. Any display logic, to show line items, strike-through, banners, totals, etc.


## How discounts work

This is an example discount passed into the engine:

**ID:** `SAVEDAY20` *(can be any string or number)*
**Value (PHP array shown, but cast it to an object):**
```lang=php
array(
  'order_has_item_limit' => (object) array('value' => 'CHOC24'),
  'order_discount' => (object) array('type' => 'percent', 'value' => 20.0),
)
```

In this example, the intent is that the entire order will be discounted by 20%, but only if the order contains product "CHOC24", two-dozen chocolate chip cookies.

The first attrbute shown, `order_has_item_limit`, looks (by name) and finds a PHP class like this:

```lang=php
/// ATTR: Order Has Item Limit:
///   Ignores discount unless order has the given item in cart
class LocalDiscountEngine__OrderHasItemLimitAttribute extends LocalDiscountEngine__AttributeBase {
    public static function getDisplayLabel() { return "Order Has Item in Cart"; }
    public $ignore_attr_in_quote_mode = true;
    public static function defaultValue() { return (object) array('value' => 9001); }
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
```

Attributes like this define one or more hooks which tell the engine what this attribute does.  In this case, the `passesOrderEnvironmentLimits` hook, passes in the contents of the transaction order, and expects a *boolean* return.  The object is instantiated with for this specific "SAVEDAY20" discount's value, with it's `value` of **CHOC24**.  The hook loops through the items of the order, and checks that an item with that ID is present or not.  A `false` return, will cause the engine to drop the "SAVEDAY20" discount from consideration.  Returning true, simply does not exclude it, and that discount is still considered on later steps.


The `order_discount` attribute find this PHP class:
```lang=php
/// ATTR: ORDER Level Discount
class LocalDiscountEngine__OrderDiscountAttribute extends LocalDiscountEngine__AttributeBase {
    public static function getDisplayLabel() { return "Order-Level Discount"; }
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
```

This attribute is a much more-complicated attribute, which has the goal of:

- Handling a fixed-money-amount-off (ie. $20.00 off your order) or a percentage-based discount
- If the fixed-money-amount-off case, it must spread the discount prorated across all order lines
- Then, it needs to handle rounding errors, with it's proportional-spreading of the discounted funds

The engine has several discrete phases for opt-in, calculation, deferral and post-cleanup.  You can see several of these in action, with this discount attribute.

**In Summary:** the combination of these 2 attributes achieves the business purpose.  DiscountEngine is really a philosophical distillation of all of the practical phases, order of operations, and calculation controls that all discount procesesses share.  Attributes defined are thus bound by rules, designed to keep each attribute in their "sandbox".  This allows for the creation of a robust suite of attributes, with which engineers can solve for all the crazy ideas desired by the business.
