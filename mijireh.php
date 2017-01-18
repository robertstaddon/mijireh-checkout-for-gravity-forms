<?php
/**
 * Plugin Name: Mijireh Checkout for Gravity Forms
 * Plugin URI: http://www.patsatech.com/
 * Description: Allows for integration with the Mijireh Checkout payment gateway.
 * Version: 1.0.1
 * Author: robertstaddon
 * Author URI: http://www.abundantdesigns.com
 * Contributors: patsatech, robertstaddon
 * Requires at least: 3.5
 * Tested up to: 4.6.1
 *
 * Text Domain: gravityformsmijirehcheckout
 * Domain Path: /lang/
 *
 * @package Mijireh Checkout for Gravity Forms
 * @author PatSaTECH
 */

add_action('parse_request', array("GFMijirehCheckout", "process_ipn"));
add_action('wp',  array('GFMijirehCheckout', 'maybe_thankyou_page'), 5);

add_action('init',  array('GFMijirehCheckout', 'init'));
register_activation_hook( __FILE__, array("GFMijirehCheckout", "add_permissions"));

if(!defined("GF_MIJIREHCHECKOUT_PLUGIN_PATH"))
    define("GF_MIJIREHCHECKOUT_PLUGIN_PATH", dirname( plugin_basename( __FILE__ ) ) );
	
if(!defined("GF_MIJIREHCHECKOUT_PLUGIN"))
    define("GF_MIJIREHCHECKOUT_PLUGIN", dirname( plugin_basename( __FILE__ ) ) . "/mijireh.php" );

if(!defined("GF_MIJIREHCHECKOUT_BASE_URL"))
    define("GF_MIJIREHCHECKOUT_BASE_URL", plugins_url(null, __FILE__) );
    
if(!defined("GF_MIJIREHCHECKOUT_BASE_PATH"))
    define("GF_MIJIREHCHECKOUT_BASE_PATH", WP_PLUGIN_DIR . "/" . basename(dirname(__FILE__)) );

class GFMijirehCheckout {

    private static $path = GF_MIJIREHCHECKOUT_PLUGIN;
    private static $url = "https://www.patsatech.com";
    private static $slug = "gravityformsmijirehcheckout";
    private static $version = "1.0.0";
    private static $min_gravityforms_version = "1.6.4";
    private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title", "post_tags", "post_custom_field", "post_content", "post_excerpt");

    //Plugin starting point. Will load appropriate files
    public static function init(){
		//supports logging
		add_filter("gform_logging_supported", array("GFMijirehCheckout", "set_logging_supported"));

        if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

            //loading translations
            load_plugin_textdomain('gravityformsmijirehcheckout', FALSE, GF_MIJIREHCHECKOUT_PLUGIN_PATH . '/languages' );

        }

        if(!self::is_gravityforms_supported())
           return;

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformsmijirehcheckout', FALSE, GF_MIJIREHCHECKOUT_PLUGIN_PATH . '/languages' );

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFMijirehCheckout", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFMijirehCheckout', 'create_menu'));

            //add actions to allow the payment status to be modified
            add_action('gform_payment_status', array('GFMijirehCheckout','admin_edit_payment_status'), 3, 3);
            add_action('gform_entry_info', array('GFMijirehCheckout','admin_edit_payment_status_details'), 4, 2);
            add_action('gform_after_update_entry', array('GFMijirehCheckout','admin_update_payment'), 4, 2);
		  	add_action( 'add_meta_boxes', array( 'GFMijirehCheckout', 'add_page_slurp_meta' ) );
		  	add_action( 'wp_ajax_page_slurp', array( 'GFMijirehCheckout', 'page_slurp' ) );


            if(self::is_mijireh_checkout_page()){

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");
                add_filter('gform_tooltips', array('GFMijirehCheckout', 'tooltips'));

                //enqueueing sack for AJAX requests
                wp_enqueue_script(array("sack"));

                //loading data lib
                require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

                //runs the setup when version changes
                self::setup();

            }
            else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

                //loading data class
                require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

                add_action('wp_ajax_gf_mijireh_checkout_update_feed_active', array('GFMijirehCheckout', 'update_feed_active'));
                add_action('wp_ajax_gf_select_mijireh_checkout_form', array('GFMijirehCheckout', 'select_mijireh_checkout_form'));
                add_action('wp_ajax_gf_mijireh_checkout_load_notifications', array('GFMijirehCheckout', 'load_notifications'));

            }
            else if(RGForms::get("page") == "gf_settings"){
                RGForms::add_settings_page("Mijireh Checkout", array("GFMijirehCheckout", "settings_page"), GF_MIJIREHCHECKOUT_BASE_URL . "/assets/images/mijireh_checkout_wordpress_icon_32.png");
            }
        }
        else{
            //loading data class
            require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

            //handling post submission.
            add_filter("gform_confirmation", array("GFMijirehCheckout", "send_to_mijireh_checkout"), 1000, 4);

            //setting some entry metas
            //add_action("gform_after_submission", array("GFMijirehCheckout", "set_entry_meta"), 5, 2);

            add_filter("gform_disable_post_creation", array("GFMijirehCheckout", "delay_post"), 10, 3);
            add_filter("gform_disable_user_notification", array("GFMijirehCheckout", "delay_autoresponder"), 10, 3);
            add_filter("gform_disable_admin_notification", array("GFMijirehCheckout", "delay_admin_notification"), 10, 3);
            add_filter("gform_disable_notification", array("GFMijirehCheckout", "delay_notification"), 10, 4);

            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFMijirehCheckout', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFMijirehCheckout', 'premium_update') );
        }
    }
	
    /**
     * page_slurp function.
     *
     * @access public
     * @return void
     */
    public static function page_slurp() {

    	self::init_mijireh();

		$page 	= get_page( absint( $_POST['page_id'] ) );
		$url 	= get_permalink( $page->ID );
		$job_id = $url;
		if ( wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'publish' ) ) ) {
			$job_id = Mijireh::slurp( $url, $page->ID, str_replace( 'https:', 'http:', add_query_arg( 'page', 'gf_mijireh_checkout_ipn', home_url( '/' ) ) ) );
    	}
		echo $job_id;
		die;
	}
	
    /**
     * add_page_slurp_meta function.
     *
     * @access public
     * @return void
     */
    public static function add_page_slurp_meta() {

    	if ( self::is_slurp_page() ) {
        	wp_enqueue_style( 'mijireh_css', GF_MIJIREHCHECKOUT_BASE_URL .'/assets/css/mijireh.css' );
        	wp_enqueue_script( 'pusher', 'https://d3dy5gmtp8yhk7.cloudfront.net/1.11/pusher.min.js', null, false, true );
        	wp_enqueue_script( 'page_slurp', GF_MIJIREHCHECKOUT_BASE_URL . '/assets/js/page_slurp.js', array('jquery'), false, true );

			add_meta_box(
				'slurp_meta_box', 		// $id
				'Mijireh Page Slurp', 	// $title
				array( 'GFMijirehCheckout', 'draw_page_slurp_meta_box' ), // $callback
				'page', 	// $page
				'normal', 	// $context
				'high'		// $priority
			);
		}
    }


    /**
     * is_slurp_page function.
     *
     * @access public
     * @return void
     */
    public static function is_slurp_page() {
		global $post;
		$is_slurp = false;
		if ( isset( $post ) && is_object( $post ) ) {
			$content = $post->post_content;
			if ( strpos( $content, '{{mj-checkout-form}}') !== false ) {
				$is_slurp = true;
			}
		}
		return $is_slurp;
    }


    /**
     * draw_page_slurp_meta_box function.
     *
     * @access public
     * @param mixed $post
     * @return void
     */
    public static function draw_page_slurp_meta_box( $post ) {

    	self::init_mijireh();

		echo "<div id='mijireh_notice' class='mijireh-info alert-message info' data-alert='alert'>";
		echo    "<h2>Slurp your custom checkout page!</h2>";
		echo    "<p>Get the page designed just how you want and when you're ready, click the button below and slurp it right up.</p>";
		echo    "<div id='slurp_progress' class='meter progress progress-info progress-striped active' style='display: none;'><div id='slurp_progress_bar' class='bar' style='width: 20%;'>Slurping...</div></div>";
		echo    "<p><a href='#' id='page_slurp' rel=". $post->ID ." class='button-primary'>Slurp This Page!</a> ";
		echo    '<a class="nobold" href="' . Mijireh::preview_checkout_link() . '" id="view_slurp" target="_new">Preview Checkout Page</a></p>';
		echo  "</div>";
    }

	/**
	 * install_slurp_page function.
	 *
	 * @access public
	 */
	public function install_slurp_page() {
	    $slurp_page_installed = get_option( 'slurp_page_installed', false );
		if ( $slurp_page_installed != 1 ) {
			if( ! get_page_by_path( 'mijireh-secure-checkout' ) ) {
				$page = array(
					'post_title' 		=> 'Mijireh Secure Checkout',
					'post_name' 		=> 'mijireh-secure-checkout',
					'post_parent' 		=> 0,
					'post_status' 		=> 'private',
					'post_type' 		=> 'page',
					'comment_status' 	=> 'closed',
					'ping_status' 		=> 'closed',
					'post_content' 		=> "<h1>Checkout</h1>\n\n{{mj-checkout-form}}",
				);
				wp_insert_post( $page );
			}
			update_option( 'slurp_page_installed', 1 );
		}
    }
	
	/**
	 * init_mijireh function.
	 *
	 * @access public
	 */
	public function init_mijireh() {
		if ( ! class_exists( 'Mijireh' ) ) {
	    	require_once 'includes/Mijireh.php';
			
            $settings = get_option("gf_mijireh_checkout_settings");
			$key = rgar($settings,"access_key");
			
	        if(empty($key)){
	            self::log_debug("Unable to get Mijireh Checkout Access Key.");
			}else{
				Mijireh::$access_key = $key;
			}
	    }
	}
	
    public static function update_feed_active(){
        check_ajax_referer('gf_mijireh_checkout_update_feed_active','gf_mijireh_checkout_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFMijirehCheckoutData::get_feed($id);
        GFMijirehCheckoutData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //-------------- Automatic upgrade ---------------------------------------


    //Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }
	
    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //------------------------------------------------------------------------

    //Creates Mijireh Checkout left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_mijireh_checkout");
        if(!empty($permission))
            $menus[] = array("name" => "gf_mijireh_checkout", "label" => __("Mijireh Checkout", "gravityformsmijirehcheckout"), "callback" =>  array("GFMijirehCheckout", "mijireh_checkout_page"), "permission" => $permission);

        return $menus;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){
        if(get_option("gf_mijireh_checkout_version") != self::$version)
            GFMijirehCheckoutData::update_table();

        update_option("gf_mijireh_checkout_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $mijireh_checkout_tooltips = array(
            "mijireh_checkout_transaction_type" => "<h6>" . __("Transaction Type", "gravityformsmijirehcheckout") . "</h6>" . __("Select which Mijireh Checkout transaction type should be used. Products and Services, Donations or Subscription.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_gravity_form" => "<h6>" . __("Gravity Form", "gravityformsmijirehcheckout") . "</h6>" . __("Select which Gravity Forms you would like to integrate with Mijireh Checkout.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_customer" => "<h6>" . __("Customer", "gravityformsmijirehcheckout") . "</h6>" . __("Map your Form Fields to the available Mijireh Checkout customer information fields.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_cancel_url" => "<h6>" . __("Cancel URL", "gravityformsmijirehcheckout") . "</h6>" . __("Enter the URL the user should be sent to should they cancel before completing their Mijireh Checkout payment.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_options" => "<h6>" . __("Options", "gravityformsmijirehcheckout") . "</h6>" . __("Turn on or off the available Mijireh Checkout checkout options.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_conditional" => "<h6>" . __("Mijireh Checkout Condition", "gravityformsmijirehcheckout") . "</h6>" . __("When the Mijireh Checkout condition is enabled, form submissions will only be sent to Mijireh Checkout when the condition is met. When disabled all form submissions will be sent to Mijireh Checkout.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_edit_payment_amount" => "<h6>" . __("Amount", "gravityformsmijirehcheckout") . "</h6>" . __("Enter the amount the user paid for this transaction.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_edit_payment_date" => "<h6>" . __("Date", "gravityformsmijirehcheckout") . "</h6>" . __("Enter the date of this transaction.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_edit_payment_transaction_id" => "<h6>" . __("Transaction ID", "gravityformsmijirehcheckout") . "</h6>" . __("The transacation id is returned from Mijireh Checkout and uniquely identifies this payment.", "gravityformsmijirehcheckout"),
            "mijireh_checkout_edit_payment_status" => "<h6>" . __("Status", "gravityformsmijirehcheckout") . "</h6>" . __("Set the payment status. This status can only be altered if not currently set to Approved.", "gravityformsmijirehcheckout")
        );
        return array_merge($tooltips, $mijireh_checkout_tooltips);
    }

    public static function delay_post($is_disabled, $form, $lead){
        //loading data class
        require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

        $config = GFMijirehCheckoutData::get_feed_by_form($form["id"]);
        if(!$config)
            return $is_disabled;

        $config = $config[0];
        if(!self::has_mijireh_checkout_condition($form, $config))
            return $is_disabled;

        return $config["meta"]["delay_post"] == true;
    }

    //Kept for backwards compatibility
    public static function delay_admin_notification($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_notification"]) ? $config["meta"]["delay_notification"] == true : $is_disabled;
    }

    //Kept for backwards compatibility
    public static function delay_autoresponder($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_autoresponder"]) ? $config["meta"]["delay_autoresponder"] == true : $is_disabled;
    }

    public static function delay_notification($is_disabled, $notification, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        $selected_notifications = is_array(rgar($config["meta"], "selected_notifications")) ? rgar($config["meta"], "selected_notifications") : array();

        return isset($config["meta"]["delay_notifications"]) && in_array($notification["id"], $selected_notifications) ? true : $is_disabled;
    }

    private static function get_selected_notifications($config, $form){
        $selected_notifications = is_array(rgar($config['meta'], 'selected_notifications')) ? rgar($config['meta'], 'selected_notifications') : array();

        if(empty($selected_notifications)){
            //populating selected notifications so that their delayed notification settings get carried over
            //to the new structure when upgrading to the new Mijireh Checkout Add-On
            if(!rgempty("delay_autoresponder", $config['meta'])){
                $user_notification = self::get_notification_by_type($form, "user");
                if($user_notification)
                    $selected_notifications[] = $user_notification["id"];
            }

            if(!rgempty("delay_notification", $config['meta'])){
                $admin_notification = self::get_notification_by_type($form, "admin");
                if($admin_notification)
                    $selected_notifications[] = $admin_notification["id"];
            }
        }

        return $selected_notifications;
    }

    private static function get_notification_by_type($form, $notification_type){
        if(!is_array($form["notifications"]))
            return false;

        foreach($form["notifications"] as $notification){
            if($notification["type"] == $notification_type)
                return $notification;
        }

        return false;

    }

    public static function mijireh_checkout_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else if($view == "stats")
            self::stats_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the mijirehcheckout feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("Mijireh Checkout Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformsmijirehcheckout"));
        }

        if(rgpost('action') == "delete"){
            check_admin_referer("list_action", "gf_mijireh_checkout_list");

            $id = absint($_POST["action_argument"]);
            GFMijirehCheckoutData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformsmijirehcheckout") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_mijireh_checkout_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFMijirehCheckoutData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformsmijirehcheckout") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("Mijireh Checkout Transactions", "gravityformsmijirehcheckout") ?>" src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL?>/assets/images/mijireh_checkout_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php
            _e("Mijireh Checkout Forms", "gravityformsmijirehcheckout");

            if(get_option("gf_mijireh_checkout_configured")){
                ?>
                <a class="button add-new-h2" href="admin.php?page=gf_mijireh_checkout&view=edit&id=0"><?php _e("Add New", "gravityformsmijirehcheckout") ?></a>
                <?php
            }
            ?>
            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_mijireh_checkout_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformsmijirehcheckout") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformsmijirehcheckout") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformsmijirehcheckout") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravityformsmijirehcheckout") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravityformsmijirehcheckout") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityformsmijirehcheckout") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformsmijirehcheckout") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformsmijirehcheckout") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformsmijirehcheckout") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformsmijirehcheckout") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php


                        $settings = GFMijirehCheckoutData::get_feeds();
			            $access_key = get_option("gf_mijireh_checkout_settings");
						$key = rgar($access_key,"access_key");
                        if(empty($key)){
                            ?>
                            <tr>
                                <td colspan="3" style="padding:20px;">
                                    <?php echo sprintf(__("To get started, please configure your %sMijireh Checkout Settings%s.", "gravityformsmijirehcheckout"), '<a href="admin.php?page=gf_settings&addon=Mijireh Checkout">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravityformsmijirehcheckout") : __("Inactive", "gravityformsmijirehcheckout");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravityformsmijirehcheckout") : __("Inactive", "gravityformsmijirehcheckout");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_mijireh_checkout&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravityformsmijirehcheckout") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e("Edit", "gravityformsmijirehcheckout")?>" href="admin.php?page=gf_mijireh_checkout&view=edit&id=<?php echo $setting["id"] ?>" ><?php _e("Edit", "gravityformsmijirehcheckout") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Stats", "gravityformsmijirehcheckout")?>" href="admin.php?page=gf_mijireh_checkout&view=stats&id=<?php echo $setting["id"] ?>"><?php _e("Stats", "gravityformsmijirehcheckout") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Entries", "gravityformsmijirehcheckout")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting["form_id"] ?>"><?php _e("Entries", "gravityformsmijirehcheckout") ?></a>
                                            |
                                            </span>
                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravityformsmijirehcheckout") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformsmijirehcheckout") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformsmijirehcheckout") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravityformsmijirehcheckout")?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                        <?php
                                            switch($setting["meta"]["type"]){
                                                case "product" :
                                                    _e("Product and Services", "gravityformsmijirehcheckout");
                                                break;

                                                case "donation" :
                                                    _e("Donation", "gravityformsmijirehcheckout");
                                                break;

                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("You don't have any Mijireh Checkout feeds configured. Let's go %screate one%s!", "gravityformsmijirehcheckout"), '<a href="admin.php?page=gf_mijireh_checkout&view=edit&id=0">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformsmijirehcheckout") ?>').attr('alt', '<?php _e("Inactive", "gravityformsmijirehcheckout") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformsmijirehcheckout") ?>').attr('alt', '<?php _e("Active", "gravityformsmijirehcheckout") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_mijireh_checkout_update_feed_active" );
                mysack.setVar( "gf_mijireh_checkout_update_feed_active", "<?php echo wp_create_nonce("gf_mijireh_checkout_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravityformsmijirehcheckout" ) ?>' )};
                mysack.runAJAX();

                return true;
            }


        </script>
        <?php
    }

    public static function load_notifications(){
        $form_id = $_POST["form_id"];
        $form = RGFormsModel::get_form_meta($form_id);
        $notifications = array();
        if(is_array(rgar($form, "notifications"))){
            foreach($form["notifications"] as $notification){
                $notifications[] = array("name" => $notification["name"], "id" => $notification["id"]);
            }
        }
        die(json_encode($notifications));
    }

    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_mijireh_checkout_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Mijireh Checkout Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformsmijirehcheckout")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_mijireh_checkout_submit"])){
            check_admin_referer("update", "gf_mijireh_checkout_update");
            $settings = array(
				"access_key" => rgpost("gf_mijireh_checkout_access_key"),
				"gateway_description_enable" => rgpost("gf_mijireh_checkout_gateway_description_enable"),
				"gateway_description" => rgpost("gf_mijireh_checkout_gateway_description")
            );


            update_option("gf_mijireh_checkout_settings", $settings);
        }
        else{
            $settings = get_option("gf_mijireh_checkout_settings");
        }
        
        if(!empty($settings)){
        	update_option("gf_mijireh_checkout_configured", TRUE);
		}

        ?>
        <style>
            .valid_credentials{color:green;}
            .invalid_credentials{color:red;}
            .size-1{width:400px;}
        </style>

        <form method="post" action="">
            <?php wp_nonce_field("update", "gf_mijireh_checkout_update") ?>

            <h3><?php _e("Mijireh Checkout Information", "gravityformsmijirehcheckout") ?></h3>
            <p style="text-align: left;">
                <?php _e(sprintf("Mijireh Checkout allows you to use your merchant account from around 90+ Payment Gateways on their PCI compliant servers to accept payments securely. If you don't have a Mijireh Checkout account, you can %ssign up for one here%s.", "<a href='http://www.mijireh.com' target='_blank'>" , "</a>"), "gravityformsmijirehcheckout") ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_mijireh_checkout_access_key"><?php _e("Access Key", "gravityformsmijirehcheckout"); ?></label> </th>
                    <td width="88%">
                        <input class="size-1" id="gf_mijireh_checkout_access_key" name="gf_mijireh_checkout_access_key" value="<?php echo esc_attr(rgar($settings,"access_key")) ?>" />
                    </td>
                </tr>
				<tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_mijireh_checkout_gateway_description"><?php _e("Custom Gateway Description", "gravityformsmijirehcheckout"); ?></label> </th>
                    <td width="88%">
                        <p><input type="checkbox" class="size-1" id="gf_mijireh_checkout_gateway_description_enable" name="gf_mijireh_checkout_gateway_description_enable" <?php if(rgar($settings,"gateway_description_enable")) { ?>checked<?php } ?> />
						<label for="gf_mijireh_checkout_gateway_description_enable"><?php _e("Enable customized {{woo_commerce_order_id}} token for Gateway Description", "gravityformsmijirehcheckout"); ?></label></p>
						<p>Mijireh Checkout allows you to customize the order information that gets sent to your gateway in the description field. One of only two tokens available in Mijireh Checkout for this is {{woo_commerce_order_id}}. Enabling the setting above allows you to load that token with whatever information you enter in the field below. You may then use this token in Mijireh Checkout when editing your Store's "Gateway Description Configuration".</p>
                    </td>
				</tr>
				<tr>
                    <th scope="row" nowrap="nowrap">&nbsp;</th>
                    <td width="88%">
                        <input class="size-1" id="gf_mijireh_checkout_gateway_description" name="gf_mijireh_checkout_gateway_description" value="<?php echo esc_attr(rgar($settings,"gateway_description")) ?>" />
						<p>Possible dynamic values include: <strong>[site_name]</strong>. <strong>[site_url]</strong>, <strong>[form_name]</strong>, and <strong>[form_id]</strong>.</p>
                    </td>
				</tr>					
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_mijireh_checkout_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformsmijirehcheckout") ?>" /></td>
                </tr>

            </table>

        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_mijireh_checkout_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_mijireh_checkout_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall Mijireh Checkout Add-On", "gravityformsmijirehcheckout") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Mijireh Checkout Feeds.", "gravityformsmijirehcheckout") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Mijireh Checkout Add-On", "gravityformsmijirehcheckout") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Mijireh Checkout Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformsmijirehcheckout") . '\');"/>';
                    echo apply_filters("gform_mijireh_checkout_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    private static function get_product_field_options($productFields, $selectedValue){
        $options = "<option value=''>" . __("Select a product", "gravityformsmijirehcheckout") . "</option>";
        foreach($productFields as $field){
            $label = GFCommon::truncate_middle($field["label"], 30);
            $selected = $selectedValue == $field["id"] ? "selected='selected'" : "";
            $options .= "<option value='{$field["id"]}' {$selected}>{$label}</option>";
        }

        return $options;
    }

    private static function stats_page(){
        ?>
        <style>
          .mijireh_checkout_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
        .mijireh_checkout_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
        .mijireh_checkout_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
        .mijireh_checkout_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
        .mijireh_checkout_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
        .mijireh_checkout_summary_title {}
        #mijireh_checkout_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
        #mijireh_checkout_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}

        .mijireh_checkout_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
        .mijireh_checkout_tooltip_sales {line-height:130%;}
        .mijireh_checkout_tooltip_revenue {line-height:130%;}
            .mijireh_checkout_tooltip_revenue .mijireh_checkout_tooltip_heading {}
            .mijireh_checkout_tooltip_revenue .mijireh_checkout_tooltip_value {}
            .mijireh_checkout_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
        </style>
        <script type="text/javascript" src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/js/flot/jquery.flot.min.js"></script>
        <script type="text/javascript" src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/js/currency.js"></script>

        <div class="wrap">
            <img alt="<?php _e("Mijireh Checkout", "gravityformsmijirehcheckout") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/images/mijireh_checkout_wordpress_icon_32.png"/>
            <h2><?php _e("Mijireh Checkout Stats", "gravityformsmijirehcheckout") ?></h2>

            <form method="post" action="">
                <ul class="subsubsub">
                    <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_mijireh_checkout&view=stats&id=<?php echo $_GET["id"] ?>"><?php _e("Daily", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_mijireh_checkout&view=stats&id=<?php echo $_GET["id"] ?>&tab=weekly"><?php _e("Weekly", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_mijireh_checkout&view=stats&id=<?php echo $_GET["id"] ?>&tab=monthly"><?php _e("Monthly", "gravityforms"); ?></a></li>
                </ul>
                <?php
                $config = GFMijirehCheckoutData::get_feed(RGForms::get("id"));

                switch(RGForms::get("tab")){
                    case "monthly" :
                        $chart_info = self::monthly_chart_info($config);
                    break;

                    case "weekly" :
                        $chart_info = self::weekly_chart_info($config);
                    break;

                    default :
                        $chart_info = self::daily_chart_info($config);
                    break;
                }

                if(!$chart_info["series"]){
                    ?>
                    <div class="mijireh_checkout_message_container"><?php _e("No payments have been made yet.", "gravityformsmijirehcheckout") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"]) ? " **" : ""?></div>
                    <?php
                }
                else{
                    ?>
                    <div class="mijireh_checkout_graph_container">
                        <div id="graph_placeholder" style="width:100%;height:300px;"></div>
                    </div>

                    <script type="text/javascript">
                        var mijireh_checkout_graph_tooltips = <?php echo $chart_info["tooltips"] ?>;

                        jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        jQuery(window).resize(function(){
                            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        });

                        var previousPoint = null;
                        jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        function startShowTooltip(item){
                            if (item) {
                                if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                                    previousPoint = item.datapoint;

                                    jQuery("#mijireh_checkout_graph_tooltip").remove();
                                    var x = item.datapoint[0].toFixed(2),
                                        y = item.datapoint[1].toFixed(2);

                                    showTooltip(item.pageX, item.pageY, mijireh_checkout_graph_tooltips[item.dataIndex]);
                                }
                            }
                            else {
                                jQuery("#mijireh_checkout_graph_tooltip").remove();
                                previousPoint = null;
                            }
                        }

                        function showTooltip(x, y, contents) {
                            jQuery('<div id="mijireh_checkout_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                                position: 'absolute',
                                display: 'none',
                                opacity: 0.90,
                                width:'150px',
                                height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                                top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                                left: x - 79
                            }).appendTo("body").fadeIn(200);
                        }


                        function convertToMoney(number){
                            var currency = getCurrentCurrency();
                            return currency.toMoney(number);
                        }
                        function formatWeeks(number){
                            number = number + "";
                            return "<?php _e("Week ", "gravityformsmijirehcheckout") ?>" + number.substring(number.length-2);
                        }

                        function getCurrentCurrency(){
                            <?php
                            if(!class_exists("RGCurrency"))
                                require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");

                            $current_currency = RGCurrency::get_currency(GFCommon::get_currency());
                            ?>
                            var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                            return currency;
                        }
                    </script>
                <?php
                }
                $payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
                $transaction_totals = GFMijirehCheckoutData::get_transaction_totals($config["form_id"]);

                switch($config["meta"]["type"]){
                    case "product" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = __("Total Orders", "gravityformsmijirehcheckout");
                    break;

                    case "donation" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = __("Total Donations", "gravityformsmijirehcheckout");
                    break;
                }

                $total_revenue = empty($transaction_totals["payment"]["revenue"]) ? 0 : $transaction_totals["payment"]["revenue"];
                ?>
                <div class="mijireh_checkout_summary_container">
                    <div class="mijireh_checkout_summary_item">
                        <div class="mijireh_checkout_summary_title"><?php _e("Total Revenue", "gravityformsmijirehcheckout")?></div>
                        <div class="mijireh_checkout_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
                    </div>
                    <div class="mijireh_checkout_summary_item">
                        <div class="mijireh_checkout_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                        <div class="mijireh_checkout_summary_value"><?php echo $chart_info["revenue"] ?></div>
                    </div>
                    <div class="mijireh_checkout_summary_item">
                        <div class="mijireh_checkout_summary_title"><?php echo $sales_label?></div>
                        <div class="mijireh_checkout_summary_value"><?php echo $total_sales ?></div>
                    </div>
                    <div class="mijireh_checkout_summary_item">
                        <div class="mijireh_checkout_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                        <div class="mijireh_checkout_summary_value"><?php echo $chart_info["sales"] ?></div>
                    </div>
                </div>
                <?php
                if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"])){
                    ?>
                    <div class="mijireh_checkout_trial_disclaimer"><?php _e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "gravityformsmijirehcheckout") ?></div>
                    <?php
                }
                ?>
            </form>
        </div>
        <?php
    }
    private function get_graph_timestamp($local_datetime){
        $local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
        $local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
        $timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
        return $timestamp;
    }

    private static function matches_current_date($format, $js_timestamp){
        $target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

        $current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
        return $target_date == $current_date;
    }

    private static function daily_chart_info($config){
        global $wpdb;

        $tz_offset = self::get_mysql_tz_offset();

        $results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_mijireh_checkout_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

        $sales_today = 0;
        $revenue_today = 0;
        $tooltips = "";

        if(!empty($results)){

            $data = "[";

            foreach($results as $result){
                $timestamp = self::get_graph_timestamp($result->date);
                if(self::matches_current_date("Y-m-d", $timestamp)){
                    $sales_today += $result->new_sales;
                    $revenue_today += $result->amount_sold;
                }
                $data .="[{$timestamp},{$result->amount_sold}],";

                $sales_line = "<div class='mijireh_checkout_tooltip_sales'><span class='mijireh_checkout_tooltip_heading'>" . __("Orders", "gravityformsmijirehcheckout") . ": </span><span class='mijireh_checkout_tooltip_value'>" . $result->new_sales . "</span></div>";
                
                $tooltips .= "\"<div class='mijireh_checkout_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='mijireh_checkout_tooltip_revenue'><span class='mijireh_checkout_tooltip_heading'>" . __("Revenue", "gravityformsmijirehcheckout") . ": </span><span class='mijireh_checkout_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
            }
            $data = substr($data, 0, strlen($data)-1);
            $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
            $data .="]";

            $series = "[{data:" . $data . "}]";
            $month_names = self::get_chart_month_names();
            $options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
        }
        switch($config["meta"]["type"]){
            case "product" :
                $sales_label = __("Orders Today", "gravityformsmijirehcheckout");
            break;

            case "donation" :
                $sales_label = __("Donations Today", "gravityformsmijirehcheckout");
            break;

            case "subscription" :
                $sales_label = __("Subscriptions Today", "gravityformsmijirehcheckout");
            break;
        }
        $revenue_today = GFCommon::to_money($revenue_today);
        return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue Today", "gravityformsmijirehcheckout"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
    }

    private static function weekly_chart_info($config){
            global $wpdb;

            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_mijireh_checkout_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
            $sales_week = 0;
            $revenue_week = 0;
            $tooltips = "";
            if(!empty($results))
            {
                $data = "[";

                foreach($results as $result){
                    if(self::matches_current_date("YW", $result->week_number)){
                        $sales_week += $result->new_sales;
                        $revenue_week += $result->amount_sold;
                    }
                    $data .="[{$result->week_number},{$result->amount_sold}],";

					$sales_line = "<div class='mijireh_checkout_tooltip_sales'><span class='mijireh_checkout_tooltip_heading'>" . __("Orders", "gravityformsmijirehcheckout") . ": </span><span class='mijireh_checkout_tooltip_value'>" . $result->new_sales . "</span></div>";
                    
                    $tooltips .= "\"<div class='mijireh_checkout_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . __("Week",  "gravityformsmijirehcheckout") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='mijireh_checkout_tooltip_revenue'><span class='mijireh_checkout_tooltip_heading'>" . __("Revenue", "gravityformsmijirehcheckout") . ": </span><span class='mijireh_checkout_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }

            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Week", "gravityformsmijirehcheckout");
                break;

                case "donation" :
                    $sales_label = __("Donations this Week", "gravityformsmijirehcheckout");
                break;
				
            }
            $revenue_week = GFCommon::to_money($revenue_week);

            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Week", "gravityformsmijirehcheckout"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
    }

    private static function monthly_chart_info($config){
            global $wpdb;
            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_mijireh_checkout_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

            $sales_month = 0;
            $revenue_month = 0;
            $tooltips = "";
            if(!empty($results)){

                $data = "[";

                foreach($results as $result){
                    $timestamp = self::get_graph_timestamp($result->date);
                    if(self::matches_current_date("Y-m", $timestamp)){
                        $sales_month += $result->new_sales;
                        $revenue_month += $result->amount_sold;
                    }
                    $data .="[{$timestamp},{$result->amount_sold}],";

					$sales_line = "<div class='mijireh_checkout_tooltip_sales'><span class='mijireh_checkout_tooltip_heading'>" . __("Orders", "gravityformsmijirehcheckout") . ": </span><span class='mijireh_checkout_tooltip_value'>" . $result->new_sales . "</span></div>";
                    
                    $tooltips .= "\"<div class='mijireh_checkout_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='mijireh_checkout_tooltip_revenue'><span class='mijireh_checkout_tooltip_heading'>" . __("Revenue", "gravityformsmijirehcheckout") . ": </span><span class='mijireh_checkout_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }
            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Month", "gravityformsmijirehcheckout");
                break;

                case "donation" :
                    $sales_label = __("Donations this Month", "gravityformsmijirehcheckout");
                break;
            }
            $revenue_month = GFCommon::to_money($revenue_month);
            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Month", "gravityformsmijirehcheckout"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
    }

    private static function get_mysql_tz_offset(){
        $tz_offset = get_option("gmt_offset");

        //add + if offset starts with a number
        if(is_numeric(substr($tz_offset, 0, 1)))
            $tz_offset = "+" . $tz_offset;

        return $tz_offset . ":00";
    }

    private static function get_chart_month_names(){
        return "['" . __("Jan", "gravityformsmijirehcheckout") ."','" . __("Feb", "gravityformsmijirehcheckout") ."','" . __("Mar", "gravityformsmijirehcheckout") ."','" . __("Apr", "gravityformsmijirehcheckout") ."','" . __("May", "gravityformsmijirehcheckout") ."','" . __("Jun", "gravityformsmijirehcheckout") ."','" . __("Jul", "gravityformsmijirehcheckout") ."','" . __("Aug", "gravityformsmijirehcheckout") ."','" . __("Sep", "gravityformsmijirehcheckout") ."','" . __("Oct", "gravityformsmijirehcheckout") ."','" . __("Nov", "gravityformsmijirehcheckout") ."','" . __("Dec", "gravityformsmijirehcheckout") ."']";
    }

    // Edit Page
    private static function edit_page(){
        ?>
        <style>
            #mijireh_checkout_submit_container{clear:both;}
            .mijireh_checkout_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
            .mijireh_checkout_field_cell {padding: 6px 17px 0 0; margin-right:15px;}

            .mijireh_checkout_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
            .mijireh_checkout_validation_error span {color: red;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .gf_mijireh_checkout_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
        </style>
        <script type="text/javascript">
            var form = Array();
            function ToggleNotifications(){

                var container = jQuery("#gf_mijireh_checkout_notification_container");
                var isChecked = jQuery("#gf_mijireh_checkout_delay_notifications").is(":checked");

                if(isChecked){
                    container.slideDown();
                    var isLoaded = jQuery(".gf_mijireh_checkout_notification").length > 0
                    if(!isLoaded){
                        container.html("<li><img src='<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/images/loading.gif' title='<?php _e("Please wait...", "gravityformsmijirehcheckout"); ?>'></li>");
                        jQuery.post(ajaxurl, {
                            action: "gf_mijireh_checkout_load_notifications",
                            form_id: form["id"],
                            },
                            function(response){

                                var notifications = jQuery.parseJSON(response);
                                if(!notifications){
                                    container.html("<li><div class='error' padding='20px;'><?php _e("Notifications could not be loaded. Please try again later or contact support", "gravityformsmijirehcheckout") ?></div></li>");
                                }
                                else if(notifications.length == 0){
                                    container.html("<li><div class='error' padding='20px;'><?php _e("The form selected does not have any notifications.", "gravityformsmijirehcheckout") ?></div></li>");
                                }
                                else{
                                    var str = "";
                                    for(var i=0; i<notifications.length; i++){
                                        str += "<li class='gf_mijireh_checkout_notification'>"
                                            +       "<input type='checkbox' value='" + notifications[i]["id"] + "' name='gf_mijireh_checkout_selected_notifications[]' id='gf_mijireh_checkout_selected_notifications' checked='checked' /> "
                                            +       "<label class='inline' for='gf_mijireh_checkout_selected_notifications'>" + notifications[i]["name"] + "</label>";
                                            +  "</li>";
                                    }
                                    container.html(str);
                                }
                            }
                        );
                    }
                    jQuery(".gf_mijireh_checkout_notification input").prop("checked", true);
                }
                else{
                    container.slideUp();
                    jQuery(".gf_mijireh_checkout_notification input").prop("checked", false);
                }
            }
        </script>
        <div class="wrap">
            <img alt="<?php _e("Mijireh Checkout", "gravityformsmijirehcheckout") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/images/mijireh_checkout_wordpress_icon_32.png"/>
            <h2><?php _e("Mijireh Checkout Transaction Settings", "gravityformsmijirehcheckout") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["mijireh_checkout_setting_id"]) ? $_POST["mijireh_checkout_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFMijirehCheckoutData::get_feed($id);
        $is_validation_error = false;
        
        $config["form_id"] = rgpost("gf_mijireh_checkout_submit") ? absint(rgpost("gf_mijireh_checkout_form")) : $config["form_id"];

        $form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();

        //updating meta information
        if(rgpost("gf_mijireh_checkout_submit")){
        	
            $config["meta"]["type"] = rgpost("gf_mijireh_checkout_type");
            $config["meta"]["cancel_url"] = rgpost("gf_mijireh_checkout_cancel_url");
            $config["meta"]["delay_post"] = rgpost('gf_mijireh_checkout_delay_post');
            $config["meta"]["update_post_action"] = rgpost('gf_mijireh_checkout_update_action');

            if(isset($form["notifications"])){
                //new notification settings
                $config["meta"]["delay_notifications"] = rgpost('gf_mijireh_checkout_delay_notifications');
                $config["meta"]["selected_notifications"] = $config["meta"]["delay_notifications"] ? rgpost('gf_mijireh_checkout_selected_notifications') : array();

                if(isset($config["meta"]["delay_autoresponder"]))
                    unset($config["meta"]["delay_autoresponder"]);
                if(isset($config["meta"]["delay_notification"]))
                    unset($config["meta"]["delay_notification"]);
            }

            // mijirehcheckout conditional
            $config["meta"]["mijireh_checkout_conditional_enabled"] = rgpost('gf_mijireh_checkout_conditional_enabled');
            $config["meta"]["mijireh_checkout_conditional_field_id"] = rgpost('gf_mijireh_checkout_conditional_field_id');
            $config["meta"]["mijireh_checkout_conditional_operator"] = rgpost('gf_mijireh_checkout_conditional_operator');
            $config["meta"]["mijireh_checkout_conditional_value"] = rgpost('gf_mijireh_checkout_conditional_value');

            //-----------------

            $customer_fields = self::get_customer_fields();
            $config["meta"]["customer_fields"] = array();
            foreach($customer_fields as $field){
                $config["meta"]["customer_fields"][$field["name"]] = $_POST["mijireh_checkout_customer_field_{$field["name"]}"];
            }

            $config = apply_filters('gform_mijireh_checkout_save_config', $config);

            $is_validation_error = apply_filters("gform_mijireh_checkout_config_validation", false, $config);

            if(!$is_validation_error){
                $id = GFMijirehCheckoutData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravityformsmijirehcheckout"), "<a href='?page=gf_mijireh_checkout'>", "</a>") ?></div>
                <?php
            }
            else{
                $is_validation_error = true;
            }

        }

        ?>
        <form method="post" action="">
            <input type="hidden" name="mijireh_checkout_setting_id" value="<?php echo $id ?>" />

            <div class="margin_vertical_10 <?php echo $is_validation_error ? "mijireh_checkout_validation_error" : "" ?>">
                <?php
                if($is_validation_error){
                    ?>
                    <span><?php _e('There was an issue saving your feed. Please address the errors below and try again.'); ?></span>
                    <?php
                }
                ?>
            </div> <!-- / validation message -->

            <div class="margin_vertical_10">
                <label class="left_header" for="gf_mijireh_checkout_type"><?php _e("Transaction Type", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_transaction_type") ?></label>

                <select id="gf_mijireh_checkout_type" name="gf_mijireh_checkout_type" onchange="SelectType(jQuery(this).val());">
                    <option value=""><?php _e("Select a transaction type", "gravityformsmijirehcheckout") ?></option>
                    <option value="product" <?php echo rgar($config['meta'], 'type') == "product" ? "selected='selected'" : "" ?>><?php _e("Products and Services", "gravityformsmijirehcheckout") ?></option>
                    <option value="donation" <?php echo rgar($config['meta'], 'type') == "donation" ? "selected='selected'" : "" ?>><?php _e("Donations", "gravityformsmijirehcheckout") ?></option>
                </select>
            </div>
            <div id="mijireh_checkout_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["type"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_mijireh_checkout_form" class="left_header"><?php _e("Gravity Form", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_gravity_form") ?></label>

                <select id="gf_mijireh_checkout_form" name="gf_mijireh_checkout_form" onchange="SelectForm(jQuery('#gf_mijireh_checkout_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">
                    <option value=""><?php _e("Select a form", "gravityformsmijirehcheckout"); ?> </option>
                    <?php

                    $active_form = rgar($config, 'form_id');
                    $available_forms = GFMijirehCheckoutData::get_available_forms($active_form);

                    foreach($available_forms as $current_form) {
                        $selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
                        ?>

                            <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>

                        <?php
                    }
                    ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GF_MIJIREHCHECKOUT_BASE_URL ?>/assets/images/loading.gif" id="mijireh_checkout_wait" style="display: none;"/>

                <div id="gf_mijireh_checkout_invalid_product_form" class="gf_mijireh_checkout_invalid_form"  style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformsmijirehcheckout") ?>
                </div>
                <div id="gf_mijireh_checkout_invalid_donation_form" class="gf_mijireh_checkout_invalid_form" style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformsmijirehcheckout") ?>
                </div>
            </div>
            <div id="mijireh_checkout_field_group" valign="top" <?php echo empty($config["meta"]["type"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Customer", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_customer") ?></label>

                    <div id="mijireh_checkout_customer_fields">
                        <?php
                            if(!empty($form))
                                echo self::get_customer_information($form, $config);
                        ?>
                    </div>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_mijireh_checkout_cancel_url"><?php _e("Cancel URL", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_cancel_url") ?></label>
                    <input type="text" name="gf_mijireh_checkout_cancel_url" id="gf_mijireh_checkout_cancel_url" class="width-1" value="<?php echo rgars($config, "meta/cancel_url") ?>"/>
                </div>

                <div class="margin_vertical_10">
                    <ul style="overflow:hidden;">

                        <li id="mijireh_checkout_delay_notification" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                            <input type="checkbox" name="gf_mijireh_checkout_delay_notification" id="gf_mijireh_checkout_delay_notification" value="1" <?php echo rgar($config["meta"], 'delay_notification') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_mijireh_checkout_delay_notification"><?php _e("Send admin notification only when payment is received.", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_delay_admin_notification") ?></label>
                        </li>
                        <li id="mijireh_checkout_delay_autoresponder" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                            <input type="checkbox" name="gf_mijireh_checkout_delay_autoresponder" id="gf_mijireh_checkout_delay_autoresponder" value="1" <?php echo rgar($config["meta"], 'delay_autoresponder') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_mijireh_checkout_delay_autoresponder"><?php _e("Send user notification only when payment is received.", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_delay_user_notification") ?></label>
                        </li>

                        <?php
                        $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
                        ?>
                        <li id="mijireh_checkout_post_action" <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_mijireh_checkout_delay_post" id="gf_mijireh_checkout_delay_post" value="1" <?php echo rgar($config["meta"],"delay_post") ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_mijireh_checkout_delay_post"><?php _e("Create post only when payment is received.", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_delay_post") ?></label>
                        </li>

                        <li id="mijireh_checkout_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_mijireh_checkout_update_post" id="gf_mijireh_checkout_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_mijireh_checkout_update_action').val(action);" />
                            <label class="inline" for="gf_mijireh_checkout_update_post"><?php _e("Update Post when subscription is cancelled.", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_update_post") ?></label>
                            <select id="gf_mijireh_checkout_update_action" name="gf_mijireh_checkout_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_mijireh_checkout_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php _e("Mark Post as Draft", "gravityformsmijirehcheckout") ?></option>
                                <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php _e("Delete Post", "gravityformsmijirehcheckout") ?></option>
                            </select>
                        </li>

                        <?php do_action("gform_mijireh_checkout_action_fields", $config, $form) ?>
                    </ul>
                </div>

                <div class="margin_vertical_10" id="gf_mijireh_checkout_notifications" <?php echo !isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                    <label class="left_header"><?php _e("Notifications", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_notifications") ?></label>
                    <?php
                    $has_delayed_notifications = rgar($config['meta'], 'delay_notifications') || rgar($config['meta'], 'delay_notification') || rgar($config['meta'], 'delay_autoresponder');
                    ?>
                    <div style="overflow:hidden;">
                        <input type="checkbox" name="gf_mijireh_checkout_delay_notifications" id="gf_mijireh_checkout_delay_notifications" value="1" onclick="ToggleNotifications();" <?php checked("1", $has_delayed_notifications)?> />
                        <label class="inline" for="gf_mijireh_checkout_delay_notifications"><?php _e("Send notifications only when payment is received.", "gravityformsmijirehcheckout"); ?></label>

                        <ul id="gf_mijireh_checkout_notification_container" style="padding-left:20px; <?php echo $has_delayed_notifications ? "" : "display:none;"?>">
                        <?php
                        if(!empty($form) && is_array($form["notifications"])){
                            $selected_notifications = self::get_selected_notifications($config, $form);

                            foreach($form["notifications"] as $notification){
                                ?>
                                <li class="gf_mijireh_checkout_notification">
                                    <input type="checkbox" name="gf_mijireh_checkout_selected_notifications[]" id="gf_mijireh_checkout_selected_notifications" value="<?php echo $notification["id"]?>" <?php checked(true, in_array($notification["id"], $selected_notifications))?> />
                                    <label class="inline" for="gf_mijireh_checkout_selected_notifications"><?php echo $notification["name"]; ?></label>
                                </li>
                                <?php
                            }
                        }
                        ?>
                        </ul>
                    </div>
                </div>

                <?php do_action("gform_mijireh_checkout_add_option_group", $config, $form); ?>

                <div id="gf_mijireh_checkout_conditional_section" valign="top" class="margin_vertical_10">
                    <label for="gf_mijireh_checkout_conditional_optin" class="left_header"><?php _e("Mijireh Checkout Condition", "gravityformsmijirehcheckout"); ?> <?php gform_tooltip("mijireh_checkout_conditional") ?></label>

                    <div id="gf_mijireh_checkout_conditional_option">
                        <table cellspacing="0" cellpadding="0">
                            <tr>
                                <td>
                                    <input type="checkbox" id="gf_mijireh_checkout_conditional_enabled" name="gf_mijireh_checkout_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_mijireh_checkout_conditional_container').fadeIn('fast');} else{ jQuery('#gf_mijireh_checkout_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_enabled') ? "checked='checked'" : ""?>/>
                                    <label for="gf_mijireh_checkout_conditional_enable"><?php _e("Enable", "gravityformsmijirehcheckout"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="gf_mijireh_checkout_conditional_container" <?php echo !rgar($config['meta'], 'mijireh_checkout_conditional_enabled') ? "style='display:none'" : ""?>>

                                        <div id="gf_mijireh_checkout_conditional_fields" style="display:none">
                                            <?php _e("Send to Mijireh Checkout if ", "gravityformsmijirehcheckout") ?>
                                            <select id="gf_mijireh_checkout_conditional_field_id" name="gf_mijireh_checkout_conditional_field_id" class="optin_select" onchange='jQuery("#gf_mijireh_checkout_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'>
                                            </select>
                                            <select id="gf_mijireh_checkout_conditional_operator" name="gf_mijireh_checkout_conditional_operator">
                                                <option value="is" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformsmijirehcheckout") ?></option>
                                                <option value="isnot" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformsmijirehcheckout") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformsmijirehcheckout") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformsmijirehcheckout") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformsmijirehcheckout") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformsmijirehcheckout") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'mijireh_checkout_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformsmijirehcheckout") ?></option>
                                            </select>
                                            <div id="gf_mijireh_checkout_conditional_value_container" name="gf_mijireh_checkout_conditional_value_container" style="display:inline;"></div>
                                        </div>

                                        <div id="gf_mijireh_checkout_conditional_message" style="display:none">
                                            <?php _e("To create a registration condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- / mijirehcheckout conditional -->

                <div id="mijireh_checkout_submit_container" class="margin_vertical_30">
                    <input type="submit" name="gf_mijireh_checkout_submit" value="<?php echo empty($id) ? __("  Save  ", "gravityformsmijirehcheckout") : __("Update", "gravityformsmijirehcheckout"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravityformsmijirehcheckout"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_mijireh_checkout'" />
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function(){
                SetPeriodNumber('#gf_mijireh_checkout_billing_cycle_number', jQuery("#gf_mijireh_checkout_billing_cycle_type").val());
                SetPeriodNumber('#gf_mijireh_checkout_trial_period_number', jQuery("#gf_mijireh_checkout_trial_period_type").val());
            });

            function SelectType(type){
                jQuery("#mijireh_checkout_field_group").slideUp();

                jQuery("#mijireh_checkout_field_group input[type=\"text\"], #mijireh_checkout_field_group select").val("");
                jQuery("#gf_mijireh_checkout_trial_period_type, #gf_mijireh_checkout_billing_cycle_type").val("M");

                jQuery("#mijireh_checkout_field_group input:checked").attr("checked", false);

                if(type){
                    jQuery("#mijireh_checkout_form_container").slideDown();
                    jQuery("#gf_mijireh_checkout_form").val("");
                }
                else{
                    jQuery("#mijireh_checkout_form_container").slideUp();
                }
            }

            function SelectForm(type, formId, settingId){
                if(!formId){
                    jQuery("#mijireh_checkout_field_group").slideUp();
                    return;
                }

                jQuery("#mijireh_checkout_wait").show();
                jQuery("#mijireh_checkout_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_mijireh_checkout_form" );
                mysack.setVar( "gf_select_mijireh_checkout_form", "<?php echo wp_create_nonce("gf_select_mijireh_checkout_form") ?>" );
                mysack.setVar( "type", type);
                mysack.setVar( "form_id", formId);
                mysack.setVar( "setting_id", settingId);
                mysack.onError = function() {jQuery("#mijireh_checkout_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravityformsmijirehcheckout") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function EndSelectForm(form_meta, customer_fields, recurring_amount_options){

                //setting global form object
                form = form_meta;

                var type = jQuery("#gf_mijireh_checkout_type").val();

                jQuery(".gf_mijireh_checkout_invalid_form").hide();
                if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
                    jQuery("#gf_mijireh_checkout_invalid_product_form").show();
                    jQuery("#mijireh_checkout_wait").hide();
                    return;
                }
                else if(type == "donation" && GetFieldsByType(["product", "donation"]).length == 0){
                    jQuery("#gf_mijireh_checkout_invalid_donation_form").show();
                    jQuery("#mijireh_checkout_wait").hide();
                    return;
                }

                jQuery(".mijireh_checkout_field_container").hide();
                jQuery("#mijireh_checkout_customer_fields").html(customer_fields);
                jQuery("#gf_mijireh_checkout_recurring_amount").html(recurring_amount_options);

                //displaying delayed post creation setting if current form has a post field
                var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
                if(post_fields.length > 0){
                    jQuery("#mijireh_checkout_post_action").show();
                }
                else{
                    jQuery("#gf_mijireh_checkout_delay_post").attr("checked", false);
                    jQuery("#mijireh_checkout_post_action").hide();
                }

                if(type == "subscription" && post_fields.length > 0){
                    jQuery("#mijireh_checkout_post_update_action").show();
                }
                else{
                    jQuery("#gf_mijireh_checkout_update_post").attr("checked", false);
                    jQuery("#mijireh_checkout_post_update_action").hide();
                }

                SetPeriodNumber('#gf_mijireh_checkout_billing_cycle_number', jQuery("#gf_mijireh_checkout_billing_cycle_type").val());
                SetPeriodNumber('#gf_mijireh_checkout_trial_period_number', jQuery("#gf_mijireh_checkout_trial_period_type").val());

                //Calling callback functions
                jQuery(document).trigger('mijirehcheckoutFormSelected', [form]);

                jQuery("#gf_mijireh_checkout_conditional_enabled").attr('checked', false);
                SetMijirehCheckoutCondition("","");

                if(form["notifications"]){
                    jQuery("#gf_mijireh_checkout_notifications").show();
                    jQuery("#mijireh_checkout_delay_autoresponder, #mijireh_checkout_delay_notification").hide();
                }
                else{
                    jQuery("#mijireh_checkout_delay_autoresponder, #mijireh_checkout_delay_notification").show();
                    jQuery("#gf_mijireh_checkout_notifications").hide();
                }

                jQuery("#mijireh_checkout_field_container_" + type).show();
                jQuery("#mijireh_checkout_field_group").slideDown();
                jQuery("#mijireh_checkout_wait").hide();
            }

            function SetPeriodNumber(element, type){
                var prev = jQuery(element).val();

                var min = 1;
                var max = 0;
                switch(type){
                    case "D" :
                        max = 100;
                    break;
                    case "W" :
                        max = 52;
                    break;
                    case "M" :
                        max = 12;
                    break;
                    case "Y" :
                        max = 5;
                    break;
                }
                var str="";
                for(var i=min; i<=max; i++){
                    var selected = prev == i ? "selected='selected'" : "";
                    str += "<option value='" + i + "' " + selected + ">" + i + "</option>";
                }
                jQuery(element).html(str);
            }

            function GetFieldsByType(types){
                var fields = new Array();
                for(var i=0; i<form["fields"].length; i++){
                    if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                        fields.push(form["fields"][i]);
                }
                return fields;
            }

            function IndexOf(ary, item){
                for(var i=0; i<ary.length; i++)
                    if(ary[i] == item)
                        return i;

                return -1;
            }

        </script>

        <script type="text/javascript">

            // Mijireh Checkout Conditional Functions

            <?php
            if(!empty($config["form_id"])){
                ?>

                // initilize form object
                form = <?php echo GFCommon::json_encode($form)?> ;

                // initializing registration condition drop downs
                jQuery(document).ready(function(){
                    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["mijireh_checkout_conditional_field_id"])?>";
                    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["mijireh_checkout_conditional_value"])?>";
                    SetMijirehCheckoutCondition(selectedField, selectedValue);
                });

                <?php
            }
            ?>

            function SetMijirehCheckoutCondition(selectedField, selectedValue){

                // load form fields
                jQuery("#gf_mijireh_checkout_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_mijireh_checkout_conditional_field_id").val();
                var checked = jQuery("#gf_mijireh_checkout_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_mijireh_checkout_conditional_message").hide();
                    jQuery("#gf_mijireh_checkout_conditional_fields").show();
                    jQuery("#gf_mijireh_checkout_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_mijireh_checkout_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_mijireh_checkout_conditional_message").show();
                    jQuery("#gf_mijireh_checkout_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_mijireh_checkout_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
					str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_mijireh_checkout_conditional_value", "name"=> "gf_mijireh_checkout_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
				}
				else if(field.choices){
					str += '<select id="gf_mijireh_checkout_conditional_value" name="gf_mijireh_checkout_conditional_value" class="optin_select">'


	                for(var i=0; i<field.choices.length; i++){
	                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
	                    var isSelected = fieldValue == selectedValue;
	                    var selected = isSelected ? "selected='selected'" : "";
	                    if(isSelected)
	                        isAnySelected = true;

	                    str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
	                }

	                if(!isAnySelected && selectedValue){
	                    str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
	                }
	                str += "</select>";
				}
				else
				{
					selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
					//create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
					str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_mijireh_checkout_conditional_value' name='gf_mijireh_checkout_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
				}

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(!text)
                    return "";

                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
			    inputType = field.inputType ? field.inputType : field.type;
			    var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
			                            "post_tags", "post_custom_field", "post_content", "post_excerpt"];

			    var index = jQuery.inArray(inputType, supported_fields);

			    return index >= 0;
			}

        </script>

        <?php

    }

    public static function select_mijireh_checkout_form(){

        check_ajax_referer("gf_select_mijireh_checkout_form", "gf_select_mijireh_checkout_form");

        $type = $_POST["type"];
        $form_id =  intval($_POST["form_id"]);
        $setting_id =  intval($_POST["setting_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        $customer_fields = self::get_customer_information($form);
        $recurring_amount_fields = self::get_product_options($form, "");

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "');");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_mijireh_checkout");
        $wp_roles->add_cap("administrator", "gravityforms_mijireh_checkout_uninstall");
		
		if ( is_admin() ) {
			self::install_slurp_page();
		}
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_mijireh_checkout", "gravityforms_mijireh_checkout_uninstall"));
    }

    public static function get_active_config($form){

        require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

        $configs = GFMijirehCheckoutData::get_feed_by_form($form["id"], true);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_mijireh_checkout_condition($form, $config))
                return $config;
        }

        return false;
    }

    public static function send_to_mijireh_checkout($confirmation, $form, $entry, $ajax){

        // ignore requests that are not the current form's submissions
        if(RGForms::post("gform_submit") != $form["id"])
        {
            return $confirmation;
		}

        //$config = self::get_active_config($form);
        $config = GFMijirehCheckoutData::get_feed_by_form($form["id"]);

        if(!$config)
        {
            self::log_debug("NOT sending to Mijireh Checkout: No Mijireh Checkout setup was located for form_id = {$form['id']}.");
            return $confirmation;
		}else{
            $config = $config[0]; //using first mijireh feed (only one mijireh feed per form is supported)
		}

        // updating entry meta with current feed id
        gform_update_meta($entry["id"], "mijireh_checkout_feed_id", $config["id"]);

        // updating entry meta with current payment gateway
        gform_update_meta($entry["id"], "payment_gateway", "mijireh");

        //updating lead's payment_status to Processing
        RGFormsModel::update_lead_property($entry["id"], "payment_status", 'Processing');

		self::init_mijireh();
		
		$mj_order = new Mijireh_Order();
		
		$mj_order->partner_id 		= 'patsatech';

        $invoice_id = apply_filters("gform_mijireh_checkout_invoice", "", $form, $entry);

		$red = $entry['id'];
		
        $invoice = empty($invoice_id) ? $red : $invoice_id;
		
		// add meta data to identify gravityforms order
		$mj_order->add_meta_data( 'gf_order_id', $invoice );

        //Customer fields
        self::customer_query_string($config, $entry, $mj_order);
		
        //URL that will listen to notifications from Mijireh Checkout
        $ipn_url = get_bloginfo("url") . "/?page=gf_mijireh_checkout_ipn&custom=".$entry["id"];
		
        $custom_field = $entry["id"] . "|" . wp_hash($entry["id"]);
		
		$mj_order->add_meta_data( 'gf_custom_id', $custom_field );
		
		// Add "wc_order_id" if "Enable customized {{woo_commerce_order_id}} token for Gateway Description" is checked in settings
		$settings = get_option("gf_mijireh_checkout_settings");
		if( !empty($settings["gateway_description_enable"]) ) {
			$description = $settings["gateway_description"];
			
			$tokens = array("[site_name]", "[site_url]", "[form_name]", "[form_id]");
			$values = array(get_bloginfo('name'), get_bloginfo('url'), $form["title"], $form["id"]);
			$description = str_replace($tokens, $values, $description);
			
			$mj_order->add_meta_data( 'wc_order_id', $description );
		}

		$mj_order->return_url = $ipn_url;
		
        switch($config["meta"]["type"]){
            case "product" :
                $total = self::get_product_query_string($form, $entry, $mj_order);
            break;

            case "donation" :
                $total = self::get_donation_query_string($form, $entry, $mj_order);
            break;
        }
		
		$mj_order->total = $total;

        // if the request has a $0 total, go ahead and set_payment_status as if it was paid and then return confirmation		
        if($total == 0) {
            self::log_debug("Not redirecting to Mijireh Checkout: Order total is $0");
            self::set_payment_status($config, $entry, 'paid', 'No Transaction ID', 'No Parent Transaction', '0.00', $mj_order );
            return $confirmation;
		}
		
		try {
			$mj_order->create();
			$url = $mj_order->checkout_url;

       		self::log_debug("Sending to Mijireh Checkout: {$url}");
		
		} catch (Mijireh_Exception $e) {
			
	        self::log_debug( __('Mijireh error:', 'woocommerce' ) . $e->getMessage() );
					
		}
		
        if(headers_sent() || $ajax){
            $confirmation = "<script>function gformRedirect(){document.location.href='$url';}";
            if(!$ajax)
                $confirmation .="gformRedirect();";
            $confirmation .="</script>";
        }
        else{
            $confirmation = array("redirect" => $url);
        }

        return $confirmation;
    }

    public static function has_mijireh_checkout_condition($form, $config) {

        $config = $config["meta"];

        $operator = isset($config["mijireh_checkout_conditional_operator"]) ? $config["mijireh_checkout_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $config["mijireh_checkout_conditional_field_id"]);

        if(empty($field) || !$config["mijireh_checkout_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $config["mijireh_checkout_conditional_value"], $operator);
        $go_to_mijireh_checkout = $is_value_match && $is_visible;

        return  $go_to_mijireh_checkout;
    }

    public static function get_config($form_id){
        if(!class_exists("GFMijirehCheckoutData"))
            require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

        //Getting mijirehcheckout settings associated with this transaction
        $config = GFMijirehCheckoutData::get_feed_by_form($form_id);

        //Ignore IPN messages from forms that are no longer configured with the Mijireh Checkout add-on
        if(!$config)
            return false;

        return $config[0]; //only one feed per form is supported (left for backwards compatibility)
    }

    public static function get_config_by_entry($entry) {

        if(!class_exists("GFMijirehCheckoutData"))
            require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

        $feed_id = gform_get_meta($entry["id"], "mijireh_checkout_feed_id");
        $feed = GFMijirehCheckoutData::get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    public static function maybe_thankyou_page(){

        if(!self::is_gravityforms_supported())
            return;

        if($str = RGForms::get("gf_mijireh_checkout_return"))
        {
            $str = base64_decode($str);

            parse_str($str, $query);
            if(wp_hash("ids=" . $query["ids"]) == $query["hash"]){
                list($form_id, $lead_id) = explode("|", $query["ids"]);

                $form = RGFormsModel::get_form_meta($form_id);
                $lead = RGFormsModel::get_lead($lead_id);

                if(!class_exists("GFFormDisplay"))
                    require_once(GFCommon::get_base_path() . "/form_display.php");

                $confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);

                if(is_array($confirmation) && isset($confirmation["redirect"])){
                    header("Location: {$confirmation["redirect"]}");
                    exit;
                }

                GFFormDisplay::$submission[$form_id] = array("is_confirmation" => true, "confirmation_message" => $confirmation, "form" => $form, "lead" => $lead);
            }
        }
    }

    public static function process_ipn($wp){

        if(!self::is_gravityforms_supported())
           return;

        //Ignore requests that are not IPN
        if(RGForms::get("page") != "gf_mijireh_checkout_ipn")
            return;

		
	    if( isset( $_REQUEST['order_number'] ) ) {
		
			self::init_mijireh();
	
	  		try {
		        self::log_debug("IPN request received. Starting to process...");
		        self::log_debug('Order Number : '.$_REQUEST['order_number']);
				
				$mj_order 	= new Mijireh_Order( esc_attr( $_REQUEST['order_number'] ) );
				
		        //Valid IPN requests must have a custom field
				$custom = $mj_order->get_meta_value( 'gf_custom_id' );
		        if(empty($custom)){
		            self::log_error("IPN request does not have a custom field, so it was not created by Gravity Forms. Aborting.");
		            return;
		        }
		
		        //Getting entry associated with this IPN message (entry id is sent in the "custom" field)
		        list($entry_id, $hash) = explode("|", $custom);
				
		        $hash_matches = wp_hash($entry_id) == $hash;
		        //Validates that Entry Id wasn't tampered with
		        if(!$hash_matches){
		            self::log_error("Entry Id verification failed. Hash does not match. Custom field: {$custom}. Aborting.");
		            return;
		        }
		
		        self::log_debug("IPN message has a valid custom field: {$custom}");
				
		        //$entry_id = RGForms::post("custom");
		        $entry = RGFormsModel::get_lead($entry_id);
		
		        // config ID is stored in entry via send_to_mijireh_checkout() function
		        $config = self::get_config_by_entry($entry);
		
		        //Ignore IPN messages from forms that are no longer configured with the Mijireh Checkout add-on
		        if(!$config){
		            self::log_error("Form no longer is configured with Mijireh Checkout Addon. Form ID: {$entry["form_id"]}. Aborting.");
		            return;
		        }
		
		        //Ignore orphan IPN messages (ones without an entry)
		        if(!$entry){
		            self::log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
		            return;
		        }
		        self::log_debug("Entry has been found." . print_r($entry, true));
				
		        self::log_debug("Form {$entry["form_id"]} is properly configured.");
				
				$payment_status = $mj_order->status;
					
				$parent_transaction_id = $mj_order->order_number;
		
		        //Pre IPN processing filter. Allows users to cancel IPN processing
		        $cancel = apply_filters("gform_mijireh_checkout_pre_ipn", false, $mj_order, $entry, $config);
				
		        if(!$cancel) {
		            self::log_debug("Setting payment status...");
		            self::set_payment_status($config, $entry, $payment_status, $mj_order->order_number, $parent_transaction_id, number_format($mj_order->total,2), $mj_order );
		        }
		        else{
		            self::log_debug("IPN processing cancelled by the gform_mijireh_checkout_pre_ipn filter. Aborting.");
		        }
		
		        self::log_debug("Before gform_mijireh_checkout_post_ipn.");
		        //Post IPN processing action
		        do_action("gform_mijireh_checkout_post_ipn", $_POST, $entry, $config, $cancel);
		
		        self::log_debug("IPN processing complete.");
				
				$uri = explode('?',$_SERVER["REQUEST_URI"]);
				
				$_SERVER["REQUEST_URI"] = $uri[0];
		
				if($payment_status == 'paid'){
		        	$redirect_url = self::return_url($entry["form_id"], $entry['id']);
				}else{
			        //Cancel URL
			        $redirect_url = !empty($config["meta"]["cancel_url"]) ? $config["meta"]["cancel_url"] : "";
				}
				
				wp_redirect($redirect_url);
				exit;
	
	  		} catch (Mijireh_Exception $e) {
	
		        self::log_debug(__( 'Mijireh error:', 'woocommerce' ) . $e->getMessage());
	
	  		}
	    }
	    elseif( isset( $_POST['page_id'] ) ) {
	      if( isset( $_POST['access_key'] ) ) {
	        wp_update_post( array( 'ID' => $_POST['page_id'], 'post_status' => 'private' ) );
	      }
	    }
    }

    public static function set_payment_status($config, $entry, $status, $transaction_id, $parent_transaction_id, $amount, $mj_order){
        global $current_user;
        $user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }
        self::log_debug("Payment status: {$status} - Transaction ID: {$transaction_id} - Parent Transaction: {$parent_transaction_id} - Amount: {$amount}");
        self::log_debug("Entry: " . print_r($entry, true));

		//handles products and donation
        switch(strtolower($status)){
        	case "paid" :
            	self::log_debug("Processing a completed payment");
                if($entry["payment_status"] != "Approved"){

                	if(self::is_valid_initial_payment_amount($config, $entry, $mj_order)){
                    	self::log_debug("Entry is not already approved. Proceeding...");
                        $entry["payment_status"] = "Approved";
                        $entry["payment_amount"] = $amount;
                        $entry["payment_date"] = gmdate("y-m-d H:i:s");
                        $entry["transaction_id"] = $transaction_id;
                        $entry["transaction_type"] = 1; //payment

						if(!$entry["is_fulfilled"]){
                            self::log_debug("Payment has been made. Fulfilling order.");
                            self::fulfill_order($entry, $transaction_id, $amount);
                            self::log_debug("Order has been fulfilled");
                        	$entry["is_fulfilled"] = true;
                        }
						
                        self::log_debug("Updating entry.");
                        RGFormsModel::update_lead($entry);
                        self::log_debug("Adding note.");
                    	RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been approved. Amount: %s. Transaction Id: %s", "gravityforms"), GFCommon::to_money($entry["payment_amount"], $entry["currency"]), $transaction_id));
					}else{
						self::log_debug("Payment amount does not match product price. Entry will not be marked as Approved.");
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));
					}
				}
                self::log_debug("Inserting transaction.");
                GFMijirehCheckoutData::insert_transaction($entry["id"], "payment", $transaction_id, $parent_transaction_id, $amount);
				
			break;

			case "pending" :
            	self::log_debug("Processing a pending transaction.");
                if($entry["payment_status"] != "Pending"){
                	if($entry["transaction_type"] != 2){
                    	$entry["payment_status"] = "Pending";
                        $entry["payment_amount"] = $amount;
                        $entry["transaction_type"] = 1; //payment
                        self::log_debug("Setting entry as Pending.");
                        RGFormsModel::update_lead($entry);
					}
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment is pending. Amount: %s. Transaction Id: %s. Reason: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id, self::get_pending_reason($pending_reason)));
				}

                GFMijirehCheckoutData::insert_transaction($entry["id"], "pending", $transaction_id, $parent_transaction_id, $amount);
			break;

            case "failed" :
            	self::log_debug("Processed a Failed request.");
                if($entry["payment_status"] != "Failed"){
                	if($entry["transaction_type"] == 1){
                    	$entry["payment_status"] = "Failed";
                        self::log_debug("Setting entry as Failed.");
                        RGFormsModel::update_lead($entry);
					}
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has Failed. Failed payments occur when they are made via your customer's bank account and could not be completed. Transaction Id: %s", "gravityforms"), $transaction_id));
			    }

            	GFMijirehCheckoutData::insert_transaction($entry["id"], "failed", $transaction_id, $parent_transaction_id, $amount);
			break;

		}
				
        self::log_debug("Before gform_post_payment_status.");
        do_action("gform_post_payment_status", $config, $entry, $status, $transaction_id, $amount);
		
		return $url;
    }

    public static function fulfill_order(&$entry, $transaction_id, $amount){

        $config = self::get_config_by_entry($entry);
        if(!$config){
            self::log_error("Order can't be fulfilled because feed wasn't found for form: {$entry["form_id"]}");
            return;
        }

        $form = RGFormsModel::get_form_meta($entry["form_id"]);
        if($config["meta"]["delay_post"]){
            self::log_debug("Creating post.");
            RGFormsModel::create_post($form, $entry);
        }

        if(isset($config["meta"]["delay_notifications"])){
            //sending delayed notifications
            GFCommon::send_notifications($config["meta"]["selected_notifications"], $form, $entry, true, "form_submission");

        }
        else{

            //sending notifications using the legacy structure
            if($config["meta"]["delay_notification"]){
               self::log_debug("Sending admin notification.");
               GFCommon::send_admin_notification($form, $entry);
            }

            if($config["meta"]["delay_autoresponder"]){
               self::log_debug("Sending user notification.");
               GFCommon::send_user_notification($form, $entry);
            }
        }

        self::log_debug("Before gform_mijireh_checkout_fulfillment.");
        do_action("gform_mijireh_checkout_fulfillment", $entry, $config, $transaction_id, $amount);
    }

    private static function customer_query_string($config, $lead, $mj_order){
        $fields = "";

		$billing = new Mijireh_Address();

        foreach(self::get_customer_fields() as $field){
            $field_id = $config["meta"]["customer_fields"][$field["name"]];
            $value = rgar($lead,$field_id);

            if($field["name"] == "country"){
                $value = GFCommon::get_country_code($value);
				$billing->country = $value;
			}
            if($field["name"] == "state"){
                $value = GFCommon::get_us_state_code($value);
				$billing->state_province = $value;
			}
				
			if( $field["name"] == "first_name" ){
				$billing->first_name = $value;
			}
				
			if( $field["name"] == "last_name" ){
				$billing->last_name = $value;
			}
				
			if( $field["name"] == "address1" ){
				$billing->street = $value;
			}
				
			if( $field["name"] == "address2" ){
				$billing->apt_suite = $value;
			}
				
			if( $field["name"] == "town" ){
				$billing->city = $value;
			}
				
			if( $field["name"] == "region" ){
				$billing->state_province = $value;
			}
				
			if( $field["name"] == "postcode" ){
				$billing->zip_code = $value;
			}
				
			if( $field["name"] == "email" ){
				$mj_order->email = $value;
			}
				
        }
		
		if ( $billing->validate() )
			$mj_order->set_billing_address( $billing );
				
        return $fields;
    }

    private static function is_valid_initial_payment_amount($config, $lead, $mj_order){

        $form = RGFormsModel::get_form_meta($lead["form_id"]);
        $products = GFCommon::get_product_fields($form, $lead, true);
        $payment_amount = $mj_order->total;

        $product_amount = 0;
        switch($config["meta"]["type"]){
            case "product" :
                $product_amount = GFCommon::get_order_total($form, $lead);
            break;

            case "donation" :
                $product_amount = self::get_donation_query_string($form, $lead, $mj_order);
            break;

        }

        //initial payment is valid if it is equal to or greater than product/subscription amount
        if(floatval($payment_amount) >= floatval($product_amount)){
            return true;
        }

        return false;

    }
	
    private static function get_product_query_string($form, $entry, $mj_order){
        $fields = "";
        $products = GFCommon::get_product_fields($form, $entry, true);
        $product_index = 1;
        $total = 0;
        $discount = 0;

        foreach($products["products"] as $product){
            $option_fields = "";
            $price = GFCommon::to_number($product["price"]);
            if(is_array(rgar($product,"options"))){
                $option_index = 1;
                foreach($product["options"] as $option){
                    $field_label = urlencode($option["field_label"]);
                    $option_name = urlencode($option["option_name"]);
                    $option_fields .= "&on{$option_index}_{$product_index}={$field_label}&os{$option_index}_{$product_index}={$option_name}";
                    $price += GFCommon::to_number($option["price"]);
                    $option_index++;
                }
            }

            $name = $product["name"];
            if($price > 0)
            {
				$mj_order->add_item( $name, $price, $product["quantity"], '' );
                $total += $price * $product['quantity'];
                $product_index++;
            }
            else{
                $discount += abs($price) * $product['quantity'];
            }

        }

        if($discount > 0){
			$mj_order->discount 		= $discount;
			$total						= $total - $discount;
        }

        $shipping = !empty($products["shipping"]["price"]) ? $products["shipping"]["price"] : "0.00";
		$mj_order->shipping 		= $shipping;
		$mj_order->show_tax			= false;

        return $total > 0 ? $total : '0.00';
    }

    private static function get_donation_query_string($form, $entry, $mj_order){
        $fields = "";

        //getting all donation fields
        $donations = GFCommon::get_fields_by_type($form, array("donation"));
        $total = 0;
        $purpose = "";
        foreach($donations as $donation){
            $value = RGFormsModel::get_lead_field_value($entry, $donation);
            list($name, $price) = explode("|", $value);
            if(empty($price)){
                $price = $name;
                $name = $donation["label"];
            }
            $purpose .= $name . ", ";
            $price = GFCommon::to_number($price);
            $total += $price;
        }

        //using product fields for donation if there aren't any legacy donation fields in the form
        if($total == 0){
            //getting all product fields
            $products = GFCommon::get_product_fields($form, $entry, true);
            foreach($products["products"] as $product){
                $options = "";
                if(is_array($product["options"]) && !empty($product["options"])){
                    $options = " (";
                    foreach($product["options"] as $option){
                        $options .= $option["option_name"] . ", ";
                    }
                    $options = substr($options, 0, strlen($options)-2) . ")";
                }
                $quantity = GFCommon::to_number($product["quantity"]);
                $quantity_label = $quantity > 1 ? $quantity . " " : "";
                $purpose .= $quantity_label . $product["name"] . $options . ", ";
            }

            $total = GFCommon::get_order_total($form, $entry);
        }

        if(!empty($purpose))
            $purpose = substr($purpose, 0, strlen($purpose)-2);

        //truncating to maximum length allowed by Mijireh Checkout
        if(strlen($purpose) > 127)
            $purpose = substr($purpose, 0, 124) . "...";
				
		$mj_order->add_item( $purpose, $total, 1, '' );

        return $total > 0 ? $total : '0.00';
    }

    public static function uninstall(){

        //loading data lib
        require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");

        if(!GFMijirehCheckout::has_access("gravityforms_mijireh_checkout_uninstall"))
            die(__("You don't have adequate permission to uninstall the Mijireh Checkout Add-On.", "gravityformsmijirehcheckout"));

        //droping all tables
        GFMijirehCheckoutData::drop_tables();

        //removing options
        delete_option("gf_mijireh_checkout_site_name");
        delete_option("gf_mijireh_checkout_auth_token");
        delete_option("gf_mijireh_checkout_version");

        //Deactivating plugin
        $plugin = GF_MIJIREHCHECKOUT_PLUGIN;
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='mijireh_checkout_col_heading'>" . __("Mijireh Checkout Fields", "gravityformsmijirehcheckout") . "</td><td class='mijireh_checkout_col_heading'>" . __("Form Fields", "gravityformsmijirehcheckout") . "</td></tr>";
        $customer_fields = self::get_customer_fields();
        foreach($customer_fields as $field){
            $selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
            $str .= "<tr><td class='mijireh_checkout_field_cell'>" . $field["label"]  . "</td><td class='mijireh_checkout_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private static function get_customer_fields(){
        return array(
						array("name" => "first_name" , "label" => "First Name"),
						array("name" => "last_name" , "label" =>"Last Name"),
						array("name" => "email" , "label" =>"Email"),
						array("name" => "address1" , "label" =>"Address"), 
						array("name" => "address2" , "label" =>"Address 2"),
						array("name" => "town" , "label" =>"City"), 
						array("name" => "region" , "label" =>"State"), 
						array("name" => "postcode" , "label" =>"Zip"), 
						array("name" => "country" , "label" =>"Country")
					);
    }

    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "mijireh_checkout_customer_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    private static function get_product_options($form, $selected_field){
        $str = "<option value=''>" . __("Select a field", "gravityformsmijirehcheckout") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        $selected = $selected_field == 'all' ? "selected='selected'" : "";
        $str .= "<option value='all' " . $selected . ">" . __("Form Total", "gravityformsmijirehcheckout") ."</option>";

        return $str;
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function return_url($form_id, $lead_id) {
        $pageURL = GFCommon::is_ssl() ? "https://" : "http://";

        if ($_SERVER["SERVER_PORT"] != "80")
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        else
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];

        $ids_query = "ids={$form_id}|{$lead_id}";
        $ids_query .= "&hash=" . wp_hash($ids_query);

        return add_query_arg("gf_mijireh_checkout_return", base64_encode($ids_query), $pageURL);
    }

    private static function is_mijireh_checkout_page(){
        $current_page = trim(strtolower(RGForms::get("page")));
        return in_array($current_page, array("gf_mijireh_checkout"));
    }

    public static function admin_edit_payment_status($payment_status, $form_id, $lead)
    {
		//allow the payment status to be edited when for mijirehcheckout, not set to Approved, and not a subscription
		$payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
		require_once(GF_MIJIREHCHECKOUT_BASE_PATH . "/data.php");
		//get the transaction type out of the feed configuration, do not allow status to be changed when subscription
		$mijireh_checkout_feed_id = gform_get_meta($lead["id"], "mijireh_checkout_feed_id");
		$feed_config = GFMijirehCheckoutData::get_feed($mijireh_checkout_feed_id);
		$transaction_type = rgars($feed_config, "meta/type");
    	if ($payment_gateway <> "mijirehcheckout" || strtolower(rgpost("save")) <> "edit" || $payment_status == "Approved" || $transaction_type == "subscription")
    		return $payment_status;

		//create drop down for payment status
		$payment_string = gform_tooltip("mijireh_checkout_edit_payment_status","",true);
		$payment_string .= '<select id="payment_status" name="payment_status">';
		$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
		$payment_string .= '<option value="Approved">Approved</option>';
		$payment_string .= '</select>';
		return $payment_string;
    }
    public static function admin_edit_payment_status_details($form_id, $lead)
    {
		//check meta to see if this entry is mijirehcheckout
		$payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
		$form_action = strtolower(rgpost("save"));
		if ($payment_gateway <> "mijirehcheckout" || $form_action <> "edit")
			return;

		//get data from entry to pre-populate fields
		$payment_amount = rgar($lead, "payment_amount");
		if (empty($payment_amount))
		{
			$form = RGFormsModel::get_form_meta($form_id);
			$payment_amount = GFCommon::get_order_total($form,$lead);
		}
	  	$transaction_id = rgar($lead, "transaction_id");
		$payment_date = rgar($lead, "payment_date");
		if (empty($payment_date))
		{
			$payment_date = gmdate("y-m-d H:i:s");
		}

		//display edit fields
		?>
		<div id="edit_payment_status_details" style="display:block">
			<table>
				<tr>
					<td colspan="2"><strong>Payment Information</strong></td>
				</tr>

				<tr>
					<td>Date:<?php gform_tooltip("mijireh_checkout_edit_payment_date") ?></td>
					<td><input type="text" id="payment_date" name="payment_date" value="<?php echo $payment_date?>"></td>
				</tr>
				<tr>
					<td>Amount:<?php gform_tooltip("mijireh_checkout_edit_payment_amount") ?></td>
					<td><input type="text" id="payment_amount" name="payment_amount" value="<?php echo $payment_amount?>"></td>
				</tr>
				<tr>
					<td nowrap>Transaction ID:<?php gform_tooltip("mijireh_checkout_edit_payment_transaction_id") ?></td>
					<td><input type="text" id="mijireh_checkout_transaction_id" name="mijireh_checkout_transaction_id" value="<?php echo $transaction_id?>"></td>
				</tr>
			</table>
		</div>
		<?php
	}

	public static function admin_update_payment($form, $lead_id)
	{
		check_admin_referer('gforms_save_entry', 'gforms_save_entry');
		//update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
		//check meta to see if this entry is mijirehcheckout
		$payment_gateway = gform_get_meta($lead_id, "payment_gateway");
		$form_action = strtolower(rgpost("save"));
		if ($payment_gateway <> "mijirehcheckout" || $form_action <> "update")
			return;
		//get lead
		$lead = RGFormsModel::get_lead($lead_id);
		//get payment fields to update
		$payment_status = rgpost("payment_status");
		//when updating, payment status may not be editable, if no value in post, set to lead payment status
		if (empty($payment_status))
		{
			$payment_status = $lead["payment_status"];
		}

		$payment_amount = rgpost("payment_amount");
		$payment_transaction = rgpost("mijireh_checkout_transaction_id");
		$payment_date = rgpost("payment_date");
		if (empty($payment_date))
		{
			$payment_date = gmdate("y-m-d H:i:s");
		}
		else
		{
			//format date entered by user
			$payment_date = date("Y-m-d H:i:s", strtotime($payment_date));
		}

		global $current_user;
		$user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

		$lead["payment_status"] = $payment_status;
		$lead["payment_amount"] = $payment_amount;
		$lead["payment_date"] =   $payment_date;
		$lead["transaction_id"] = $payment_transaction;

		// if payment status does not equal approved or the lead has already been fulfilled, do not continue with fulfillment
        if($payment_status == 'Approved' && !$lead["is_fulfilled"])
        {
        	//call fulfill order, mark lead as fulfilled
        	self::fulfill_order($lead, $payment_transaction, $payment_amount);
        	$lead["is_fulfilled"] = true;
		}
		//update lead, add a note
		RGFormsModel::update_lead($lead);
		RGFormsModel::add_note($lead["id"], $user_id, $user_name, sprintf(__("Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s", "gravityforms"), $lead["payment_status"], GFCommon::to_money($lead["payment_amount"], $lead["currency"]), $payment_transaction, $lead["payment_date"]));
	}

	public static function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "Mijireh Checkout";
		return $plugins;
	}

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}
}

if(!function_exists("rgget")){
function rgget($name, $array=null){
    if(!isset($array))
        $array = $_GET;

    if(isset($array[$name]))
        return $array[$name];

    return "";
}
}

if(!function_exists("rgpost")){
function rgpost($name, $do_stripslashes=true){
    if(isset($_POST[$name]))
        return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

    return "";
}
}

if(!function_exists("rgar")){
function rgar($array, $name){
    if(isset($array[$name]))
        return $array[$name];

    return '';
}
}

if(!function_exists("rgars")){
function rgars($array, $name){
    $names = explode("/", $name);
    $val = $array;
    foreach($names as $current_name){
        $val = rgar($val, $current_name);
    }
    return $val;
}
}

if(!function_exists("rgempty")){
function rgempty($name, $array = null){
    if(!$array)
        $array = $_POST;

    $val = rgget($name, $array);
    return empty($val);
}
}

if(!function_exists("rgblank")){
function rgblank($text){
    return empty($text) && strval($text) != "0";
}
}
