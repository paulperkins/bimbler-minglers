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
	

	function fetch_meetup_events ($api_key) {
		       
		$import = new stdClass ();   
			    
		$import->events = array();
			
		$offset = 0;
		$cont = true;
		
		$get = "http://api.meetup.com/2/events?group_id=6763812&status=upcoming&format=json";
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
                        'test' 		=> 'N',
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
		$content .= 'API Key: ' .  $a['api_key'] . '<br>';
		$content .= 'Test mode: ' . $a['test'] . '<br><br>';

		$output = '';
		
		$test_mode = false;
		
		if ('Y' == $a['test']) {
			$test_mode = true;
		}
		
		$import = $this->fetch_meetup_events ($a['api_key']);
		
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
				
				$content .= '<br>';
				
				// See if this event already exists.
				$get_posts = tribe_get_events( array(
					'eventDisplay' 	=> 'custom',
					'posts_per_page'=>	1,
					'meta_query' 	=> array(
											array(
													'key' 		=> '_EventStartDate',
													'value' 	=> $event->event_date,
													'compare' 	=> '=',
													'type' 		=> 'datetime'
											), 
										),
					'tax_query' 	=> array(
											array(
												'taxonomy' => TribeEvents::TAXONOMY,
															'field' => 'slug',
															'terms' => 'mingle'),
											)
										)
									);

					$content .= '&nbsp;--'; 
					
					//error_log (json_encode ($get_posts));

					// What does this app do today?
					$create_if_not_found = false;
					$update_meta_if_found = true;
					
					// Got the same event (or at least, *a* Mingle starting at the same time).
					if (!empty ($get_posts)) {
						
						$found++;
						 
						$this_event = $get_posts[0];
						
						// $content .= ' Found: ' . $this_event->post_title . ' on ' . tribe_get_start_date($this_event->ID, false, 'Y-m-d H:i:s') . '.';
						$content .= ' Found: post ID <a href="' . get_permalink ($this_event->ID) . '" target="_external">' . $this_event->ID . '</a>.';
							
						// We're updating existing events - backfill data.				
						if ($update_meta_if_found) {
								
							// Event found, but not with a Meetup ID.
							if (!get_post_meta ($this_event->ID, 'bimbler_meetup_id', true)) {
								
								if (!$test_mode) {	
									// Update with Meetup ID if it's not already set
									update_post_meta ($this_event->ID, 'bimbler_meetup_id', $event->id);
									
									$content .= ' Updated Meetup ID. ';
									
								} else {

									$content .= ' Test mode - Meetup ID not updated. ';
								}

								$updated++;
								
							} else {
								
								$content .= ' Existing event with Meetup ID set - no action to take.';	
							}
						}
						
					} else { // No mingler event found at that time on that day.
						$content .= ' Not found.'; 
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
