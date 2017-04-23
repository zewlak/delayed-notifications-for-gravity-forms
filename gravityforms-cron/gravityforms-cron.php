<?php
/*
Plugin Name: Gravity Forms Delayed Notifications
Plugin URI: http://www.zewlak.com/gfc
Description: Add-on for Gravity Forms to send delayed notifications
Version: 1.0
Author: Tomasz Zewlakow
Author URI: http://www.zewlak.com
*/

//*********** for install/uninstall actions (optional) ********************//
/*register_activation_hook(__FILE__,'gfc_install');
register_deactivation_hook(__FILE__, 'gfc_uninstall');
function gfc_install(){
     gfc_uninstall();//force to uninstall option
     add_option("gfc_secret", generateRandom(10));
}

function gfc_uninstall(){
    if(get_option('gfc_secret')){
     delete_option("gfc_secret");
     }
}*/
//*********** end of install/uninstall actions (optional) ********************//

add_action( 'admin_notices', 'gfc_dependencies' );

function gfc_dependencies() {
  if( ! is_plugin_active( 'gravityforms/gravityforms.php' ) )
    echo '<div class="error"><p>' . __( '<strong>Warning: <i>Gravity Forms Delayed Notifications</i> needs <i>Gravity Forms</i> plugin to work</strong>', 'gfc' ) . '</p></div>';
}


add_filter( 'gform_addon_navigation', 'add_menu_item' );
function add_menu_item( $menu_items ) {
    $menu_items[] = array( "name" => "gfc_options", "label" => "Delayed notifications", "callback" => "gfc_options2", "permission" => "edit_posts" );
    return $menu_items;
}

function gfc_options2(){
  	if (!current_user_can('manage_options'))  {
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}
	
	//Create an instance of our package class...
    $testListTable = new TT_Example_List_Table();
    //Fetch, prepare, sort, and filter our data...
    $testListTable->prepare_items();
    
    ?>
    <div class="wrap">
        
        <div id="icon-users" class="icon32"><br/></div>
        <h2>Gravity Forms Delayed Notifications</h2>

        <!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
        <form id="movies-filter" method="get">
            <!-- For plugins, we also need to ensure that the form posts back to our current page -->
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
            <!-- Now we can render the completed list table -->
            <?php $testListTable->display() ?>
        </form>
        
    </div>
    <?php
}


function get_events_from_settings(){
	$events = array();
	$crons = AdminPageFramework::getOption( 'APF_AddFields', 'gfc_crons' );
	
	$crons[] = array('size' => 5, 'unit'=> 'min');
	if(sizeof($crons)){
		foreach($crons as $cron){
			if(!is_array($cron)) continue;
			$cronName = 'cron_'.$cron['size'].'_'.$cron['unit'];
			$events[] = $cronName;
		}
	}
	
	return $events;
}



/* add new options to select in notification settings */
add_filter( 'gform_notification_events', 'gw_add_manual_notification_event' );
function gw_add_manual_notification_event( $events ) {
	
	$crons = AdminPageFramework::getOption( 'APF_AddFields', 'gfc_crons' );
	$crons[] = array('size' => 5, 'unit'=> 'min');
	if(sizeof($crons)){
		foreach($crons as $cron){
			if(!is_array($cron)) continue;
			$cronName = 'cron_'.$cron['size'].'_'.$cron['unit'];
			switch($cron['unit']){
				case 'm':
					$period = 'minute(s)';
					break;
				case 'h':
					$period = 'hour(s)';
					break;
				case 'd':
					$period = 'day(s)';
					break;
			}
			$events[$cronName] = __( $cron['size'].' '.$period.' after Form is submitted' );
		}
	}
	
    return $events;
}

add_action( 'gform_after_submission', 'schedule_notifications', 10, 2 );
function schedule_notifications( $entry, $form ) {
	
	/* shedule cron notifications */
	$events = get_events_from_settings();
	foreach($events as $event){
		$notifications_to_send = array();
		$notifications_to_send = gfc_get_notifications_by_event($event,$form,$entry);
		
		if(sizeof($notifications_to_send)){
			$options = new stdClass();
			$options->event = $event;
			$options->form_id = $form['id'];
			$options->entry_id = $entry['id'];
			$options->notifications = $notifications_to_send;
			wp_schedule_single_event( gfc_get_timestamp_from_event($event), 'gfc_action_send_cron_notification',array($options));
		}
	}
	
}

/* notification send invoke */
add_action( 'gfc_action_send_cron_notification', 'gfc_send_cron_notification', 10, 3 );
function gfc_send_cron_notification( $options) {
	$event = $options->event;
	$form = GFAPI::get_form($options->form_id);
	$lead = GFAPI::get_entry($options->entry_id);
	GFCommon::send_notifications( $options->notifications, $form, $lead, true, $event );
	die();
}

function gfc_get_notifications_by_event($event, $form, $lead){
	$notifications = GFCommon::get_notifications_to_send( $event, $form, $lead );
	
	$notifications_to_send = array();
	//running through filters that disable form submission notifications
	foreach ( $notifications as $notification ) {
		if ( apply_filters( "gform_disable_notification_{$form['id']}", apply_filters( 'gform_disable_notification', false, $notification, $form, $lead ), $notification, $form, $lead ) ) {
			//skip notifications if it has been disabled by a hook
			continue;
		}
		if( $notification["isActive"] != "1" ) {
			continue;
		}
		$notifications_to_send[] = $notification['id'];
	}
	
	
	return $notifications_to_send;
}

function gfc_get_timestamp_from_event($event){
	$timestamp = false;
	$eventParts = explode('_',$event);
	
	
	if($eventParts[0] != 'cron' || empty($eventParts[1]) || empty($eventParts[2])) return false;
	switch($eventParts[2]){
		case 'm':
			$timestamp = time() + intval($eventParts[1]) * MINUTE_IN_SECONDS;
			break;
		case 'h':
			$timestamp = time() + intval($eventParts[1]) * HOUR_IN_SECONDS;
			break;
		case 'd':
			$timestamp = time() + intval($eventParts[1]) * DAY_IN_SECONDS;
			break;
	}
	return $timestamp;
}












if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}




/************************** CREATE A PACKAGE CLASS *****************************
 *******************************************************************************
 * Create a new list table package that extends the core WP_List_Table class.
 * WP_List_Table contains most of the framework for generating the table, but we
 * need to define and override some methods so that our data can be displayed
 * exactly the way we need it to be.
 * 
 * To display this example on a page, you will first need to instantiate the class,
 * then call $yourInstance->prepare_items() to handle any data manipulation, then
 * finally call $yourInstance->display() to render the table to the page.
 * 
 * Our theme for this list table is going to be movies.
 */
class TT_Example_List_Table extends WP_List_Table {
    
    /** ************************************************************************
     * Normally we would be querying data from a database and manipulating that
     * for use in your list table. For this example, we're going to simplify it
     * slightly and create a pre-built array. Think of this as the data that might
     * be returned by $wpdb->query()
     * 
     * In a real-world scenario, you would make your own custom query inside
     * this class' prepare_items() method.
     * 
     * @var array 
     **************************************************************************/
    
    /** ************************************************************************
     * REQUIRED. Set up a constructor that references the parent constructor. We 
     * use the parent reference to set some default configs.
     ***************************************************************************/
    function __construct(){
        global $status, $page;
                
        //Set parent defaults
        parent::__construct( array(
            'singular'  => 'movie',     //singular name of the listed records
            'plural'    => 'movies',    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );
        
    }


    /** ************************************************************************
     * Recommended. This method is called when the parent class can't find a method
     * specifically build for a given column. Generally, it's recommended to include
     * one method for each column you want to render, keeping your package class
     * neat and organized. For example, if the class needs to process a column
     * named 'title', it would first see if a method named $this->column_title() 
     * exists - if it does, that method will be used. If it doesn't, this one will
     * be used. Generally, you should try to use custom column methods as much as 
     * possible. 
     * 
     * Since we have defined a column_title() method later on, this method doesn't
     * need to concern itself with any column with a name of 'title'. Instead, it
     * needs to handle everything else.
     * 
     * For more detailed insight into how columns are handled, take a look at 
     * WP_List_Table::single_row_columns()
     * 
     * @param array $item A singular item (one full row's worth of data)
     * @param array $column_name The name/slug of the column to be processed
     * @return string Text or HTML to be placed inside the column <td>
     **************************************************************************/
    function column_default($item, $column_name){
        switch($column_name){
            case 'date':
            case 'entry':
            case 'form':
                return $item[$column_name];
            default:
                return print_r($item,true); //Show the whole array for troubleshooting purposes
        }
    }


    /** ************************************************************************
     * Recommended. This is a custom column method and is responsible for what
     * is rendered in any column with a name/slug of 'title'. Every time the class
     * needs to render a column, it first looks for a method named 
     * column_{$column_title} - if it exists, that method is run. If it doesn't
     * exist, column_default() is called instead.
     * 
     * This example also illustrates how to implement rollover actions. Actions
     * should be an associative array formatted as 'slug'=>'link html' - and you
     * will need to generate the URLs yourself. You could even ensure the links
     * 
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/
    
	function column_title($item){
        
        //Build row actions
        $actions = array(
            'edit'      => sprintf('<a href="?page=%s&action=%s&movie=%s">Edit</a>',$_REQUEST['page'],'edit',$item['ID']),
            'delete'    => sprintf('<a href="?page=%s&action=%s&movie=%s">Delete</a>',$_REQUEST['page'],'delete',$item['ID']),
        );
        /*
        //Return the title contents
        return sprintf('%1$s <span style="color:silver">(id:%2$s)</span>%3$s',
            $item['title'],
            $item['ID']
            $this->row_actions($actions)
        );
		*/
		//Return the title contents
        return $item['title'];
    }
	

    /** ************************************************************************
     * REQUIRED if displaying checkboxes or using bulk actions! The 'cb' column
     * is given special treatment when columns are processed. It ALWAYS needs to
     * have it's own method.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/
    function column_cb($item){
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            /*$1%s*/ $this->_args['singular'],  //Let's simply repurpose the table's singular label ("movie")
            /*$2%s*/ $item['ID']                //The value of the checkbox should be the record's id
        );
    }


    /** ************************************************************************
     * REQUIRED! This method dictates the table's columns and titles. This should
     * return an array where the key is the column slug (and class) and the value 
     * is the column's title text. If you need a checkbox for bulk actions, refer
     * to the $columns array below.
     * 
     * The 'cb' column is treated differently than the rest. If including a checkbox
     * column in your table you must create a column_cb() method. If you don't need
     * bulk actions or checkboxes, simply leave the 'cb' entry out of your array.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @return array An associative array containing column information: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_columns(){
        $columns = array(
            'cb'        => '<input type="checkbox" />', //Render a checkbox instead of text
            'entry'    => 'Entry',
            'title'     => 'Form',
            'date'    => 'Send date',
            'form'  => 'Notitication'
        );
        return $columns;
    }


    /** ************************************************************************
     * Optional. If you want one or more columns to be sortable (ASC/DESC toggle), 
     * you will need to register it here. This should return an array where the 
     * key is the column that needs to be sortable, and the value is db column to 
     * sort by. Often, the key and value will be the same, but this is not always
     * the case (as the value is a column name from the database, not the list table).
     * 
     * This method merely defines which columns should be sortable and makes them
     * clickable - it does not handle the actual sorting. You still need to detect
     * the ORDERBY and ORDER querystring variables within prepare_items() and sort
     * your data accordingly (usually by modifying your query).
     * 
     * @return array An associative array containing all the columns that should be sortable: 'slugs'=>array('data_values',bool)
     **************************************************************************/
    function get_sortable_columns() {
        $sortable_columns = array(
            'entry'    => array('entry',false),
            'title'     => array('title',false),     //true means it's already sorted
            'date'    => array('date',false),
            'form'  => array('form',false)
        );
        return $sortable_columns;
    }


    /** ************************************************************************
     * Optional. If you need to include bulk actions in your list table, this is
     * the place to define them. Bulk actions are an associative array in the format
     * 'slug'=>'Visible Title'
     * 
     * If this method returns an empty value, no bulk action will be rendered. If
     * you specify any bulk actions, the bulk actions box will be rendered with
     * the table automatically on display().
     * 
     * Also note that list tables are not automatically wrapped in <form> elements,
     * so you will need to create those manually in order for bulk actions to function.
     * 
     * @return array An associative array containing all the bulk actions: 'slugs'=>'Visible Titles'
     **************************************************************************/
    /*
	function get_bulk_actions() {
        $actions = array(
            'delete'    => 'Delete'
        );
        return $actions;
    }
	*/


    /** ************************************************************************
     * Optional. You can handle your bulk actions anywhere or anyhow you prefer.
     * For this example package, we will handle it in the class to keep things
     * clean and organized.
     * 
     * @see $this->prepare_items()
     **************************************************************************/
    function process_bulk_action() {
        
        //Detect when a bulk action is being triggered...
        if( 'delete'===$this->current_action() ) {
            wp_die('Items deleted (or they would be if we had items to delete)!');
        }
        
    }


    /** ************************************************************************
     * REQUIRED! This is where you prepare your data for display. This method will
     * usually be used to query the database, sort and filter the data, and generally
     * get it ready to be displayed. At a minimum, we should set $this->items and
     * $this->set_pagination_args(), although the following properties and methods
     * are frequently interacted with here...
     * 
     * @global WPDB $wpdb
     * @uses $this->_column_headers
     * @uses $this->items
     * @uses $this->get_columns()
     * @uses $this->get_sortable_columns()
     * @uses $this->get_pagenum()
     * @uses $this->set_pagination_args()
     **************************************************************************/
    function prepare_items() {
        global $wpdb; //This is used only if making any database queries

        /**
         * First, lets decide how many records per page to show
         */
        $per_page = 5;
        
        
        /**
         * REQUIRED. Now we need to define our column headers. This includes a complete
         * array of columns to be displayed (slugs & titles), a list of columns
         * to keep hidden, and a list of columns that are sortable. Each of these
         * can be defined in another method (as we've done here) before being
         * used to build the value for our _column_headers property.
         */
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        
        /**
         * REQUIRED. Finally, we build an array to be used by the class for column 
         * headers. The $this->_column_headers property takes an array which contains
         * 3 other arrays. One for all columns, one for hidden columns, and one
         * for sortable columns.
         */
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        
        /**
         * Optional. You can handle your bulk actions however you see fit. In this
         * case, we'll handle them within our package just to keep things clean.
         */
        $this->process_bulk_action();
        
        
        /**
         * Instead of querying a database, we're going to fetch the example data
         * property we created for use in this plugin. This makes this example 
         * package slightly different than one you might build on your own. In 
         * this example, we'll be using array manipulation to sort and paginate 
         * our data. In a real-world implementation, you will probably want to 
         * use sort and pagination data to build a custom query instead, as you'll
         * be able to use your precisely-queried data immediately.
         */
        
        $data = array();
		
		$crons = _get_cron_array();
		if(sizeof($crons)){
			foreach($crons as $timestamp => $cron){
				
				foreach($cron as $key => $property){
					$pluginCron = false;
					switch($key){
						case 'gfc_action_send_cron_notification':
							$pluginCron = true;
							reset($property);
							$cronId = key($property);
							$cronData = $property[$cronId];
							
							
							$options = $cronData['args'][0];
							$form = GFAPI::get_form($options->form_id);
							$lead = GFAPI::get_entry($options->entry_id);
							
							$notifications = $options->notifications;
							
							foreach($notifications as $notification){
									
									$element = array(
										'ID'        => $cronId,
										'entry'    => 'Entry id: <a href="http://www.zewlak.com/wp-admin/admin.php?page=gf_entries&view=entry&id='.$form['id'].'&lid='.$lead['id'].'&dir=DESC&filter&paged=1&pos=0&field_id&operator">'.$lead['id'].'</a>',
										'title'     => $form['title'],
										'date'    => gfc_get_next_cron_execution($timestamp,$options->event),
										'form'  => $form['notifications'][$notification]['name']
										
									);
									$data[] = $element;
									//json_encode($cronData['args'])
							}
							
							
							
							break;
					}
					
				}
			}
		}
		
        
        /**
         * This checks for sorting input and sorts the data in our array accordingly.
         * 
         * In a real-world situation involving a database, you would probably want 
         * to handle sorting by passing the 'orderby' and 'order' values directly 
         * to a custom query. The returned data will be pre-sorted, and this array
         * sorting technique would be unnecessary.
         */
        function usort_reorder($a,$b){
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'date'; //If no sort, default to title
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc'; //If no order, default to asc
            $result = strcmp($a[$orderby], $b[$orderby]); //Determine sort order
            return ($order==='asc') ? $result : -$result; //Send final sort direction to usort
        }
        usort($data, 'usort_reorder');
        
        
        /***********************************************************************
         * ---------------------------------------------------------------------
         * vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv
         * 
         * In a real-world situation, this is where you would place your query.
         *
         * For information on making queries in WordPress, see this Codex entry:
         * http://codex.wordpress.org/Class_Reference/wpdb
         * 
         * ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
         * ---------------------------------------------------------------------
         **********************************************************************/
        
                
        /**
         * REQUIRED for pagination. Let's figure out what page the user is currently 
         * looking at. We'll need this later, so you should always include it in 
         * your own package classes.
         */
        $current_page = $this->get_pagenum();
        
        /**
         * REQUIRED for pagination. Let's check how many items are in our data array. 
         * In real-world use, this would be the total number of items in your database, 
         * without filtering. We'll need this later, so you should always include it 
         * in your own package classes.
         */
        $total_items = count($data);
        
        
        /**
         * The WP_List_Table class does not handle pagination for us, so we need
         * to ensure that the data is trimmed to only the current page. We can use
         * array_slice() to 
         */
        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);
        
        
        
        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $data;
        
        
        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
        ) );
    }


}

function gfc_get_next_cron_execution($timestamp, $event) {
	if ($timestamp - time() <= 0)
		return __('At next page refresh', 'acm');

	return __('In', 'acm').' '.human_time_diff( time(), $timestamp ).'<br>'.date("d.m.Y H:i:s", $timestamp);

}

include( dirname( __FILE__ ) . '/admin-page-framework/library/apf/admin-page-framework.php' );


 
// Extend the class
class APF_AddFields extends AdminPageFramework {
 
    /**
     * The set-up method which is triggered automatically with the 'wp_loaded' hook.
     * 
     * Here we define the setup() method to set how many pages, page titles and icons etc.
     */
    public function setUp() {
       
        // Create the root menu - specifies to which parent menu to add.
        // the available built-in root menu labels: Dashboard, Posts, Media, Links, Pages, Comments, Appearance, Plugins, Users, Tools, Settings, Network Admin
        
		$this->addSubMenuPage( array(
                'page_slug' => 'gfc_options',
                'title'     => 'Delay periods',
                'order'     => 20,
            )   );
        

    }
    
    /**
     * One of the pre-defined methods which is triggered when the registered page loads.
     * 
     * Here we add form fields.
     * @callback        action      load_{page slug}
     */

    public function load_gfc_options( $oAdminPage ) {
        
		
        $this->addSettingFields(
			
            array( // Repeatable Size Fields
                'field_id'      => 'gfc_crons',
                'title'         => __( 'Enter new periods', 'admin-page-framework-loader' ),
                'type'          => 'size',
                'repeatable'    => true,
				'units' 		=> array( 'm' => 'minute(s)', 'h' => 'hour(s)', 'd' => 'day(s)' )
				
            ),
			array( // Submit button
                'field_id'      => 'submit_button',
                'type'          => 'submit',
            ) 
            
        );         
        
    }
    
    /**
     * One of the pre-defined methods which is triggered when the page contents is going to be rendered.
     * @callback        action      do_{page slug}
     */
    public function do_my_first_forms() {
                   
        // Show the saved option value.
        // The extended class name is used as the option key. This can be changed by passing a custom string to the constructor.
        echo '<h3>Saved Fields</h3>';
        echo '<pre>my_text_field: ' . AdminPageFramework::getOption( 'APF_AddFields', 'my_text_field', 'default text value' ) . '</pre>';
        echo '<pre>my_textarea_field: ' . AdminPageFramework::getOption( 'APF_AddFields', 'my_textarea_field', 'default text value' ) . '</pre>';
        
        echo '<h3>Show all the options as an array</h3>';
        echo $this->oDebug->getArray( AdminPageFramework::getOption( 'APF_AddFields' ) );
       
    }
   
}
 
// Instantiate the class object.
new APF_AddFields;