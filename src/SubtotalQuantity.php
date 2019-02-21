<?php
/**
 * --- STOP! ---
 * Get the latest version:
 * https://gravitywiz.com/documentation/gravity-forms-ecommerce-fields/
 * -------------
 *
 * Calculation Subtotal Merge Tag
 *
 * Adds a {subtotal} merge tag which calculates the subtotal of the form. This merge tag can only be used
 * within the "Formula" setting of Calculation-enabled fields (i.e. Number, Calculated Product).
 *
 * @author    David Smith <david@gravitywiz.com>
 * @license   GPL-2.0+
 * @link      http://gravitywiz.com/subtotal-merge-tag-for-calculations/
 * @copyright 2013 Gravity Wiz
 */

namespace WPS\WP\Plugins\GravityForms\MergeTags;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\SubtotalQuantity' ) ) {
	class SubtotalQuantity extends \WPS\Core\Singleton {

		public static $merge_tag = '{subtotal_quantity}';

		protected function __construct() {

			// front-end
			add_filter( 'gform_register_init_scripts', array( $this, 'subtotal_script' ) );
			add_filter( 'gform_pre_render', array( $this, 'maybe_replace_subtotal_merge_tag' ) );
			add_filter( 'gform_pre_validation', array( $this, 'maybe_replace_subtotal_merge_tag_submission' ) );

			// back-end
			add_filter( 'gform_admin_pre_render', array( $this, 'add_merge_tags' ), 50 );
			add_filter( 'gform_calculation_result', array( $this, 'gform_calculation_result' ), 10, 5 );

		}

		protected function get_fee( $card_type, $currency = 'USD', $intl = false ) {
			if ( 'amex' !== $card_type ) {
				$fee = array( 'Percent' => 2.2, 'Fixed' => 0.30 );
			} else {
				$fee = array( 'Percent' => 3.5, 'Fixed' => 0 );
			}

			if ( $intl ) {
				$fee['Percent'] += 1;
			}

			if ( 'USD' !== $currency ) {
				$fee['Percent'] += 1;
			}

			return $fee;
		}

		protected function calc_fee( $amount, $currency = 'USD', $_fee = null ) {
			if ( null === $_fee ) {
				$fees = array(
					'USD' => array( 'Percent' => 2.9, 'Fixed' => 0.30 ),
					'GBP' => array( 'Percent' => 2.4, 'Fixed' => 0.20 ),
					'EUR' => array( 'Percent' => 2.4, 'Fixed' => 0.24 ),
					'CAD' => array( 'Percent' => 2.9, 'Fixed' => 0.30 ),
					'AUD' => array( 'Percent' => 2.9, 'Fixed' => 0.30 ),
					'NOK' => array( 'Percent' => 2.9, 'Fixed' => 2 ),
					'DKK' => array( 'Percent' => 2.9, 'Fixed' => 1.8 ),
					'SEK' => array( 'Percent' => 2.9, 'Fixed' => 1.8 ),
					'JPY' => array( 'Percent' => 3.6, 'Fixed' => 0 ),
					'MXN' => array( 'Percent' => 3.6, 'Fixed' => 3 )
				);
				$_fee = isset( $fees[ $currency ] ) ? $fees[ $currency ] : array( 'Percent' => 2.9, 'Fixed' => 0.30 );
			}

			$total = ( $amount + $_fee['Fixed'] ) / ( 1 - $_fee['Percent'] / 100 );
			$fee   = $total - $amount;

			return array(
				'amount' => $amount,
				'fee'    => \GFCommon::round_number( $fee, 2 ),
				'total'  => \GFCommon::round_number( $total, 2 ),
			);
		}

		public function gform_calculation_result( $result, $formula, $field, $form, $entry ) {

			if ( ! isset( $field->hasSubtotal ) ) {
				return $result;
			}

			$products = \GFCommon::get_product_fields( $form, $entry, false );
			if ( empty( $products['products'] ) ) {
				return $result;
			}

			foreach ( $products['products'] as $field_id => $field ) {
//				$field_id = self::get_field_id_by_entry_value( $entry, $value );
				$field = \GFFormsModel::get_field( $form, $field_id );
				if ( self::has_subtotal_merge_tag( $field ) || ( isset( $field->hasSubtotal ) && $field->hasSubtotal ) ) {
					unset( $products['products'][ $field_id ] );
				}
			}
			$total = \GFCommon::get_total( $products );

			$credit_card_type = isset( $_POST['stripe_credit_card_type'] ) ? strtolower( $_POST['stripe_credit_card_type'] ) : 'visa';
			$fee = $this->get_fee( $credit_card_type, 'USD', false );
			$res = $this->calc_fee( $total, 'USD', $fee );
			return $res['fee'];

		}

		/**
		 * Look for {subtotal} merge tag in form fields 'calculationFormula' property. If found, replace with the
		 * aggregated subtotal merge tag string.
		 *
		 * @param mixed $form
		 */
		public function maybe_replace_subtotal_merge_tag( $form, $filter_tags = false ) {

			foreach ( $form['fields'] as &$field ) {

				if ( current_filter() == 'gform_pre_render' && rgar( $field, 'origCalculationFormula' ) ) {
					$field['calculationFormula'] = $field['origCalculationFormula'];
				}

				if ( ! self::has_subtotal_merge_tag( $field ) ) {
					continue;
				}

				$subtotal_merge_tags             = self::get_subtotal_merge_tag_string( $form, $field, $filter_tags );
				$field['origCalculationFormula'] = $field['calculationFormula'];
				$field['calculationFormula']     = str_replace( self::$merge_tag, $subtotal_merge_tags, $field['calculationFormula'] );
				$field['hasSubtotal']            = true;

			}

			return $form;
		}

		public function maybe_replace_subtotal_merge_tag_submission( $form ) {
			return $this->maybe_replace_subtotal_merge_tag( $form, true );
		}

		/**
		 * Get all the pricing fields on the form, get their corresponding merge tags and aggregate them into a formula that
		 * will yeild the form's subtotal.
		 *
		 * @param mixed $form
		 */
		public static function get_subtotal_merge_tag_string( $form, $current_field, $filter_tags = false ) {

			$pricing_fields     = self::get_pricing_fields( $form );
			$product_tag_groups = array();

			foreach ( $pricing_fields['products'] as $product ) {

				$product_field  = rgar( $product, 'product' );
				$option_fields  = rgar( $product, 'options' );
				$quantity_field = rgar( $product, 'quantity' );

				// do not include current field in subtotal
				if ( $product_field['id'] == $current_field['id'] ) {
					continue;
				}

				$product_tags = \GFCommon::get_field_merge_tags( $product_field );
				$quantity_tag = 1;

				// if a single product type, only get the "price" merge tag
				if ( in_array( \GFFormsModel::get_input_type( $product_field ), array(
					'singleproduct',
					'calculation',
					'hiddenproduct'
				) ) ) {

					// single products provide quantity merge tag
					if ( empty( $quantity_field ) && ! rgar( $product_field, 'disableQuantity' ) ) {
						$quantity_tag = $product_tags[2]['tag'];
					}

					$product_tags = array( $product_tags[1] );
				}

				// if quantity field is provided for product, get merge tag
				if ( ! empty( $quantity_field ) ) {
					$quantity_tag = \GFCommon::get_field_merge_tags( $quantity_field );
					$quantity_tag = $quantity_tag[0]['tag'];
				}

				if ( $filter_tags && ! self::has_valid_quantity( $quantity_tag ) ) {
					continue;
				}

				$product_tags = wp_list_pluck( $product_tags, 'tag' );
				$option_tags  = array();

				foreach ( $option_fields as $option_field ) {

					if ( is_array( $option_field['inputs'] ) ) {

						$choice_number = 1;

						foreach ( $option_field['inputs'] as &$input ) {

							//hack to skip numbers ending in 0. so that 5.1 doesn't conflict with 5.10
							if ( $choice_number % 10 == 0 ) {
								$choice_number ++;
							}

							$input['id'] = $option_field['id'] . '.' . $choice_number ++;

						}
					}

					$new_options_tags = \GFCommon::get_field_merge_tags( $option_field );
					if ( ! is_array( $new_options_tags ) ) {
						continue;
					}

					if ( \GFFormsModel::get_input_type( $option_field ) == 'checkbox' ) {
						array_shift( $new_options_tags );
					}

					$option_tags = array_merge( $option_tags, $new_options_tags );
				}

				$option_tags = wp_list_pluck( $option_tags, 'tag' );

				$product_tag_groups[] = '( ( ' . implode( ' + ', array_merge( $product_tags, $option_tags ) ) . ' ) * ' . $quantity_tag . ' )';

			}

			$shipping_tag = 0;
			/* Shipping should not be included in subtotal, correct?
			if( rgar( $pricing_fields, 'shipping' ) ) {
				$shipping_tag = GFCommon::get_field_merge_tags( rgars( $pricing_fields, 'shipping/0' ) );
				$shipping_tag = $shipping_tag[0]['tag'];
			}*/

			$pricing_tag_string = '( ( ' . implode( ' + ', $product_tag_groups ) . ' ) + ' . $shipping_tag . ' )';

			return $pricing_tag_string;
		}

		/**
		 * Get all pricing fields from a given form object grouped by product and shipping with options nested under their
		 * respective products.
		 *
		 * @param mixed $form
		 */
		public static function get_pricing_fields( $form ) {

			$product_fields = array();

			foreach ( $form["fields"] as $field ) {

				if ( $field["type"] != 'product' ) {
					continue;
				}

				$option_fields = \GFCommon::get_product_fields_by_type( $form, array( "option" ), $field['id'] );

				// can only have 1 quantity field
				$quantity_field = \GFCommon::get_product_fields_by_type( $form, array( "quantity" ), $field['id'] );
				$quantity_field = rgar( $quantity_field, 0 );

				$product_fields[] = array(
					'product'  => $field,
					'options'  => $option_fields,
					'quantity' => $quantity_field
				);

			}

			$shipping_field = \GFCommon::get_fields_by_type( $form, array( "shipping" ) );

			return array( "products" => $product_fields, "shipping" => $shipping_field );
		}

		public static function has_valid_quantity( $quantity_tag ) {

			if ( is_numeric( $quantity_tag ) ) {

				$qty_value = $quantity_tag;

			} else {

				// extract qty input ID from the merge tag
				preg_match_all( '/{[^{]*?:(\d+(\.\d+)?)(:(.*?))?}/mi', $quantity_tag, $matches, PREG_SET_ORDER );
				$qty_input_id = rgars( $matches, '0/1' );
				$qty_value    = rgpost( 'input_' . str_replace( '.', '_', $qty_input_id ) );

			}

			return floatval( $qty_value ) > 0;
		}

		public function subtotal_script( $form ) {

			$script = '!function(e){var f={USD:{Percent:2.9,Fixed:.3},GBP:{Percent:2.4,Fixed:.2},EUR:{Percent:2.4,Fixed:.24},CAD:{Percent:2.9,Fixed:.3},AUD:{Percent:2.9,Fixed:.3},NOK:{Percent:2.9,Fixed:2},DKK:{Percent:2.9,Fixed:1.8},SEK:{Percent:2.9,Fixed:1.8},JPY:{Percent:3.6,Fixed:0},MXN:{Percent:3.6,Fixed:3}};gform.addFilter("gform_calculation_result",function(e,r,t,i){if(!_gformPriceFields[t])return e;if(gfSubtotalFieldID.constructor===Array&&gfSubtotalFieldID[t]===r.field_id){var c=_gformPriceFields[t],n=0;_anyProductSelected=!1;for(var d=0;d<c.length;d++)c[d]!==gfSubtotalFieldID[t]&&(n+=gformCalculateProductPrice(t,c[d]));_anyProductSelected&&(n+=gformGetShippingPrice(t));var o=jQuery(".ginput_container_creditcard").attr("id")+"_1",a=gformFindCardType(jQuery("#"+o).val()),l=(P=(P="USD")||"USD",u="amex"!==a?{Percent:2.2,Fixed:.3}:{Percent:3.5,Fixed:0},(!1||!1)&&(u.Percent+=1),"USD"!==P&&(u.Percent+=1),u),F=function(e,r,t){t=t||f[r];var i=((e=parseFloat(e))+parseFloat(t.Fixed))/(1-parseFloat(t.Percent)/100);return{amount:e,fee:(i-e).toFixed(2),total:i.toFixed(2)}}(n,"USD",l);return console.log("getFee",l),console.log("calcFee",F),F.fee}var P,u;return e})}(jQuery);';
//			.
//			          '(function($){' .
//			          '$(".ginput_total_" + formId).bind("DOMSubtreeModified", function(e) {' .
//			          'if (e.target.innerHTML.length > 0) {' .
//			          'console.log(e);' .
//			          '}' .
//			          '});' .
//			          '})(jQuery);';

			foreach ( $form['fields'] as &$field ) {
				if ( self::has_subtotal_merge_tag( $field ) || ( isset( $field->hasSubtotal ) && $field->hasSubtotal ) ) {
					$script = sprintf( 'window["gfSubtotalFieldID"]=window["gfSubtotalFieldID"] || [];' .
					                   'gfSubtotalFieldID[%d]=%d;',
							$form['id'],
							$field->id
					          ) . $script;
					\GFFormDisplay::add_init_script( $form['id'], 'subtotal', \GFFormDisplay::ON_PAGE_RENDER, $script );
					break;
				}

			}


		}

		public function add_merge_tags( $form ) {

			$label = __( 'Subtotal', 'gravityforms' );

			?>

			<script type="text/javascript">

                // for the future (not yet supported for calc field)
                gform.addFilter("gform_merge_tags", "gwcs_add_merge_tags");

                function gwcs_add_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
                    mergeTags["pricing"].tags.push({
                        tag: '<?php echo self::$merge_tag; ?>',
                        label: '<?php echo $label; ?>'
                    });
                    return mergeTags;
                }

                // hacky, but only temporary
                jQuery(document).ready(function ($) {

                    var $calcMergeTagSelect = $('#field_calculation_formula_variable_select');
                    $calcMergeTagSelect.find('optgroup').eq(0).append('<option value="<?php echo self::$merge_tag; ?>"><?php echo $label; ?></option>');

                });

			</script>

			<?php
			//return the form object from the php hook
			return $form;
		}

		public static function get_subtotal_fields( $form ) {
			$fields = array();

			foreach ( $form['fields'] as $field ) {
				if ( self::has_subtotal_merge_tag( $field ) || ( isset( $field->hasSubtotal ) && $field->hasSubtotal ) ) {
					$fields[] = $field;
				}
			}

			return $fields;
		}

		public static function has_subtotal_merge_tag( $field ) {

			// check if form is passed
			if ( isset( $field['fields'] ) ) {

				$form = $field;
				foreach ( $form['fields'] as $field ) {
					if ( self::has_subtotal_merge_tag( $field ) ) {
						return true;
					}
				}

			} else {

				if ( isset( $field['calculationFormula'] ) && strpos( $field['calculationFormula'], self::$merge_tag ) !== false ) {
					$field['hasSubtotal'] = true;

					return true;
				}

			}

			return false;
		}

	}
}
