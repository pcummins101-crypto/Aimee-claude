<?php
/**
 * Public Hyperlane leaderboard storage and REST API.
 *
 * The leaderboard deliberately stores no account details, raw player key,
 * IP address or user-agent string. A short-lived keyed fingerprint is used
 * only for request throttling and one-use run-token validation.
 *
 * @package Avenra_Hyperlane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Avenra_Hyperlane_Leaderboard {
	const DB_VERSION            = '7';
	const DB_VERSION_OPTION     = 'avenra_hyperlane_db_version';
	const SEASON_OPTION         = 'avenra_hyperlane_leaderboard_season';
	const CACHE_GENERATION      = 'avenra_hyperlane_leaderboard_cache_generation';
	const DEFAULT_SEASON        = 3;
	const REST_NAMESPACE        = 'avenra-hyperlane/v1';
	const RUN_TOKEN_TTL         = 600;
	const MINIMUM_RUN_SECONDS   = 80;
	const LEADERBOARD_CACHE_TTL = 30;
	const DEFAULT_ROUTE         = 'city';
	const ROUTES                = array( 'city', 'rural', 'motorway' );
	const STANDARD_RUN_TYPE     = 'standard';
	const WEEKLY_RUN_TYPE       = 'weekly';
	const WEEKLY_HANDLING_PROFILE = 'road';
	const SECTION_MINIMUM_TIMES = array(
		1 => 25,
		2 => 55,
		3 => 80,
	);
	const SECTION_DURATION_BOUNDS = array(
		1 => array( 27000, 30000 ),
		2 => array( 57000, 60000 ),
		3 => array( 88000, 92000 ),
	);
	const SECTION_SCORE_BASE_CEILINGS = array(
		1 => 3500,
		2 => 7000,
		3 => 11000,
	);

	/**
	 * Register schema, privacy and REST hooks.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade_schema' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Return the current site's leaderboard table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'avenra_hyperlane_scores';
	}

	/**
	 * Create or upgrade the current site's leaderboard schema.
	 */
	public static function install_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_hash char(64) NOT NULL,
			season smallint(5) unsigned NOT NULL DEFAULT 3,
			route_id varchar(12) NOT NULL DEFAULT 'city',
			challenge_id varchar(12) NOT NULL DEFAULT '',
			sections_completed tinyint(3) unsigned NOT NULL DEFAULT 3,
			nickname varchar(20) NOT NULL,
			score int(10) unsigned NOT NULL DEFAULT 0,
			top_speed smallint(5) unsigned NOT NULL DEFAULT 0,
			passes smallint(5) unsigned NOT NULL DEFAULT 0,
			near_misses smallint(5) unsigned NOT NULL DEFAULT 0,
			max_flow decimal(4,2) unsigned NOT NULL DEFAULT 1.00,
			ride_mode tinyint(3) unsigned NOT NULL DEFAULT 3,
			time_of_day varchar(8) NOT NULL DEFAULT 'night',
			weather varchar(16) NOT NULL DEFAULT 'rain',
			graphics_tier varchar(12) NOT NULL DEFAULT 'smooth',
			duration_ms int(10) unsigned NOT NULL DEFAULT 90000,
			target_132 tinyint(1) unsigned NOT NULL DEFAULT 0,
			is_hidden tinyint(1) unsigned NOT NULL DEFAULT 0,
			achieved_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY player_season_route_challenge (player_hash,season,route_id,challenge_id),
			KEY leaderboard (season,is_hidden,score,achieved_at),
			KEY leaderboard_progress (season,is_hidden,sections_completed,score,achieved_at),
			KEY leaderboard_route (season,route_id,is_hidden,sections_completed,score,achieved_at),
			KEY leaderboard_route_challenge (season,route_id,challenge_id,is_hidden,sections_completed,score,achieved_at)
		) {$charset_collate};";

		dbDelta( $sql );
		$installed_table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);
		if ( $table_name !== $installed_table ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane leaderboard database table could not be created.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		$progress_column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				'sections_completed'
			)
		);
		if ( 'sections_completed' !== $progress_column ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane leaderboard progress upgrade could not be completed.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		$route_column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				'route_id'
			)
		);
		if ( 'route_id' !== $route_column ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane route leaderboard upgrade could not be completed.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		$challenge_column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				'challenge_id'
			)
		);
		if ( 'challenge_id' !== $challenge_column ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane Weekly Works storage upgrade could not be completed.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		// Cinematic is longer than the original eight-character column. Verify
		// dbDelta preserved that non-destructive widening during the DB6 upgrade.
		$graphics_column = $wpdb->get_row(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				'graphics_tier'
			)
		);
		$graphics_width = 0;
		if ( $graphics_column && isset( $graphics_column->Type ) && preg_match( '/\Avarchar\((\d+)\)\z/i', (string) $graphics_column->Type, $graphics_matches ) ) {
			$graphics_width = (int) $graphics_matches[1];
		}
		if ( $graphics_width < 9 ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane graphics-tier storage upgrade could not be completed.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		// Post-Rain requires nine characters. Confirm the DB7 migration widened
		// the legacy weather column before marking the schema current.
		$weather_column = $wpdb->get_row(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table_name} LIKE %s",
				'weather'
			)
		);
		$weather_width = 0;
		if ( $weather_column && isset( $weather_column->Type ) && preg_match( '/\Avarchar\((\d+)\)\z/i', (string) $weather_column->Type, $weather_matches ) ) {
			$weather_width = (int) $weather_matches[1];
		}
		if ( $weather_width < 9 ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane weather storage upgrade could not be completed.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		// dbDelta adds the challenge-aware key but deliberately does not remove
		// older uniqueness constraints. Drop them only after the new key has
		// been verified so an interrupted upgrade can never allow duplicate
		// rows for one browser, season, route and challenge.
		$new_unique = $wpdb->get_var(
			"SHOW INDEX FROM {$table_name} WHERE Key_name = 'player_season_route_challenge' AND Non_unique = 0"
		);
		if ( null === $new_unique ) {
			$wpdb->query(
				"ALTER TABLE {$table_name} ADD UNIQUE KEY player_season_route_challenge (player_hash,season,route_id,challenge_id)"
			);
			$new_unique = $wpdb->get_var(
				"SHOW INDEX FROM {$table_name} WHERE Key_name = 'player_season_route_challenge' AND Non_unique = 0"
			);
		}

		if ( null !== $new_unique ) {
			foreach ( array( 'player_season_route', 'player_season' ) as $legacy_unique_name ) {
				$legacy_unique = $wpdb->get_var(
					$wpdb->prepare(
						"SHOW INDEX FROM {$table_name} WHERE Key_name = %s AND Non_unique = 0",
						$legacy_unique_name
					)
				);
				if ( null !== $legacy_unique ) {
					$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX {$legacy_unique_name}" );
				}
			}
		}

		$new_unique = $wpdb->get_var(
			"SHOW INDEX FROM {$table_name} WHERE Key_name = 'player_season_route_challenge' AND Non_unique = 0"
		);
		$old_route_unique = $wpdb->get_var(
			"SHOW INDEX FROM {$table_name} WHERE Key_name = 'player_season_route' AND Non_unique = 0"
		);
		$old_season_unique = $wpdb->get_var(
			"SHOW INDEX FROM {$table_name} WHERE Key_name = 'player_season' AND Non_unique = 0"
		);
		if ( null === $new_unique || null !== $old_route_unique || null !== $old_season_unique ) {
			set_transient(
				'avenra_hyperlane_install_error',
				'The Hyperlane Weekly Works leaderboard index upgrade could not be completed.',
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		$installed_season = get_option( self::SEASON_OPTION, false );
		if ( false === $installed_season ) {
			add_option( self::SEASON_OPTION, self::DEFAULT_SEASON, '', false );
		} elseif ( (int) $installed_season < self::DEFAULT_SEASON ) {
			// Start the new rules in Season 3 without altering historical score rows.
			update_option( self::SEASON_OPTION, self::DEFAULT_SEASON, false );
		}
		if ( false === get_option( self::CACHE_GENERATION, false ) ) {
			add_option( self::CACHE_GENERATION, 1, '', false );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Run schema upgrades on public as well as administrative requests.
	 */
	public static function maybe_upgrade_schema() {
		if ( self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			return;
		}
		self::install_schema();
	}

	/**
	 * Return the exact REST discovery URL used by the static game.
	 *
	 * @return string
	 */
	public static function get_config_url() {
		return rest_url( self::REST_NAMESPACE . '/config' );
	}

	/**
	 * Build the authoritative Weekly Works descriptor for an ISO week in UTC.
	 *
	 * Route order deliberately rotates City, Rural and Motorway. Every other
	 * field is derived from the public challenge ID, so all browsers receive
	 * exactly the same setup without any stored scheduler state.
	 *
	 * @param int|null $timestamp Unix timestamp used to identify the week.
	 * @return array
	 */
	private static function current_weekly_challenge( $timestamp = null ) {
		$now          = null === $timestamp ? time() : max( 0, (int) $timestamp );
		$year         = (int) gmdate( 'Y', $now );
		$month        = (int) gmdate( 'n', $now );
		$day          = (int) gmdate( 'j', $now );
		$day_start    = gmmktime( 0, 0, 0, $month, $day, $year );
		$weekday      = (int) gmdate( 'N', $now );
		$week_start   = $day_start - ( ( $weekday - 1 ) * DAY_IN_SECONDS );
		$week_end     = $week_start + WEEK_IN_SECONDS;
		$challenge_id = gmdate( 'o', $week_start ) . '-W' . gmdate( 'W', $week_start );
		$hash         = hash( 'sha256', 'avenra-hyperlane-weekly-works-v1|' . $challenge_id );
		$week_index   = intdiv( $week_start, WEEK_IN_SECONDS );
		$route_index  = ( ( $week_index % count( self::ROUTES ) ) + count( self::ROUTES ) ) % count( self::ROUTES );
		$times        = array( 'day', 'night' );
		$weather      = array( 'clear', 'rain' );
		$seed         = (int) hexdec( substr( $hash, 0, 7 ) );

		if ( 0 === $seed ) {
			$seed = 1;
		}

		return array(
			'challengeId'     => $challenge_id,
			'runType'         => self::WEEKLY_RUN_TYPE,
			'routeId'         => self::ROUTES[ $route_index ],
			'timeOfDay'       => $times[ hexdec( substr( $hash, 7, 2 ) ) % count( $times ) ],
			'weather'         => $weather[ hexdec( substr( $hash, 9, 2 ) ) % count( $weather ) ],
			'rideMode'        => 3,
			'handlingProfile' => self::WEEKLY_HANDLING_PROFILE,
			'seed'            => $seed,
			'startsAt'        => gmdate( 'c', $week_start ),
			'endsAt'          => gmdate( 'c', $week_end ),
		);
	}

	/**
	 * Register public read routes and tightly validated anonymous write routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_config' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_create_run' ),
				'permission_callback' => array( __CLASS__, 'write_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/scores',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_submit_score' ),
				'permission_callback' => array( __CLASS__, 'write_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/leaderboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_leaderboard' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/leave',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_leave' ),
				'permission_callback' => array( __CLASS__, 'write_permission' ),
			)
		);
	}

	/**
	 * Require anonymous writes to originate from the REST API's exact origin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public static function write_permission( $request ) {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $content_length > 4096 ) {
			return new WP_Error( 'ahl_payload_too_large', 'The request is too large.', array( 'status' => 413 ) );
		}

		$request_origin = get_http_origin();
		if ( ! $request_origin ) {
			$request_origin = $request->get_header( 'referer' );
		}

		$expected_origin = self::normalise_origin( rest_url() );
		$actual_origin   = self::normalise_origin( $request_origin );
		if ( ! $actual_origin || ! $expected_origin || ! hash_equals( $expected_origin, $actual_origin ) ) {
			return new WP_Error( 'ahl_origin_rejected', 'This request did not originate from the Hyperlane site.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Return endpoint discovery and public leaderboard rules.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_config() {
		$weekly_works = self::current_weekly_challenge();
		$weekly_end   = strtotime( $weekly_works['endsAt'] );
		$cache_ttl    = $weekly_end ? max( 0, min( 300, $weekly_end - time() ) ) : 0;
		$response     = new WP_REST_Response(
			array(
				'enabled'          => self::schema_is_ready(),
				'season'           => self::current_season(),
				'routeId'          => self::DEFAULT_ROUTE,
				'defaultRoute'     => self::DEFAULT_ROUTE,
				'routes'           => self::ROUTES,
				'minimumRunTime'   => self::MINIMUM_RUN_SECONDS,
				'sectionMinimumTimes' => self::SECTION_MINIMUM_TIMES,
				'nicknameMin'      => 2,
				'nicknameMax'      => 20,
				'leaderboardLimit' => 100,
				'graphicsTiers'    => array( 'smooth', 'enhanced', 'ultra', 'cinematic' ),
				'weeklyWorks'      => $weekly_works,
				'privacyUrl'       => get_privacy_policy_url(),
				'endpoints'        => array(
					'runs'        => rest_url( self::REST_NAMESPACE . '/runs' ),
					'scores'      => rest_url( self::REST_NAMESPACE . '/scores' ),
					'leaderboard' => rest_url( self::REST_NAMESPACE . '/leaderboard' ),
					'leave'       => rest_url( self::REST_NAMESPACE . '/leave' ),
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=' . $cache_ttl );
		return $response;
	}

	/**
	 * Issue a short-lived one-use run token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create_run( $request ) {
		$payload = self::json_payload( $request, array( 'routeId', 'runType', 'challengeId' ), array() );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$requested_type = array_key_exists( 'runType', $payload )
			? $payload['runType']
			: ( array_key_exists( 'challengeId', $payload ) && '' !== $payload['challengeId'] ? self::WEEKLY_RUN_TYPE : self::STANDARD_RUN_TYPE );
		$run_type = self::validate_run_type( $requested_type );
		if ( is_wp_error( $run_type ) ) {
			return $run_type;
		}

		$weekly_works = null;
		$challenge_id = '';
		if ( self::WEEKLY_RUN_TYPE === $run_type ) {
			if ( ! array_key_exists( 'challengeId', $payload ) ) {
				return new WP_Error( 'ahl_challenge_required', 'Choose the current Weekly Works challenge before starting.', array( 'status' => 400 ) );
			}
			$challenge_id = self::validate_challenge_id( $payload['challengeId'] );
			if ( is_wp_error( $challenge_id ) ) {
				return $challenge_id;
			}

			$weekly_works = self::current_weekly_challenge();
			if ( ! hash_equals( $weekly_works['challengeId'], $challenge_id ) ) {
				return new WP_Error( 'ahl_challenge_stale', 'That Weekly Works challenge has closed. Refresh the game for the current run.', array( 'status' => 409 ) );
			}
		} elseif ( array_key_exists( 'challengeId', $payload ) && '' !== $payload['challengeId'] ) {
			return new WP_Error( 'ahl_challenge_unexpected', 'A standard ride cannot use a Weekly Works challenge ID.', array( 'status' => 400 ) );
		}

		$default_route = $weekly_works ? $weekly_works['routeId'] : self::DEFAULT_ROUTE;
		$route_id      = self::validate_route_id(
			array_key_exists( 'routeId', $payload ) ? $payload['routeId'] : $default_route
		);
		if ( is_wp_error( $route_id ) ) {
			return $route_id;
		}
		if ( $weekly_works && ! hash_equals( $weekly_works['routeId'], $route_id ) ) {
			return new WP_Error( 'ahl_challenge_route_mismatch', 'The selected route does not belong to this Weekly Works challenge.', array( 'status' => 409 ) );
		}
		if ( ! self::schema_is_ready() ) {
			return new WP_Error( 'ahl_schema_unavailable', 'The leaderboard upgrade is still in progress. Please try again shortly.', array( 'status' => 503 ) );
		}

		$created_at        = time();
		$challenge_ends_at = $weekly_works ? strtotime( $weekly_works['endsAt'] ) : 0;
		$full_route_time   = (int) ceil( self::SECTION_DURATION_BOUNDS[3][1] / 1000 );
		if ( $weekly_works && ( ! $challenge_ends_at || $created_at + $full_route_time >= $challenge_ends_at ) ) {
			return new WP_Error( 'ahl_challenge_closing', 'This Weekly Works challenge is closing before another full ride can qualify.', array( 'status' => 409 ) );
		}
		$token_expires_at = $challenge_ends_at
			? min( $created_at + self::RUN_TOKEN_TTL, $challenge_ends_at )
			: $created_at + self::RUN_TOKEN_TTL;

		$rate = self::take_rate_limit( 'runs', 30, HOUR_IN_SECONDS );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		try {
			$token = self::base64url_encode( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'ahl_token_unavailable', 'A run token could not be created.', array( 'status' => 500 ) );
		}

		set_transient(
			self::run_transient_key( $token ),
			array(
				'created_at'        => $created_at,
				'fingerprint'       => self::request_fingerprint(),
				'season'            => self::current_season(),
				'route_id'          => $route_id,
				'run_type'          => $run_type,
				'challenge_id'      => $challenge_id,
				'time_of_day'       => $weekly_works ? $weekly_works['timeOfDay'] : '',
				'weather'           => $weekly_works ? $weekly_works['weather'] : '',
				'ride_mode'         => $weekly_works ? $weekly_works['rideMode'] : 0,
				'handling_profile'  => $weekly_works ? $weekly_works['handlingProfile'] : '',
				'seed'              => $weekly_works ? $weekly_works['seed'] : 0,
				'challenge_ends_at' => $challenge_ends_at ? (int) $challenge_ends_at : 0,
			),
			max( 1, $token_expires_at - $created_at )
		);

		$response_data = array(
			'runToken'            => $token,
			'season'              => self::current_season(),
			'routeId'             => $route_id,
			'runType'             => $run_type,
			'challengeId'         => $challenge_id,
			'startedAt'           => gmdate( 'c', $created_at ),
			'expiresAt'           => gmdate( 'c', $token_expires_at ),
			'minimumTime'         => self::MINIMUM_RUN_SECONDS,
			'sectionMinimumTimes' => self::SECTION_MINIMUM_TIMES,
		);
		if ( $weekly_works ) {
			$response_data['timeOfDay']         = $weekly_works['timeOfDay'];
			$response_data['weather']           = $weekly_works['weather'];
			$response_data['rideMode']          = $weekly_works['rideMode'];
			$response_data['handlingProfile']   = $weekly_works['handlingProfile'];
			$response_data['seed']              = $weekly_works['seed'];
			$response_data['challengeStartsAt'] = $weekly_works['startsAt'];
			$response_data['challengeEndsAt']   = $weekly_works['endsAt'];
		}

		$response = new WP_REST_Response(
			$response_data,
			201
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Validate and persist a banked checkpoint or completed route score.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_submit_score( $request ) {
		$required = array(
			'runToken', 'playerKey', 'nickname', 'completed', 'durationMs', 'score',
			'topSpeed', 'passes', 'nearMisses', 'maxFlow', 'rideMode', 'timeOfDay',
			'weather', 'graphicsTier', 'target132',
		);
		$allowed  = array_merge( $required, array( 'outcome', 'sectionsCompleted', 'routeId', 'runType', 'challengeId', 'seed', 'handlingProfile' ) );
		$payload  = self::json_payload( $request, $allowed, $required );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$validated = self::validate_score_payload( $payload );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( ! self::schema_is_ready() ) {
			return new WP_Error( 'ahl_schema_unavailable', 'The leaderboard upgrade is still in progress. Please try again shortly.', array( 'status' => 503 ) );
		}

		$rate = self::take_rate_limit( 'scores', 15, HOUR_IN_SECONDS );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$token_key = self::run_transient_key( $validated['run_token'] );
		$run       = get_transient( $token_key );
		if ( ! is_array( $run ) || empty( $run['created_at'] ) || empty( $run['fingerprint'] ) || empty( $run['season'] ) ) {
			return new WP_Error( 'ahl_run_invalid', 'This run token is invalid or has expired.', array( 'status' => 409 ) );
		}

		// Consume before verification so every issued run token is genuinely one-use.
		delete_transient( $token_key );

		if ( ! hash_equals( (string) $run['fingerprint'], self::request_fingerprint() ) ) {
			return new WP_Error( 'ahl_run_mismatch', 'This run token belongs to a different game session.', array( 'status' => 403 ) );
		}

		$run_route = isset( $run['route_id'] ) ? (string) $run['route_id'] : self::DEFAULT_ROUTE;
		if ( ! hash_equals( $run_route, $validated['route_id'] ) ) {
			return new WP_Error( 'ahl_run_route_mismatch', 'This run token belongs to a different Hyperlane route.', array( 'status' => 409 ) );
		}

		$run_type = isset( $run['run_type'] ) ? (string) $run['run_type'] : self::STANDARD_RUN_TYPE;
		if ( ! hash_equals( $run_type, $validated['run_type'] ) ) {
			return new WP_Error( 'ahl_run_type_mismatch', 'This run token belongs to a different ride type.', array( 'status' => 409 ) );
		}

		$run_challenge_id = isset( $run['challenge_id'] ) ? (string) $run['challenge_id'] : '';
		if ( ! hash_equals( $run_challenge_id, $validated['challenge_id'] ) ) {
			return new WP_Error( 'ahl_run_challenge_mismatch', 'This run token belongs to a different Weekly Works challenge.', array( 'status' => 409 ) );
		}

		if ( self::WEEKLY_RUN_TYPE === $run_type ) {
			$locked_time_of_day = isset( $run['time_of_day'] ) ? (string) $run['time_of_day'] : '';
			$locked_weather     = isset( $run['weather'] ) ? (string) $run['weather'] : '';
			$locked_ride_mode   = isset( $run['ride_mode'] ) ? (int) $run['ride_mode'] : 0;
			$locked_handling    = isset( $run['handling_profile'] ) ? (string) $run['handling_profile'] : '';
			$locked_seed        = isset( $run['seed'] ) ? (int) $run['seed'] : 0;
			if (
				! hash_equals( $locked_time_of_day, $validated['time_of_day'] )
				|| ! hash_equals( $locked_weather, $validated['weather'] )
				|| $locked_ride_mode !== $validated['ride_mode']
				|| ! hash_equals( $locked_handling, $validated['handling_profile'] )
				|| $locked_seed !== $validated['seed']
			) {
				return new WP_Error( 'ahl_challenge_setup_mismatch', 'The ride setup does not match this Weekly Works challenge.', array( 'status' => 409 ) );
			}

			$challenge_ends_at = isset( $run['challenge_ends_at'] ) ? (int) $run['challenge_ends_at'] : 0;
			if ( $challenge_ends_at <= 0 || time() >= $challenge_ends_at ) {
				return new WP_Error( 'ahl_challenge_closed', 'This Weekly Works challenge has closed.', array( 'status' => 409 ) );
			}
		}

		$elapsed         = time() - (int) $run['created_at'];
		$minimum_seconds = self::SECTION_MINIMUM_TIMES[ $validated['sections_completed'] ];
		if ( $elapsed < $minimum_seconds ) {
			return new WP_Error( 'ahl_run_too_short', 'The run reached this checkpoint too quickly to qualify.', array( 'status' => 409 ) );
		}
		if ( $elapsed > self::RUN_TOKEN_TTL ) {
			return new WP_Error( 'ahl_run_expired', 'This run token has expired.', array( 'status' => 409 ) );
		}

		$season      = max( 1, (int) $run['season'] );
		$player_hash = self::player_hash( $validated['player_key'] );
		$result      = self::upsert_score( $season, $player_hash, $validated );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Return the public leaderboard page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_leaderboard( $request ) {
		$page                = self::positive_query_integer( $request->get_param( 'page' ), 1, 1, 10000 );
		$per_page            = self::positive_query_integer( $request->get_param( 'per_page' ), 50, 1, 100 );
		$requested_type      = $request->get_param( 'runType' );
		$requested_challenge = $request->get_param( 'challengeId' );
		if ( null === $requested_type || '' === $requested_type ) {
			$requested_type = null !== $requested_challenge && '' !== $requested_challenge
				? self::WEEKLY_RUN_TYPE
				: self::STANDARD_RUN_TYPE;
		}
		$run_type = self::validate_run_type( $requested_type );
		if ( is_wp_error( $run_type ) ) {
			return $run_type;
		}

		$weekly_works = null;
		$challenge_id = '';
		if ( self::WEEKLY_RUN_TYPE === $run_type ) {
			$weekly_works = self::current_weekly_challenge();
			$challenge_id = null === $requested_challenge || '' === $requested_challenge
				? $weekly_works['challengeId']
				: self::validate_challenge_id( $requested_challenge );
			if ( is_wp_error( $challenge_id ) ) {
				return $challenge_id;
			}
			if ( ! hash_equals( $weekly_works['challengeId'], $challenge_id ) ) {
				return new WP_Error( 'ahl_challenge_stale', 'Only the current Weekly Works leaderboard is available.', array( 'status' => 409 ) );
			}
		} elseif ( null !== $requested_challenge && '' !== $requested_challenge ) {
			return new WP_Error( 'ahl_challenge_unexpected', 'A standard leaderboard cannot use a Weekly Works challenge ID.', array( 'status' => 400 ) );
		}

		$route_parameter = $request->get_param( 'route' );
		if ( null === $route_parameter || '' === $route_parameter ) {
			if ( $weekly_works ) {
				$route_parameter = $weekly_works['routeId'];
			} else {
				$route_parameter = self::DEFAULT_ROUTE;
			}
		}
		$route_id = self::validate_route_id(
			$route_parameter
		);
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( is_wp_error( $per_page ) ) {
			return $per_page;
		}
		if ( is_wp_error( $route_id ) ) {
			return $route_id;
		}
		if (
			$weekly_works
			&& ! hash_equals( $weekly_works['routeId'], $route_id )
		) {
			return new WP_Error( 'ahl_challenge_route_mismatch', 'The selected route does not belong to this Weekly Works challenge.', array( 'status' => 400 ) );
		}
		if ( ! self::schema_is_ready() ) {
			return new WP_Error( 'ahl_schema_unavailable', 'The leaderboard upgrade is still in progress. Please try again shortly.', array( 'status' => 503 ) );
		}

		$data = self::get_leaderboard_page( $page, $per_page, $route_id, $challenge_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $data['total'] );
		$response->header( 'X-WP-TotalPages', (string) $data['totalPages'] );
		$response->header( 'Cache-Control', 'public, max-age=' . self::LEADERBOARD_CACHE_TTL );
		return $response;
	}

	/**
	 * Remove every season belonging to the requesting browser's private key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_leave( $request ) {
		$payload = self::json_payload( $request, array( 'playerKey' ), array( 'playerKey' ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( ! is_string( $payload['playerKey'] ) || ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $payload['playerKey'] ) ) {
			return new WP_Error( 'ahl_player_key_invalid', 'The private game key is invalid.', array( 'status' => 400 ) );
		}

		$rate = self::take_rate_limit( 'leave', 10, HOUR_IN_SECONDS );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		global $wpdb;
		$removed = $wpdb->delete(
			self::table_name(),
			array( 'player_hash' => self::player_hash( $payload['playerKey'] ) ),
			array( '%s' )
		);
		if ( false === $removed ) {
			return new WP_Error( 'ahl_leave_failed', 'The leaderboard entry could not be removed.', array( 'status' => 500 ) );
		}

		if ( $removed > 0 ) {
			self::invalidate_cache();
		}
		$response = new WP_REST_Response( array( 'removed' => (int) $removed ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Public server-side helper for the landing page.
	 *
	 * @param int    $limit Maximum number of results, between 1 and 100.
	 * @param string $route_id Route leaderboard to read.
	 * @return array
	 */
	public static function get_top_scores( $limit = 10, $route_id = self::DEFAULT_ROUTE ) {
		if ( ! self::schema_is_ready() ) {
			return array();
		}
		$limit = max( 1, min( 100, (int) $limit ) );
		$route_id = self::validate_route_id( $route_id );
		if ( is_wp_error( $route_id ) ) {
			return array();
		}
		$data  = self::get_leaderboard_page( 1, $limit, $route_id );
		return is_wp_error( $data ) ? array() : $data['entries'];
	}

	/**
	 * Query a cached leaderboard page.
	 *
	 * @param int $page Page number.
	 * @param int $per_page Results per page.
	 * @param string $route_id Route leaderboard to read.
	 * @param string $challenge_id Weekly Works challenge, or an empty string for standard rides.
	 * @return array|WP_Error
	 */
	private static function get_leaderboard_page( $page, $per_page, $route_id, $challenge_id = '' ) {
		$season     = self::current_season();
		$generation = max( 1, (int) get_option( self::CACHE_GENERATION, 1 ) );
		$board_id   = '' === $challenge_id ? self::STANDARD_RUN_TYPE : $challenge_id;
		$cache_key  = 'ahl_board_' . $generation . '_' . $season . '_' . $route_id . '_' . $board_id . '_' . $page . '_' . $per_page;
		$cached     = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table  = self::table_name();
		$total  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE season = %d AND route_id = %s AND challenge_id = %s AND is_hidden = 0",
				$season,
				$route_id,
				$challenge_id
			)
		);
		$offset = ( $page - 1 ) * $per_page;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, route_id, challenge_id, sections_completed, nickname, score, top_speed, passes, near_misses, max_flow, ride_mode, time_of_day, weather, graphics_tier, duration_ms, target_132, achieved_at
				FROM {$table}
				WHERE season = %d AND route_id = %s AND challenge_id = %s AND is_hidden = 0
				ORDER BY sections_completed DESC, score DESC, achieved_at ASC, id ASC
				LIMIT %d OFFSET %d",
				$season,
				$route_id,
				$challenge_id,
				$per_page,
				$offset
			)
		);
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'ahl_leaderboard_unavailable', 'The leaderboard is temporarily unavailable.', array( 'status' => 503 ) );
		}

		$entries = array();
		foreach ( $rows as $index => $row ) {
			$entries[] = self::public_entry( $row, $offset + $index + 1 );
		}

		$data = array(
			'season'      => $season,
			'routeId'     => $route_id,
			'runType'     => '' === $challenge_id ? self::STANDARD_RUN_TYPE : self::WEEKLY_RUN_TYPE,
			'challengeId' => $challenge_id,
			'page'        => $page,
			'perPage'     => $per_page,
			'total'       => $total,
			'totalPages'  => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'entries'     => $entries,
		);
		set_transient( $cache_key, $data, self::LEADERBOARD_CACHE_TTL );
		return $data;
	}

	/**
	 * Insert or update one browser's best score for a season.
	 *
	 * @param int    $season Season number.
	 * @param string $player_hash One-way player identifier.
	 * @param array  $score Validated score payload.
	 * @return array|WP_Error
	 */
	private static function upsert_score( $season, $player_hash, $score ) {
		global $wpdb;
		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sections_completed, score FROM {$table} WHERE player_hash = %s AND season = %d AND route_id = %s AND challenge_id = %s LIMIT 1",
				$player_hash,
				$season,
				$score['route_id'],
				$score['challenge_id']
			)
		);
		$new_best = ! $existing
			|| $score['sections_completed'] > (int) $existing->sections_completed
			|| (
				$score['sections_completed'] === (int) $existing->sections_completed
				&& $score['score'] > (int) $existing->score
			);

		$query = $wpdb->prepare(
			"INSERT INTO {$table}
			(player_hash, season, route_id, challenge_id, sections_completed, nickname, score, top_speed, passes, near_misses, max_flow, ride_mode, time_of_day, weather, graphics_tier, duration_ms, target_132, achieved_at, updated_at)
			VALUES (%s, %d, %s, %s, %d, %s, %d, %d, %d, %d, %f, %d, %s, %s, %s, %d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
			nickname = VALUES(nickname),
			top_speed = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(top_speed), top_speed),
			passes = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(passes), passes),
			near_misses = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(near_misses), near_misses),
			max_flow = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(max_flow), max_flow),
			ride_mode = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(ride_mode), ride_mode),
			time_of_day = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(time_of_day), time_of_day),
			weather = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(weather), weather),
			graphics_tier = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(graphics_tier), graphics_tier),
			duration_ms = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(duration_ms), duration_ms),
			target_132 = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(target_132), target_132),
			achieved_at = IF(VALUES(sections_completed) > sections_completed OR (VALUES(sections_completed) = sections_completed AND VALUES(score) > score), VALUES(achieved_at), achieved_at),
			updated_at = VALUES(updated_at),
			score = IF(VALUES(sections_completed) > sections_completed, VALUES(score), IF(VALUES(sections_completed) = sections_completed, GREATEST(score, VALUES(score)), score)),
			sections_completed = GREATEST(sections_completed, VALUES(sections_completed))",
			$player_hash,
			$season,
			$score['route_id'],
			$score['challenge_id'],
			$score['sections_completed'],
			$score['nickname'],
			$score['score'],
			$score['top_speed'],
			$score['passes'],
			$score['near_misses'],
			$score['max_flow'],
			$score['ride_mode'],
			$score['time_of_day'],
			$score['weather'],
			$score['graphics_tier'],
			$score['duration_ms'],
			$score['target_132'] ? 1 : 0,
			$now,
			$now
		);
		if ( false === $wpdb->query( $query ) ) {
			return new WP_Error( 'ahl_score_save_failed', 'The score could not be saved.', array( 'status' => 500 ) );
		}

		self::invalidate_cache();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, route_id, challenge_id, sections_completed, nickname, score, top_speed, passes, near_misses, max_flow, ride_mode, time_of_day, weather, graphics_tier, duration_ms, target_132, achieved_at
				FROM {$table} WHERE player_hash = %s AND season = %d AND route_id = %s AND challenge_id = %s LIMIT 1",
				$player_hash,
				$season,
				$score['route_id'],
				$score['challenge_id']
			)
		);
		if ( ! $row ) {
			return new WP_Error( 'ahl_score_read_failed', 'The saved score could not be read.', array( 'status' => 500 ) );
		}

		$rank = self::rank_for_row( $row, $season, $score['route_id'], $score['challenge_id'] );
		return array(
			'accepted'    => true,
			'newBest'     => (bool) $new_best,
			'personalRank' => $rank,
			'entry'       => self::public_entry( $row, $rank ),
		);
	}

	/**
	 * Calculate an entry's exact positional rank using stable tie ordering.
	 *
	 * @param object $row Score row.
	 * @param int    $season Season number.
	 * @param string $route_id Route leaderboard being ranked.
	 * @param string $challenge_id Weekly Works challenge, or an empty string for standard rides.
	 * @return int
	 */
	private static function rank_for_row( $row, $season, $route_id, $challenge_id = '' ) {
		global $wpdb;
		$table = self::table_name();
		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE season = %d AND route_id = %s AND challenge_id = %s AND is_hidden = 0 AND (
					sections_completed > %d OR
					(sections_completed = %d AND (
						score > %d OR
						(score = %d AND achieved_at < %s) OR
						(score = %d AND achieved_at = %s AND id < %d)
					))
				)",
				$season,
				$route_id,
				$challenge_id,
				(int) $row->sections_completed,
				(int) $row->sections_completed,
				(int) $row->score,
				(int) $row->score,
				(string) $row->achieved_at,
				(int) $row->score,
				(string) $row->achieved_at,
				(int) $row->id
			)
		);
	}

	/**
	 * Convert a database row to a privacy-safe public object.
	 *
	 * @param object $row Score row.
	 * @param int    $rank Public rank.
	 * @return array
	 */
	private static function public_entry( $row, $rank ) {
		$timestamp          = strtotime( (string) $row->achieved_at . ' UTC' );
		$sections_completed = isset( $row->sections_completed ) ? (int) $row->sections_completed : 3;
		$challenge_id       = isset( $row->challenge_id ) ? (string) $row->challenge_id : '';
		return array(
			'rank'              => (int) $rank,
			'nickname'          => (string) $row->nickname,
			'routeId'           => isset( $row->route_id ) ? (string) $row->route_id : self::DEFAULT_ROUTE,
			'runType'           => '' === $challenge_id ? self::STANDARD_RUN_TYPE : self::WEEKLY_RUN_TYPE,
			'challengeId'       => $challenge_id,
			'sectionsCompleted' => max( 1, min( 3, $sections_completed ) ),
			'score'             => (int) $row->score,
			'topSpeed'          => (int) $row->top_speed,
			'passes'            => (int) $row->passes,
			'nearMisses'        => (int) $row->near_misses,
			'maxFlow'           => (float) $row->max_flow,
			'rideMode'          => (int) $row->ride_mode,
			'timeOfDay'         => (string) $row->time_of_day,
			'weather'           => (string) $row->weather,
			'graphicsTier'      => (string) $row->graphics_tier,
			'durationMs'        => (int) $row->duration_ms,
			'target132'         => (bool) $row->target_132,
			'achievedAt'        => $timestamp ? gmdate( 'c', $timestamp ) : '',
		);
	}

	/**
	 * Validate the checkpoint score contract without coercing untrusted values.
	 *
	 * @param array $payload Raw JSON object.
	 * @return array|WP_Error
	 */
	private static function validate_score_payload( $payload ) {
		if ( ! is_string( $payload['runToken'] ) || ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $payload['runToken'] ) ) {
			return new WP_Error( 'ahl_run_token_invalid', 'The run token is invalid.', array( 'status' => 400 ) );
		}
		if ( ! is_string( $payload['playerKey'] ) || ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $payload['playerKey'] ) ) {
			return new WP_Error( 'ahl_player_key_invalid', 'The private game key is invalid.', array( 'status' => 400 ) );
		}
		$nickname = self::validate_nickname( $payload['nickname'] );
		if ( is_wp_error( $nickname ) ) {
			return $nickname;
		}

		$requested_type = array_key_exists( 'runType', $payload )
			? $payload['runType']
			: ( array_key_exists( 'challengeId', $payload ) && '' !== $payload['challengeId'] ? self::WEEKLY_RUN_TYPE : self::STANDARD_RUN_TYPE );
		$run_type = self::validate_run_type( $requested_type );
		if ( is_wp_error( $run_type ) ) {
			return $run_type;
		}

		$challenge_id = '';
		if ( self::WEEKLY_RUN_TYPE === $run_type ) {
			if ( ! array_key_exists( 'challengeId', $payload ) ) {
				return new WP_Error( 'ahl_challenge_required', 'A Weekly Works score must identify its challenge.', array( 'status' => 400 ) );
			}
			$challenge_id = self::validate_challenge_id( $payload['challengeId'] );
			if ( is_wp_error( $challenge_id ) ) {
				return $challenge_id;
			}
			if ( ! array_key_exists( 'routeId', $payload ) ) {
				return new WP_Error( 'ahl_route_required', 'A Weekly Works score must identify its locked route.', array( 'status' => 400 ) );
			}
			if ( ! array_key_exists( 'seed', $payload ) || ! is_int( $payload['seed'] ) || $payload['seed'] < 1 || $payload['seed'] > 0x0fffffff ) {
				return new WP_Error( 'ahl_challenge_seed_invalid', 'The Weekly Works traffic seed is invalid.', array( 'status' => 400 ) );
			}
			if ( ! array_key_exists( 'handlingProfile', $payload ) || ! is_string( $payload['handlingProfile'] ) || ! hash_equals( self::WEEKLY_HANDLING_PROFILE, $payload['handlingProfile'] ) ) {
				return new WP_Error( 'ahl_handling_profile_invalid', 'The Weekly Works handling profile is invalid.', array( 'status' => 400 ) );
			}
		} elseif ( array_key_exists( 'challengeId', $payload ) && '' !== $payload['challengeId'] ) {
			return new WP_Error( 'ahl_challenge_unexpected', 'A standard score cannot use a Weekly Works challenge ID.', array( 'status' => 400 ) );
		} elseif ( array_key_exists( 'seed', $payload ) ) {
			return new WP_Error( 'ahl_challenge_seed_unexpected', 'A standard score cannot use a Weekly Works traffic seed.', array( 'status' => 400 ) );
		} elseif ( array_key_exists( 'handlingProfile', $payload ) ) {
			return new WP_Error( 'ahl_handling_profile_unexpected', 'A standard score cannot use a Weekly Works handling profile.', array( 'status' => 400 ) );
		}

		$route_id = self::validate_route_id(
			array_key_exists( 'routeId', $payload ) ? $payload['routeId'] : self::DEFAULT_ROUTE
		);
		if ( is_wp_error( $route_id ) ) {
			return $route_id;
		}
		$sections_completed = array_key_exists( 'sectionsCompleted', $payload ) ? $payload['sectionsCompleted'] : 3;
		if ( ! is_int( $sections_completed ) || ! in_array( $sections_completed, array( 1, 2, 3 ), true ) ) {
			return new WP_Error( 'ahl_sections_completed_invalid', 'The submitted route checkpoint is invalid.', array( 'status' => 400 ) );
		}
		$outcome          = array_key_exists( 'outcome', $payload ) ? $payload['outcome'] : 'complete';
		$expected_outcome = 3 === $sections_completed ? 'complete' : 'checkpoint';
		if ( ! is_string( $outcome ) || $outcome !== $expected_outcome ) {
			return new WP_Error( 'ahl_run_outcome_invalid', 'The ride outcome is inconsistent with this checkpoint.', array( 'status' => 400 ) );
		}
		if ( ! is_bool( $payload['completed'] ) || $payload['completed'] !== ( 3 === $sections_completed ) ) {
			return new WP_Error( 'ahl_run_completion_invalid', 'The route completion value is inconsistent with this checkpoint.', array( 'status' => 400 ) );
		}
		if ( self::WEEKLY_RUN_TYPE === $run_type && 3 !== $sections_completed ) {
			return new WP_Error( 'ahl_weekly_completion_required', 'Weekly Works scores qualify only after the full route is completed.', array( 'status' => 400 ) );
		}

		$duration_bounds = self::SECTION_DURATION_BOUNDS[ $sections_completed ];
		if ( ! is_int( $payload['durationMs'] ) || $payload['durationMs'] < $duration_bounds[0] || $payload['durationMs'] > $duration_bounds[1] ) {
			return new WP_Error( 'ahl_durationms_invalid', 'The submitted checkpoint time is outside its valid range.', array( 'status' => 400 ) );
		}

		$integer_bounds = array(
			'score'      => array( 0, 1000000 ),
			'topSpeed'   => array( 0, 132 ),
			'passes'     => array( 0, 400 ),
			'nearMisses' => array( 0, 400 ),
			'rideMode'   => array( 1, 3 ),
		);
		foreach ( $integer_bounds as $key => $bounds ) {
			if ( ! is_int( $payload[ $key ] ) || $payload[ $key ] < $bounds[0] || $payload[ $key ] > $bounds[1] ) {
				return new WP_Error( 'ahl_' . strtolower( $key ) . '_invalid', 'The submitted ride data is outside its valid range.', array( 'status' => 400 ) );
			}
		}
		if ( ! in_array( $payload['rideMode'], array( 1, 2, 3 ), true ) ) {
			return new WP_Error( 'ahl_ride_mode_invalid', 'The selected ride mode is invalid.', array( 'status' => 400 ) );
		}
		if ( $payload['nearMisses'] > $payload['passes'] ) {
			return new WP_Error( 'ahl_pass_count_invalid', 'Near misses cannot exceed total passes.', array( 'status' => 400 ) );
		}

		if ( ! is_int( $payload['maxFlow'] ) && ! is_float( $payload['maxFlow'] ) ) {
			return new WP_Error( 'ahl_flow_invalid', 'The Flow multiplier is invalid.', array( 'status' => 400 ) );
		}
		$max_flow = (float) $payload['maxFlow'];
		if ( ! is_finite( $max_flow ) || $max_flow < 1 || $max_flow > 5 ) {
			return new WP_Error( 'ahl_flow_invalid', 'The Flow multiplier is outside its valid range.', array( 'status' => 400 ) );
		}
		$flow_ceiling = min( 5, 1 + ( $payload['nearMisses'] * 0.25 ) );
		if ( $max_flow > $flow_ceiling + 0.01 ) {
			return new WP_Error( 'ahl_flow_inconsistent', 'The Flow multiplier is inconsistent with this run.', array( 'status' => 400 ) );
		}

		$enums = array(
			'timeOfDay'    => array( 'day', 'dusk', 'night' ),
			'weather'      => array( 'clear', 'rain', 'post-rain', 'storm', 'fog' ),
			'graphicsTier' => array( 'smooth', 'enhanced', 'ultra', 'cinematic' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( ! is_string( $payload[ $key ] ) || ! in_array( $payload[ $key ], $allowed, true ) ) {
				return new WP_Error( 'ahl_' . strtolower( $key ) . '_invalid', 'The submitted ride option is invalid.', array( 'status' => 400 ) );
			}
		}
		if ( ! is_bool( $payload['target132'] ) ) {
			return new WP_Error( 'ahl_target_invalid', 'The 132 mph target value is invalid.', array( 'status' => 400 ) );
		}
		if ( $payload['target132'] && $payload['topSpeed'] < 131 ) {
			return new WP_Error( 'ahl_target_inconsistent', 'The top-speed target is inconsistent with this run.', array( 'status' => 400 ) );
		}

		$score_ceiling = self::SECTION_SCORE_BASE_CEILINGS[ $sections_completed ]
			+ ( $payload['nearMisses'] * 2100 )
			+ ( ( $payload['passes'] - $payload['nearMisses'] ) * 110 );
		if ( $payload['score'] > $score_ceiling ) {
			return new WP_Error( 'ahl_score_inconsistent', 'The score is inconsistent with the submitted ride data.', array( 'status' => 400 ) );
		}

		return array(
			'run_token'          => $payload['runToken'],
			'player_key'         => $payload['playerKey'],
			'nickname'           => $nickname,
			'route_id'           => $route_id,
			'run_type'           => $run_type,
			'challenge_id'       => $challenge_id,
			'seed'               => self::WEEKLY_RUN_TYPE === $run_type ? $payload['seed'] : 0,
			'handling_profile'   => self::WEEKLY_RUN_TYPE === $run_type ? $payload['handlingProfile'] : '',
			'sections_completed' => $sections_completed,
			'duration_ms'        => $payload['durationMs'],
			'score'             => $payload['score'],
			'top_speed'         => $payload['topSpeed'],
			'passes'            => $payload['passes'],
			'near_misses'       => $payload['nearMisses'],
			'max_flow'          => round( $max_flow, 2 ),
			'ride_mode'         => $payload['rideMode'],
			'time_of_day'       => $payload['timeOfDay'],
			'weather'           => $payload['weather'],
			'graphics_tier'     => $payload['graphicsTier'],
			'target_132'        => $payload['target132'],
		);
	}

	/**
	 * Validate a public route identifier without accepting aliases or coercion.
	 *
	 * @param mixed $value Submitted route identifier.
	 * @return string|WP_Error
	 */
	private static function validate_route_id( $value ) {
		if ( ! is_string( $value ) || ! in_array( $value, self::ROUTES, true ) ) {
			return new WP_Error( 'ahl_route_invalid', 'The selected Hyperlane route is invalid.', array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * Validate the public ride type without accepting aliases or coercion.
	 *
	 * @param mixed $value Submitted ride type.
	 * @return string|WP_Error
	 */
	private static function validate_run_type( $value ) {
		if ( ! is_string( $value ) || ! in_array( $value, array( self::STANDARD_RUN_TYPE, self::WEEKLY_RUN_TYPE ), true ) ) {
			return new WP_Error( 'ahl_run_type_invalid', 'The selected ride type is invalid.', array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * Validate an ISO-week Weekly Works identifier.
	 *
	 * @param mixed $value Submitted challenge identifier.
	 * @return string|WP_Error
	 */
	private static function validate_challenge_id( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/\A\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])\z/', $value ) ) {
			return new WP_Error( 'ahl_challenge_invalid', 'The Weekly Works challenge ID is invalid.', array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * Validate, normalise and moderate a player-chosen public nickname.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|WP_Error
	 */
	private static function validate_nickname( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'ahl_nickname_invalid', 'Enter a valid public nickname.', array( 'status' => 400 ) );
		}
		$nickname = wp_check_invalid_utf8( wp_strip_all_tags( $value, true ) );
		$nickname = preg_replace( '/\s+/u', ' ', trim( $nickname ) );
		if ( ! is_string( $nickname ) ) {
			return new WP_Error( 'ahl_nickname_invalid', 'Enter a valid public nickname.', array( 'status' => 400 ) );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $nickname, 'UTF-8' ) : strlen( $nickname );
		if ( $length < 2 || $length > 20 || ! preg_match( "/\A[\p{L}\p{N}](?:[\p{L}\p{N} ._'’\-]*[\p{L}\p{N}])?\z/u", $nickname ) ) {
			return new WP_Error( 'ahl_nickname_invalid', 'Use 2–20 letters or numbers, with simple spaces or punctuation.', array( 'status' => 400 ) );
		}

		$reserved = array( 'admin', 'administrator', 'avenra', 'avenra support', 'halo support', 'emergency assist' );
		$plain    = strtolower( remove_accents( $nickname ) );
		$plain    = preg_replace( '/\s+/', ' ', trim( $plain ) );
		if ( in_array( $plain, $reserved, true ) ) {
			return new WP_Error( 'ahl_nickname_reserved', 'That nickname is reserved.', array( 'status' => 400 ) );
		}

		if ( function_exists( 'wp_check_comment_disallowed_list' ) && wp_check_comment_disallowed_list( $nickname, '', '', $nickname, '', '' ) ) {
			return new WP_Error( 'ahl_nickname_disallowed', 'That nickname is not available.', array( 'status' => 400 ) );
		}

		$allowed = apply_filters( 'avenra_hyperlane_nickname_allowed', true, $nickname );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new WP_Error( 'ahl_nickname_disallowed', 'That nickname is not available.', array( 'status' => 400 ) );
		}
		return $nickname;
	}

	/**
	 * Require an application/json object with only the documented keys.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param array           $allowed Allowed object keys.
	 * @param array           $required Required object keys.
	 * @return array|WP_Error
	 */
	private static function json_payload( $request, $allowed, $required ) {
		$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( 0 !== strpos( $content_type, 'application/json' ) ) {
			return new WP_Error( 'ahl_json_required', 'Send this request as application/json.', array( 'status' => 415 ) );
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || self::is_list_array( $payload ) ) {
			return new WP_Error( 'ahl_json_invalid', 'The request body must be a JSON object.', array( 'status' => 400 ) );
		}
		$unexpected = array_diff( array_keys( $payload ), $allowed );
		if ( ! empty( $unexpected ) ) {
			return new WP_Error( 'ahl_fields_unexpected', 'The request contains unsupported fields.', array( 'status' => 400 ) );
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				return new WP_Error( 'ahl_field_missing', 'The request is missing required ride data.', array( 'status' => 400 ) );
			}
		}
		return $payload;
	}

	/**
	 * PHP 7.4-compatible array_is_list equivalent.
	 *
	 * @param array $value Array to inspect.
	 * @return bool
	 */
	private static function is_list_array( $value ) {
		if ( array() === $value ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Validate a positive integer query argument without coercing junk input.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $default Default when omitted.
	 * @param int   $minimum Minimum value.
	 * @param int   $maximum Maximum value.
	 * @return int|WP_Error
	 */
	private static function positive_query_integer( $value, $default, $minimum, $maximum ) {
		if ( null === $value || '' === $value ) {
			return $default;
		}
		if ( ! is_scalar( $value ) || ! preg_match( '/\A\d+\z/', (string) $value ) ) {
			return new WP_Error( 'ahl_pagination_invalid', 'The leaderboard pagination is invalid.', array( 'status' => 400 ) );
		}
		$value = (int) $value;
		if ( $value < $minimum || $value > $maximum ) {
			return new WP_Error( 'ahl_pagination_invalid', 'The leaderboard pagination is outside its valid range.', array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * Apply an anonymous transient request limit using only a one-way fingerprint.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit Maximum requests.
	 * @param int    $window Window in seconds.
	 * @return true|WP_Error
	 */
	private static function take_rate_limit( $bucket, $limit, $window ) {
		$window_id = (int) floor( time() / $window );
		$key       = 'ahl_rate_' . sanitize_key( $bucket ) . '_' . substr( self::request_fingerprint(), 0, 32 ) . '_' . $window_id;
		$count     = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error( 'ahl_rate_limited', 'Too many leaderboard requests. Please try again later.', array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, $window + MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Generate a short-lived fingerprint without retaining raw request metadata.
	 *
	 * @return string
	 */
	private static function request_fingerprint() {
		$address    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 512 ) : '';
		return hash_hmac( 'sha256', $address . '|' . $user_agent, wp_salt( 'nonce' ) );
	}

	/**
	 * Convert a URL to a comparable scheme/host/port origin.
	 *
	 * @param string $url URL or origin.
	 * @return string
	 */
	private static function normalise_origin( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . $host . ( $port ? ':' . $port : '' );
	}

	/**
	 * Create a transient key from a run token without storing the raw token.
	 *
	 * @param string $token Raw one-use token.
	 * @return string
	 */
	private static function run_transient_key( $token ) {
		return 'ahl_run_' . hash( 'sha256', $token );
	}

	/**
	 * Hash a private browser key before any database use.
	 *
	 * @param string $player_key Raw private browser key.
	 * @return string
	 */
	private static function player_hash( $player_key ) {
		return hash_hmac( 'sha256', $player_key, wp_salt( 'auth' ) );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Random bytes.
	 * @return string
	 */
	private static function base64url_encode( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Current public score season.
	 *
	 * @return int
	 */
	private static function current_season() {
		return max( self::DEFAULT_SEASON, (int) get_option( self::SEASON_OPTION, self::DEFAULT_SEASON ) );
	}

	/**
	 * Confirm that challenge-aware uniqueness and queries are safe to use.
	 *
	 * @return bool
	 */
	private static function schema_is_ready() {
		return self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' );
	}

	/**
	 * Move reads to a fresh transient namespace after a write.
	 */
	private static function invalidate_cache() {
		$generation = max( 1, (int) get_option( self::CACHE_GENERATION, 1 ) );
		update_option( self::CACHE_GENERATION, $generation + 1, false );
	}

	/**
	 * Suggest transparent leaderboard wording to the site's Privacy Policy guide.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Avenrà Hyperlane',
			wp_kses_post(
				'<p>When a player chooses to join the public Hyperlane leaderboard, Avenrà Hyperlane stores the nickname they provide, their best banked checkpoint or completed-route score for each standard route or Weekly Works challenge, the challenge identifier where applicable, summary ride statistics, route progress, the score date, and a one-way identifier derived from a private random game key held in that browser. Collision details and HALO Emergency Assist decisions are not included. The public leaderboard does not display the identifier.</p>' .
				'<p>The plugin does not store the raw game key, IP address or user-agent string. One-way request fingerprints are retained temporarily for run validation and rate limiting. Players can use the in-game leave control on the same browser to delete all of their route entries. Deleting the plugin permanently removes the leaderboard table.</p>'
			)
		);
	}
}
