<?php
/**
 * WP SMS 46elks
 *
 * @package     wp-sms-46elks
 * @author      Tobias Ehlert
 * @license     GPL2
 * @link        http://ehlert.se/wordpress-plugins/wp-sms-46elks/
 */

/*
Plugin Name:    WordPress SMS for 46elks
Plugin URI:     http://ehlert.se/wordpress-plugins/wp-sms-46elks/
Description:    WordPress module for sending SMS using 46elks. It's displayed in the WordPress admin area.
Version:        0.1
Author:         Tobias Ehlert
Author URI:     http://ehlert.se/
License:        GPL2
License URI:    http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain:    wp-sms-46elks
Domain Path:    /languages

 
WordPress SMS for 46elks is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
WordPress SMS for 46elks is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with WordPress SMS for 46elks.
*/

if ( !class_exists( 'WPSMS46elks' ) )
{
	class WPSMS46elks
	{
        private         $plugin_slug    = 'wp-sms-46elks';
        private         $debug          = false;
        private         $from           = 'SSSF';
        private         $SMSprice       = '0,35';
        private         $API_uri        = 'https://api.46elks.com/a1';

		protected       $API_basic  = array();
        
        protected       $AccountBalance;
        
        
        protected       $receivers  = array();
        protected       $message;
        protected       $result     = array();
        protected       $APIresult  = array();
        protected       $status     = array();
        

        public function __construct()
		{
            // init post function for wp-sms-46elks
            add_action( 'init', array( $this, 'wpsms46elks_init' ) );
            
            // add pages to WP-admin
            add_action( 'admin_menu', array( $this, 'wpsms46elks_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'wpsms46elks_admin_init' ) );
            
            // add dashboad widget for WP-admin
            add_action( 'wp_dashboard_setup', array( $this, 'wpsms46elks_wp_dashboard_setup' ) );
            
            // add new field on users called cellphone
            add_filter( 'user_contactmethods', array( $this, 'wpsms46elks_user_contactmethods' ) );
            
            // setting up basics for API request containing credentials
            $this->API_basic = array(
                'headers'   => array(
                    'Authorization' => 'Basic ' .base64_encode( get_option($this->plugin_slug.'-api-username').':'.get_option($this->plugin_slug.'-api-password') ),
                    'Content-type'  => 'application/x-www-form-urlencoded'
                )
            );
            
            // adding jquery if it's not enqueued yet
            if ( wp_style_is( 'jquery' ) )
                wp_enqueue_script('jquery');
        }

        function wpsms46elks_init ()
        {
            // Add receivers based on Wordpress setup for wp-sms-46elks
            $this->addReceiversFromWP();
            
            // check if message is longer than zero
            if ( strlen( $_POST['wp-sms-46elks-message'] ) > 0 )
            {
                // add message content to SMS
                $this->addMessage( $_POST['wp-sms-46elks-message'] );
                
                // sending of the SMS
                $this->sendSMS();

                // giving success message
                if ( $this->result['success'] >= 1 )
                {
                    $data['body'] = json_decode( $this->APIresult[0]['servermsg']['body'] );
                    $this->status['success'] = __('Your message was successful when sending to', 'wp-sms-46elks').' '.$this->result['success'].' '.__('cellphones', 'wp-sms-46elks').'!<br />'.
                        __('The SMS cost was ', 'wp-sms-46elks').( $this->convertBalanceValue ( $this->result['success'] * $data['body']->cost ) ).' sek';
                }
                if ( $this->result['failed'] >= 1 )
                    $this->status['failed'] = __('Your message failed when sending to', 'wp-sms-46elks').' '.$this->result['failed'].' '.__('cellphones', 'wp-sms-46elks').'!';
            }
            elseif ( isset( $_POST['wp-sms-46elks-message'] ) )
                $this->status['failed'] = __('You forgot to enter a message!', 'wp-sms-46elks');
            
            // getting the current account balance for status window
            $this->getAccountBalance();
        }
        
        function wpsms46elks_wp_dashboard_setup ()
        {
            wp_add_dashboard_widget( $this->plugin_slug.'-dashboard', __( 'WordPress SMS for 46elks', 'wp-sms-46elks' ), array( $this, 'wpsms46elks_dashboard_content' ), null );
        }
        function wpsms46elks_dashboard_content ()
        {
            if ( ! $this->InvalidAccount )
            {
                ?>
                <h4><?php _e( 'Account balance', 'wp-sms-46elks' );?></h4>
                <?php
                $this->wpsms46elks_account_status();
                ?>
                <hr />
                <?php
            }
            ?>
            <p><a href="<?php echo admin_url( 'admin.php?page='.$this->plugin_slug ); ?>"><?php _e( 'Go to plugin page', 'wp-sms-46elks' );?></a></p>
            <?php
        }
        
        function handleResponse ( $response )
        {
            // saving result for later
            if ( is_wp_error( $response ) ) {
                $return = array(
                    'status' => 'error',
                    'servermsg' => $response->get_error_message()
                );
            }
            else {
                if ( wp_remote_retrieve_response_code( $response ) == '200' )
                    $this->result['success']++;
                else
                    $this->result['failed']++;
                
                $return = array(
                    'status' => 'success',
                    'servermsg' => array(
                        'code' => wp_remote_retrieve_response_code( $response ),
                        'message' => wp_remote_retrieve_response_message( $response ),
                        'body' => wp_remote_retrieve_body( $response )
                    )
                );
            }
            
            return $return;
        }
        
        function getAccountBalance ()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->API_basic;
            $this->response = wp_remote_get(
                $this->API_uri.'/me',
                $sms
            );
            
            $data = $this->handleResponse( $this->response );
            $data['body'] = json_decode( $data['servermsg']['body'] );
            
            if ( $data['servermsg']['code'] == 401 && $data['servermsg']['message'] == 'Authorization Required' )
            {
                $this->InvalidAccount = array(
                    'status' => true,
                    'msg' => '401 '.__( 'Authorization Required', 'wp-sms-46elks' )
                );
                return false;
            }
            else
            {
                $this->AccountBalance = array(
                    'name'      => $data['body']->name,
                    'balance'   => $data['body']->balanceused,
                    'limit'     => $data['body']->usagelimit,
                    'leftcred'  => ( $data['body']->usagelimit - $data['body']->balanceused )
                );
                return true;
            }
        }
        
        function wpsms46elks_account_status()
        {
            ?>
            <p>
                <?php _e( '46elks account name', 'wp-sms-46elks'); ?>: <?php echo $this->AccountBalance['name']; ?><br />
                <?php _e( '46elks credits left', 'wp-sms-46elks'); ?>: <b><?php echo $this->convertBalanceValue( $this->AccountBalance['leftcred'] ); ?> sek</b>
            </p>
            <p>
                <?php _e( 'Cost per 1 SMS', 'wp-sms-46elks'); ?>: <?php echo $this->SMSprice; ?> kr<br />
                <?php _e( 'Current amount of receivers', 'wp-sms-46elks'); ?>: <?php echo count( $this->receivers ); ?>
            </p>
            <?php
        }
        
        // function to make value more readable
        function convertBalanceValue ( $value = 0 )
        {
            return ( $value / 10000 );
        }
        
        // function that displays the whole WP-admin GUI
        function wpsms46elks_gui ()
        {
            ?>
            <div class="wrap">

                <h2><?php _e( 'WordPress SMS for 46elks', 'wp-sms-46elks' ); ?></h2>

                <?php
                if ( $this->InvalidAccount )
                {
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <b><?php _e( '46elks credentials wrong or missing.', 'wp-sms-46elks' );?></b><br />
                            <?php _e( 'Error', 'wp-sms-46elks' );?>: <?php echo $this->InvalidAccount['msg']; ?>
                        </p>
                    </div>
                    <?php
                }

                // print success message
                if ( ! empty( $this->status['success'] ) )
                {
                    ?><div class="notice notice-success">
                        <p><?php echo $this->status['success']; ?></p>
                    </div><?php
                }
                // print error message
                if ( ! empty( $this->status['failed'] ) )
                {
                    ?><div class="notice notice-error">
                        <p><?php echo $this->status['failed']; ?></p>
                    </div><?php
                }
                ?>

                <div class="metabox-holder">
                    
                    <?php
                    if ( ! $this->InvalidAccount )
                    {
                        ?>
                        <div id="wp-sms-46elks-new-container" class="postbox-container" style="width: 100%;" >
                            <div class="postbox " id="wp-sms-46elks-new"  >
                                <h3 class="hndle" style="cursor: inherit;"><span><?php _e( 'Send SMS', 'wp-sms-46elks' );?></span></h3>
                                <div class="inside">

                                    <script type="text/javascript" >
                                    jQuery(document).ready(function() {
                                        jQuery('#wp-sms-46elks-message').keyup(function(){
                                            var chars = this.value.length,
                                                smslength = 160;
                                                messages = Math.ceil(chars / smslength),
                                                remaining = messages * smslength - (chars % (messages * smslength) || messages * smslength);
                                            jQuery('#wp-sms-46elks-submit').attr("disabled", false);
                                            jQuery('#wp-sms-46elks-message-used-chars').text(remaining);
                                            jQuery('#wp-sms-46elks-message-sms-count').text(messages);
                                            if ( remaining == 0 && messages == 0 )
                                                {
                                                    remaining = smslength;
                                                    messages = 1;
                                                    jQuery('#wp-sms-46elks-submit').attr("disabled", true);
                                                }
                                        });
                                    });
                                    </script>
                                    
                                    <style type="text/css">
                                    @media screen and (min-width: 783px) {
                                        .form-table td textarea {
                                            width: 25em;
                                        }
                                    }
                                    </style>
                                    
                                    <form method="POST" >
                                        <table class="form-table">
                                            <tbody>
                                                <tr>
                                                    <th><label for="wp-sms-46elks-from"><?php _e( 'From', 'wp-sms-46elks' );?></label></th>
                                                    <td><input type="text" name="wp-sms-46elks-from" id="wp-sms-46elks-from" value="<?php echo $this->from; ?>" class="regular-text" readonly ></td>
                                                </tr>
                                                <tr>
                                                    <th><label for="wp-sms-46elks-to"><?php _e( 'To', 'wp-sms-46elks' );?></label></th>
                                                    <td><input type="text" name="wp-sms-46elks-to" id="wp-sms-46elks-to" value="<?php _e( 'All WordPress users with cellphones', 'wp-sms-46elks' );?>" class="regular-text" readonly ></td>
                                                </tr>
                                                <tr>
                                                    <th><label for="wp-sms-46elks-message"><?php _e( 'Message content', 'wp-sms-46elks' );?></label></th>
                                                    <td><textarea id="wp-sms-46elks-message" name="wp-sms-46elks-message" placeholder="<?php _e( 'Write your SMS text here..', 'wp-sms-46elks' );?>" rows="5" cols="30" ></textarea>
                                                        <p class="wp-sms-46elks-message-description">
                                                            <span id="wp-sms-46elks-message-used-chars">160</span>/<span id="wp-sms-46elks-message-total-chars">160</span> <?php _e( 'characters remaining', 'wp-sms-46elks' );?> ( <span id="wp-sms-46elks-message-sms-count">1</span> <?php _e( 'SMS', 'wp-sms-46elks' );?> )
                                                        </p>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th>&nbsp;</th>
                                                    <td>
                                                        <?php submit_button( __('Send SMS', 'wp-sms-46elks'), 'primary', $this->plugin_slug.'-submit', true, array( 'disabled' => 'disabled') ); ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </form>

                                </div><!-- div inside -->
                            </div><!-- div wp-sms-46elks-new -->
                        </div><!-- div wp-sms-46elks-new-container -->

                        <?php
                    }
                    ?>
                    
                    <div id="wp-sms-46elks-account-container" class="postbox-container" style="width: 100%;" >
                        <div class="postbox " id="wp-sms-46elks-account"  >
                            <h3 class="hndle" style="cursor: inherit;"><span><?php _e( '46elks account', 'wp-sms-46elks' );?></span></h3>
                            <div class="inside">
                                
                                <?php
                                if ( ! $this->InvalidAccount )
                                {
                                    $this->wpsms46elks_account_status();
                                }
                                if ( ! $this->InvalidAccount  && is_super_admin() )
                                {
                                    ?>
                                    <br />
                                    <hr />
                                    <?php
                                }
                                if ( is_super_admin() )
                                {
                                    ?>
                                    <h4><?php _e( 'Account credentials', 'wp-sms-46elks' );?></h4>
                                    <form method="POST" action="options.php" >
                                        <?php
                                        settings_fields( $this->plugin_slug.'-settings' );
                                        do_settings_sections( $this->plugin_slug.'-settings' );
                                        ?>
                                        <table class="form-table">
                                            <tbody>
                                                <tr>
                                                    <th><label for="wp-sms-46elks-api-username"><?php _e( 'Your API username', 'wp-sms-46elks' );?></label></th>
                                                    <td><input type="text" name="wp-sms-46elks-api-username" id="wp-sms-46elks-api-username" value="<?php echo get_option($this->plugin_slug.'-api-username'); ?>" class="regular-text" ></td>
                                                </tr>
                                                <tr>
                                                    <th><label for="wp-sms-46elks-api-password"><?php _e( 'Your API password', 'wp-sms-46elks' );?></label></th>
                                                    <td><input type="password" name="wp-sms-46elks-api-password" id="wp-sms-46elks-api-password" value="<?php echo get_option($this->plugin_slug.'-api-password'); ?>" class="regular-text" ></td>
                                                </tr>
                                                <tr>
                                                    <th>&nbsp;</th>
                                                    <td>
                                                        <?php submit_button(); ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </form>
                                    <?php
                                }
                                ?>
                                </div><!-- div inside -->
                            </div><!-- div wp-sms-46elks-account-new -->
                        </div><!-- div wp-sms-46elks-account-container -->
                    
                    <div class="clear"></div>
                </div><!-- div metabox-holder -->

                <?php
                // Debug stuff to print various output
                if ( is_super_admin() && $this->debug )
                {
                    ?>
                    <hr />
                    <h4><?php _e( 'Debug $this', 'wp-sms-46elks' );?></h4>
                    <pre>
                        <?php print_r( $this ); ?>
                    </pre>
                    <?php
                }
                ?>
            </div>
            <?
        }

        function getReceivers ()
        {
            return $this->receivers;
        }
        
        function addReceiver ( $data )
        {
            // Add receiver to receiver list
            array_push( $this->receivers, $data );
            return true;
        }
        
        function addReceiversFromWP ()
        {
            $args = array(
                'meta_query' => array(
                    array (
                        'key' => 'cellphone',
                        'value' => '',
                        'compare' => '!='
                    ),
                ),
                'orderby' => 'first_name, last_name',
                'fields' => array( 'ID', 'display_name' ),
                'order' => 'ASC'
            );
            $users = get_users( $args );
            if ( ! empty ( $users ) )
            {
                foreach ( $users as $user )
                {
                    $cellphone = get_user_meta( $user->ID, 'cellphone', true );
                    $cellphone = $this->convertToInternational( $cellphone );
                    $this->addReceiver ( array( $cellphone => $user->display_name ) );
                }
            }
            return true;
        }
        
        function convertToInternational( $number )
        {
                $number = str_replace( ' ', '', $number );
                $number = str_replace( '-', '', $number );
                $number = preg_replace( '/^00/',    '+',    $number );
                $number = preg_replace( '/^0/',     '+46',  $number );
                return $number;
        }
        
        function addMessage( $message )
        {
            $this->message = $message;
            return true;
        }
        
        function sendSMS ()
        {
            // check if there are any receivers
            if ( ! empty( $this->receivers ) )
            {
                unset( $this->result );
                // foreach on receivers
                foreach ( $this->receivers as $tmp => $receiver )
                {
                    foreach ( $receiver as $phone => $name )
                    {
                        $sms = $this->API_basic;
                        $sms['body'] = array(
                            'from'      => $this->from,
                            'to'        => $phone,
                            'message'   => $this->message
                        );

                        // creating WP_remote_post and performing sending
                        $this->response = wp_remote_post(
                            $this->API_uri.'/SMS',
                            $sms
                        );
                        
                        $data = $this->handleResponse( $this->response );
                        
                        array_push( $this->APIresult, $data );
                    }
                }
            }
        }
        
        function wpsms46elks_user_contactmethods ( $profile_fields )
        {
            // Add new fields
            $profile_fields['cellphone'] = 'Cellphone';
            return $profile_fields;
        }
        
        
        function wpsms46elks_admin_menu() {
            add_menu_page( __( 'WordPress SMS for 46elks', 'wp-sms-46elks' ), __('SMS via 46elks', 'wp-sms-46elks'), 'publish_pages', $this->plugin_slug, array( $this, 'wpsms46elks_gui' ), 'dashicons-testimonial', 3.98765  );
        }
        
        function wpsms46elks_admin_init()
        {
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-api-username' );
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-api-password' );
        }
    }
}


if ( class_exists('WPSMS46elks') )
{
    // instantiate the plugin WPSMS46elks class
    new WPSMS46elks();
}
