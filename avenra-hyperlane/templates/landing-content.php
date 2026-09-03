<?php
/**
 * Player-facing Hyperlane landing content.
 *
 * Expected variables: $game_url, $wordmark_url, $city_shot,
 * $desktop_hero_shot, $mobile_hero_shot, $conditions_shot, $halo_shot,
 * $leaderboard_routes and $leaderboard_url.
 *
 * @package Avenra_Hyperlane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$install_url     = add_query_arg( 'intent', 'install', $game_url );
$home_url        = home_url( '/' );
$motorcycles_url = home_url( '/#model-selector' );
$ownership_url   = home_url( '/subscription/' );
$safety_url      = home_url( '/safety/' );
$test_ride_url   = add_query_arg( 'model', 'EVO', home_url( '/test-ride/' ) );
$configure_url   = add_query_arg( 'model', 'EVO', home_url( '/configurator/' ) );
$privacy_url     = home_url( '/privacy-policy/' );
$terms_url       = home_url( '/terms/' );
$progress_labels = array(
	1 => 'CHECKPOINT 1 / 3',
	2 => 'CHECKPOINT 2 / 3',
	3 => 'FULL ROUTE',
);
$route_labels = array(
	'city'     => 'City',
	'rural'    => 'Rural',
	'motorway' => 'Motorway',
);
?>
<div class="ahl" id="ahl-top">
	<nav class="ahl-site-nav" aria-label="Primary navigation">
		<div class="ahl-site-nav-inner">
			<a class="ahl-wordmark" href="<?php echo esc_url( $home_url ); ?>" aria-label="Avenrà home">
				<img src="<?php echo esc_url( $wordmark_url ); ?>" width="409" height="91" alt="Avenrà">
				<span>Hyperlane: The Game</span>
			</a>
			<div class="ahl-site-links">
				<a href="#ahl-experience">The challenge</a>
				<a href="#ahl-leaderboard">Leaderboard</a>
				<a href="#ahl-gallery">Screenshots</a>
				<a href="#ahl-how">How to play</a>
			</div>
			<div class="ahl-nav-actions">
				<a class="ahl-nav-install" href="<?php echo esc_url( $install_url ); ?>" target="_blank" rel="noopener">Install game</a>
				<a class="ahl-nav-play" href="<?php echo esc_url( $game_url ); ?>" target="_blank" rel="noopener">Play game</a>
			</div>
		</div>
	</nav>

	<main id="ahl-main">
		<section class="ahl-hero" aria-labelledby="ahl-title">
			<picture class="ahl-hero-picture">
				<source media="(max-width: 760px)" srcset="<?php echo esc_url( $mobile_hero_shot ); ?>">
				<img class="ahl-hero-image" src="<?php echo esc_url( $desktop_hero_shot ); ?>" alt="Avenrà EVO cockpit riding through the rain-lit Avenrà District" width="1600" height="900" fetchpriority="high">
			</picture>
			<div class="ahl-hero-shade" aria-hidden="true"></div>
			<div class="ahl-hero-content">
				<div class="ahl-game-status" aria-label="Game availability"><span><i></i> Game online</span><span>Free to play</span><span>Browser + PWA</span></div>
				<p class="ahl-eyebrow ahl-eyebrow-light">The official Avenrà browser game</p>
				<h1 id="ahl-title"><span>Hyperlane</span><small>The Game</small></h1>
				<p class="ahl-hero-lead">Ride the EVO. Read the road. Own the run.</p>
				<p class="ahl-hero-copy">Choose City, Rural or M1, then enter a next-generation ninety-second ride with Living Roads traffic, Rider Dynamics 2.0, atmospheric depth and a shared Weekly Works challenge.</p>
				<div class="ahl-game-chips" aria-label="Game highlights"><span><b>3</b> ROUTES</span><span><b>90</b> SEC</span><span><b>132</b> MPH</span></div>
				<div class="ahl-actions">
					<a class="ahl-button ahl-button-primary" href="<?php echo esc_url( $game_url ); ?>" target="_blank" rel="noopener">Play the game</a>
					<a class="ahl-button ahl-button-light" href="<?php echo esc_url( $install_url ); ?>" target="_blank" rel="noopener">Install for offline</a>
				</div>
				<p class="ahl-launch-detail"><span></span> Launches separately in full-screen game mode. Landscape and sound recommended.</p>
			</div>
			<a class="ahl-hero-scroll" href="#ahl-experience" aria-label="Explore Hyperlane"><span></span></a>
		</section>

		<section class="ahl-stat-strip" aria-label="Game highlights">
			<div><strong>90</strong><span>second run</span></div>
			<div><strong>132</strong><span>mph HyperCore boost</span></div>
			<div><strong>3</strong><span>distinct routes</span></div>
			<div><strong>5</strong><span>graphics choices</span></div>
		</section>

		<section class="ahl-section ahl-experience" id="ahl-experience" aria-labelledby="ahl-experience-title">
			<div class="ahl-section-heading ahl-section-heading-split">
				<div>
					<p class="ahl-eyebrow">The challenge</p>
					<h2 id="ahl-experience-title">Three routes. Choose your challenge.</h2>
				</div>
				<p>Every route is a complete ninety-second run shaped by the Living Roads director. Traffic reads the motorcycle and the road around it, developing situations are telegraphed fairly, and the debrief scores Pace, Precision, Awareness, Smoothness and Discipline.</p>
			</div>

			<div class="ahl-route-grid">
				<article class="ahl-route-city">
					<span>01</span>
					<div><p>Intermediate difficulty</p><h3>City</h3><small>The Avenrà District, HALO Tunnel and Racing Expressway, rebuilt with layered depth, moving near-field architecture and traffic that responds to the rider.</small></div>
				</article>
				<article class="ahl-route-rural">
					<span>02</span>
					<div><p>Hardest route</p><h3>Rural</h3><small>Ribblehead, Hawes and Buttertubs Pass with heavier oncoming traffic, 2+1 overtaking sections, country-road hazards and changing visibility.</small></div>
				</article>
				<article class="ahl-route-motorway">
					<span>03</span>
					<div><p>Easiest route</p><h3>M1 Motorway</h3><small>Luton to Leeds with three live lanes, merging traffic, roadworks, persistent gantries and four photoreal service-station set pieces.</small></div>
				</article>
			</div>
		</section>

		<section class="ahl-section ahl-leaderboard-section" id="ahl-leaderboard" aria-labelledby="ahl-leaderboard-title">
			<div class="ahl-section-heading ahl-section-heading-split">
				<div>
					<p class="ahl-eyebrow">Season 3 · Global scores · Weekly Works</p>
					<h2 id="ahl-leaderboard-title">Top riders.</h2>
				</div>
				<p>City, Rural and M1 each have their own fair ranking. The Weekly Works Run gives every rider the same route, weather and traffic seed; public entries still use a chosen rider tag and safely banked progress only.</p>
			</div>
			<div class="ahl-leaderboard-card">
				<div class="ahl-leaderboard-tabs" role="tablist" aria-label="Choose route leaderboard">
					<?php foreach ( $route_labels as $route_id => $route_label ) : ?>
						<button type="button" role="tab" id="ahl-tab-<?php echo esc_attr( $route_id ); ?>" aria-controls="ahl-board-<?php echo esc_attr( $route_id ); ?>" aria-selected="<?php echo 'city' === $route_id ? 'true' : 'false'; ?>" tabindex="<?php echo 'city' === $route_id ? '0' : '-1'; ?>" data-ahl-route-tab="<?php echo esc_attr( $route_id ); ?>"><?php echo esc_html( $route_label ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $route_labels as $route_id => $route_label ) : ?>
					<?php $route_entries = isset( $leaderboard_routes[ $route_id ] ) && is_array( $leaderboard_routes[ $route_id ] ) ? $leaderboard_routes[ $route_id ] : array(); ?>
					<div class="ahl-leaderboard-panel" id="ahl-board-<?php echo esc_attr( $route_id ); ?>" role="tabpanel" aria-labelledby="ahl-tab-<?php echo esc_attr( $route_id ); ?>" data-ahl-route-panel="<?php echo esc_attr( $route_id ); ?>" <?php echo 'city' === $route_id ? '' : 'hidden'; ?>>
						<?php if ( ! empty( $route_entries ) ) : ?>
							<div class="ahl-leaderboard-table-wrap">
								<table class="ahl-leaderboard-table">
									<caption class="screen-reader-text"><?php echo esc_html( 'Avenrà Hyperlane Season 3 ' . $route_label . ' riders ranked by route progress and Works Score' ); ?></caption>
									<thead><tr><th scope="col">Rank</th><th scope="col">Rider / progress</th><th scope="col">Score</th><th scope="col">Top speed</th></tr></thead>
									<tbody>
									<?php foreach ( $route_entries as $entry_index => $entry ) : ?>
										<?php
										$sections_completed = isset( $entry['sectionsCompleted'] ) ? (int) $entry['sectionsCompleted'] : 3;
										$sections_completed = max( 1, min( 3, $sections_completed ) );
										$progress_label      = $progress_labels[ $sections_completed ];
										?>
										<tr>
											<td><span><?php echo esc_html( isset( $entry['rank'] ) ? $entry['rank'] : $entry_index + 1 ); ?></span></td>
											<th scope="row"><?php echo esc_html( isset( $entry['nickname'] ) ? $entry['nickname'] : '' ); ?><br><small><?php echo esc_html( $progress_label ); ?></small></th>
											<td><?php echo esc_html( number_format_i18n( isset( $entry['score'] ) ? (int) $entry['score'] : 0 ) ); ?></td>
											<td><?php echo esc_html( isset( $entry['topSpeed'] ) ? (int) $entry['topSpeed'] : ( isset( $entry['top_speed'] ) ? (int) $entry['top_speed'] : 0 ) ); ?> mph</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php else : ?>
							<div class="ahl-leaderboard-empty"><strong>THE <?php echo esc_html( strtoupper( $route_label ) ); ?> GRID IS OPEN</strong><span>Bank a checkpoint and set the first <?php echo esc_html( $route_label ); ?> score.</span></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<div class="ahl-leaderboard-footer">
					<p>Public entries use a player-chosen rider tag, banked route progress and ride statistics only. Use a nickname—not your real name, email address or phone number.</p>
					<a class="ahl-button ahl-button-primary" href="<?php echo esc_url( $leaderboard_url ); ?>" target="_blank" rel="noopener">View the leaderboard</a>
				</div>
			</div>
		</section>

		<section class="ahl-section ahl-gallery-section" id="ahl-gallery" aria-labelledby="ahl-gallery-title">
			<div class="ahl-section-heading ahl-section-heading-split">
				<div>
					<p class="ahl-eyebrow">Game screens</p>
					<h2 id="ahl-gallery-title">From cockpit to control room.</h2>
				</div>
				<p>Current 16:9 captures from the bundled game. Select any image for a closer look.</p>
			</div>
			<div class="ahl-gallery">
				<button type="button" class="ahl-shot ahl-shot-featured" data-ahl-lightbox-src="<?php echo esc_url( $city_shot ); ?>" data-ahl-lightbox-alt="Avenrà EVO cockpit in the rain-lit Avenrà District">
					<img src="<?php echo esc_url( $city_shot ); ?>" alt="Avenrà EVO cockpit in the rain-lit Avenrà District" width="1600" height="900" loading="lazy">
					<span><b>Avenrà District</b><small>Live EVO instrumentation and two-way traffic</small></span>
				</button>
				<button type="button" class="ahl-shot" data-ahl-lightbox-src="<?php echo esc_url( $conditions_shot ); ?>" data-ahl-lightbox-alt="Hyperlane Ride Setup selector">
					<img src="<?php echo esc_url( $conditions_shot ); ?>" alt="Hyperlane Ride Setup selector" width="1600" height="900" loading="lazy">
					<span><b>Ride setup</b><small>Guided route, conditions, graphics and launch review</small></span>
				</button>
				<button type="button" class="ahl-shot" data-ahl-lightbox-src="<?php echo esc_url( $halo_shot ); ?>" data-ahl-lightbox-alt="HALO Emergency Assist guided operator challenge">
					<img src="<?php echo esc_url( $halo_shot ); ?>" alt="HALO Emergency Assist guided operator challenge" width="1600" height="900" loading="lazy">
					<span><b>HALO operator challenge</b><small>Three guided decisions with a clear route back</small></span>
				</button>
			</div>
		</section>

		<section class="ahl-section ahl-briefing" id="ahl-how" aria-labelledby="ahl-how-title">
			<div class="ahl-briefing-intro">
				<p class="ahl-eyebrow">How to play</p>
				<h2 id="ahl-how-title">Your mission starts here.</h2>
				<p>No account or download is required. Open the game in a new window and follow the short pre-ride setup before launching your run.</p>
				<a class="ahl-text-link" href="<?php echo esc_url( $game_url ); ?>" target="_blank" rel="noopener">Play the game <span aria-hidden="true">→</span></a>
			</div>
			<div class="ahl-briefing-content">
				<ol class="ahl-step-list">
					<li><span>1</span><div><h3>Build your run</h3><p>Follow Route, Conditions and Ready. Pick one of four road conditions—Dry Day, Raining Day, Dry Night or Raining Night—then select the accessible or deeper Rider Dynamics experience. Every ride uses the performance-refined Ultra graphics profile.</p></div></li>
					<li><span>2</span><div><h3>Read the route</h3><p>Watch traffic body language, brake lights, junctions and HALO warnings. The fastest result comes from smooth, disciplined decisions rather than manufactured near misses.</p></div></li>
					<li><span>3</span><div><h3>Review the ride</h3><p>Use the five-part Rider Rating and debrief to see where Pace, Precision, Awareness, Smoothness or Discipline can improve.</p></div></li>
					<li><span>4</span><div><h3>Take the HALO challenge</h3><p>After a collision, the game clearly switches you into the operator role for three guided decisions—or lets you return to the game.</p></div></li>
				</ol>

				<details class="ahl-controls">
					<summary><span><small>Desktop and mobile</small>View controls</span><b aria-hidden="true">+</b></summary>
					<div class="ahl-control-grid">
						<div><strong>Steer</strong><span><kbd>←</kbd> <kbd>A</kbd> / <kbd>→</kbd> <kbd>D</kbd><small>Tap, drag or enable tilt on mobile</small></span></div>
						<div><strong>Brake</strong><span><kbd>↓</kbd> or <kbd>S</kbd><small>Hold Brake on mobile</small></span></div>
						<div><strong>Boost</strong><span>Hold <kbd>Space</kbd><small>Hold Boost on mobile</small></span></div>
						<div><strong>Ride mode</strong><span><kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd><small>60, 90 or 109 mph</small></span></div>
					</div>
				</details>
			</div>
		</section>

		<section class="ahl-section ahl-halo-section" id="ahl-halo" aria-labelledby="ahl-halo-title">
			<div class="ahl-halo-copy">
				<p class="ahl-eyebrow ahl-eyebrow-light">Stay safe — HALO</p>
				<h2 id="ahl-halo-title">The ride ends.<br>The response begins.</h2>
				<p>If the run ends in a collision, the game clearly changes perspective. Step into the role of an Avenrà Emergency Assist operator and follow three guided steps: contact the simulated rider, check the airbag-jacket telemetry and recommend the appropriate response.</p>
				<ul>
					<li>Continue welfare monitoring</li>
					<li>Request a standard ambulance</li>
					<li>Escalate for air ambulance consideration</li>
				</ul>
				<small>Fictional in-game training simulation. No real alert, phone call or emergency-service request is made, and you can return to the game menu at any time.</small>
			</div>
			<div class="ahl-halo-visual">
				<img src="<?php echo esc_url( $halo_shot ); ?>" alt="HALO Emergency Assist incident review console" width="1600" height="900" loading="lazy">
				<span><i></i> Training case active</span>
			</div>
		</section>

		<section class="ahl-site-cta" aria-label="Play Avenrà Hyperlane: The Game">
			<div><p class="ahl-eyebrow">EVO ready</p><h2>Every road is alive.</h2><p>Play instantly, take on this week's shared Works Run or install the PWA for an app-like, offline-ready launch.</p></div>
			<div class="ahl-actions">
				<a class="ahl-button ahl-button-primary" href="<?php echo esc_url( $game_url ); ?>" target="_blank" rel="noopener">Play the game</a>
				<a class="ahl-button ahl-button-light" href="<?php echo esc_url( $install_url ); ?>" target="_blank" rel="noopener">Install PWA</a>
			</div>
		</section>
	</main>

	<footer class="ahl-footer">
		<div class="ahl-footer-grid">
			<div class="ahl-footer-brand">
				<a href="<?php echo esc_url( $home_url ); ?>"><img src="<?php echo esc_url( $wordmark_url ); ?>" width="409" height="91" alt="Avenrà"></a>
				<p><strong>Hyperlane: The Game</strong><br>A free EVO browser challenge by Avenrà.</p>
			</div>
			<div><h3>Explore</h3><a href="<?php echo esc_url( $motorcycles_url ); ?>">Motorcycles</a><a href="<?php echo esc_url( $ownership_url ); ?>">Ownership</a><a href="<?php echo esc_url( $safety_url ); ?>">Safety</a></div>
			<div><h3>The game</h3><a href="<?php echo esc_url( $install_url ); ?>" target="_blank" rel="noopener">Install PWA</a><a href="<?php echo esc_url( $game_url ); ?>" target="_blank" rel="noopener">Play in new window</a><a href="#ahl-leaderboard">Leaderboard</a><a href="#ahl-how">How to play</a></div>
			<div><h3>Visit Avenrà</h3><a href="<?php echo esc_url( $test_ride_url ); ?>">Book a test ride</a><a href="<?php echo esc_url( $configure_url ); ?>">Configure &amp; reserve</a></div>
		</div>
		<div class="ahl-footer-bottom">
			<p>© <?php echo esc_html( wp_date( 'Y' ) ); ?> Avenrà. Hyperlane: The Game is a virtual closed-route entertainment and training simulation.</p>
			<div><a href="<?php echo esc_url( $privacy_url ); ?>">Privacy policy</a><a href="<?php echo esc_url( $terms_url ); ?>">Terms of service</a></div>
		</div>
	</footer>

	<aside class="ahl-mobile-actions" aria-label="Hyperlane game actions">
		<a href="<?php echo esc_url( $install_url ); ?>" target="_blank" rel="noopener">Install game</a>
		<a class="is-primary" href="<?php echo esc_url( $game_url ); ?>" target="_blank" rel="noopener">Play game</a>
	</aside>

	<dialog class="ahl-lightbox" data-ahl-lightbox aria-label="Screenshot viewer">
		<button type="button" class="ahl-lightbox-close" data-ahl-lightbox-close aria-label="Close screenshot">×</button>
		<img src="" alt="">
	</dialog>
</div>
