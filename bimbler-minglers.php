<?php 
    /*
    Plugin Name: Bimbler Minglers
    Plugin URI: http://www.bimblers.com
    Description: Plugin to implement functionality to support multuple event categories. i.e. deal with Mingler events.
    Author: Paul Perkins
    Version: 0.1
    Author URI: http://www.bimblers.com
    */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
        die;
} // end if

require_once( plugin_dir_path( __FILE__ ) . 'class-bimbler-minglers.php' );

Bimbler_Minglers::get_instance();
