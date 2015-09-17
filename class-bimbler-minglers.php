<?php
/**
 * Bimbler Minglers
 *
 * @package   Bimbler_Minglers
 * @author    Paul Perkins <paul@paulperkins.net>
 * @license   GPL-2.0+
 * @link      http://www.paulperkins.net
 * @copyright 2014 Paul Perkins
 */

/**
 * Include dependencies necessary... (none at present)
 *
 */

/**
 * Bimbler Minglers
 *
 * @package Bimbler_Minglers
 * @author  Paul Perkins <paul@paulperkins.net>
 */
class Bimbler_Minglers {

        /*--------------------------------------------*
         * Constructor
         *--------------------------------------------*/

        /**
         * Instance of this class.
         *
         * @since    1.0.0
         *
         * @var      object
         */
        protected static $instance = null;

        /**
         * Return an instance of this class.
         *
         * @since     1.0.0
         *
         * @return    object    A single instance of this class.
         */
        public static function get_instance() {

                // If the single instance hasn't been set, set it now.
                if ( null == self::$instance ) {
                        self::$instance = new self;
                } // end if

                return self::$instance;

        } // end get_instance

        /**
         * Initializes the plugin by setting localization, admin styles, and content filters.
         */
        private function __construct() {

                //add_filter( 'tribe-events-bar-filters',  array ($this, 'bimbler_tribe_events_bar_setup'), 1, 1);

                //add_filter( 'tribe_events_pre_get_posts', array ($this, 'bimbler_tribe_events_bar_query'), 10, 1 );

/*
        	add_action( 'wp_enqueue_scripts' , array( $this, 'load_bootstrap' ),100 );
                
                add_filter ('tribe_events_after_header', array ($this, 'bimbler_tribe_events_add_cat_filter'));
                
                */
                
                
                // Meetup event importer.
                add_shortcode( 'bimbler_mingler_event_import', array ($this, 'import_meetup_events'));

                	         	
	} // End constructor.
	

	function fetch_meetup_events ($group_id, $api_key) {
		       
		$import = new stdClass ();   
			    
		$import->events = array();
			
		$offset = 0;
		$cont = true;
		
		$get = "http://api.meetup.com/2/events?group_id=" . $group_id . "&status=upcoming&format=json";
		$get .= "&page=100&offset=";		
		
		$key = '&key=' . $api_key;
		
		while ($cont) {
		
			$url = $get . $offset . $key;
			
			$import->output .= 'Fetching from \'' . $url . '\'... <br>';
			
			$json = @file_get_contents($url);
			
			if (false === $json) {
				$import->output .= '<div class="bimbler-alert-box error"><span>Error: </span>Cannot fetch JSON data from api.meetup.com: ' . error_get_last()['message'] . '</div>';
				return $import;
			}
			
			$data = json_decode($json);
			
			if (!isset ($data)) {
				$import->output .= '<div class="bimbler-alert-box error"><span>Error: </span>Cannot convert JSON to PHP object.</div>';
				return $import;
			}
			
			$import->events = array_merge ($data->results, $import->events);

			// Got all records, stop now.			
			if (count($data->results) < 100) {
				$cont = false;
			}
			
			// Get the next page-full.			
			$offset++;
		}                
			
		return $import;
	}

	function get_event_date ($event_data) {
		return ($event_data->time) / 1000;
	}
	
	function sanitise_input ($text) {
		$text = str_replace ('~', '', $text);
		
		$text = str_replace (PHP_EOL, '', $text);
		
		return $text;
	}

	function get_current_user_id () {

		// Running interactively.
		if (is_user_logged_in()) {
			global $current_user;
			get_currentuserinfo();
			
			if (isset ($current_user)) {
				
				return $current_user->ID;
				
			}
		}
		
		// Running from cron - return the primary admin user.
		$admin_users = Bimbler_RSVP::get_instance()->get_admin_users();
		
		sort ($admin_users);
		
		return $admin_users[0];
	}


	function import_meetup_events($atts) {
		
		$fetched = 0;
		$found = 0;
		$created = 0;
		$updated = 0;
		
		$content = '';
		
		//$content .= '<p>Remote host: ' . $_SERVER["REMOTE_ADDR"] . '</p>';
		
		$host = $_SERVER["REMOTE_ADDR"];
		$local_ip = getHostByName(getHostName());
		
		if ( !current_user_can( 'manage_options' ) && ($local_ip != $host))  {
			$content .= '<div class="bimbler-alert-box error"><span>Error: </span>You must be an admin user to view this page.</div>';
			
			error_log ('import_meetup_events called by non-admin or not from localhost.');
				
			return $content;
		} 
		
		$a = shortcode_atts (array (
                        'api_key' 	=> 7,
						'group_id'	=> 0,
						'create_if_not_found' => 'N',
						'update_meta_if_found' => 'N',
                        'test' 		=> 'Y',
		), $atts);
		
		if (!isset ($a)) {
			error_log ('import_meetup_events called with no parameters set.');
			$content .= '<div class="bimbler-alert-box error"><span>Error: </span>No parameters specified.</div>';
			return $content;
		}
		
		// Work around the UTC bug...
		date_default_timezone_set('Australia/Brisbane');
		
		$date_from = date('Y-m-d', strtotime($a['ahead'] . ' days'));
		$date_to = date('Y-m-d', strtotime($a['ahead'] + 1 . ' days'));
		
		
		$content .= '<h5>Input Parameters</h5>';
		$content .= 'Group ID: ' .  $a['group_id'] . '<br>';
		$content .= 'API Key: ' .  $a['api_key'] . '<br>';
		$content .= 'Test mode: ' . $a['test'] . '<br>';
		$content .= 'Create new: ' . $a['create_if_not_found'] . '<br>';
		$content .= 'Update meta: ' . $a['update_meta_if_found'] . '<br><br>';

		$output = '';

		// What does this app do today?
		$create_if_not_found = false;
		$update_meta_if_found = false;

		if ('Y' == $a['create_if_not_found']) {
			$create_if_not_found = true;
		}

		if ('Y' == $a['update_meta_if_found']) {
			$update_meta_if_found = true;
		}
		
		$test_mode = false;
		
		if ('Y' == $a['test']) {
			$test_mode = true;
		}
		
		$import = $this->fetch_meetup_events ($a['group_id'], $a['api_key']);
		
		// Who are are running as?
		$this_user_id = $this->get_current_user_id();		
		//$content .= 'Running as user ID: ' . $this_user_id . '<br><br>';

		$content .= '<h4>Output:</h4>';
		$content .= $import->output;
		$content .= '<br>';
		
		$fetched = count ($import->events);
		
		$content .= 'Fetched ' . $fetched . ' events.<br>';
		
		if ($fetched > 0) {
			
			$i = 1;
			
			foreach ($import->events as $event) {
				
				// Create new member, containing parsed date.
				$event->event_date = date ('Y-m-d H:i:s', $this->get_event_date ($event));

				$content .= $i++ . ': ' . $event->id . ' -> ' . $event->name . ' on ' . $event->event_date;
				
				// $content .= '<br>';
				
				// See if this event already exists.
				$get_posts = tribe_get_events( array(
					'eventDisplay' 	=> 'custom',
					'posts_per_page'=>	1,
					'post_status' 	=> array ('publish', 'draft'),
					'meta_query' 	=> array(
											array(	'key' 		=> '_EventStartDate',
													'value' 	=> $event->event_date,
													'compare' 	=> '=',
													'type' 		=> 'datetime'), 
											),
					'tax_query' 	=> array(
											array(	'taxonomy' => TribeEvents::TAXONOMY,
													'field' => 'slug',
													'terms' => 'mingle'),
											)
										)
									);

					$content .= '&nbsp;--'; 
					
					//error_log (json_encode ($get_posts));
					
					// Got the same event (or at least, *a* Mingle starting at the same time).
					if (!empty ($get_posts)) {
						
						$found++;
						 
						$this_event = $get_posts[0];
						
						//$content .= '<pre>' . json_encode ($this_event, true) . '</pre>';
						
						// $content .= ' Found: ' . $this_event->post_title . ' on ' . tribe_get_start_date($this_event->ID, false, 'Y-m-d H:i:s') . '.';
						$content .= ' Found: post ID <a href="' . get_permalink ($this_event->ID) . '" target="_external">' . $this_event->ID . '</a>.<br>';
							
						// We're updating existing events - backfill data.				
						if ($update_meta_if_found) {
								
							// Event found, but not with a Meetup ID.
							if (!get_post_meta ($this_event->ID, 'bimbler_meetup_id', true)) {
								
								if (!$test_mode) {	
									// Update with Meetup ID if it's not already set
									update_post_meta ($this_event->ID, 'bimbler_meetup_id', $event->id);
									
									$content .= '<font color="orange">&nbsp;-- Updated Meetup ID.</font> ';
									
								} else {

									$content .= '<font color="orange">&nbsp;-- Test mode - Meetup ID not updated. </font>';
								}

								$updated++;
								
							} else {
								
								$content .= '<font color="green">&nbsp;-- Existing event with Meetup ID set - no action to take.</font>';
								
								// See if we need to update date / time.
								// Can't do this, as the query loop is searching by date / time.	
/*								$curr_start_date = tribe_get_start_date($event->ID, false, 'Y-m-d H:i:s');
								$curr_end_date = tribe_get_end_date($event->ID, false, 'Y-m-d H:i:s');
								
								if ($curr_start_date != $event->event_date) {
									$content .= ' Meetup start time not same as here (' . $curr_start_date . ' -> ' . $event->event_date);  
								} */
								
							}
						} else {
							$content .= '<font color="green">&nbsp;-- Not running in "Update Meetup ID if found" mode - no action taken.</font>'; 
						}
						
					} else { // No mingler event found at that time on that day.
					
						if ($create_if_not_found) {

							//$content .= '<pre>' . print_r ($event, true) . '</pre>'; 	

							//$new_event = new stdClass();
							$new_event = array ();
							
							$new_event['EventStartDate'] = date( 'Y-m-d', strtotime ($event->event_date) );
							$new_event['EventStartHour'] = date( 'h', strtotime ($event->event_date) );
							$new_event['EventStartMinute'] = date( 'i', strtotime ($event->event_date) );

							if (isset ($event->duration)) {
								//$content .= ' Adding ' . $event->duration / 1000 . ' seconds '; 
								$new_event['EventEndDate'] = date( 'Y-m-d', strtotime($event->event_date . ' + ' . $event->duration / 1000 . ' seconds') );
								$new_event['EventEndHour'] = date( 'h', strtotime($event->event_date . ' + ' . $event->duration / 1000 . ' seconds') );
								$new_event['EventEndMinute'] = date( 'i', strtotime($event->event_date . ' + ' . $event->duration / 1000 . ' seconds') );
							} else {
								// Add an abitrary 3 hours to the start time to get the end time.
								$new_event['EventEndDate'] = date( 'Y-m-d', strtotime($event->event_date . ' + 3 hours') );
								$new_event['EventEndHour'] = date( 'h', strtotime($event->event_date . ' + 3 hours') );
								$new_event['EventEndMinute'] = date( 'i', strtotime($event->event_date . ' + 3 hours') );
							}

							$content .= '<br><font color="red">&nbsp;-- Creating new event.</font>';
							
							//$content .= '<pre>' . print_r ($new_event, true) . '</pre>'; 	
							
						    $new_event['post_status'] = 'draft';
							$new_event['author'] = $this_user_id;
							$new_event['post_title'] = $event->name;
							$new_event['post_content'] = $event->description; 

							// XXXXX REMOVE SECOND TERM WHEN FINISHED TESTING XXXX
							if (!$test_mode && ($created < 1)) {
							
							    $new_event_id = TribeEventsAPI::createEvent ($new_event);
    
								// Copy the taxonomies.
								$taxonomies = get_object_taxonomies ('tribe_events');
								
								foreach( $taxonomies AS $tax ) {
									$terms = wp_get_object_terms( $event_id, $tax );
									$term = array();
									foreach( $terms AS $t ) {
										$term[] = $t->slug;
									} 
								
									wp_set_object_terms( $new_event_id, $term, $tax );
								}

								// Update with Meetup ID.
								update_post_meta ($new_event_id, 'bimbler_meetup_id', $event->id);

								$tribe_ecp = TribeEvents::instance();
								
								// Set this to a Mingle.
								$term_info = get_term_by( 'slug', 'mingle', $tribe_ecp->get_event_taxonomy() );
								
								wp_set_object_terms ($new_event_id, $term_info->term_id, $term_info->taxonomy);

								$content .= ' New event ID: <a href="' . get_permalink ($new_event_id)  . '" target="_external">' . $new_event_id . '</a>';

							} else {

									$content .= '<font color="orange">&nbsp;-- Test mode - Event not created. </font>';
								
							}
							
							$created++;

							
						} else { // End create_if_not_found.
							
							$content .= '<font color="green"><br>&nbsp;-- Not running in "Create if not found" mode - no action taken.</font>';
						} 
					}
					
					$content .= '<br>';

				
			}
			
		}
		
		$summary = 'import_meetup_events: fetched: ' .  $fetched . ', found: ' . $found . ', created: ' . $created . ', updated: ' . $updated . '.';
		
		$content .= '<br>' . $summary;

		error_log ($summary);
		
		return $content;
	}
	

	/*
	 * Set up filters below tribe events bar.
	 *
	 */
        function bimbler_tribe_events_add_cat_filter () {
                
                $filter_html = 'Filter events: <div class="btn-group" data-toggle="buttons">
  <label class="btn btn-primary active">
    <input type="checkbox" autocomplete="off" checked> Bimbles
  </label>
  <label class="btn btn-primary active">
    <input type="checkbox" autocomplete="off" checked> Mingles
  </label>
  <label class="btn btn-primary active">
    <input type="checkbox" autocomplete="off" checked> Social
  </label>
</div>';

                echo $filter_html;

                error_log ('bimbler_tribe_events_add_cat_filter: applying filters.');

        }
        
	/*
	 * Handle filters in tribe events bar.
	 *
	 */
        function bimbler_tribe_events_bar_query( $query ){
                
                if ( !empty( $_REQUEST['tribe-bar-my-field'] ) ) {
                        // do stuff
                }
                
                error_log ('bimbler_tribe_events_bar_query: running query: ' /*. json_encode ($query)*/);
                
                return $query;
        }  
        
        
        // Load bootstrap JS.
        function load_bootstrap () {
                
                // CSS.
                wp_register_style( 'bimbler-bootstrap-style', 'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.css');
                //wp_enqueue_style( 'bimbler-bootstrap-style' );
        
                // Javascript.
                wp_register_script ('bimbler-bootstrap-script', 'https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js', array( 'jquery' ),null,true);
                wp_enqueue_script( 'bimbler-bootstrap-script');

        }
        
              
		
} // End class
