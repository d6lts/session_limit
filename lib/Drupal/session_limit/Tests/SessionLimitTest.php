<?php

/**
 * @file
 * Simpletest tests for session_limit.
 */

namespace Drupal\session_limit\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Base test for session limits.
 *
 * This contains a collection of helper functions and session_limit
 * assertions.
 */
class SessionLimitTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('session_limit');
  protected $profile = 'standard';

  /**
   * A store references to different sessions.
   */

  /**
   * getInfo() returns properties that are displayed in the test selection form.
   */
  public static function getInfo() {
    return array(
      'name' => 'Session Limit MutiSession Tests',
      'description' => 'Ensure the multi session tests for SimpleTest work as expected',
      'group' => 'Session Limit',
    );
  }

  /**
   * setUp() performs any pre-requisite tasks that need to happen.
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Test session stash and restore.
   *
   * Drupal Simpletest has no native ability to test over multiple sessions.
   * The session_limit tests add this functionality. This first test checks
   * that multiple sessions are working in SimpleTest by logging in as two
   * different users simultaneously via two cUrl sessions.
   */
  public function testSessionStashAndRestore() {

    // Create and log in our privileged user.
    $user1 = $this->drupalCreateUser(array('access content'));
    $user2 = $this->drupalCreateUser(array('access content'));

    // Make sure that session_limit does not interfere with
    // this test of the tests.
    variable_set('session_limit_behaviour', 0);
    variable_set('session_limit_max', 100);

    // Login under session 1.
    $this->drupalLogin($user1);
    $this->drupalGet('user');
    $this->assertText(t('Log out'), t('User is logged in under session 1.'));
    $this->assertText($user1->name, t('User1 is logged in under session 1.'));

    // Backup session 1.
    $session_1 = $this->stashSession();

    // Check session 2 is logged out.
    $this->drupalGet('node');
    $this->assertNoText(t('Log out'), t('Session 1 is shelved.'));

    // Login under session 2.
    $this->drupalLogin($user2);
    $this->drupalGet('user');
    $this->assertText(t('Log out'), t('User is logged in under session 2.'));
    $this->assertText($user2->name, t('User2 is logged in under session 2.'));

    // Backup session 2.
    $session_2 = $this->stashSession();

    // Switch to session 1.
    $this->restoreSession($session_1);

    // Check still logged in as session 1.
    $this->drupalGet('user');
    $this->assertText(t('Log out'), t('User is logged in under session 1.'));
    $this->assertText($user1->name, t('User1 is logged in under session 1.'));

    // Switch to session 2.
    $this->restoreSession($session_2);

    // Check still logged in as session 2.
    $this->drupalGet('user');
    $this->assertText(t('Log out'), t('User is logged in under session 2.'));
    $this->assertText($user2->name, t('User2 is logged in under session 2.'));
  }

  /**
   * Initialise a new unique session.
   *
   * @return string
   *   Unique identifier for the session just stored.
   *   It is the cookiefile name.
   */
  public function stashSession() {
    if (empty($this->cookieFile)) {
      // No session to stash.
      return;
    }

    // The session_id is the current cookieFile.
    $session_id = $this->cookieFile;

    $this->curlHandles[$session_id] = $this->curlHandle;
    $this->loggedInUsers[$session_id] = $this->loggedInUser;

    // Reset Curl.
    unset($this->curlHandle);
    $this->loggedInUser = FALSE;

    // Set a new unique cookie filename.
    do {
      $this->cookieFile = $this->public_files_directory . '/' . $this->randomName() . '.jar';
    }
    while (isset($this->curlHandles[$this->cookieFile]));

    return $session_id;
  }

  /**
   * Restore a previously stashed session.
   *
   * @param string $session_id
   *   The session to restore as returned by stashSession();
   *   This is also the path to the cookie file.
   *
   * @return string
   *   The old session id that was replaced.
   */
  public function restoreSession($session_id) {
    $old_session_id = NULL;

    if (isset($this->curlHandle)) {
      $old_session_id = $this->stashSession();
    }

    // Restore the specified session.
    $this->curlHandle = $this->curlHandles[$session_id];
    $this->cookieFile = $session_id;
    $this->loggedInUser = $this->loggedInUsers[$session_id];

    return $old_session_id;
  }

  /**
   * Close all stashed sessions and the current session.
   */
  public function closeAllSessions() {
    foreach ($this->curlHandles as $cookie_file => $curl_handle) {
      if (isset($curl_handle)) {
        curl_close($curl_handle);
      }
    }

    // Make the server forget all sessions.
    db_truncate('sessions')->execute();

    $this->curlHandles = array();
    $this->loggedInUsers = array();
    $this->loggedInUser = FALSE;
    $this->cookieFile = $this->public_files_directory . '/' . $this->randomName() . '.jar';
    unset($this->curlHandle);
  }
}
