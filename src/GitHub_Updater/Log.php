<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen, Bjorn Wijers
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

namespace Fragen\GitHub_Updater;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Log {

  /** 
   * Write to log file using PHP's error_log() function
   * but only if WP_DEBUG is set to true, otherwise do nothing.
   *
   * @param string message
   * @return bool || null TRUE upon success, FALSE upon failure, NULL upon 
   * WP_DEBUG not enabled  
   **/
  public static function write2log( $msg ) {
    if( true === WP_DEBUG ) {
      return error_log( $msg );  
    } else {
      return;  
    }
  }   
}
