<?php
/**
 * Gravity Forms Merge Tag
 *
 * Enables
 *
 * You may copy, distribute and modify the software as long as you track changes/dates in source files.
 * Any modifications to or software including (via compiler) GPL-licensed code must also be made
 * available under the GPL along with build & install instructions.
 *
 * @package    WPS\Plugins\GravityForms\MergeTags
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2015-2019 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @version    0.0.1
 */

namespace WPS\WP\Plugins\GravityForms\MergeTags;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\MergeTag' ) ) {
	/**
	 * Class MergeTag.
	 *
	 * @package WPS\Plugins\GravityForms\MergeTags
	 */
	class MergeTag {

		/**
		 * Gravity Forms Entry Array.
		 *
		 * @var array
		 */
		protected static $_entry = null;

		/**
		 * Value of the merge tag.
		 *
		 * @var mixed|null
		 */
		private $value = null;

		/**
		 * Callback to get the value of the merge tag.
		 *
		 * @var callable|null
		 */
		private $callback = null;

		/**
		 * Merge tag.
		 *
		 * @var string
		 */
		private $merge_tag;

		/**
		 * Merge Tag Factory.
		 *
		 * @var \WPS\Plugins\GravityForms\MergeTags\MergeTagFactory
		 */
		private $factory;

		/**
		 * MergeTag constructor.
		 *
		 * @param string $merge_tag         Merge tag string with or without {}.
		 * @param string $merge_tag_label   Merge tag label for the Merge Tag admin dropdowns.
		 * @param mixed  $value_or_callback Value or callback to retrieve the value of the merge tag.
		 * @param string $merge_group       Merge group slug (optional).
		 * @param string $merge_group_label Merge group label (optional).
		 */
		public function __construct( $merge_tag, $merge_tag_label, $value_or_callback, $merge_group = 'custom', $merge_group_label = '' ) {

			if ( ! class_exists( 'GFForms' ) ) {
				return;
			}

			// Instantiate the Factory.
			$this->factory = MergeTagFactory::get_instance();

			// Sanitize the merge tag.
			$this->merge_tag = $merge_tag = $this->factory->sanitize_merge_tag( $merge_tag );

			// Create the Merge Tag group.
			$merge_group_label = '' === $merge_group_label ? __( 'Custom', 'wps' ) : $merge_group_label;
			$this->factory->create_merge_group( $merge_group, $merge_group_label );

			// Store the value/callback.
			if ( is_callable( $value_or_callback ) ) {
				$this->callback = $value_or_callback;
			} else {
				$this->value = $value_or_callback;
			}

			// Add the merge tag
			$this->factory->add_merge_tag( $this->merge_tag, $merge_tag_label, $merge_group );

			// Hook into the stuffs.
			add_filter( 'gform_replace_merge_tags', array( $this, 'gform_replace_merge_tags' ), 10, 3 );

		}

		/**
		 * Gets the merge tag string.
		 *
		 * @return string
		 */
		public function get_merge_tag() {
			return $this->merge_tag;
		}

		/**
		 * Replaces the merge tags that appear in the content.
		 *
		 * @param string $content Content to be searched.
		 *
		 * @return string
		 */
		public function replace_merge_tags( $content ) {

			$entry = $this->get_entry();
			if ( ! $entry ) {
				return $content;
			}

			$form    = \GFFormsModel::get_form_meta( $entry['form_id'] );
			$content = \GFCommon::replace_variables( $content, $form, $entry, false, false, false );

			return $content;

		}

		/**
		 * Replaces merge tags from a specified text string.
		 *
		 * @param string $text Text to be searched.
		 * @param array $form Gravity Forms Form Array Object.
		 * @param array $entry Gravity Forms Entry Array Object.
		 *
		 * @return mixed
		 */
		public function gform_replace_merge_tags( $text, $form, $entry ) {

			if ( strpos( $text, $this->merge_tag ) === false ) {
				return $text;
			}

			if ( is_callable( $this->callback ) ) {
				$value = call_user_func( $this->callback, $form, $entry );
			} else {
				$value = $this->value;
			}

			return str_replace( $this->merge_tag, $value, $text );
		}

		/**
		 * Gets the entry.
		 *
		 * @return array|bool|\WP_Error
		 */
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

		/**
		 * Gets the entry ID.
		 *
		 * @return bool|mixed|string
		 */
		protected function get_entry_id() {

			$entry_id = rgget( 'eid' );
			if ( $entry_id ) {
				return $entry_id;
			}

			$post = get_post();
			if ( $post ) {
				$entry_id = get_post_meta( $post->ID, '_gform-entry-id', true );
			}

			return $entry_id ? $entry_id : false;
		}

	}
}
