'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', 'assets', 'js', 'ride-engine.js'),
	'utf8'
);

const GRAVITY = 9.80665;

function loadRideEngine() {
	const document = {
		hidden: false,
		visibilityState: 'visible',
		addEventListener() {},
		removeEventListener() {}
	};
	const window = {
		crypto: { randomUUID: () => 'crash-detection-test' },
		addEventListener() {},
		removeEventListener() {},
		setInterval,
		clearInterval,
		setTimeout,
		clearTimeout
	};
	const context = {
		window,
		document,
		navigator: { onLine: true },
		EventTarget,
		CustomEvent,
		Date,
		Math,
		Promise,
		setInterval,
		clearInterval,
		setTimeout,
		clearTimeout
	};
	vm.runInNewContext(source, context, { filename: 'ride-engine.js' });
	return window;
}

/**
 * A riding engine with the sensors stubbed out. `ridingFor` and `fixAge`
 * control the two arming conditions the detector depends on.
 */
function ridingEngine(options = {}) {
	const settings = Object.assign({ ridingFor: 120000, fixAge: 500, speedMph: 45 }, options);
	const RideEngine = loadRideEngine().AvenraHaloRideEngineClass;
	const engine = new RideEngine({ persistEveryPoints: 1000 });
	const now = Date.now();
	engine.state = 'riding';
	engine.session = {
		id: 'ride-crash-detection',
		startedAt: new Date(now - settings.ridingFor).toISOString(),
		points: [],
		context: {}
	};
	engine.ridingSince = now - settings.ridingFor;
	engine.lastAcceptedAt = now - settings.fixAge;
	engine.lastPosition = { lat: 52.1, lng: -1.2, accuracy: 6, at: now - settings.fixAge };
	engine.currentSpeed = settings.speedMph;
	const candidates = [];
	engine.addEventListener('crashcandidate', (event) => candidates.push(event.detail));
	return { engine, candidates };
}

/** Feed a handset that only exposes accelerationIncludingGravity. */
function feedWithGravity(engine, resultantG, count = 1, interval = 16) {
	for (let index = 0; index < count; index += 1) {
		engine.handleMotion({
			acceleration: null,
			accelerationIncludingGravity: { x: 0, y: 0, z: resultantG * GRAVITY },
			interval
		});
	}
}

/** Feed a handset that exposes gravity-free linear acceleration. */
function feedLinear(engine, dynamicG, count = 1, interval = 16) {
	for (let index = 0; index < count; index += 1) {
		engine.handleMotion({
			acceleration: { x: 0, y: 0, z: dynamicG * GRAVITY },
			accelerationIncludingGravity: { x: 0, y: 0, z: (dynamicG + 1) * GRAVITY },
			interval
		});
	}
}

function settle(engine) {
	feedWithGravity(engine, 1, 60);
}

test('gravity is removed before a sample is compared with a crash threshold', () => {
	const { engine, candidates } = ridingEngine();
	settle(engine);
	assert.equal(engine.peakDynamicG, 0, 'a stationary 1g sample is not dynamic acceleration');

	// 5g resultant including gravity is roughly 4g of real acceleration. The raw
	// resultant alone used to clear the immediate-dispatch bar on its own.
	feedWithGravity(engine, 5, 2);

	assert.equal(candidates.length, 0, 'a 4g road impulse must not dispatch on the accelerometer alone');
	assert.ok(engine.pendingImpact, 'it is still recorded as a possible impact');
	assert.ok(Math.abs(engine.pendingImpact.gForce - 4) < 0.2, `expected ~4 dynamic g, got ${engine.pendingImpact.gForce}`);
	assert.ok(Math.abs(engine.lastAcceleration.resultantG - 5) < 0.01, 'the raw resultant is still kept as evidence');
	engine.cleanupSensors();
});

test('a possible impact still activates when the speed collapses', () => {
	const { engine, candidates } = ridingEngine();
	settle(engine);
	feedWithGravity(engine, 5, 2);
	assert.equal(candidates.length, 0);

	engine.acceptPosition({
		timestamp: Date.now(),
		coords: { latitude: 52.1001, longitude: -1.2, accuracy: 6, altitude: null, heading: 90, speed: 0 }
	});

	assert.equal(candidates.length, 1, 'the corroborating stop opens the cancellation window');
	assert.equal(engine.crashPhase, 'countdown');
	engine.cleanupSensors();
});

test('a severe gravity-free impact still dispatches immediately', () => {
	const { engine, candidates } = ridingEngine();
	settle(engine);
	feedLinear(engine, 7, 2);
	assert.equal(candidates.length, 1);
	assert.equal(engine.crashPhase, 'countdown');
	assert.equal(candidates[0].countdown_seconds, 20);
	engine.cleanupSensors();
});

test('nothing is detected during the settle window at the start of a ride', () => {
	const { engine, candidates } = ridingEngine({ ridingFor: 2000 });
	settle(engine);
	feedLinear(engine, 10, 4);
	assert.equal(candidates.length, 0, 'mounting or stowing the phone must not open an incident');
	assert.equal(engine.pendingImpact, null);
	engine.cleanupSensors();
});

test('a single anomalous sample is treated as a sensor spike', () => {
	const { engine, candidates } = ridingEngine();
	settle(engine);

	feedLinear(engine, 10, 1);
	assert.equal(candidates.length, 0, 'one sample is not an impulse');
	assert.equal(engine.pendingImpact, null);

	feedLinear(engine, 10, 1);
	assert.equal(candidates.length, 1, 'a sustained impulse does dispatch');
	engine.cleanupSensors();
});

test('a stale GPS fix disarms detection rather than reusing the last speed', () => {
	const { engine, candidates } = ridingEngine({ fixAge: 30000 });
	settle(engine);
	feedLinear(engine, 10, 4);
	assert.equal(candidates.length, 0);
	assert.equal(engine.pendingImpact, null);
	engine.cleanupSensors();
});

test('a stationary rider is never a crash candidate', () => {
	const { engine, candidates } = ridingEngine({ speedMph: 4 });
	settle(engine);
	feedLinear(engine, 10, 4);
	assert.equal(candidates.length, 0);
	assert.equal(engine.pendingImpact, null);
	engine.cleanupSensors();
});

test('sustained vibration cannot roll the confirmation window forward', () => {
	const { engine } = ridingEngine();
	settle(engine);
	feedWithGravity(engine, 5, 2);
	const openedAt = engine.pendingImpact.at;

	feedWithGravity(engine, 5, 6);

	assert.equal(engine.pendingImpact.at, openedAt, 'the impact keeps the moment it was first seen');
	engine.cleanupSensors();
});

test('the crash thresholds are documented as gravity-free values', () => {
	const RideEngine = loadRideEngine().AvenraHaloRideEngineClass;
	const engine = new RideEngine();
	assert.equal(engine.options.crashGThreshold, 2.5);
	assert.equal(engine.options.crashImmediateGThreshold, 6);
	assert.equal(engine.options.crashMinSpeedMph, 15);
	assert.equal(engine.options.crashArmDelayMs, 10000);
	assert.equal(engine.options.crashImpulseSamples, 2);
	assert.doesNotMatch(source, /crashGThreshold\s*\*\s*1\.8/);
	assert.doesNotMatch(source, /gForce\s*<\s*this\.options\.crashGThreshold/);
	engine.destroy();
});
