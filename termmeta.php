<?php

/**
 * Setup the termmeta table
 *
 * Use <code>add_theme_support( 'term-meta' );</code> to enable support for term meta 
 */
function hm_add_term_meta_table() {

	global $wpdb;

	if ( ! current_theme_supports( 'term-meta' ) || defined( 'WP_INSTALLING' ) )
		return false;

	hm_create_term_meta_table();

	$wpdb->tables[] = 'termmeta';
	$wpdb->termmeta = $wpdb->prefix . 'termmeta';

}
add_action( 'init', 'hm_add_term_meta_table' );

/**
 * Create the termmeta table if it doesn't exist
 *
 * @todo should check if the table exists directly rather than relying on an option
 */
function hm_create_term_meta_table() {
	global $wpdb;

	// check if the table already exists
	if ( get_option( 'hm_created_term_meta_table' ) )
		return false;

	$wpdb->query( "
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}termmeta` (
		  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `term_id` bigint(20) unsigned NOT NULL DEFAULT '0',
		  `meta_key` varchar(255) DEFAULT NULL,
		  `meta_value` longtext,
		  PRIMARY KEY (`meta_id`),
		  KEY `term_id` (`term_id`),
		  KEY `meta_key` (`meta_key`)
		) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;" );

	update_option( 'hm_created_term_meta_table', true );

	return true;
}

if ( ! function_exists( 'add_term_meta' ) ) :
/**
 * Add meta data field to a term.
 *
 * @param int $term_id term_id.
 * @param string $key Metadata name.
 * @param mixed $value Metadata value.
 * @param bool $unique Optional, default is false. Whether the same key should not be added.
 * @return bool False for failure. True for success.
 */
function add_term_meta( $term_id, $meta_key, $meta_value, $unique = false ) {
	wp_cache_set( 'last_changed', microtime(), 'terms' );
	return add_metadata( 'term', $term_id, $meta_key, $meta_value, $unique );
}
endif;

if ( ! function_exists( 'delete_term_meta' ) ) :
/**
 * Remove metadata matching criteria from a term.
 *
 * You can match based on the key, or key and value. Removing based on key and
 * value, will keep from removing duplicate metadata with the same key. It also
 * allows removing all metadata matching key, if needed.
 *
 * @param int $term_id term_id
 * @param string $meta_key Metadata name.
 * @param mixed $meta_value Optional. Metadata value.
 * @return bool False for failure. True for success.
 */
function delete_term_meta( $term_id, $meta_key, $meta_value = '' ) {
	wp_cache_set( 'last_changed', microtime(), 'terms' );
	return delete_metadata( 'term', $term_id, $meta_key, $meta_value );
}
endif;

if ( ! function_exists( 'get_term_meta' ) ) :
/**
 * Retrieve term meta field for a term.
 *
 * @param int $term_id term_id.
 * @param string $key The meta key to retrieve.
 * @param bool $single Whether to return a single value.
 * @return mixed Will be an array if $single is false. Will be value of meta data field if $single
 * is true.
 */
function get_term_meta( $term_id, $key, $single = false ) {
	return get_metadata( 'term', $term_id, $key, $single );
}
endif;

if ( ! function_exists( 'update_term_meta' ) ) :
/**
 * Update term meta field based on term_id.
 *
 * Use the $prev_value parameter to differentiate between meta fields with the
 * same key and term_id.
 *
 * If the meta field for the term does not exist, it will be added.
 *
 * @param int $term_id term ID.
 * @param string $key Metadata key.
 * @param mixed $value Metadata value.
 * @param mixed $prev_value Optional. Previous value to check before removing.
 * @return bool False on failure, true if success.
 */
function update_term_meta( $term_id, $meta_key, $meta_value, $prev_value = '' ) {
	wp_cache_set( 'last_changed', microtime(), 'terms' );
	return update_metadata( 'term', $term_id, $meta_key, $meta_value, $prev_value );
}
endif;

if ( ! function_exists( 'get_term_custom' ) ) :
/**
 * Retrieve term meta fields, based on term_id.
 *
 * The term meta fields are retrieved from the cache, so the function is
 * optimized to be called more than once. It also applies to the functions, that
 * use this function.
 *
 * @param int $term_id term_id
 * @return array
 */
 function get_term_custom( $term_id = 0 ) {

	$term_id = (int) $term_id;

	if ( ! wp_cache_get( $term_id, 'term_meta' ) )
		update_termmeta_cache( $term_id );

	return wp_cache_get( $term_id, 'term_meta' );
}
endif;

if ( ! function_exists( 'update_termmeta_cache' ) ) :
/**
* Updates metadata cache for list of term_ids.
*
* Performs SQL query to retrieve the metadata for the term_ids and updates the
* metadata cache for the terms. Therefore, the functions which call this
* function do not need to perform SQL queries on their own.
*
* @param array $term_ids List of term_ids.
* @return bool|array Returns false if there is nothing to update or an array of metadata.
*/
function update_termmeta_cache( $term_ids ) {
	return update_meta_cache( 'term', $term_ids );
}
endif;

add_filter( 'terms_clauses', 'hm_add_term_meta_query_support', 10, 3 );

function hm_add_term_meta_query_support( $pieces, $taxonomies, $args ) {
	if ( empty( $args['meta_query'] ) ) {
		return $pieces;
	}

	$meta_query = new WP_Meta_Query( $args['meta_query'] );

	$sql = $meta_query->get_sql( 'term', 't', 'term_id' );

	if ( ! $sql ) {
		return $pieces;
	}
	
	// Support advanced meta ordering introduced in WP 4.2
	if ( $args['orderby'] && method_exists( $meta_query, 'get_clauses' ) ) {
		$orderby = $args['orderby'];
		$meta_clauses = $meta_query->get_clauses();
		
		$reserved_terms = array(
			'id',
			'count',
			'name',
			'slug',
			'term_group',
			'description',
			'none',
		);
		
		if ( ! in_array( $orderby, $reserved_terms ) && array_key_exists( $orderby, $meta_clauses ) ) {
			$meta_clause = $meta_clauses[ $orderby ];
			$orderby_clause = "CAST({$meta_clause['alias']}.meta_value AS {$meta_clause['cast']})";
			$pieces['orderby'] = "ORDER BY $orderby_clause";
		}
	}

	$pieces['join'] .= $sql['join'];
	$pieces['where'] .= $sql['where'];

	return $pieces;
}

/**
 * get_terms will cache based of it's defualt args, in the case of using
 * meta queries, they get ignored if an existing cache was stored, as the meta
 * query is not included in the cache key.
 *
 * Fortunatly we can make use of the cache_domain arg which essentially just
 * becomes part of the cache key.
 */
function hm_add_get_terms_cache_key_modifications( $args ) {

	if ( empty( $args['meta_query'] ) ) {
		return $args;
	}

	$args['cache_domain'] .= serialize( $args['meta_query'] );

	return $args;
}

add_filter( 'get_terms_args', 'hm_add_get_terms_cache_key_modifications' );

/**
 * Duplicate all meta from one term to the new one when it is split.
 *
 * For one reason or another, the initial design of this termmeta plugin stores
 * meta against a term ID, rather than term_taxonomy_id, this was an incorrect
 * design decision, however we now have to handle the backwards compatibility.
 *
 * If a shared term is split between two taxonomies, there is no way to determine what
 * meta entries in the meta table are tied to which taxonomy's term. So, the only
 * reliable solution is to duplicate all meta from the old term id to the new one, ensuring
 * the new term has any potential meta it owns.
 *
 * @param  int $old_term_id
 * @param  int $new_term_id
 */
function hm_duplicate_meta_on_split_term( $old_term_id, $new_term_id ) {

	$meta = get_metadata( 'term', $old_term_id );

	foreach ( $meta as $meta_key => $values ) {
		foreach ( $values as $value ) {
			$value = maybe_unserialize( $value );
			add_term_meta( $new_term_id, $meta_key, $value );
		}
	}
}

add_action( 'split_shared_term', 'hm_duplicate_meta_on_split_term', 10, 2 );
