<?php
namespace KittMedia\Eventbrite_Connect;

/*
Plugin Name:	Eventbrite Connect
Description:	Connect Eventbrite with your WordPress.
Version:		0.1
Author:			KittMedia
Author URI:		https://kittmedia.com
License URI:	https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:	eventbrite-connect
Domain Path:	/languages

Eventbrite Connect is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Eventbrite Connect is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Eventbrite Connect. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

// exit if ABSPATH is not defined
defined( 'ABSPATH' ) || exit;


/**
 * Autoload all necessary classes.
 * 
 * @param	string		$class The class name of the auto-loaded class
 */
\spl_autoload_register( function( $class ) {
	$path = \explode( '\\', $class );
	$filename = \str_replace( '_', '-', \strtolower( \array_pop( $path ) ) );
	$class = \str_replace(
		[ 'kittmedia\eventbrite_connect\\', '\\', '_' ],
		[ '', '/', '-' ],
		\strtolower( $class )
	);
	$class = \str_replace( $filename, 'class-' . $filename, $class );
	$maybe_file = __DIR__ . '/inc/' . $class . '.php';
	
	if ( \file_exists( $maybe_file ) ) {
		require_once( __DIR__ . '/inc/' . $class . '.php' );
	}
} );

$eventbrite_connect = new Eventbrite_Connect( __FILE__ );
$eventbrite_connect->load();
