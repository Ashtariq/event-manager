<?php
/**
 * Runs when the plugin is deleted from the admin.
 * Removes plugin options only. Event posts are kept on purpose,
 * so content is never lost by accident.
 *
 * @package EWEM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ewem_default_per_page' );
delete_option( 'ewem_cache_version' );
