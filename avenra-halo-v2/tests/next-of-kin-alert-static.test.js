'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = (file) => fs.readFileSync(path.join(__dirname, '..', 'includes', file), 'utf8');
const rest = read('class-halo-v2-rest.php');
const emergency = read('class-halo-v2-emergency.php');
const bridge = read('class-halo-v2-legacy-bridge.php');

function phpMethod(source, signature, nextSignature) {
	const start = source.indexOf(signature);
	assert.notEqual(start, -1, `${signature} must exist`);
	const end = source.indexOf(nextSignature, start + signature.length);
	assert.notEqual(end, -1, `${nextSignature} must follow ${signature}`);
	return source.slice(start, end);
}

// Errors that prove the V1 callback never ran. Any other legacy failure may
// already have submitted a message, so Halo must not send a second one.
const SAFE_TO_RETRY = ['legacy_action_missing', 'legacy_action_disabled', 'legacy_action_not_allowed'];

test('the legacy bridge can be asked whether anything is listening', () => {
	const method = phpMethod(bridge, 'public function has_handler(', 'public function dispatch(');
	assert.match(method, /has_filter\( 'avenra_halo_v2_legacy_bridge_result' \)/);
	assert.match(method, /has_action\( 'wp_ajax_' \. \$action \)/);
	assert.match(method, /has_action\( 'wp_ajax_nopriv_' \. \$action \)/);
});

test('a safety alert falls back to Halo\'s own SMS transport when V1 is absent', () => {
	const method = phpMethod(rest, 'private function perform_safety_alert(', 'private function body(');
	assert.match(method, /\$bridge->has_handler\( \$legacy_action \)/, 'the bridge is only dispatched when it can answer');
	assert.match(
		method,
		/Avenra_Halo_V2_Emergency::instance\(\)->send_next_of_kin_sms\( \$customer, \$kind, \$payload \)/,
		'a V2-only site must still have a next-of-kin transport'
	);

	const guard = method.indexOf('! in_array( $legacy->get_error_code()');
	assert.ok(guard > 0, 'a legacy failure must be classified before any fallback');
	for (const code of SAFE_TO_RETRY) {
		assert.ok(method.includes(`'${code}'`), `${code} must be listed as safe to re-send`);
	}
	const unavailable = method.indexOf("'alert_provider_unavailable'", guard);
	const fallback = method.indexOf('send_next_of_kin_sms', guard);
	assert.ok(unavailable > guard && unavailable < fallback, 'any other legacy failure still reports unavailable');
});

test('the responder and next-of-kin messages share one SMS adapter', () => {
	const responder = phpMethod(emergency, 'private function deliver_responder_sms(', 'private function send_sms(');
	const transport = phpMethod(emergency, 'private function send_sms(', 'public function send_next_of_kin_sms(');
	assert.match(responder, /return \$this->send_sms\(/, 'the responder path uses the shared transport');
	assert.match(transport, /apply_filters\( 'avenra_halo_v2_emergency_sms_delivery', null, \$context \)/);
	assert.match(transport, /self::FIRETEXT_ENDPOINT/);
	assert.match(transport, /'provider_not_configured'/);
	assert.equal(
		(emergency.match(/self::FIRETEXT_ENDPOINT,/g) || []).length,
		1,
		'there must be exactly one FireText submission in the plugin'
	);
});

test('the next-of-kin sender re-checks consent and labels a test unmistakably', () => {
	const method = phpMethod(emergency, 'public function send_next_of_kin_sms(', '/** @return array{state:string');
	assert.match(method, /has_nok_alert_consent\( \$customer_id \)/, 'consent is rechecked at the transport');
	assert.match(method, /nok_alert_not_enabled/);
	assert.match(method, /nok_mobile_invalid/);
	assert.match(method, /TEST - NO EMERGENCY/, 'a test message can never read as a real incident');
	assert.match(method, /alert_provider_not_configured/, 'an unconfigured provider is reported as configuration, not a retry');
	assert.match(method, /nok_alert_unconfirmed/, 'an ambiguous provider response is never reported as sent');

	const testBranch = method.indexOf('TEST - NO EMERGENCY');
	const mapLink = method.indexOf('$this->osm_url(');
	assert.ok(mapLink > testBranch, 'only a real incident carries the location');
});

test('the responder-driven next-of-kin notification has the same fallback', () => {
	const method = phpMethod(emergency, 'private function notify_next_of_kin(', 'private function record_nok_result(');
	assert.match(method, /send_nok_crash_alert_v2/, 'V1 stays the preferred transport');
	assert.match(method, /\$this->send_next_of_kin_sms\( \$customer, 'crash', \$payload \)/);
	for (const code of SAFE_TO_RETRY) {
		assert.ok(method.includes(`'${code}'`), `${code} must be listed as safe to re-send`);
	}
	assert.match(method, /emergency_call_required/, '999 must still be recorded first');
	assert.match(method, /test_action_blocked/, 'a test exercise never contacts a next of kin');
});

test('a next-of-kin failure is not reported as a retryable outage by default', () => {
	const handler = phpMethod(rest, 'public function test_safety_alert(', 'public function record_incident_candidate(');
	assert.match(handler, /'nok_alert_not_enabled', 'nok_mobile_invalid'/, 'a profile problem is a 409, not a 503');
	assert.match(handler, /array_key_exists\( 'retryable', \$data \)/, 'the sender decides whether a retry helps');
});

test('the administrator can see whether a direct next-of-kin SMS is possible', () => {
	const status = phpMethod(emergency, 'public function provider_status(', 'public function encryption_status(');
	assert.match(status, /'nok_direct_sms'\s*=>\s*\$override \|\| \$firetext/);
});
