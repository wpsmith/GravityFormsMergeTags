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
 * @copyright  2015-2019 Travis Smith, 2014-2019 Gravity Wiz
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://gist.github.com/spivurno/6893785
 * @version    1.2
 * @author     David Smith <david@gravitywiz.com>
 * @video     http://screencast.com/t/g6Y12zOf4
 * @since      0.1.0
 */

namespace WPS\WP\Plugins\GravityForms\MergeTags;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\MergeTagFactory' ) ) {
	/**
	 * Class MergeTagFactory.
	 *
	 * @package WPS\Plugins\GravityForms\MergeTags
	 */
	class MergeTagFactory extends \WPS\Core\Singleton {

		/**
		 * Merge Groups.
		 *
		 * Holds an array of group slug-label pairs.
		 *
		 * For example:
		 * array(
		 *    'group-slug' => 'Group Label',
		 * )
		 * @var array
		 */
		private $groups = array();

		/**
		 * Merge Tags.
		 *
		 * Holds an array of group-tags arrays where tag arrays have `tag` and `label` properties.
		 *
		 * For example:
		 * array(
		 *    'custom' => array(
		 *        '{tagName}' => array(
		 *            'tag'   => '{tagName}',
		 *            'label' => 'Tag Label',
		 *        ),
		 *    ),
		 * )
		 * @var array
		 */
		private $merge_tags = array();

		/**
		 * MergeTagFactory constructor.
		 */
		protected function __construct() {

			if ( ! class_exists( 'GFForms' ) ) {
				return;
			}

			add_action( 'gform_admin_pre_render', array( $this, 'add_merge_tags' ), 999 );

		}

		/**
		 * Determines whether a specific group exists.
		 *
		 * @param string $group Group slug.
		 *
		 * @return bool
		 */
		public function group_exists( $group ) {
			return ( isset( $this->merge_tags[ $group ] ) || isset( $this->groups[ $group ] ) );
		}

		/**
		 * Creates a merge group.
		 *
		 * @param string $group Group slug (sanitized with dashes).
		 * @param string $label Group label. Optional.
		 *                      Default: Slug capitalized, dashes/underscores replaced with spaces.
		 *
		 * @return bool Whether the group was created or not.
		 */
		public function create_merge_group( $group, $label = '' ) {
			$group = sanitize_title_with_dashes( $group );

			if ( $this->group_exists( $group ) ) {
				return false;
			}

			$this->merge_tags[ $group ] = array();

			if ( '' !== $label ) {
				$this->groups[ $group ] = $label;
			} else {
				$this->groups[ $group ] = ucwords( str_replace( '_', ' ', str_replace( '-', ' ', $group ) ) );
			}

			return true;
		}

		/**
		 * Removes a group.
		 *
		 * @param string $group
		 *
		 * @return bool Whether the group was removed or not.
		 */
		public function remove_merge_group( $group ) {
			$group = sanitize_title_with_dashes( $group );

			if ( ! $this->group_exists( $group ) ) {
				return false;
			}

			if ( isset( $this->merge_tags[ $group ] ) ) {
				unset( $this->merge_tags[ $group ] );
			}
			if ( isset( $this->groups[ $group ] ) ) {
				unset( $this->groups[ $group ] );
			}

			return true;
		}

		/**
		 * Adds the merge tag to the specified group.
		 *
		 * @param string $merge_tag       Merge tag with or without {}.
		 * @param string $merge_tag_label Merge tag label.
		 * @param string $group           Group slug. Optional. Defaults to 'custom'.
		 * @param string $group_label     Group label. Optional. Defaults to 'Custom'.
		 */
		public function add_merge_tag( $merge_tag, $merge_tag_label, $group = 'custom', $group_label = '' ) {

			if ( ! $this->group_exists( $group ) ) {
				$this->create_merge_group( $group, $group_label );
			}

			// Sanitize.
			$merge_tag = $this->sanitize_merge_tag( $merge_tag );

			// Add.
			$this->merge_tags[ $group ][ $merge_tag ] = array(
				'tag'   => $merge_tag,
				'label' => $merge_tag_label,
			);

		}

		/**
		 * Sanitizes the merge tag to ensure it is wrapped with braces {merge_tag}.
		 *
		 * Merge tag is sanitized with `sanitize_html_class`.
		 *
		 * @param string $merge_tag Merge tag string with or without {}.
		 *
		 * @return string Sanitized merge tag wrapped with braces {tag}.
		 */
		public function sanitize_merge_tag( $merge_tag ) {
			$merge_tag = trim( $merge_tag, ' {}' );

			return '{' . sanitize_html_class( $merge_tag ) . '}';
		}

		/**
		 * Removes merge tag.
		 *
		 * @param string $merge_tag Removes the merge tag from the group.
		 * @param string $group     Group slug.
		 *
		 * @return bool Whether the tag was removed.
		 */
		public function remove_merge_tag( $merge_tag, $group = 'custom' ) {

			if ( ! $this->group_exists( $group ) ) {
				return false;
			}

			$merge_tag = $this->sanitize_merge_tag( $merge_tag );
			if ( isset( $this->merge_tags[ $group ][ $merge_tag ] ) ) {
				unset( $this->merge_tags[ $group ][ $merge_tag ] );

				return true;
			}

			return false;
		}

		/**
		 * Include UR related merge tags in the merge tag drop downs in the form settings area.
		 *
		 * @param array $form The current form object.
		 *
		 * @return array
		 */
		public function add_merge_tags( $form ) {
			?>
			<script type="text/javascript">
                if (window.gform) {
                    gform.addFilter('gform_merge_tags', 'wps_gf_merge_tags');
                }

                var mergeTags = mergeTags || {};
                if (typeof form != 'undefined' && jQuery('.merge-tag-support').length >= 0) {
                    jQuery('.merge-tag-support').each(function () {
                        new gfMergeTagsObj(form, jQuery(this));
                    });
                }
                function wps_gf_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
					<?php
					foreach ( $this->merge_tags as $group => $merge_tags ) {
						printf( 'mergeTags["%1$s"] = mergeTags["%1$s"] || {label:"%2$s",tags:[]};'  . "\n", $group, $this->groups[ $group ] );
						foreach ( $merge_tags as $merge_tag ) {
							printf( 'mergeTags["%s"].tags.push(%s);' . "\n", $group, json_encode( $merge_tag ) );
						}
					}
					?>

                    return mergeTags;
                }
			</script>
			<?php

			return $form;
		}

	}
}
