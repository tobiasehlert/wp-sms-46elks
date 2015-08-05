<?php
/**
 * WP SMS 46elks
 *
 * @package     wp-sms-46elks
 * @author      Tobias Ehlert
 * @license     GPL2
 * @link        http://ehlert.se/wordpress/wp-sms-46elks/
 */

// If uninstall is not called from WordPress, exit
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-from' );
unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-filter' );
unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-default-countrycode' );
unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-balancealert' );
unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-balancealert-sent' );
unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-api-username' );
unregister_setting( 'wp-sms-46elks-settings', 'wp-sms-46elks-api-password' );