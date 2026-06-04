<?php
/**
 * Plugin Name: MCP Abilities - Broken Link Checker
 * Plugin URI: https://devenia.com
 * Description: Broken Link Checker (BLC) abilities for MCP. List broken links, replace URLs in content, auto-fix redirected links, and clear the BLC local queue.
 * Version: 0.1.4
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_BLC
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Abilities API is available.
 */
function mcp_blc_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p><strong>MCP Abilities - Broken Link Checker</strong> requires the Abilities API plugin to be installed and activated.</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Check if Broken Link Checker plugin appears to be active.
 */
function mcp_blc_is_active(): bool {
	if ( defined( 'BLC_VERSION' ) || class_exists( 'WPMUDEV_BLC' ) || class_exists( 'BLC_Link' ) ) {
		return true;
	}

	// Some BLC versions don't expose stable runtime symbols early enough for this check.
	// If core BLC tables exist, treat the local scanner as available.
	return mcp_blc_table_exists( mcp_blc_table_name( 'links' ) );
}

/**
 * Return a standard inactive error response.
 */
function mcp_blc_require_active(): ?array {
	if ( mcp_blc_is_active() ) {
		return null;
	}

	return array(
		'success' => false,
		'message' => 'Broken Link Checker plugin is not active.',
	);
}

/**
 * Return the BLC table name for a suffix.
 *
 * @param string $suffix Table suffix (e.g. links, instances, synch).
 */
function mcp_blc_table_name( string $suffix ): string {
	global $wpdb;
	return $wpdb->prefix . 'blc_' . $suffix;
}

/**
 * Check whether a DB table exists (cached per request).
 *
 * @param string $table Full table name.
 */
function mcp_blc_table_exists( string $table ): bool {
	static $cache = array();

	if ( isset( $cache[ $table ] ) ) {
		return $cache[ $table ];
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time schema inspection.
	$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	$cache[ $table ] = $exists;

	return $exists;
}

/**
 * Get column names for a table (cached per request).
 *
 * @param string $table Full table name.
 * @return string[]
 */
function mcp_blc_get_table_columns( string $table ): array {
	static $cache = array();

	if ( isset( $cache[ $table ] ) ) {
		return $cache[ $table ];
	}

	if ( ! mcp_blc_table_exists( $table ) ) {
		$cache[ $table ] = array();
		return $cache[ $table ];
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection.
	$rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
	$cols = array();
	foreach ( $rows as $row ) {
		if ( isset( $row['Field'] ) ) {
			$cols[] = (string) $row['Field'];
		}
	}

	$cache[ $table ] = $cols;
	return $cols;
}

/**
 * Check if a table has a specific column.
 */
function mcp_blc_has_column( string $table, string $column ): bool {
	return in_array( $column, mcp_blc_get_table_columns( $table ), true );
}

/**
 * Build a list of BLC tables in the current site DB.
 *
 * @return string[] Full table names.
 */
function mcp_blc_list_tables_internal(): array {
	global $wpdb;
	$pattern = $wpdb->esc_like( $wpdb->prefix . 'blc_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime discovery for interoperability.
	$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
	if ( ! is_array( $rows ) ) {
		return array();
	}

	return array_values(
		array_map(
			static function ( $table ) {
				return (string) $table;
			},
			$rows
		)
	);
}

/**
 * Normalize BLC link rows to a stable output shape.
 *
 * @param array[] $rows Raw rows from blc_links.
 * @return array[]
 */
function mcp_blc_normalize_broken_links( array $rows ): array {
	$normalized = array();

	foreach ( $rows as $row ) {
		$url          = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		$final_url    = isset( $row['final_url'] ) ? trim( (string) $row['final_url'] ) : '';
		$redirect_url = isset( $row['redirect_url'] ) ? trim( (string) $row['redirect_url'] ) : '';
		$suggested    = '';

		foreach ( array( $final_url, $redirect_url ) as $candidate ) {
			if ( '' !== $candidate && $candidate !== $url ) {
				$suggested = $candidate;
				break;
			}
		}

		$normalized[] = array(
			'link_id'           => isset( $row['link_id'] ) ? (int) $row['link_id'] : 0,
			'url'               => $url,
			'broken'            => isset( $row['broken'] ) ? (bool) (int) $row['broken'] : null,
			'false_positive'    => isset( $row['false_positive'] ) ? (bool) (int) $row['false_positive'] : null,
			'dismissed'         => isset( $row['dismissed'] ) ? (bool) (int) $row['dismissed'] : null,
			'http_code'         => isset( $row['http_code'] ) ? (int) $row['http_code'] : null,
			'status_code'       => isset( $row['status_code'] ) ? (int) $row['status_code'] : null,
			'redirect_count'    => isset( $row['redirect_count'] ) ? (int) $row['redirect_count'] : null,
			'final_url'         => $final_url,
			'redirect_url'      => $redirect_url,
			'suggested_target'  => $suggested,
			'last_check'        => isset( $row['last_check'] ) ? (string) $row['last_check'] : null,
			'last_check_attempt' => isset( $row['last_check_attempt'] ) ? (string) $row['last_check_attempt'] : null,
			'first_failure'     => isset( $row['first_failure'] ) ? (string) $row['first_failure'] : null,
			'being_checked'     => isset( $row['being_checked'] ) ? (int) $row['being_checked'] : null,
		);
	}

	return $normalized;
}

/**
 * Query broken links from blc_links using adaptive column checks.
 *
 * @param int  $limit              Max rows.
 * @param int  $offset             Offset.
 * @param bool $include_dismissed  Include dismissed rows.
 * @param bool $include_false_pos  Include false positives.
 * @return array
 */
function mcp_blc_get_broken_links( int $limit = 100, int $offset = 0, bool $include_dismissed = false, bool $include_false_pos = false ): array {
	$links_table = mcp_blc_table_name( 'links' );
	if ( ! mcp_blc_table_exists( $links_table ) ) {
		return array(
			'success' => false,
			'message' => 'BLC links table not found: ' . $links_table,
			'rows'    => array(),
		);
	}

	$columns = mcp_blc_get_table_columns( $links_table );
	$wanted  = array(
		'link_id',
		'url',
		'broken',
		'false_positive',
		'dismissed',
		'http_code',
		'status_code',
		'redirect_count',
		'final_url',
		'redirect_url',
		'last_check',
		'last_check_attempt',
		'first_failure',
		'being_checked',
	);
	$select_columns = array_values( array_intersect( $wanted, $columns ) );

	if ( empty( $select_columns ) ) {
		return array(
			'success' => false,
			'message' => 'BLC links table exists but none of the expected columns were found.',
			'rows'    => array(),
		);
	}

	$where = array();
	if ( in_array( 'broken', $columns, true ) ) {
		$where[] = '`broken` = 1';
	} elseif ( in_array( 'http_code', $columns, true ) ) {
		$where[] = '`http_code` >= 400';
	} elseif ( in_array( 'status_code', $columns, true ) ) {
		$where[] = '`status_code` >= 400';
	}

	if ( ! $include_false_pos && in_array( 'false_positive', $columns, true ) ) {
		$where[] = '(`false_positive` = 0 OR `false_positive` IS NULL)';
	}

	if ( ! $include_dismissed && in_array( 'dismissed', $columns, true ) ) {
		$where[] = '(`dismissed` = 0 OR `dismissed` IS NULL)';
	}

	$where_sql = '';
	if ( ! empty( $where ) ) {
		$where_sql = ' WHERE ' . implode( ' AND ', $where );
	}

	$order_by = in_array( 'last_check', $columns, true ) ? '`last_check` DESC' : ( in_array( 'link_id', $columns, true ) ? '`link_id` DESC' : '`url` ASC' );
	$limit    = min( 500, max( 1, $limit ) );
	$offset   = max( 0, $offset );

	$sql = 'SELECT ' . implode(
		', ',
		array_map(
			static function ( string $column ): string {
				return '`' . esc_sql( $column ) . '`';
			},
			$select_columns
		)
	) . ' FROM `' . esc_sql( $links_table ) . '`' . $where_sql . ' ORDER BY ' . $order_by . ' LIMIT %d OFFSET %d';

	global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Query uses whitelisted columns/table names plus prepared LIMIT/OFFSET values.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A );

	$rows = is_array( $rows ) ? $rows : array();

	return array(
		'success' => true,
		'rows'    => mcp_blc_normalize_broken_links( $rows ),
		'message' => '',
	);
}

/**
 * Attach per-link instance counts and sampled source refs when possible.
 *
 * @param array[] $links Normalized links.
 * @param int     $max_sources_per_link Max source refs to attach per link.
 * @return array[]
 */
function mcp_blc_enrich_with_instances( array $links, int $max_sources_per_link = 5 ): array {
	$instances_table = mcp_blc_table_name( 'instances' );
	if ( empty( $links ) || ! mcp_blc_table_exists( $instances_table ) || ! mcp_blc_has_column( $instances_table, 'link_id' ) ) {
		return $links;
	}

	$link_ids = array_values(
		array_filter(
			array_map(
				static function ( array $row ): int {
					return isset( $row['link_id'] ) ? (int) $row['link_id'] : 0;
				},
				$links
			),
			static function ( int $id ): bool {
				return $id > 0;
			}
		)
	);

	if ( empty( $link_ids ) ) {
		return $links;
	}

	global $wpdb;
	$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
	$columns      = mcp_blc_get_table_columns( $instances_table );
	$select_cols  = array( '`link_id`' );

	foreach ( array( 'container_type', 'container_id', 'parser_type', 'link_text' ) as $column ) {
		if ( in_array( $column, $columns, true ) ) {
			$select_cols[] = '`' . esc_sql( $column ) . '`';
		}
	}

	$sql = 'SELECT ' . implode( ', ', $select_cols ) . ' FROM `' . esc_sql( $instances_table ) . '` WHERE `link_id` IN (' . $placeholders . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Query uses whitelisted columns/table names and prepared link IDs.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $link_ids ), ARRAY_A );

	$grouped = array();
	foreach ( (array) $rows as $row ) {
		$link_id = isset( $row['link_id'] ) ? (int) $row['link_id'] : 0;
		if ( $link_id <= 0 ) {
			continue;
		}

		if ( ! isset( $grouped[ $link_id ] ) ) {
			$grouped[ $link_id ] = array(
				'count'   => 0,
				'sources' => array(),
			);
		}

		++$grouped[ $link_id ]['count'];

		if ( count( $grouped[ $link_id ]['sources'] ) >= $max_sources_per_link ) {
			continue;
		}

		$source = array();
		foreach ( array( 'container_type', 'container_id', 'parser_type', 'link_text' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$source[ $column ] = $row[ $column ];
			}
		}

		if ( isset( $source['container_type'], $source['container_id'] ) && 'post' === (string) $source['container_type'] ) {
			$post_id = (int) $source['container_id'];
			$post    = get_post( $post_id );
			if ( $post ) {
				$source['post_type']   = $post->post_type;
				$source['post_status'] = $post->post_status;
				$source['post_title']  = get_the_title( $post_id );
				$permalink             = get_permalink( $post_id );
				if ( $permalink ) {
					$source['permalink'] = $permalink;
				}
			}
		}

		$grouped[ $link_id ]['sources'][] = $source;
	}

	foreach ( $links as &$link ) {
		$link_id = isset( $link['link_id'] ) ? (int) $link['link_id'] : 0;
		if ( $link_id > 0 && isset( $grouped[ $link_id ] ) ) {
			$link['instances_count'] = $grouped[ $link_id ]['count'];
			$link['sources']         = $grouped[ $link_id ]['sources'];
		} else {
			$link['instances_count'] = 0;
			$link['sources']         = array();
		}
	}
	unset( $link );

	return $links;
}

/**
 * Replace a URL in post/page content and excerpt.
 *
 * @param string   $old_url URL to replace.
 * @param string   $new_url Replacement URL.
 * @param string[] $post_types Post types to scan.
 * @param string[] $statuses Post statuses to scan.
 * @param bool     $dry_run If true, don't write.
 * @return array
 */
function mcp_blc_replace_url_in_content( string $old_url, string $new_url, array $post_types, array $statuses, bool $dry_run = false ): array {
	global $wpdb;

	$old_url = trim( $old_url );
	$new_url = trim( $new_url );

	if ( '' === $old_url || '' === $new_url ) {
		return array(
			'success' => false,
			'message' => 'Both old_url and new_url are required.',
		);
	}

	if ( $old_url === $new_url ) {
		return array(
			'success' => true,
			'message' => 'old_url and new_url are identical; nothing to change.',
			'updated_posts' => array(),
			'updated_count' => 0,
		);
	}

	$post_types = array_values(
		array_filter(
			array_map( 'sanitize_key', $post_types ),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		)
	);
	if ( empty( $post_types ) ) {
		$post_types = array( 'post', 'page' );
	}

	$statuses = array_values(
		array_filter(
			array_map( 'sanitize_key', $statuses ),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		)
	);
	if ( empty( $statuses ) ) {
		$statuses = array( 'publish' );
	}

	$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$like_value          = '%' . $wpdb->esc_like( $old_url ) . '%';

	$sql = "SELECT ID, post_type, post_status, post_title, post_content, post_excerpt
		FROM {$wpdb->posts}
		WHERE post_type IN ($type_placeholders)
		  AND post_status IN ($status_placeholders)
		  AND (post_content LIKE %s OR post_excerpt LIKE %s)";

	$args = array_merge( $post_types, $statuses, array( $like_value, $like_value ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholder lists are built from sanitized arrays and values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	$rows = is_array( $rows ) ? $rows : array();

	$updated_posts = array();
	foreach ( $rows as $row ) {
		$post_id      = (int) $row['ID'];
		$post_content = (string) $row['post_content'];
		$post_excerpt = (string) $row['post_excerpt'];

		$new_content  = str_replace( $old_url, $new_url, $post_content, $content_replacements );
		$new_excerpt  = str_replace( $old_url, $new_url, $post_excerpt, $excerpt_replacements );
		$total_repl   = (int) $content_replacements + (int) $excerpt_replacements;

		if ( $total_repl <= 0 ) {
			continue;
		}

		$entry = array(
			'id'                => $post_id,
			'post_type'         => (string) $row['post_type'],
			'post_status'       => (string) $row['post_status'],
			'title'             => (string) $row['post_title'],
			'replacements'      => $total_repl,
			'content_replaced'  => (int) $content_replacements,
			'excerpt_replaced'  => (int) $excerpt_replacements,
			'permalink'         => get_permalink( $post_id ) ?: '',
			'updated'           => false,
			'error'             => '',
		);

		if ( ! $dry_run ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$entry['error'] = 'No permission to edit post.';
				$updated_posts[] = $entry;
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
					'post_excerpt' => $new_excerpt,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				$entry['error'] = $result->get_error_message();
			} else {
				$entry['updated'] = true;
			}
		} else {
			$entry['updated'] = true;
		}

		$updated_posts[] = $entry;
	}

	$updated_count = count(
		array_filter(
			$updated_posts,
			static function ( array $row ): bool {
				return ! empty( $row['updated'] );
			}
		)
	);

	return array(
		'success'       => true,
		'old_url'       => $old_url,
		'new_url'       => $new_url,
		'dry_run'       => $dry_run,
		'updated_count' => $updated_count,
		'updated_posts' => $updated_posts,
	);
}

/**
 * Clear BLC queue-like tables (e.g. blc_synch) and optional transients.
 *
 * @param bool $clear_transients Whether to also delete BLC transients.
 * @param bool $dry_run Whether to avoid writes.
 * @return array
 */
function mcp_blc_clear_queue_internal( bool $clear_transients = false, bool $dry_run = false ): array {
	if ( $error = mcp_blc_require_active() ) {
		return $error;
	}

	global $wpdb;
	$tables             = mcp_blc_list_tables_internal();
	$queue_tables       = array();
	$cleared_table_rows = array();

	foreach ( $tables as $table ) {
		$basename = str_replace( $wpdb->prefix, '', $table );
		if ( false === strpos( $basename, 'blc_' ) ) {
			continue;
		}

		if ( false !== stripos( $basename, 'synch' ) || false !== stripos( $basename, 'queue' ) || false !== stripos( $basename, 'sync' ) ) {
			$queue_tables[] = $table;
		}
	}

	foreach ( $queue_tables as $table ) {
		$count = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue row count for operational report.
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );

		if ( ! $dry_run ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Operational queue cleanup.
			$wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $table ) . '`' );
		}

		$cleared_table_rows[] = array(
			'table'      => $table,
			'rows'       => $count,
			'truncated'  => ! $dry_run,
		);
	}

	$transient_deleted = 0;
	if ( $clear_transients ) {
		$patterns = array(
			'_transient_blc_%',
			'_transient_timeout_blc_%',
			'_site_transient_blc_%',
			'_site_transient_timeout_blc_%',
		);

		foreach ( $patterns as $pattern ) {
			$like = $pattern;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup report count.
			$matches = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->options . ' WHERE option_name LIKE %s', $like ) );
			if ( ! $dry_run ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Operational transient cleanup.
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s', $like ) );
			}
			$transient_deleted += $matches;
		}
	}

	return array(
		'success'            => true,
		'dry_run'            => $dry_run,
		'queue_tables_found' => $queue_tables,
		'queue_tables_count' => count( $queue_tables ),
		'tables'             => $cleared_table_rows,
		'transients_deleted' => $transient_deleted,
		'message'            => $dry_run ? 'Dry run completed.' : 'BLC queue cleanup completed.',
	);
}

/**
 * Mark BLC links as false positives (and optionally dismissed).
 *
 * @param int[] $link_ids Link IDs from blc_links.link_id.
 * @param bool  $dismiss Also mark as dismissed when the column exists.
 * @param bool  $clear_queue Clear queue after update.
 * @param bool  $clear_transients Clear BLC transients after update.
 * @param bool  $dry_run Report only.
 * @return array
 */
function mcp_blc_mark_false_positives_internal( array $link_ids, bool $dismiss = true, bool $clear_queue = true, bool $clear_transients = false, bool $dry_run = false ): array {
	if ( $error = mcp_blc_require_active() ) {
		return $error;
	}

	$links_table = mcp_blc_table_name( 'links' );
	if ( ! mcp_blc_table_exists( $links_table ) ) {
		return array(
			'success' => false,
			'message' => 'BLC links table not found: ' . $links_table,
		);
	}

	$ids = array_values(
		array_unique(
			array_filter(
				array_map( 'intval', $link_ids ),
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		)
	);

	if ( empty( $ids ) ) {
		return array(
			'success' => false,
			'message' => 'No valid link_ids provided.',
		);
	}

	$columns = mcp_blc_get_table_columns( $links_table );
	if ( ! in_array( 'false_positive', $columns, true ) ) {
		return array(
			'success' => false,
			'message' => 'BLC links table does not have a false_positive column.',
		);
	}

	global $wpdb;
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$set_parts    = array( '`false_positive` = 1' );
	if ( $dismiss && in_array( 'dismissed', $columns, true ) ) {
		$set_parts[] = '`dismissed` = 1';
	}

	$count_sql = 'SELECT COUNT(*) FROM `' . esc_sql( $links_table ) . '` WHERE `link_id` IN (' . $placeholders . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted table, prepared IDs.
	$matched = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $ids ) );

	$updated = 0;
	if ( ! $dry_run && $matched > 0 ) {
		$update_sql = 'UPDATE `' . esc_sql( $links_table ) . '` SET ' . implode( ', ', $set_parts ) . ' WHERE `link_id` IN (' . $placeholders . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted table/columns, prepared IDs.
		$result = $wpdb->query( $wpdb->prepare( $update_sql, $ids ) );
		$updated = is_int( $result ) ? $result : 0;
	}

	$queue_cleanup = null;
	if ( $clear_queue ) {
		$queue_cleanup = mcp_blc_clear_queue_internal( $clear_transients, $dry_run );
	}

	return array(
		'success'       => true,
		'dry_run'       => $dry_run,
		'link_ids'      => $ids,
		'matched_count' => $matched,
		'updated_count' => $dry_run ? $matched : $updated,
		'dismissed'     => $dismiss && in_array( 'dismissed', $columns, true ),
		'queue_cleanup' => $queue_cleanup,
		'message'       => $dry_run ? 'Dry run completed.' : 'Marked selected links as false positives.',
	);
}

/**
 * Delete selected BLC link rows (and related instances) by link_id.
 *
 * Intended for stale BLC records where the source content no longer contains the URL.
 *
 * @param int[] $link_ids Link IDs from blc_links.link_id.
 * @param bool  $clear_queue Clear queue after delete.
 * @param bool  $clear_transients Clear BLC transients after delete.
 * @param bool  $dry_run Report only.
 * @return array
 */
function mcp_blc_delete_links_internal( array $link_ids, bool $clear_queue = true, bool $clear_transients = false, bool $dry_run = false ): array {
	if ( $error = mcp_blc_require_active() ) {
		return $error;
	}

	$links_table     = mcp_blc_table_name( 'links' );
	$instances_table = mcp_blc_table_name( 'instances' );

	if ( ! mcp_blc_table_exists( $links_table ) ) {
		return array(
			'success' => false,
			'message' => 'BLC links table not found: ' . $links_table,
		);
	}

	$ids = array_values(
		array_unique(
			array_filter(
				array_map( 'intval', $link_ids ),
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		)
	);

	if ( empty( $ids ) ) {
		return array(
			'success' => false,
			'message' => 'No valid link_ids provided.',
		);
	}

	global $wpdb;
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	$matched_links = 0;
	$matched_instances = 0;
	$deleted_links = 0;
	$deleted_instances = 0;

	$count_links_sql = 'SELECT COUNT(*) FROM `' . esc_sql( $links_table ) . '` WHERE `link_id` IN (' . $placeholders . ')';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted table, prepared IDs.
	$matched_links = (int) $wpdb->get_var( $wpdb->prepare( $count_links_sql, $ids ) );

	if ( mcp_blc_table_exists( $instances_table ) && mcp_blc_has_column( $instances_table, 'link_id' ) ) {
		$count_instances_sql = 'SELECT COUNT(*) FROM `' . esc_sql( $instances_table ) . '` WHERE `link_id` IN (' . $placeholders . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted table, prepared IDs.
		$matched_instances = (int) $wpdb->get_var( $wpdb->prepare( $count_instances_sql, $ids ) );
	}

	if ( ! $dry_run && $matched_links > 0 ) {
		if ( mcp_blc_table_exists( $instances_table ) && mcp_blc_has_column( $instances_table, 'link_id' ) ) {
			$delete_instances_sql = 'DELETE FROM `' . esc_sql( $instances_table ) . '` WHERE `link_id` IN (' . $placeholders . ')';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted table, prepared IDs.
			$result_instances = $wpdb->query( $wpdb->prepare( $delete_instances_sql, $ids ) );
			$deleted_instances = is_int( $result_instances ) ? $result_instances : 0;
		}

		$delete_links_sql = 'DELETE FROM `' . esc_sql( $links_table ) . '` WHERE `link_id` IN (' . $placeholders . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Whitelisted table, prepared IDs.
		$result_links = $wpdb->query( $wpdb->prepare( $delete_links_sql, $ids ) );
		$deleted_links = is_int( $result_links ) ? $result_links : 0;
	}

	$queue_cleanup = null;
	if ( $clear_queue ) {
		$queue_cleanup = mcp_blc_clear_queue_internal( $clear_transients, $dry_run );
	}

	return array(
		'success'             => true,
		'dry_run'             => $dry_run,
		'link_ids'            => $ids,
		'matched_links'       => $matched_links,
		'matched_instances'   => $matched_instances,
		'deleted_links'       => $dry_run ? $matched_links : $deleted_links,
		'deleted_instances'   => $dry_run ? $matched_instances : $deleted_instances,
		'queue_cleanup'       => $queue_cleanup,
		'message'             => $dry_run ? 'Dry run completed.' : 'Deleted selected BLC links and related instances.',
	);
}

/**
 * Build auto-fix mappings from BLC broken rows using redirect/final URL columns.
 *
 * @param array[] $broken_links Normalized broken links.
 * @return array[] Map rows.
 */
function mcp_blc_build_auto_fix_mappings( array $broken_links ): array {
	$mappings = array();
	$seen     = array();

	foreach ( $broken_links as $row ) {
		$from = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
		$to   = isset( $row['suggested_target'] ) ? trim( (string) $row['suggested_target'] ) : '';

		if ( '' === $from || '' === $to || $from === $to ) {
			continue;
		}

		if ( ! wp_http_validate_url( $from ) || ! wp_http_validate_url( $to ) ) {
			continue;
		}

		if ( isset( $seen[ $from ] ) ) {
			continue;
		}

		$seen[ $from ] = true;
		$mappings[]    = array(
			'from'    => $from,
			'to'      => $to,
			'link_id' => isset( $row['link_id'] ) ? (int) $row['link_id'] : 0,
		);
	}

	return $mappings;
}

/**
 * Decode a JSON-backed option into an array.
 *
 * @param string $option_name Option name.
 * @return array{exists:bool,value:array,raw:mixed}
 */
function mcp_blc_get_json_option_array( string $option_name ): array {
	$raw = get_option( $option_name, null );
	if ( null === $raw ) {
		return array(
			'exists' => false,
			'value'  => array(),
			'raw'    => null,
		);
	}

	if ( is_array( $raw ) ) {
		return array(
			'exists' => true,
			'value'  => $raw,
			'raw'    => $raw,
		);
	}

	if ( is_string( $raw ) && '' !== trim( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array(
				'exists' => true,
				'value'  => $decoded,
				'raw'    => $raw,
			);
		}
	}

	return array(
		'exists' => true,
		'value'  => array(),
		'raw'    => $raw,
	);
}

/**
 * Persist a JSON-backed option using the same string format BLC uses.
 */
function mcp_blc_update_json_option_array( string $option_name, array $value ): bool {
	return update_option( $option_name, wp_json_encode( $value ), false );
}

/**
 * Summarize BLC notification settings without returning large module payloads.
 */
function mcp_blc_get_notification_settings_internal(): array {
	$legacy = mcp_blc_get_json_option_array( 'wsblc_options' );
	$modern = mcp_blc_get_json_option_array( 'blc_settings' );
	$modern_schedule = isset( $modern['value']['schedule'] ) && is_array( $modern['value']['schedule'] ) ? $modern['value']['schedule'] : array();

	return array(
		'success' => true,
		'active'  => mcp_blc_is_active(),
		'legacy'  => array(
			'option_exists'                     => (bool) $legacy['exists'],
			'send_email_notifications'         => (bool) ( $legacy['value']['send_email_notifications'] ?? false ),
			'send_authors_email_notifications' => (bool) ( $legacy['value']['send_authors_email_notifications'] ?? false ),
			'notification_email_address'       => (string) ( $legacy['value']['notification_email_address'] ?? '' ),
			'notification_schedule'            => (string) ( $legacy['value']['notification_schedule'] ?? '' ),
			'last_email'                       => isset( $legacy['value']['last_email'] ) && is_array( $legacy['value']['last_email'] ) ? $legacy['value']['last_email'] : null,
		),
		'modern'  => array(
			'option_exists' => (bool) $modern['exists'],
			'schedule'      => array(
				'active'                     => (bool) ( $modern_schedule['active'] ?? false ),
				'frequency'                  => (string) ( $modern_schedule['frequency'] ?? '' ),
				'time'                       => (string) ( $modern_schedule['time'] ?? '' ),
				'recipients'                 => isset( $modern_schedule['recipients'] ) && is_array( $modern_schedule['recipients'] ) ? $modern_schedule['recipients'] : array(),
				'registered_recipients_data' => isset( $modern_schedule['registered_recipients_data'] ) && is_array( $modern_schedule['registered_recipients_data'] ) ? $modern_schedule['registered_recipients_data'] : array(),
				'emailrecipients'            => isset( $modern_schedule['emailrecipients'] ) && is_array( $modern_schedule['emailrecipients'] ) ? $modern_schedule['emailrecipients'] : array(),
				'emailRecipients'            => isset( $modern_schedule['emailRecipients'] ) && is_array( $modern_schedule['emailRecipients'] ) ? $modern_schedule['emailRecipients'] : array(),
			),
		),
	);
}

/**
 * Add or set a BLC notification recipient across legacy and modern settings.
 */
function mcp_blc_add_notification_recipient_internal( string $email, bool $dry_run = false, bool $activate_schedule = false ): array {
	$email = sanitize_email( $email );
	if ( '' === $email || ! is_email( $email ) ) {
		return array(
			'success' => false,
			'message' => 'A valid email address is required.',
		);
	}

	$changes = array();

	$legacy = mcp_blc_get_json_option_array( 'wsblc_options' );
	if ( $legacy['exists'] ) {
		$options = $legacy['value'];
		$before  = array(
			'send_email_notifications'   => (bool) ( $options['send_email_notifications'] ?? false ),
			'notification_email_address' => (string) ( $options['notification_email_address'] ?? '' ),
		);

		$options['send_email_notifications']   = true;
		$options['notification_email_address'] = $email;

		$after = array(
			'send_email_notifications'   => true,
			'notification_email_address' => $email,
		);

		$changed = ( $before !== $after );
		if ( $changed && ! $dry_run ) {
			mcp_blc_update_json_option_array( 'wsblc_options', $options );
		}

		$changes[] = array(
			'option'  => 'wsblc_options',
			'changed' => $changed,
			'before'  => $before,
			'after'   => $after,
		);
	}

	$modern = mcp_blc_get_json_option_array( 'blc_settings' );
	if ( $modern['exists'] ) {
		$settings = $modern['value'];
		if ( ! isset( $settings['schedule'] ) || ! is_array( $settings['schedule'] ) ) {
			$settings['schedule'] = array();
		}

		$schedule_before = $settings['schedule'];
		$email_rows      = isset( $settings['schedule']['emailrecipients'] ) && is_array( $settings['schedule']['emailrecipients'] ) ? $settings['schedule']['emailrecipients'] : array();
		$found           = false;

		foreach ( $email_rows as &$row ) {
			if ( isset( $row['email'] ) && strtolower( (string) $row['email'] ) === strtolower( $email ) ) {
				$row['email']     = $email;
				$row['confirmed'] = true;
				$row['name']      = isset( $row['name'] ) && '' !== (string) $row['name'] ? (string) $row['name'] : $email;
				$row['key']       = isset( $row['key'] ) && '' !== (string) $row['key'] ? (string) $row['key'] : wp_generate_password( 32, false, false );
				$found            = true;
				break;
			}
		}
		unset( $row );

		if ( ! $found ) {
			$email_rows[] = array(
				'email'     => $email,
				'name'      => $email,
				'key'       => wp_generate_password( 32, false, false ),
				'confirmed' => true,
			);
		}

		$settings['schedule']['emailrecipients'] = $email_rows;
		$settings['schedule']['emailRecipients'] = $email_rows;
		if ( $activate_schedule ) {
			$settings['schedule']['active'] = true;
		}
		if ( empty( $settings['schedule']['frequency'] ) ) {
			$settings['schedule']['frequency'] = 'daily';
		}
		if ( empty( $settings['schedule']['time'] ) ) {
			$settings['schedule']['time'] = '00:00';
		}

		$schedule_after = $settings['schedule'];
		$changed        = ( $schedule_before !== $schedule_after );
		if ( $changed && ! $dry_run ) {
			mcp_blc_update_json_option_array( 'blc_settings', $settings );
		}

		$changes[] = array(
			'option'  => 'blc_settings',
			'changed' => $changed,
			'before'  => array(
				'active'          => (bool) ( $schedule_before['active'] ?? false ),
				'emailrecipients' => isset( $schedule_before['emailrecipients'] ) && is_array( $schedule_before['emailrecipients'] ) ? $schedule_before['emailrecipients'] : array(),
			),
			'after'   => array(
				'active'          => (bool) ( $schedule_after['active'] ?? false ),
				'emailrecipients' => isset( $schedule_after['emailrecipients'] ) && is_array( $schedule_after['emailrecipients'] ) ? $schedule_after['emailrecipients'] : array(),
			),
		);
	}

	return array(
		'success'           => true,
		'dry_run'           => $dry_run,
		'email'             => $email,
		'activate_schedule' => $activate_schedule,
		'changed_count'     => count(
			array_filter(
				$changes,
				static function ( array $change ): bool {
					return ! empty( $change['changed'] );
				}
			)
		),
		'changes'           => $changes,
		'settings'          => $dry_run ? null : mcp_blc_get_notification_settings_internal(),
	);
}

/**
 * Register BLC abilities.
 */
function mcp_register_blc_abilities(): void {
	if ( ! mcp_blc_check_dependencies() ) {
		return;
	}

	// =========================================================================
	// BLC - List Tables
	// =========================================================================
	wp_register_ability(
		'blc/list-tables',
		array(
			'label'               => 'List Broken Link Checker Tables',
			'description'         => 'Lists BLC database tables detected for the current site and their columns.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'array', 'null' ),
				'properties'           => array(
					'_' => array(
						'type'        => array( 'string', 'number', 'boolean', 'null' ),
						'description' => 'Optional no-op compatibility field.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'active'  => array( 'type' => 'boolean' ),
					'tables'  => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => static function ( $input = null ): array {
				$tables  = mcp_blc_list_tables_internal();
				$result  = array();

				foreach ( $tables as $table ) {
					$result[] = array(
						'table'   => $table,
						'columns' => mcp_blc_get_table_columns( $table ),
					);
				}

				return array(
					'success' => true,
					'active'  => mcp_blc_is_active(),
					'tables'  => $result,
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// BLC - List Broken Links
	// =========================================================================
	wp_register_ability(
		'blc/list-broken-links',
		array(
			'label'               => 'List Broken Links (BLC)',
			'description'         => 'Lists broken links from BLC local database with optional source instance details and suggested redirect targets when available.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'               => array( 'type' => 'integer', 'default' => 100 ),
					'offset'              => array( 'type' => 'integer', 'default' => 0 ),
					'include_instances'   => array( 'type' => 'boolean', 'default' => true ),
					'max_sources_per_link'=> array( 'type' => 'integer', 'default' => 5 ),
					'include_dismissed'   => array( 'type' => 'boolean', 'default' => false ),
					'include_false_positive' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'count'   => array( 'type' => 'integer' ),
					'links'   => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				if ( $error = mcp_blc_require_active() ) {
					return $error;
				}

				$limit             = min( 500, max( 1, (int) ( $input['limit'] ?? 100 ) ) );
				$offset            = max( 0, (int) ( $input['offset'] ?? 0 ) );
				$include_instances = (bool) ( $input['include_instances'] ?? true );
				$max_sources       = min( 20, max( 1, (int) ( $input['max_sources_per_link'] ?? 5 ) ) );
				$include_dismissed = (bool) ( $input['include_dismissed'] ?? false );
				$include_false_pos = (bool) ( $input['include_false_positive'] ?? false );

				$data = mcp_blc_get_broken_links( $limit, $offset, $include_dismissed, $include_false_pos );
				if ( empty( $data['success'] ) ) {
					return $data;
				}

				$links = (array) $data['rows'];
				if ( $include_instances ) {
					$links = mcp_blc_enrich_with_instances( $links, $max_sources );
				}

				return array(
					'success' => true,
					'count'   => count( $links ),
					'links'   => $links,
					'message' => '',
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Get Notification Settings
	// =========================================================================
	wp_register_ability(
		'blc/get-notification-settings',
		array(
			'label'               => 'Get BLC Notification Settings',
			'description'         => 'Returns the Broken Link Checker notification recipient settings from legacy and modern BLC options.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'array', 'null' ),
				'properties'           => array(
					'_' => array(
						'type'        => array( 'string', 'number', 'boolean', 'null' ),
						'description' => 'Optional no-op compatibility field.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'active'  => array( 'type' => 'boolean' ),
					'legacy'  => array( 'type' => 'object' ),
					'modern'  => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => static function ( $input = null ): array {
				return mcp_blc_get_notification_settings_internal();
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Add Notification Recipient
	// =========================================================================
	wp_register_ability(
		'blc/add-notification-recipient',
		array(
			'label'               => 'Add BLC Notification Recipient',
			'description'         => 'Adds or sets a Broken Link Checker notification recipient in legacy and modern BLC settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'email' ),
				'properties'           => array(
					'email'             => array( 'type' => 'string' ),
					'dry_run'           => array( 'type' => 'boolean', 'default' => false ),
					'activate_schedule' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'email'         => array( 'type' => 'string' ),
					'changed_count' => array( 'type' => 'integer' ),
					'changes'       => array( 'type' => 'array' ),
					'settings'      => array( 'type' => array( 'object', 'null' ) ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_blc_add_notification_recipient_internal(
					isset( $input['email'] ) ? (string) $input['email'] : '',
					(bool) ( $input['dry_run'] ?? false ),
					(bool) ( $input['activate_schedule'] ?? false )
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Replace URL in Content
	// =========================================================================
	wp_register_ability(
		'blc/replace-url-in-content',
		array(
			'label'               => 'Replace URL In Content',
			'description'         => 'Replaces a URL across post/page content and excerpts for selected post types/statuses.',
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'old_url', 'new_url' ),
				'properties'           => array(
					'old_url'    => array( 'type' => 'string' ),
					'new_url'    => array( 'type' => 'string' ),
					'post_types' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'post', 'page' ),
					),
					'statuses'  => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'publish' ),
					),
					'dry_run'   => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'updated_count' => array( 'type' => 'integer' ),
					'updated_posts' => array( 'type' => 'array' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				$old_url = isset( $input['old_url'] ) ? (string) $input['old_url'] : '';
				$new_url = isset( $input['new_url'] ) ? (string) $input['new_url'] : '';

				return mcp_blc_replace_url_in_content(
					$old_url,
					$new_url,
					isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array( 'post', 'page' ),
					isset( $input['statuses'] ) && is_array( $input['statuses'] ) ? $input['statuses'] : array( 'publish' ),
					(bool) ( $input['dry_run'] ?? false )
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' ) && current_user_can( 'edit_pages' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Auto Fix Redirected Broken Links
	// =========================================================================
	wp_register_ability(
		'blc/auto-fix-broken-links',
		array(
			'label'               => 'Auto Fix Broken Links (Redirect Targets)',
			'description'         => 'Uses BLC final_url/redirect_url suggestions to replace broken URLs in content, then optionally clears the BLC queue.',
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'                => array( 'type' => 'integer', 'default' => 200 ),
					'post_types'           => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'post', 'page' ),
					),
					'statuses'             => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'publish' ),
					),
					'clear_queue'          => array( 'type' => 'boolean', 'default' => true ),
					'clear_transients'     => array( 'type' => 'boolean', 'default' => false ),
					'dry_run'              => array( 'type' => 'boolean', 'default' => false ),
					'include_dismissed'    => array( 'type' => 'boolean', 'default' => false ),
					'include_false_positive' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'mappings_found'     => array( 'type' => 'integer' ),
					'mappings_applied'   => array( 'type' => 'integer' ),
					'updated_posts'      => array( 'type' => 'integer' ),
					'unresolved_count'   => array( 'type' => 'integer' ),
					'queue_cleanup'      => array( 'type' => 'object' ),
					'details'            => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				if ( $error = mcp_blc_require_active() ) {
					return $error;
				}

				$limit             = min( 500, max( 1, (int) ( $input['limit'] ?? 200 ) ) );
				$dry_run           = (bool) ( $input['dry_run'] ?? false );
				$clear_queue       = (bool) ( $input['clear_queue'] ?? true );
				$clear_transients  = (bool) ( $input['clear_transients'] ?? false );
				$include_dismissed = (bool) ( $input['include_dismissed'] ?? false );
				$include_false_pos = (bool) ( $input['include_false_positive'] ?? false );

				$broken = mcp_blc_get_broken_links( $limit, 0, $include_dismissed, $include_false_pos );
				if ( empty( $broken['success'] ) ) {
					return $broken;
				}

				$rows     = (array) $broken['rows'];
				$mappings = mcp_blc_build_auto_fix_mappings( $rows );

				$details                = array();
				$applied_mapping_count   = 0;
				$total_updated_posts     = 0;
				$updated_post_ids        = array();

				foreach ( $mappings as $mapping ) {
					$result = mcp_blc_replace_url_in_content(
						(string) $mapping['from'],
						(string) $mapping['to'],
						isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array( 'post', 'page' ),
						isset( $input['statuses'] ) && is_array( $input['statuses'] ) ? $input['statuses'] : array( 'publish' ),
						$dry_run
					);

					$updated_posts = isset( $result['updated_posts'] ) && is_array( $result['updated_posts'] ) ? $result['updated_posts'] : array();
					foreach ( $updated_posts as $post_row ) {
						if ( ! empty( $post_row['updated'] ) && isset( $post_row['id'] ) ) {
							$updated_post_ids[ (int) $post_row['id'] ] = true;
						}
					}

					if ( ! empty( $result['success'] ) && (int) ( $result['updated_count'] ?? 0 ) > 0 ) {
						++$applied_mapping_count;
					}

					$details[] = array(
						'link_id'       => (int) ( $mapping['link_id'] ?? 0 ),
						'from'          => (string) $mapping['from'],
						'to'            => (string) $mapping['to'],
						'updated_count' => (int) ( $result['updated_count'] ?? 0 ),
						'success'       => (bool) ( $result['success'] ?? false ),
						'message'       => (string) ( $result['message'] ?? '' ),
					);
				}

				$total_updated_posts = count( $updated_post_ids );
				$unresolved_count    = 0;
				foreach ( $rows as $row ) {
					$suggested = isset( $row['suggested_target'] ) ? trim( (string) $row['suggested_target'] ) : '';
					if ( '' === $suggested ) {
						++$unresolved_count;
					}
				}

				$queue_cleanup = null;
				if ( $clear_queue ) {
					$queue_cleanup = mcp_blc_clear_queue_internal( $clear_transients, $dry_run );
				}

				return array(
					'success'          => true,
					'mappings_found'   => count( $mappings ),
					'mappings_applied' => $applied_mapping_count,
					'updated_posts'    => $total_updated_posts,
					'unresolved_count' => $unresolved_count,
					'queue_cleanup'    => $queue_cleanup,
					'details'          => $details,
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Clear Queue
	// =========================================================================
	wp_register_ability(
		'blc/clear-queue',
		array(
			'label'               => 'Clear BLC Queue',
			'description'         => 'Clears BLC local queue-like tables (such as blc_synch) and optionally BLC transients.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'clear_transients' => array( 'type' => 'boolean', 'default' => false ),
					'dry_run'          => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'queue_tables_count' => array( 'type' => 'integer' ),
					'tables'             => array( 'type' => 'array' ),
					'message'            => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_blc_clear_queue_internal(
					(bool) ( $input['clear_transients'] ?? false ),
					(bool) ( $input['dry_run'] ?? false )
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Delete Links
	// =========================================================================
	wp_register_ability(
		'blc/delete-links',
		array(
			'label'               => 'Delete BLC Links by ID',
			'description'         => 'Deletes selected rows from BLC link tables by link_id (and related instances). Use for stale BLC records after source content has been fixed.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'link_ids' ),
				'properties'           => array(
					'link_ids'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'clear_queue'      => array( 'type' => 'boolean', 'default' => true ),
					'clear_transients' => array( 'type' => 'boolean', 'default' => false ),
					'dry_run'          => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'matched_links'     => array( 'type' => 'integer' ),
					'matched_instances' => array( 'type' => 'integer' ),
					'deleted_links'     => array( 'type' => 'integer' ),
					'deleted_instances' => array( 'type' => 'integer' ),
					'message'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_blc_delete_links_internal(
					isset( $input['link_ids'] ) && is_array( $input['link_ids'] ) ? $input['link_ids'] : array(),
					(bool) ( $input['clear_queue'] ?? true ),
					(bool) ( $input['clear_transients'] ?? false ),
					(bool) ( $input['dry_run'] ?? false )
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// BLC - Mark False Positives
	// =========================================================================
	wp_register_ability(
		'blc/mark-false-positives',
		array(
			'label'               => 'Mark BLC Links as False Positives',
			'description'         => 'Marks selected BLC link IDs as false positives (and optionally dismissed), then optionally clears the BLC queue.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'link_ids' ),
				'properties'           => array(
					'link_ids'          => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'dismiss'           => array( 'type' => 'boolean', 'default' => true ),
					'clear_queue'       => array( 'type' => 'boolean', 'default' => true ),
					'clear_transients'  => array( 'type' => 'boolean', 'default' => false ),
					'dry_run'           => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'updated_count' => array( 'type' => 'integer' ),
					'matched_count' => array( 'type' => 'integer' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_blc_mark_false_positives_internal(
					isset( $input['link_ids'] ) && is_array( $input['link_ids'] ) ? $input['link_ids'] : array(),
					(bool) ( $input['dismiss'] ?? true ),
					(bool) ( $input['clear_queue'] ?? true ),
					(bool) ( $input['clear_transients'] ?? false ),
					(bool) ( $input['dry_run'] ?? false )
				);
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_register_blc_abilities' );
