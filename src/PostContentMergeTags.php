<?php
/**
 * Gravity Wiz // Gravity Forms Post Content Merge Tags
 *
 * Adds support for using Gravity Form merge tags in your post content. This functionality requires that the entry ID is
 * is passed to the post via the "eid" parameter.
 *
 * Setup your confirmation page (requires GFv1.8) or confirmation URL "Redirect Query String" setting to
 * include this parameter: 'eid={entry_id}'. You can then use any entry-based merge tag in your post content.
 *
 * You may copy, distribute and modify the software as long as you track changes/dates in source files.
 * Any modifications to or software including (via compiler) GPL-licensed code must also be made
 * available under the GPL along with build & install instructions.
 *
 * @package    WPS\Plugins\GravityForms\CPT
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2015-2018 Travis Smith, 2014-2018 Gravity Wiz
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://gist.github.com/spivurno/6893785
 * @version    1.2
 * @author     David Smith <david@gravitywiz.com>
 * @video     http://screencast.com/t/g6Y12zOf4
 * @since      0.1.0
 */

namespace WPS\Plugins\GravityForms\MergeTags;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPS\Plugins\GravityForms\MergeTags\PostContentMergeTags' ) ) {
	class PostContentMergeTags extends \WPS\Core\Singleton {

		public static $_entry = null;
		private $_args = null;

		protected function __construct( $args ) {

			if ( ! class_exists( 'GFForms' ) ) {
				return;
			}

			$this->_args = wp_parse_args( $args, array(
				'auto_append_eid' => true, // true, false or array of form IDs
				'encrypt_eid'     => false,
			) );

			add_filter( 'the_content', array( $this, 'replace_merge_tags' ), 1 );
			add_filter( 'gform_replace_merge_tags', array( $this, 'replace_encrypt_entry_id_merge_tag' ), 10, 3 );

			if ( ! empty( $this->_args['auto_append_eid'] ) ) {
				add_filter( 'gform_confirmation', array( $this, 'append_eid_parameter' ), 20, 3 );
			}

		}

		public function replace_merge_tags( $post_content ) {

			$entry = $this->get_entry();
			if ( ! $entry ) {
				return $post_content;
			}

			$form = \GFFormsModel::get_form_meta( $entry['form_id'] );

			$post_content = $this->replace_field_label_merge_tags( $post_content, $form );
			$post_content = \GFCommon::replace_variables( $post_content, $form, $entry, false, false, false );

			return $post_content;
		}

		protected function replace_field_label_merge_tags( $text, $form ) {

			preg_match_all( '/{([^:]+?)}/', $text, $matches, PREG_SET_ORDER );
			if ( empty( $matches ) ) {
				return $text;
			}

			foreach ( $matches as $match ) {

				list( $search, $field_label ) = $match;

				foreach ( $form['fields'] as $field ) {

					$matches_admin_label = rgar( $field, 'adminLabel' ) == $field_label;
					$matches_field_label = false;

					$input_id = '';
					if ( is_array( $field['inputs'] ) ) {
						foreach ( $field['inputs'] as $input ) {
							if ( \GFFormsModel::get_label( $field, $input['id'] ) == $field_label ) {
								$matches_field_label = true;
								$input_id            = $input['id'];
								break;
							}
						}
					} else {
						$matches_field_label = \GFFormsModel::get_label( $field ) == $field_label;
						$input_id            = $field['id'];
					}

					if ( ! $matches_admin_label && ! $matches_field_label ) {
						continue;
					}

					if ( '' !== $input_id ) {
						$replace = sprintf( '{%s:%s}', $field_label, (string) $input_id );
						$text    = str_replace( $search, $replace, $text );
					}

					break;
				}

			}

			return $text;
		}

		public function replace_encrypt_entry_id_merge_tag( $text, $form, $entry ) {

			if ( strpos( $text, '{encrypted_entry_id}' ) === false ) {
				return $text;
			}

			// $entry is not always a "full" entry
			$entry_id = rgar( $entry, 'id' );
			if ( $entry_id ) {
				$entry_id = $this->prepare_eid( $entry['id'], true );
			}

			return str_replace( '{encrypted_entry_id}', $entry_id, $text );
		}

		public function append_eid_parameter( $confirmation, $form, $entry ) {

			$is_ajax_redirect = is_string( $confirmation ) && strpos( $confirmation, 'gformRedirect' );
			$is_redirect      = is_array( $confirmation ) && isset( $confirmation['redirect'] );

			if ( ! $this->is_auto_eid_enabled( $form ) || ! ( $is_ajax_redirect || $is_redirect ) ) {
				return $confirmation;
			}

			$eid = $this->prepare_eid( $entry['id'] );

			if ( $is_ajax_redirect ) {
				preg_match_all( '/gformRedirect.+?(http.+?)(?=\'|")/', $confirmation, $matches, PREG_SET_ORDER );
				list( $full_match, $url ) = $matches[0];
				$redirect_url = add_query_arg( array( 'eid' => $eid ), $url );
				$confirmation = str_replace( $url, $redirect_url, $confirmation );
			} else {
				$redirect_url             = add_query_arg( array( 'eid' => $eid ), $confirmation['redirect'] );
				$confirmation['redirect'] = $redirect_url;
			}

			return $confirmation;
		}

		protected function prepare_eid( $entry_id, $force_encrypt = false ) {

			$eid        = $entry_id;
			$do_encrypt = $force_encrypt || $this->_args['encrypt_eid'];

			if ( $do_encrypt && is_callable( array( 'GFCommon', 'encrypt' ) ) ) {
				$eid = rawurlencode( \GFCommon::openssl_encrypt( $eid ) );
			}

			return $eid;
		}

		protected function get_entry() {

			if ( ! self::$_entry ) {

				$entry_id = $this->get_entry_id();
				if ( ! $entry_id ) {
					return false;
				}

				$entry = \GFFormsModel::get_lead( $entry_id );
				if ( empty( $entry ) ) {
					return false;
				}

				self::$_entry = $entry;

			}

			return self::$_entry;
		}

		protected function get_entry_id() {

			$entry_id = rgget( 'eid' );
			if ( $entry_id ) {
				return $this->maybe_decrypt_entry_id( $entry_id );
			}

			$post = get_post();
			if ( $post ) {
				$entry_id = get_post_meta( $post->ID, '_gform-entry-id', true );
			}

			return $entry_id ? $entry_id : false;
		}

		protected function maybe_decrypt_entry_id( $entry_id ) {

			// if encryption is enabled, 'eid' parameter MUST be encrypted
			$do_encrypt = $this->_args['encrypt_eid'];

			if ( ! $entry_id ) {
				return null;
			} elseif ( ! $do_encrypt && is_numeric( $entry_id ) && intval( $entry_id ) > 0 ) {
				return $entry_id;
			} else {
				// gEYs6Cqzh1akKc7Y4RGkV8HtcJqQZRmNH+ONxuFEvXM
				// 0FSCGpzzmt+4Y05fFsJ4ipRZfqD/zdi2ecEeMMRKCjc=
				$entry_id = is_callable( array( 'GFCommon', 'decrypt' ) ) ? \GFCommon::openssl_decrypt( $entry_id ) : $entry_id;

				return intval( $entry_id );
			}

		}

		protected function is_auto_eid_enabled( $form ) {

			$auto_append_eid = $this->_args['auto_append_eid'];

			if ( is_bool( $auto_append_eid ) && $auto_append_eid === true ) {
				return true;
			}

			if ( is_array( $auto_append_eid ) && in_array( $form['id'], $auto_append_eid ) ) {
				return true;
			}

			return false;
		}

	}
}