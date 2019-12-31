<?php
/**
 * Plugin Name:     Fix Missing Media
 * Plugin URI:      https://newspackstaging.com
 * Description:     Seeks to fix the missing media on a Newspack staging site.
 * Author:          Automattic
 * Author URI:      https://automattic.com
 * Text Domain:     fix-missing-media
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Fix_Missing_Media
 */

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

function fmm_fix_missing_media( $args, $assoc_args ) {
	WP_CLI::line( 'Fixing media...' );

	$assoc_args = wp_parse_args( $assoc_args, [
		'limit'   => 100,
		'batches' => 1,
	] );

	if ( filter_var( $args[0], FILTER_VALIDATE_URL ) ) {
		$from_url = $args[0];
	} else {
		WP_CLI::error( __( 'Invalid URL.', 'fix-missing-media' ) );
	}

	$limit = min( $assoc_args['limit'], 100 );

	if ( isset( $args[1] ) && intval( $args[1] ) ) {

		WP_CLI::line( sprintf( 'Checking for specific attachment %d...', $args[1] ) );

		$image_url = fmm_get_missing_media( $from_url, $args[1] );

	} else {

		WP_CLI::line( sprintf( 'Checking %d batches of %d items', $assoc_args['batches'], $assoc_args['limit'] ) );

		for ( $i = 1; $i <= $assoc_args['batches']; $i++ ) {

			WP_CLI::line( sprintf( 'Checking batch %d', $i ) );

			$query = new WP_Query( [
				'post_type'      => 'attachment',
				'posts_per_page' => $limit,
				'paged'          => $i,
				'post_status'    => 'any',
				'meta_query'     => [ [
					'key'     => 'fmm_processed',
					'compare' => 'NOT EXISTS',
				] ],
			] );

			if ( $query->have_posts() ) :

				while ( $query->have_posts() ) :

					// Setup post data.
					$query->the_post();

					fmm_get_missing_media( $from_url, get_the_ID() );

					// Rest briefly to avoid a self-DDoS.
					#sleep( 2 );

				endwhile;

			else:

				WP_CLI::success( 'All the missing attachments have been found!' );

			endif;

			// Flush the cache because it seems the query is getting cached
			// resulting already processed items being checked again.
			wp_cache_flush();

		}

	}
}

WP_CLI::add_command( 'fmm-fix-missing-media', 'fmm_fix_missing_media', [
	'shortdesc' => 'Finds and fixes any missing media.',
	'synopsis'  => [
		[
			'type'        => 'positional',
			'name'        => 'from_url',
			'description' => 'Provide the full domain URL where images should be downloaded from. E.g. https://newspack.blog',
			'optional'    => true,

		],
		[
			'type'        => 'positional',
			'name'        => 'attachment',
			'description' => 'Provide a specific attachment ID to check a single attachment.',
			'optional'    => true,

		],
		[
			'type'        => 'assoc',
			'name'        => 'limit',
			'description' => 'How many media items to check in each batch.',
			'optional'    => true,
			'repeating'   => false,
			'default'     => 100,
		],
		[
			'type'        => 'assoc',
			'name'        => 'batches',
			'description' => 'How many batches to run.',
			'optional'    => true,
			'repeating'   => false,
			'default'     => 1,
		],
	],
] );

function fmm_get_missing_media( $from_url, $attachment_id ) {

	// Grab the image URL.
	$image_url = wp_get_attachment_url( $attachment_id );

	// Check if the image is actually present.
	$image_request = wp_remote_head( $image_url );
	if ( is_wp_error( $image_request ) ) {
			WP_CLI::warning( sprintf(
				'Local image (%s) returned an error: %s',
				$image_url,
				$image_request->get_error_message()
			) );
			return;
	}

	if ( 200 == $image_request['response']['code'] ) {

		WP_CLI::line( sprintf( 'Attachment %d is working fine at %s', $attachment_id, esc_url( $image_url ) ) );

		// Mark it as checked/sorted.
		add_post_meta( $attachment_id, 'fmm_processed', 1 );

	// Image isn't present, so let's grab it from the production domain.
	} else {

		// Scheme and host of the staging site that we want to replace.
		$staging_domain = wp_parse_url( $image_url, PHP_URL_SCHEME ) . '://' . wp_parse_url( $image_url, PHP_URL_HOST );

		// Ignore `.bmp` files, which we don't allow on wpcom.
		$pathinfo = pathinfo( basename( $image_url ) );
		if ( in_array( $pathinfo['extension'], [ 'bmp', 'psd' ] ) ) {
			WP_CLI::warning( sprintf( 'Skipping disallowed filetype, %s.', $pathinfo['extension'] ) );
			add_post_meta( $attachment_id, 'fmm_processed', 1 );
			return;
		}

		// Replace the staging domain with the production domain.
		$prod_image_url = str_replace(
			$staging_domain,
			$from_url,
			$image_url
		);

		// OnTheWight has a silly upload path.
		/*$prod_image_url = str_replace(
			'wp-content/uploads',
			'wp-content',
			$prod_image_url
		);*/

		WP_CLI::line( sprintf( 'Attachment %d needs grabbing from %s', $attachment_id, esc_url( $prod_image_url ) ) );

		$temp_file = download_url( $prod_image_url, 5 );
		if ( ! is_wp_error( $temp_file ) ) {

			$file = [
				'name'     => basename( $prod_image_url ),
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => filesize( $temp_file ),
			];

			$overrides = [
				'test_form'   => false,
				'test_size'   => true,
				'test_upload' => true,
			];

			global $fmm_prod_image_url;
			$fmm_prod_image_url = $prod_image_url;

			add_filter( 'upload_dir', 'fmm_upload_dir' );

			$results = wp_handle_sideload( $file, $overrides );

			remove_filter( 'upload_dir', 'fmm_upload_dir' );

			if ( ! empty( $results['error'] ) ) {
				WP_CLI::warning( 'Failed: '. $results['error'] );
			} else {
				WP_CLI::success( sprintf( 'Downloaded image for %d to %s', $attachment_id, $results['file'] ) );
			}

			// Mark it as checked/sorted.
			add_post_meta( $attachment_id, 'fmm_processed', 1 );

		} else {
			$msg = sprintf( 'Failed to download %s. The error message was: %s', esc_url_raw( $prod_image_url ), esc_html( $temp_file->get_error_message() ) );
			WP_CLI::warning( $msg );
			add_post_meta( $attachment_id, 'fmm_processed', $msg );
		}

	}

}

function fmm_upload_dir( $dir ) {

	global $fmm_prod_image_url;

	// Get the directories from within the uploads path.
	$parsed = wp_parse_url( $fmm_prod_image_url );
	$path = str_replace(
		'/wp-content',
		'',
		str_replace( '/' . basename( $fmm_prod_image_url ), '', $parsed['path'] )
	);

	// Replace the current year/month dir with the one needed for the missing file.
	$new_dir = [
		'path'   => str_replace( $dir['subdir'], $path, $dir['path'] ),
		'url'    => str_replace( $dir['subdir'], $path, $dir['url'] ),
		'subdir' => str_replace( $dir['subdir'], $path, $dir['subdir'] ),
	];

	$dir = array_merge( $dir, $new_dir );

	return $dir;

}
