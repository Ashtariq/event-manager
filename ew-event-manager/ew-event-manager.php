<?php
/**
 * Plugin Name: EW Event Manager
 * Description: Lightweight events plugin: custom post type, meta box, settings page, cached  REST endpoint and shortcode. Usage: [ewem_events per_page="5" upcoming="true"].
 * Version:     1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Ayesha
 * License:     GPL-2.0-or-later
 * Text Domain: ew-event-manager
 *
 * @package EWEM
 */

namespace EWEM;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EWEM_VERSION', '1.1.0' );
define( 'EWEM_CPT', 'ewem_event' );
define( 'EWEM_META_DATE', '_ewem_date' );
define( 'EWEM_META_LOCATION', '_ewem_location' );
define( 'EWEM_CACHE_VERSION_OPTION', 'ewem_cache_version' );

/**
 * Cache layer.
 *
 * Why: transients are keyed with a "version" number. Instead of hunting down
 * every cached key when an event changes, we bump the version once and all
 * old entries become unreachable (they expire naturally).
 */
class Cache {

	const TTL = HOUR_IN_SECONDS;

	/**
	 * Current cache version.
	 *
	 * @return int
	 */
	private static function version() {
		return (int) get_option( EWEM_CACHE_VERSION_OPTION, 1 );
	}

	/**
	 * Build a cache key for a set of arguments.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function key( array $args ) {
		return 'ewem_' . self::version() . '_' . md5( wp_json_encode( $args ) );
	}

	/**
	 * Invalidate all cached event queries.
	 *
	 * @return void
	 */
	public static function flush() {
		update_option( EWEM_CACHE_VERSION_OPTION, self::version() + 1, false );
	}
}

/**
 * Data access. Single place that talks to WP_Query so the REST endpoint
 * and the shortcode always return identical data.
 */
class Repository {

	/**
	 * Get events (cached).
	 *
	 * @param int  $per_page Number of events (1-50).
	 * @param bool $upcoming Only events dated today or later.
	 * @return array[] List of normalised event arrays.
	 */
	public static function get_events( $per_page = 10, $upcoming = true ) {
		$per_page = max( 1, min( 50, (int) $per_page ) );
		$args     = array(
			'per_page' => $per_page,
			'upcoming' => (bool) $upcoming,
		);

		$key    = Cache::key( $args );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$query_args = array(
			'post_type'              => EWEM_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'meta_key'               => EWEM_META_DATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'                => 'meta_value',
			'order'                  => 'ASC',
			'no_found_rows'          => true, // Skip the expensive COUNT query.
			'update_post_term_cache' => false,
		);

		if ( $args['upcoming'] ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => EWEM_META_DATE,
					'value'   => wp_date( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			);
		}

		$events = array();
		$query  = new \WP_Query( $query_args );

		foreach ( $query->posts as $post ) {
			$events[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'link'     => get_permalink( $post ),
				'date'     => (string) get_post_meta( $post->ID, EWEM_META_DATE, true ),
				'location' => (string) get_post_meta( $post->ID, EWEM_META_LOCATION, true ),
			);
		}

		set_transient( $key, $events, Cache::TTL );

		return $events;
	}
}

/**
 * Registers the Event post type.
 */
class Post_Type {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Register the CPT.
	 *
	 * @return void
	 */
	public function register() {
		register_post_type(
			EWEM_CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Events', 'ew-event-manager' ),
					'singular_name' => __( 'Event', 'ew-event-manager' ),
					'add_new_item'  => __( 'Add New Event', 'ew-event-manager' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-calendar-alt',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'events' ),
			)
		);
	}

	/**
	 * Register meta with sanitisation, so every write path (REST, code) is safe.
	 *
	 * @return void
	 */
	public function register_meta() {
		register_post_meta(
			EWEM_CPT,
			EWEM_META_DATE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_date' ),
				'auth_callback'     => array( $this, 'can_edit' ),
			)
		);
		register_post_meta(
			EWEM_CPT,
			EWEM_META_LOCATION,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'can_edit' ),
			)
		);
	}

	/**
	 * Accept only valid Y-m-d dates.
	 *
	 * @param string $value Raw value.
	 * @return string Valid date or empty string.
	 */
	public function sanitize_date( $value ) {
		$date = \DateTime::createFromFormat( 'Y-m-d', sanitize_text_field( $value ) );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Permission check for meta edits.
	 *
	 * @return bool
	 */
	public function can_edit() {
		return current_user_can( 'edit_posts' );
	}
}

/**
 * Admin meta box for date and location.
 */
class Meta_Box {

	const NONCE_ACTION = 'ewem_save_event';
	const NONCE_NAME   = 'ewem_nonce';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . EWEM_CPT, array( $this, 'save' ) );
	}

	/**
	 * Add the meta box.
	 *
	 * @return void
	 */
	public function add() {
		add_meta_box(
			'ewem_details',
			__( 'Event Details', 'ew-event-manager' ),
			array( $this, 'render' ),
			EWEM_CPT,
			'side'
		);
	}

	/**
	 * Render fields. Everything printed is escaped.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( $post ) {
		$date     = get_post_meta( $post->ID, EWEM_META_DATE, true );
		$location = get_post_meta( $post->ID, EWEM_META_LOCATION, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label for="ewem_date"><?php esc_html_e( 'Date', 'ew-event-manager' ); ?></label><br>
			<input type="date" id="ewem_date" name="ewem_date" value="<?php echo esc_attr( $date ); ?>">
		</p>
		<p>
			<label for="ewem_location"><?php esc_html_e( 'Location', 'ew-event-manager' ); ?></label><br>
			<input type="text" id="ewem_location" name="ewem_location" class="widefat" value="<?php echo esc_attr( $location ); ?>">
		</p>
		<?php
	}

	/**
	 * Save fields after verifying nonce, autosave status and capability.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ewem_date'] ) ) {
			update_post_meta( $post_id, EWEM_META_DATE, sanitize_text_field( wp_unslash( $_POST['ewem_date'] ) ) );
		}
		if ( isset( $_POST['ewem_location'] ) ) {
			update_post_meta( $post_id, EWEM_META_LOCATION, sanitize_text_field( wp_unslash( $_POST['ewem_location'] ) ) );
		}
	}
}

/**
 * Public, read-only REST endpoint: GET /wp-json/ewem/v1/events
 */
class Rest_Controller {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route with validated, sanitised arguments.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'ewem/v1',
			'/events',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_events' ),
				// Public data, so open read access is intentional and explicit.
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'upcoming' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Endpoint callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_events( \WP_REST_Request $request ) {
		$events = Repository::get_events(
			$request->get_param( 'per_page' ),
			rest_sanitize_boolean( $request->get_param( 'upcoming' ) )
		);
		return rest_ensure_response( $events );
	}
}

/**
 * Shortcode: [ewem_events per_page="5" upcoming="true"]
 */
class Shortcode {

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( 'ewem_events', array( $this, 'render' ) );
	}

	/**
	 * Render the list.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page' => Settings::get_per_page(),
				'upcoming' => 'true',
			),
			$atts,
			'ewem_events'
		);

		$events = Repository::get_events( absint( $atts['per_page'] ), rest_sanitize_boolean( $atts['upcoming'] ) );

		if ( empty( $events ) ) {
			return '<p>' . esc_html__( 'No events found.', 'ew-event-manager' ) . '</p>';
		}

		$html = '<ul class="ewem-events">';
		foreach ( $events as $event ) {
			$html .= sprintf(
				'<li><a href="%1$s">%2$s</a> <span class="ewem-date">%3$s</span> <span class="ewem-location">%4$s</span></li>',
				esc_url( $event['link'] ),
				esc_html( $event['title'] ),
				esc_html( $event['date'] ),
				esc_html( $event['location'] )
			);
		}
		$html .= '</ul>';

		return $html;
	}
}

/**
 * Settings page (Events > Settings) using the Settings API.
 *
 * Why: lets site owners control the default number of events in the
 * shortcode without touching code.
 */
class Settings {

	const OPTION = 'ewem_default_per_page';
	const GROUP  = 'ewem_settings';
	const PAGE   = 'ewem-settings';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Default events per page (falls back to 5).
	 *
	 * @return int
	 */
	public static function get_per_page() {
		return max( 1, min( 50, (int) get_option( self::OPTION, 5 ) ) );
	}

	/**
	 * Add the submenu page under Events.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . EWEM_CPT,
			__( 'Event Settings', 'ew-event-manager' ),
			__( 'Settings', 'ew-event-manager' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Register setting, section and field.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'integer',
				'default'           => 5,
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section( 'ewem_main', '', '__return_false', self::PAGE );

		add_settings_field(
			self::OPTION,
			__( 'Default events in shortcode', 'ew-event-manager' ),
			array( $this, 'render_field' ),
			self::PAGE,
			'ewem_main',
			array( 'label_for' => self::OPTION )
		);
	}

	/**
	 * Keep value between 1 and 50.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize( $value ) {
		return max( 1, min( 50, absint( $value ) ) );
	}

	/**
	 * Render the number field.
	 *
	 * @return void
	 */
	public function render_field() {
		printf(
			'<input type="number" min="1" max="50" id="%1$s" name="%1$s" value="%2$d" class="small-text">',
			esc_attr( self::OPTION ),
			esc_attr( self::get_per_page() )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

/**
 * Bootstrap.
 */
class Plugin {

	/**
	 * Wire everything together.
	 *
	 * @return void
	 */
	public function run() {
		( new Post_Type() )->register_hooks();
		( new Meta_Box() )->register_hooks();
		( new Rest_Controller() )->register_hooks();
		( new Shortcode() )->register_hooks();
		( new Settings() )->register_hooks();

		// Any change to an event (save, trash, delete) invalidates the cache.
		add_action( 'save_post_' . EWEM_CPT, array( Cache::class, 'flush' ) );
		add_action( 'deleted_post', array( Cache::class, 'flush' ) );
		add_action( 'trashed_post', array( Cache::class, 'flush' ) );
	}

	/**
	 * Activation: register CPT then flush rewrite rules once.
	 *
	 * @return void
	 */
	public static function activate() {
		( new Post_Type() )->register();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: clean rewrite rules.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

( new Plugin() )->run();
