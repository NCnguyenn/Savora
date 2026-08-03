'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('profile and review contracts are registered and ownership scoped', () => {
  for (const file of [
    'database/migrations/004a_profiles_reviews.php',
    'lib/repositories/profile_repository.php', 'lib/repositories/review_repository.php',
    'lib/services/profile_service.php', 'lib/services/review_service.php',
    'api/profile.php', 'api/reviews.php'
  ]) assert.ok(fs.existsSync(path.join(root, file)), `${file} must exist`);

  const migrations = read('lib/migrations.php');
  const profile = read('lib/services/profile_service.php');
  const reviews = read('lib/services/review_service.php');
  const reviewRepository = read('lib/repositories/review_repository.php');
  assert.match(migrations, /004a_profiles_reviews/);
  assert.match(profile, /function profile_for_user/);
  assert.match(profile, /function profile_update_customer/);
  assert.match(profile, /function profile_save_address/);
  assert.match(profile, /function favorite_set/);
  assert.match(reviews, /function review_create_for_order/);
  assert.match(reviews, /function review_reply_as_restaurant/);
  assert.match(reviews, /customer_user_id/);
  assert.match(reviewRepository, /owner_user_id/);
});

test('Customer profile and favorites use server APIs without authoritative local writers', () => {
  const profile = read('customer_profile.php');
  const favorites = read('customer_favorites.php');
  const history = read('customer_history.php');
  for (const source of [profile, favorites, history]) assert.match(source, /js\/api_client\.js/);
  assert.match(profile, /api\/profile\.php/);
  assert.match(favorites, /api\/profile\.php/);
  assert.match(history, /api\/reviews\.php/);
  assert.doesNotMatch(profile, /SavoraState\.(?:setProfile|persist)/);
  assert.doesNotMatch(favorites, /SavoraState\.(?:toggleFavorite|persist)/);
  assert.doesNotMatch(history, /order\.review\s*=|SavoraState\.persist/);
});

test('Restaurant reviews hydrate and reply through the server', () => {
  const page = read('restaurant_reviews.php');
  const insights = read('js/restaurant_insights.js');
  assert.match(page, /js\/api_client\.js/);
  assert.match(insights, /api\/reviews\.php/);
  assert.match(insights, /SavoraApi\.post/);
  assert.doesNotMatch(insights, /setReviewReply|RestaurantState.*reviews|local demo/i);
});

test('profile and review mutations use CSRF and stable idempotency boundaries', () => {
  for (const file of ['api/profile.php', 'api/reviews.php']) {
    const source = read(file);
    assert.match(source, /savora_require_csrf/);
    assert.match(source, /savora_require_idempotency_key/);
    assert.match(source, /savora_idempotency_hash/);
    assert.match(source, /savora_idempotency_lock/);
    assert.match(source, /savora_idempotency_unlock/);
  }
});
