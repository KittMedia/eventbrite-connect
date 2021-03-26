<?php
namespace KittMedia\Eventbrite_Connect;
use WP_Query;
use function add_action;
use function add_shortcode;
use function array_merge;
use function date;
use function dirname;
use function error_log;
use function esc_html__;
use function file_get_contents;
use function get_template_part;
use function is_string;
use function json_decode;
use function json_last_error;
use function load_plugin_textdomain;
use function ob_get_clean;
use function ob_start;
use function plugin_basename;
use function register_activation_hook;
use function register_deactivation_hook;
use function register_post_meta;
use function register_post_type;
use function strpos;
use function strtotime;
use function unlink;
use function unserialize;
use function wp_clear_scheduled_hook;
use function wp_insert_post;
use function wp_next_scheduled;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_schedule_event;
use function wp_upload_bits;
use const EVENTBRITE_CONNECT_TOKEN;
use const PHP_EOL;

/**
 * Get Events from Eventbrite via API to a custom post type.
 * 
 * @author		KittMedia
 * @license		GPL2
 */
class Eventbrite_Connect {
	/**
	 * @var		string The full path to the main plugin file
	 */
	public $plugin_file = '';
	
	/**
	 * Eventbrite_Connect constructor.
	 * 
	 * @param	string		$plugin_file The path of the main plugin file
	 */
	public function __construct( $plugin_file ) {
		// assign variables
		$this->plugin_file = $plugin_file;
	}
	
	/**
	 * Initial load of the class.
	 */
	public final function load() {
		// hook into cron
		add_action( 'eventbrite_connect_hourly_cron_hook', [ $this, 'event_hourly_cron' ] );
		// activate|deactivate cron
		register_activation_hook( $this->plugin_file, [ $this, 'hourly_cron_activation' ] );
		register_deactivation_hook( $this->plugin_file, [ $this, 'hourly_cron_deactivation' ] );
		// set textdomain
		add_action( 'init', [ $this, 'load_textdomain' ] );
		// register post type
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_post_meta' ] );
		// shortcode
		add_shortcode( 'eventbrite_connect', [ $this, 'add_shortcode' ] );
	}
	
	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'eventbrite-connect', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}
	
	/**
	 * Activate the hourly cron.
	 */
	public function hourly_cron_activation() {
		if ( ! wp_next_scheduled( 'eventbrite_connect_hourly_cron_hook' ) ) {
			wp_schedule_event( time(), 'hourly', 'eventbrite_connect_hourly_cron_hook' );
		}
	}
	
	/**
	 * Deactivate the hourly cron.
	 */
	public function hourly_cron_deactivation() {
		if ( wp_next_scheduled( 'eventbrite_connect_hourly_cron_hook' ) ) {
			wp_clear_scheduled_hook( 'eventbrite_connect_hourly_cron_hook' );
		}
	}
	
	/**
	 * Delete all Eventbrite events including their post meta.
	 * 
	 * Run this only in cron mode as it may take a while.
	 */
	private function delete_events() {
		global $wpdb;
		
		// delete all cover images
		$sql = "SELECT		meta_value
				FROM		" . $wpdb->prefix . "postmeta AS meta
				WHERE		meta.meta_key = 'eventbrite_event_cover'";
		$results = $wpdb->get_results( $sql );
		
		foreach ( $results as $result ) {
			if ( ! isset( $result->meta_value ) ) continue;
			
			$image_data = @unserialize( $result->meta_value );
			// delete the actual file
			unlink( $image_data['file'] );
		}
		
		// delete all posts by post type
		$sql = "DELETE		post,
							meta
				FROM		" . $wpdb->prefix . "posts AS post
				LEFT JOIN	" . $wpdb->prefix . "postmeta AS meta
				ON			meta.post_id = post.ID
				WHERE		post.post_type = 'events'";
		
		$wpdb->query( $sql );
	}
	
	/**
	 * Get new events regularly.
	 */
	public function event_hourly_cron() {
		// get new events
		$events = $this->get_events();
		
		// stop if there are no events
		if ( $events === false ) {
			return;
		}
		
		// get additional information before deleting old events
		foreach ( $events->events as &$event ) {
			// get ticket information
			$event = $this->get_event_information( $event, 'ticket_availability' );
			// get venue information
			$event = $this->get_event_information( $event, 'venue' );
		}
		
		// delete old events
		$this->delete_events();
		
		// insert events as custom post type
		foreach ( $events->events as $event ) {
			// store cover image
			$upload_file = wp_upload_bits( $event->id . '.jpg', null, file_get_contents( $event->logo->url ) );
			$event->_wp_cover = $upload_file;
			
			// sorting default
			$sorting = 99;
			
			// get sorting
			if ( strpos( $event->name->text, 'Gesamte' ) !== false ) {
				if ( strpos( $event->name->text, 'Frühlingsreihe' ) !== false ) {
					$sorting = 5;
				}
				else if ( strpos( $event->name->text, 'Pfingstreihe' ) !== false ) {
					$sorting = 6;
				}
				else if ( strpos( $event->name->text, 'Sommerreihe' ) !== false ) {
					$sorting = 7;
				}
			}
			else if ( strpos( $event->name->text, 'Frühlingsreihe' ) !== false ) {
				$sorting = 1;
			}
			else if ( strpos( $event->name->text, 'Pfingstreihe' ) !== false ) {
				$sorting = 2;
			}
			else if ( strpos( $event->name->text, 'Sommerreihe' ) !== false ) {
				$sorting = 3;
			}
			
			$post_args = [
				'post_title' => $event->name->text,
				'post_status' => ( $event->status === 'live' ? 'publish' : 'draft' ),
				'post_type' => 'events',
				'meta_input' => [
					'eventbrite_event_address' => $event->venue->address->localized_address_display,
					'eventbrite_event_cover' => $event->_wp_cover,
					'eventbrite_event_location_name' => $event->venue->name,
					'eventbrite_event_is_free' => $event->is_free,
					'eventbrite_event_price_max' => $event->ticket_availability->maximum_ticket_price->major_value,
					'eventbrite_event_price_min' => $event->ticket_availability->minimum_ticket_price->major_value,
					'eventbrite_event_price_currency' => $event->currency,
					'eventbrite_event_sorting' => $sorting,
					'eventbrite_event_time' => date( 'H:i', strtotime( $event->start->local ) ) . ' – ' . date( 'H:i', strtotime( $event->end->local ) ),
					'eventbrite_event_timestamp' => strtotime( $event->start->local ),
					'eventbrite_event_url' => $event->url,
				],
			];
			
			// add post
			wp_insert_post( $post_args );
		}
	}
	
	/**
	 * Get specific additional information of an event.
	 * 
	 * @param	object		$event The event
	 * @param	string		$information The additional information
	 * @return	object The updated event object
	 */
	private function get_event_information( $event, $information = '' ) {
		$url = 'https://www.eventbriteapi.com/v3/events/' . $event->id . '/' . ( $information ? '?expand=' . $information : '' );
		$event_information = $this->request( $url );
		
		// stop here if there is no additional event information
		if ( $event_information === false ) return $event;
		
		$event = (object) array_merge( (array) $event, (array) $event_information );
		
		return $event;
	}
	
	/**
	 * Get events from the Eventbrite API.
	 * 
	 * @return	array|false
	 */
	private function get_events() {
		$organization_id = $this->get_organization_id();
		
		if ( ! $organization_id ) {
			return false;
		}
		
		$url = 'https://www.eventbriteapi.com/v3/organizations/' . $organization_id . '/events/';
		
		return $this->request( $url );
	}
	
	/**
	 * Get the organization ID.
	 * 
	 * @return	int|false The organization ID or false on failure
	 */
	private function get_organization_id() {
		$url = 'https://www.eventbriteapi.com/v3/users/me/organizations/';
		$response = $this->request( $url );
		
		if ( empty( $response->organizations ) ) {
			return false;
		}
		
		$organization = reset( $response->organizations );
		
		if ( ! empty( $organization->id ) ) {
			return (int) $organization->id;
		}
		
		return false;
	}
	
	/**
	 * Request the Eventbrite API.
	 * 
	 * @param	string		$url The URL to request
	 * @return	array|false
	 */
	private function request( $url ) {
		if ( ! defined( 'EVENTBRITE_CONNECT_TOKEN' ) ) {
			return false;
		}
		
		$request = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . EVENTBRITE_CONNECT_TOKEN,
			],
		] );
		$response = wp_remote_retrieve_body( $request );
		
		// return if response is no valid JSON
		if ( ! self::is_json( $response ) ) return false;
		
		$json = json_decode( $response );
		
		// return if response contains errors
		if ( ! empty( $json->errors ) ) {
			error_log( 'Request: ' . $url . PHP_EOL );
			error_log( print_r( $json, true ) );
			
			return false;
		}
		
		return $json;
	}
	
	/**
	 * Check if given string is a valid JSON.
	 * 
	 * @param	string		$string
	 * @return	bool
	 */
	protected static function is_json( $string ) {
		if ( ! is_string( $string ) ) return false;
		
		json_decode( $string );
		
		return ( json_last_error() === JSON_ERROR_NONE );
	}
	
	/**
	 * Register the needed post meta.
	 */
	public function register_post_meta() {
		register_post_meta(
			'events',
			'eventbrite_event_address',
			[
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_cover',
			[
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_location_name',
			[
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_time',
			[
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_is_free',
			[
				'type' => 'boolean',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_price_max',
			[
				'type' => 'float',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_price_min',
			[
				'type' => 'float',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_price_currency',
			[
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_sorting',
			[
				'type' => 'integer',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_timestamp',
			[
				'type' => 'integer',
				'single' => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'events',
			'eventbrite_event_url',
			[
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			]
		);
	}
	
	/**
	 * Register the post type.
	 */
	public function register_post_type() {
		$post_type_args = [
			'labels' => [
				'name' => esc_html__( 'Eventbrite events', 'eventbrite-connect' ),
				'singular_name' => esc_html__( 'Eventbrite event', 'eventbrite-connect' ),
				'menu_name' => esc_html__( 'Eventbrite events', 'eventbrite-connect' ),
			],
			'public' => false,
			'show_ui' => false,
			'show_in_rest' => true,
			'supports' => [
				'title',
				'custom-fields',
			],
			'rest_base' => 'events',
			'capability_type' => 'page',
		];
		
		register_post_type( 'events', $post_type_args );
	}
	
	/**
	 * Eventbrite Connect shortcode.
	 * 
	 * @return	false|string The shortcode content
	 */
	public function add_shortcode() {
		$args = [
			'meta_key' => 'eventbrite_event_sorting',
			'order' => 'ASC',
			'orderby' => [
				'eventbrite_event_sorting' => 'ASC',
				'eventbrite_event_timestamp' => 'ASC',
			],
			'posts_per_page' => 20,
			'post_status' => 'publish',
			'post_type' => 'events',
		];
		$query = new WP_Query( $args );
		
		ob_start();
		echo '<div class="eventbrite-connect-container">';
		
		while ( $query->have_posts() ) {
			$query->the_post();
			get_template_part('template-parts/content', 'events' );
		}
		
		echo '</div>';
		
		return ob_get_clean();
	}
}
