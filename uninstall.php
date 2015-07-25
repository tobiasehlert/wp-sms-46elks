<?php
/**
 * WP SMS 46elks
 *
 * @package     wp-sms-46elks
 * @author      Tobias Ehlert
 * @license     GPL2
 * @link        http://ehlert.se/wordpress-plugins/wp-sms-46elks/
 */

// If uninstall is not called from WordPress, exit
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

unregister_setting( 'sssf-sms-46elks-settings', 'sssf-sms-46elks-id' );
unregister_setting( 'sssf-sms-46elks-settings', 'sssf-sms-46elks-sec' );