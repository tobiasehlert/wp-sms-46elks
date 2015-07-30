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
Version:        0.2
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
        private         $cellphone_slug = 'cellphone';
        private         $debug          = true;
        private         $frommaxlength  = '11';
        private         $totalSMScost   = '0';
        private         $credMultiply   = '10000';
        private         $API_uri        = 'https://api.46elks.com/a1';

        protected       $AccountBalance;
        protected       $receivers  = array();
        protected       $message;
        protected       $result     = array();
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
                    $this->status['success'] = __('Your message was successful when sending to', $this->plugin_slug ).' '.$this->result['success'].' '.__('cellphones', $this->plugin_slug ).'!<br />'.
                        __('The SMS cost was ', $this->plugin_slug ).$this->convertBalanceValue ( $this->totalSMScost ).' sek';
                }
                if ( $this->result['failed'] >= 1 )
                    $this->status['failed'] = __('Your message failed when sending to', $this->plugin_slug ).' '.$this->result['failed'].' '.__('cellphones', $this->plugin_slug ).'!';
            }
            elseif ( isset( $_POST['wp-sms-46elks-message'] ) )
                $this->status['failed'] = __('You forgot to enter a message', $this->plugin_slug ).'!';
            
            // getting the current account balance for status window
            $this->getAccountRequest();
        }
        
        function wpsms46elks_wp_dashboard_setup ()
        {
            wp_add_dashboard_widget( $this->plugin_slug.'-dashboard', __( 'WordPress SMS for 46elks', $this->plugin_slug ), array( $this, 'wpsms46elks_dashboard_content' ), null );
        }
        function wpsms46elks_dashboard_content ()
        {
            if ( $this->getAccountLowCredits() )
            {
               ?>
                <p>
                    <b><?php _e( 'There are only a few credits left', $this->plugin_slug );?>!</b><br />
                </p>
                <hr />
                <?php
            }
            if ( $this->getAccountNoCredits() )
            {
                ?>
                <p>
                    <b><?php _e( 'There are no credits left', $this->plugin_slug );?>!</b><br />
                </p>
                <hr />
                <?php
            }
            $this->wpsms46elks_account_status();
            ?>
            <hr />
            <p>
                <a href="<?php echo admin_url( 'admin.php?page='.$this->plugin_slug ); ?>"><?php _e( 'Go to plugin page', $this->plugin_slug );?></a>
            </p>
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
        
        function getAccountRequest ()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->generateSMSbasics();
            $this->response = wp_remote_get(
                $this->API_uri.'/me',
                $sms
            );
            
            $data = $this->handleResponse( $this->response );
            $data['body'] = json_decode( $data['servermsg']['body'] );

            if ( $data['servermsg']['code'] != 200 )
            {
                // set account to invalid
                $this->setAccountValidStatus( false );
                $this->setAccountStatusMessage( $data['servermsg']['code'].' '.$data['servermsg']['message'] );
                
                return false;
            }
            else
            {
                // set account to valid
                $this->setAccountValidStatus( true );
                
                // set various parameters for account stuff..
                $this->generateAccountInformation( $data['body'] );

                return true;
            }
        }

        function generateAccountInformation ( $data  = '' )
        {
            if ( strlen( $data->displayname ) > 0 )
                $this->setAccountType( 'main' );
            elseif ( strlen( $data->name ) > 0 )
                $this->setAccountType( 'sub' );
            
            if ( $this->getAccountType() == 'main' )
            {
                $this->setAccountBalance( 'name', $data->displayname );
                
                // account with limitation
                $this->setAccountLimited( true );
                
                if ( is_numeric( $data->balance ) )
                    $this->setAccountBalance( 'leftcred', $data->balance );
                else
                    $this->setAccountBalance( 'leftcred', '0' );
            }
            elseif ( $this->getAccountType() == 'sub' )
            {
                $this->setAccountBalance( 'name', $data->name );

                if ( is_numeric( $data->balanceused ) )
                    $this->setAccountBalance( 'balanceused', $data->balanceused );
                else
                    $this->setAccountBalance( 'balanceused', '0' );
                
                if ( is_numeric( $data->usagelimit ) )
                {
                    // account with limitation
                    $this->setAccountLimited( true );
                    
                    $this->setAccountBalance( 'usagelimit', $data->usagelimit );
                    $this->setAccountBalance( 'leftcred', ( $this->getAccountBalance( 'usagelimit' ) - $this->getAccountBalance( 'balanceused' )  ) );
                }
                else
                {
                    // account does not have any limitation set
                    $this->setAccountLimited( false );
                }
            }
            
            // run is its a limited account
            if ( $this->getAccountLimited() )
            {
                if ( ( count( $this->getReceivers() ) * $this->credMultiply ) >= $this->getAccountBalance( 'leftcred' ) )
                    $this->setAccountNoCredits( true );
                else
                {
                    $this->setAccountNoCredits( false );
                    
                    $balancealert = get_option( $this->plugin_slug.'-balancealert' );
                    if ( ( $balancealert * $this->credMultiply ) >= $this->getAccountBalance( 'leftcred' ) )
                    {
                        $this->setAccountLowCredits( true );
                        $this->triggerAlertEmail();
                    }
                    else
                    {
                        $this->setAccountLowCredits( false );

                        // resetting sent trigger if limit is lower than credits left
                        if ( get_option( $this->plugin_slug.'-balancealert-sent' ) != 'true' )
                            update_option( $this->plugin_slug.'-balancealert-sent', 'false' );
                    }
                }
            }

            return true;
        }
        
        function setAccountValidStatus ( $status = false )
        {
            $this->AccountValidStatus = $status;
            return true;
        }
        function getAccountValidStatus()
        {
            return ( $this->AccountValidStatus );
        }
        
        function setAccountType ( $type = 'main' )
        {
            $this->AccountType = $type;
            return true;
        }
        function getAccountType()
        {
            return ( $this->AccountType );
        }
        
        function setAccountLimited ( $limited = false )
        {
            $this->AccountLimited = $limited;
            return true;
        }
        function getAccountLimited ()
        {
            return $this->AccountLimited;
        }
        
        function setAccountStatusMessage ( $message = '' )
        {
            $this->AccountStatusMessage = $message;
            return true;
        }
        function getAccountStatusMessage()
        {
            return $this->AccountStatusMessage;
        }
        
        function setAccountLowCredits ( $status = false )
        {
            $this->AccountLowCredits = $status;
            return true;
        }
        function getAccountLowCredits()
        {
            return $this->AccountLowCredits;
        }
        
        function setAccountNoCredits ( $status = false )
        {
            $this->AccountNoCredits = $status;
            return true;
        }
        function getAccountNoCredits()
        {
            return $this->AccountNoCredits;
        }

        function setAccountBalance ( $key = '', $value = '' )
        {
            if ( strlen( $key ) > 0 && strlen ( $value ) > 0 )
            {
                $this->AccountBalance[ $key ] = $value;
            }
            return true;
        }
        function getAccountBalance ( $which = '' )
        {
            if ( isset ( $this->AccountBalance[ $which ] ) )
                return $this->AccountBalance[ $which ];
            else
                return '';
        }
                
        function triggerAlertEmail ()
        {
            if ( get_option( $this->plugin_slug.'-balancealert-sent' ) != 'true' )
            {
                $to = get_option( 'admin_email' );
                $subject = '['.get_option('siteurl').'] Wordpress SMS for 46elks - low on credits';
                $body = 'Hello admin for '.get_option('siteurl').'!<br /><br />Your account has a balance of: '.$this->getAccountBalance( 'leftcred' ).'<br />Add more credits or you might run out of option to send SMS to users.<br /><b< />/ Wordpress SMS for 46elks';
                $headers = array('Content-Type: text/html; charset=UTF-8');
                wp_mail( $to, $subject, $body, $headers );
                
                update_option( $this->plugin_slug.'-balancealert-sent', 'true' );
                return true;
            }
            else
                return false;
        }


        function wpsms46elks_account_status()
        {
            if ( $this->getAccountValidStatus() )
            {
                ?>
                <p>
                    <?php _e( '46elks account name', $this->plugin_slug ); ?>:<br />
                    <b><?php echo $this->getAccountBalance( 'name' ); ?></b>
                </p>
                <p>
                    <?php _e( '46elks credits left', $this->plugin_slug ); ?>:<br />
                    <b>
                        <?php
                        if ( $this->getAccountLimited() )
                            echo $this->convertBalanceValue( $this->getAccountBalance( 'leftcred' ) ).' sek';
                        else
                            echo _e( 'unavailable', $this->plugin_slug );
                        ?></b>
                </p>
                <?php
            }
            else
            {
                ?>
                <p>
                    <b><?php _e( '46elks credentials wrong or missing', $this->plugin_slug );?>.</b>
                </p>
                <?php
            }
        }
        
        // function to make value more readable
        function convertBalanceValue ( $value = 0 )
        {
            return ( $value / $this->credMultiply );
        }
        
        // function that displays the whole WP-admin GUI
        function wpsms46elks_gui ()
        {
            ?>
            <div class="wrap">

                <h2><?php _e( 'WordPress SMS for 46elks', $this->plugin_slug ); ?></h2>

                <?php
                if ( ! $this->getAccountValidStatus() )
                {
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <b><?php _e( '46elks credentials wrong or missing', $this->plugin_slug );?>.</b>
                            <?php
                            if ( is_super_admin() )
                            {
                                ?><br />
                                <?php _e( 'Error', $this->plugin_slug );?>: <?php echo $this->getAccountStatusMessage();
                            }
                            ?>
                        </p>
                    </div>
                    <?php
                }

                if ( $this->getAccountLowCredits() )
                {
                    ?>
                    <div class="notice notice-warning">
                        <p>
                            <b><?php _e( 'Few credits left', $this->plugin_slug );?>!</b><br />
                            <?php _e( 'There are only a few credits left', $this->plugin_slug );?>: <?php echo $this->convertBalanceValue( $this->getAccountBalance( 'leftcred' ) ); ?> sek<br />
                        </p>
                    </div>
                    <?php
                }
                if ( $this->getAccountNoCredits() )
                {
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <b><?php _e( 'No credits left', $this->plugin_slug );?>!</b><br />
                            <?php _e( 'There are not enought credits left to perform sending of SMS to all receivers', $this->plugin_slug );?>
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

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <?php // CURRENTHERE
                        if ( $this->getAccountValidStatus() && ! $this->getAccountNoCredits() )
                        {
                            ?>
                            <div id="post-body-content" style="position: relative;">
                                <div id="wp-sms-46elks-new-container" class="postbox-container" style="width: 100%;" >
                                    <div class="postbox " id="wp-sms-46elks-new"  >
                                        <h3 class="hndle" style="cursor: inherit;"><span><?php _e( 'Send SMS', $this->plugin_slug );?></span></h3>
                                        <div class="inside">

                                            <script type="text/javascript" >
                                            jQuery(document).ready(function()
                                            {
                                                jQuery('#wp-sms-46elks-message').keyup(function()
                                                {
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
                                                            <th><label for="wp-sms-46elks-from"><?php _e( 'From', $this->plugin_slug );?></label></th>
                                                            <td><input type="text" name="wp-sms-46elks-from" id="wp-sms-46elks-from" value="<?php echo get_option($this->plugin_slug.'-from'); ?>" class="regular-text" readonly ></td>
                                                        </tr>
                                                        <tr>
                                                            <th><label for="wp-sms-46elks-message"><?php _e( 'Message content', $this->plugin_slug );?></label></th>
                                                            <td><textarea id="wp-sms-46elks-message" name="wp-sms-46elks-message" placeholder="<?php _e( 'Write your SMS text here..', $this->plugin_slug );?>" rows="5" cols="30" ></textarea>
                                                                <p class="wp-sms-46elks-message-description">
                                                                    <span id="wp-sms-46elks-message-used-chars">160</span>/<span id="wp-sms-46elks-message-total-chars">160</span> <?php _e( 'characters remaining', $this->plugin_slug );?> ( <span id="wp-sms-46elks-message-sms-count">1</span> <?php _e( 'SMS', $this->plugin_slug );?> )
                                                                </p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>&nbsp;</th>
                                                            <td>
                                                                <?php submit_button( __('Send SMS', $this->plugin_slug ), 'primary', $this->plugin_slug.'-submit', true, array( 'disabled' => 'disabled') ); ?>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </form>

                                        </div><!-- div inside -->
                                    </div><!-- div wp-sms-46elks-new -->
                                </div><!-- div wp-sms-46elks-new-container -->
                            </div>
                            <?php
                        }
                        ?>


                        <div id="postbox-container-1" class="postbox-container">
                            
                            <div class="meta-box-sortables">
                                <div class="postbox ">
                                    <h3 class="hndle" style="cursor: inherit;"><span><?php _e( 'Account status', $this->plugin_slug );?></span></h3>
                                    <div class="inside">
                                        <div id="misc-publishing-actions">
                                            <?php
                                            $this->wpsms46elks_account_status();
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="meta-box-sortables">
                                <div class="postbox ">
                                    <h3 class="hndle" style="cursor: inherit;"><span><?php _e( 'Current receivers', $this->plugin_slug );?></span></h3>
                                    <div class="inside">
                                        <div id="misc-publishing-actions">
                                            <p>
                                                <?php _e( 'Current amount of receivers', $this->plugin_slug ); echo count( $this->getReceivers() ); ?>
                                            </p>
                                            <p>
                                                <?php
                                                if ( count( $this->getReceivers() ) > 0 )
                                                {
                                                    foreach ( $this->getReceivers() as $key => $value )
                                                    {
                                                        foreach ( $value as $cellphone => $name )
                                                        {
                                                            echo $name; ?> <i><?php echo $cellphone; ?></i><br /><?php
                                                        }
                                                    }
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php
                            if ( is_super_admin() )
                            {
                                ?>
                                <div class="meta-box-sortables">
                                    <div class="postbox ">
                                        <h3 class="hndle" style="cursor: inherit;"><span><?php _e( 'Cost history', $this->plugin_slug );?></span></h3>
                                        <div class="inside">
                                            <div id="misc-publishing-actions">
                                                <p>
                                                    <?php
                                                    $this->getCostHistory();
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                            
                        </div>

                        <div id="postbox-container-2" class="postbox-container">
                            <?php
                            if ( is_super_admin() )
                            {
                                ?>
                                <div class="meta-box-sortables">
                                    <div class="postbox ">
                                        <h3 class="hndle" style="cursor: inherit;"><span><?php _e( 'Settings', $this->plugin_slug );?></span></h3>
                                        <div class="inside">
                                            <form method="POST" action="options.php" >
                                                <?php
                                                settings_fields( $this->plugin_slug.'-settings' );
                                                do_settings_sections( $this->plugin_slug.'-settings' );

                                                if ( $this->getAccountValidStatus() )
                                                {
                                                    ?>
                                                    <table class="form-table">
                                                        <tbody>
                                                            <tr>
                                                                <th><label for="wp-sms-46elks-from"><?php _e( 'SMS from name / number', $this->plugin_slug );?></label></th>
                                                                <td>
                                                                    <input type="text" name="wp-sms-46elks-from" id="wp-sms-46elks-from" value="<?php echo get_option($this->plugin_slug.'-from'); ?>" class="regular-text" maxlength="<?php $this->frommaxlength; ?>" />
                                                                    <p class="description"><?php _e( 'When using an alphanumeric "from" it needs to be between 3-11 characters in length and you are restricted to using only a-z, A-Z, 0-9.', $this->plugin_slug );?><br />
                                                                        <?php _e( 'No other characters can be used, and the name has to begin with a letter.', $this->plugin_slug );?></p>
                                                                </td>
                                                            </tr>
                                                            <?php
                                                            if ( $this->getAccountLimited() )
                                                            {
                                                                ?>
                                                                <tr>
                                                                    <th><label for="wp-sms-46elks-balancealert"><?php _e( 'Send balancealert email', $this->plugin_slug );?></label></th>
                                                                    <td>
                                                                        <input type="text" name="wp-sms-46elks-balancealert" id="wp-sms-46elks-balancealert" value="<?php echo get_option($this->plugin_slug.'-balancealert'); ?>" class="regular-text" />
                                                                        <p class="description"><?php _e( 'Enter an balancealert value to account on which the pageadmin will get notification on low credits.', $this->plugin_slug );?><br />
                                                                            <?php _e( 'Number must be an integer like 5, which means 5 which is in', $this->plugin_slug );?> sek</p>
                                                                    </td>
                                                                </tr>
                                                                <?php
                                                            }
                                                            ?>
                                                            <tr>
                                                                <th><label for="wp-sms-46elks-filter"><?php _e( 'Extra filter on users', $this->plugin_slug );?></label></th>
                                                                <td>
                                                                    <input type="text" name="wp-sms-46elks-filter" id="wp-sms-46elks-filter" value="<?php echo get_option($this->plugin_slug.'-filter'); ?>" class="regular-text" />
                                                                    <p class="description"><?php _e( 'Its possible to add extra filter on the retrieval of cellphone numbers of users. Values need to be metadata on users.', $this->plugin_slug );?><br />
                                                                        <?php _e( 'Example: status = 1', $this->plugin_slug );?></p>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                    <hr />
                                                    <?php
                                                }
                                                ?>
                                                <table class="form-table">
                                                    <tbody>
                                                        <tr>
                                                            <th><label for="wp-sms-46elks-api-username"><?php _e( 'Your API username', $this->plugin_slug ); ?></label></th>
                                                            <td><input type="text" name="wp-sms-46elks-api-username" id="wp-sms-46elks-api-username" value="<?php echo get_option($this->plugin_slug.'-api-username'); ?>" class="regular-text" ></td>
                                                        </tr>
                                                        <tr>
                                                            <th><label for="wp-sms-46elks-api-password"><?php _e( 'Your API password', $this->plugin_slug ); ?></label></th>
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
                                        </div>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>


                        <?php
                        // Debug stuff to print various output
                        if ( is_super_admin() && $this->debug )
                        {
                            ?>
                            <hr />
                            <h4><?php _e( 'Debug $this', $this->plugin_slug );?></h4>
                            <pre>
                                <?php print_r( $this ); ?>
                            </pre>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div><!-- div wrap -->
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
        
        function getCostHistoryData ()
        {
            // creating WP_remote_post and performing sending
            $sms = $this->generateSMSbasics();
            $this->response = wp_remote_get(
                $this->API_uri.'/SMS',
                $sms
            );
            
            $data = $this->handleResponse( $this->response );
            $data['body'] = json_decode( $data['servermsg']['body'] );

            if ( $data['servermsg']['code'] == 200 )
            {
                $list = $data['body']->data;
                
                // Loop until all SMS are receaved or max 10 000 messages.
                $max = 100;
                while ( true )
                {
                    if ( isset( $data['body']->next ) )
                    {
                        if ( $max < 0 )
                        {
                            break;
                        }

                        $max = $max - 1;
                        
                        // creating WP_remote_post and performing sending
                        $sms = $this->generateSMSbasics();
                        $this->response = wp_remote_get(
                            $this->API_uri.'/SMS?start='.$data['body']->next,
                            $sms
                        );
                        
                        $data = $this->handleResponse( $this->response );
                        $data['body'] = json_decode( $data['servermsg']['body'] );

                        if ( $data['servermsg']['code'] == 200 )
                        {
                            $list = array_merge($list, $data['body']->data);
                        }
                        
                        else
                        {
                            break;
                        }
                    }
                    else
                        break;
                }
                return $list;
            }
            else
                return false;
        }
        function getCostHistory()
        {
            $list = $this->getCostHistoryData();
            if ( $list != false )
            {
                $costmonth = array();
                $numbermonth = array();
                
                // Read all items.
                foreach ( $list as $sms )
                {
                    if ( isset( $sms->cost ) )
                    {
                        $month      = substr( $sms->created, 0, 7 );
                        $numstart   = substr( $sms->to, 0, 4 );

                        if ( isset( $costmounth[$month] ) == false )
                            $costmounth[$month] = 0;
                        $costmonth[$month] = $costmonth[$month] + $sms->cost;

                        if ( isset( $numbermonth[$month] ) == false )
                            $numbermonth[$month] = array();
                        if ( isset( $numbermonth[$month][$numstart] ) == false )
                            $numbermonth[$month][$numstart] = 0;
                        $numbermonth[$month][$numstart] = $numbermonth[$month][$numstart] + 1;
                    }
                }
                
                if ( is_super_admin() )
                {
                    ?><table style="width: 100%;">
                        <thead>
                            <tr>
                                <th><?php _e( 'Month', $this->plugin_slug ); ?></th>
                                <th><?php _e( 'Cost', $this->plugin_slug ); ?></th>
                                <th><?php _e( 'Destination', $this->plugin_slug ); ?></th>
                                <th><?php _e( 'SMS', $this->plugin_slug ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Print the data to the user:
                            foreach ( $costmonth as $month => $cost )
                            {
                                $i = 0;
                                $numbers = '';
                                $amounts = '';
                                
                                foreach($numbermonth[$month] as $number => $amount )
                                {
                                    $i++;
                                    if ( $i != 1 )
                                        $linebreak = '<br />';
                                    else
                                        $linebreak = '';
                                    
                                    $numbers .= $number.$linebreak;
                                    $amounts .= $amount.$linebreak;
                                }
                                
                                ?>
                                <tr>
                                    <td><?php echo $month; ?></td>
                                    <td><?php echo $this->convertBalanceValue( $cost ); ?></td>
                                    <td><?php echo $numbers; ?></td>
                                    <td><?php echo $amounts; ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                    <?php
                }
            }
            else
            {
                _e( 'No SMS sent yet so there is no history available.', $this->plugin_slug );
            }
        }
        
        function generateArgsUserQuery ()
        {
            $return = array(
                'meta_query' => array(
                    array (
                        'key' => $this->cellphone_slug,
                        'compare' => '!=',
                        'value' => ''
                    ),
                ),
                'orderby' => 'first_name, last_name',
                'fields' => array( 'ID', 'display_name' ),
                'order' => 'ASC'
            );
            
            // retrieval of extra filter made in settings
            $settingsfilter = get_option($this->plugin_slug.'-filter');
            if ( strlen( $settingsfilter ) > 0 )
            {
                // extra filter based from settings
                $data = explode( " ", $settingsfilter );
                if ( count( $data ) == 3 )
                {
                    $data = array(
                            'key' => trim( $data['0'] ),
                            'compare' => trim( $data['1'] ),
                            'value' => trim( $data['2'] )
                        );
                    array_push( $return['meta_query'], $data );
                }
            }
            
            return $return;
        }
        
        function addReceiversFromWP ()
        {
            $users = get_users( $this->generateArgsUserQuery() );
            if ( ! empty ( $users ) )
            {
                foreach ( $users as $user )
                {
                    $cellphone = get_user_meta( $user->ID, $this->cellphone_slug, true );
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
                // FIXME add option to set default country code ( $this->plugin_slug.'-default-countrycode' )
                $number = preg_replace( '/^0/',     '+46',  $number );
                return $number;
        }
        
        function addMessage( $message )
        {
            $this->message = $message;
            return true;
        }
        
        function generateSMSbasics()
        {
            $data = array(
                'headers'   => array(
                    'Authorization' => 'Basic ' .base64_encode( get_option($this->plugin_slug.'-api-username').':'.get_option($this->plugin_slug.'-api-password') ),
                    'Content-type'  => 'application/x-www-form-urlencoded'
                )
            );
            return $data;
        }
        
        function sendSMS ()
        {
            // check if there are any receivers
            if ( count( $this->getReceivers() ) > 0 )
            {
                unset( $this->result );
                
                // foreach on receivers
                foreach ( $this->getReceivers() as $key => $value )
                {
                    foreach ( $value as $phone => $name )
                    {
                        $sms = $this->generateSMSbasics();
                        $sms['body'] = array(
                            'from'      => get_option($this->plugin_slug.'-from'),
                            'to'        => $phone,
                            'message'   => $this->message
                        );

                        // creating WP_remote_post and performing sending
                        $this->response = wp_remote_post(
                            $this->API_uri.'/SMS',
                            $sms
                        );
                        
                        $data = $this->handleResponse( $this->response );
                        $data['body'] = json_decode( $data['servermsg']['body'] );
                        
                        $this->totalSMScost += $data['body']->cost;
                        
                        if ( $this->debug && is_super_admin() )
                            array_push( $this->APIresult, $data );
                    }
                }
            }
        }
        
        function wpsms46elks_user_contactmethods ( $profile_fields )
        {
            // Add new fields
            $profile_fields[$this->cellphone_slug] = 'Cellphone';
            return $profile_fields;
        }
        
        
        function wpsms46elks_admin_menu() {
            add_menu_page( __( 'WordPress SMS for 46elks', $this->plugin_slug ), __('SMS via 46elks', $this->plugin_slug ), 'publish_pages', $this->plugin_slug, array( $this, 'wpsms46elks_gui' ), 'dashicons-testimonial', 3.98765  );
        }
        
        function wpsms46elks_admin_init()
        {
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-from' );
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-filter' );
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-default-countrycode' );
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-balancealert' );
            register_setting( $this->plugin_slug.'-settings', $this->plugin_slug.'-balancealert-sent' );
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
