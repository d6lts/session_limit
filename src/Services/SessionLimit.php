<?php

/**
 * @file
 * Contains \Drupal\session_limit\Services\SessionLimit.
 */

namespace Drupal\session_limit\Services;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SessionLimit implements EventSubscriberInterface {

  const ACTION_DO_NOTHING = 0;

  const ACTION_SESSION_LIMIT_DROP = 1;

  const ACTION_SESSION_LIMIT_DISALLOW_NEW = 2;

  /**
   * @return array
   *   Keys are session limit action ids
   *   Values are text descriptions of each action.
   */
  public static function getActions() {
    return [
      SessionLimit::ACTION_DO_NOTHING => t('Do nothing.'),
      SessionLimit::ACTION_SESSION_LIMIT_DROP => t('Automatically drop the oldest sessions without prompting.'),
      SessionLimit::ACTION_SESSION_LIMIT_DISALLOW_NEW => t('Prevent new session.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['checkSessionLimit'];
    return $events;
  }

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * SessionLimit constructor.
   * @param \Drupal\Core\Database\Connection $database
   */
  public function __construct($database) {
    $this->database = $database;
  }

  /**
   * @return \Drupal\Core\Session\AccountProxyInterface
   */
  protected function getCurrentUser() {
    if (!isset($this->currentUser)) {
      $this->currentUser = \Drupal::currentUser();
    }

    return $this->currentUser;
  }

  /**
   * If the user has too many sessions invoke collision event.
   */
  public function checkSessionLimit() {
    if ($this->getCurrentUser()->id() > 1 && !isset($_SESSION['session_limit'])) {

      // @todo bypass for path as a hook.

      // @todo move this function to a service so db connection can be injected.
      $query = $this->database->select('sessions', 's')
        // Use distict so that HTTP and HTTPS sessions
        // are considered a single sessionId.
        ->distinct()
        ->fields('s', ['sid'])
        ->condition('s.uid', $this->getCurrentUser()->id());

      // @todo add support for masquerade.

      $active_sessions = $query->countQuery()->execute()->fetchField();
      $max_sessions = $this->getUserMaxSessions($this->getCurrentUser());

      if (!empty($max_sessions) && $active_sessions > $max_sessions) {
        // @todo maybe replace with an event to allow other modules to react.
        $this->sessionCollision(session_id());
      }
      else {
        // force checking this twice as there's a race condition around
        // sessionId creation see issue #1176412.
        if (!isset($_SESSION['session_limit_checkonce'])) {
          $_SESSION['session_limit_checkonce'] = TRUE;
        }
        else {
          // mark sessionId as verified to bypass this in future.
          $_SESSION['session_limit'] = TRUE;
        }
      }
    }
  }

  /**
   * React to a collision - a user has multiple sessions.
   *
   * @param string $sessionId
   *   The sessionId id string which identifies the current sessionId.
   */
  public function sessionCollision($sessionId) {
    if ($this->getCollisionBehaviour() === self::ACTION_DO_NOTHING) {
      // @todo add a watchdog log here.
      return;
    }

    // @todo need to deal with the ACTION_DISALLOW_NEW.

    // Get the number of sessions that should be removed.
    // @todo replace the straight db query with a select.
    $limit = $this->database->query("SELECT COUNT(DISTINCT(sid)) - :max_sessions FROM {sessions} WHERE uid = :uid", array(
      // @todo replace with variable number of sessions.
      ':max_sessions' => 1,
      ':uid' => $this->getCurrentUser()->id(),
    ))->fetchField();

    if ($limit > 0) {
      // Secure sessionId ids are separate rows in the database, but we don't
      // want to kick the user off there http sessionId and not there https
      // sessionId or vice versa. This is why this query is DISTINCT.
      $result = $this->database->select('sessions', 's')
        ->distinct()
        ->fields('s', array('sid'))
        ->condition('s.uid', $this->getCurrentUser()->id())
        ->orderBy('timestamp', 'ASC')
        ->range(0, $limit)
        ->execute();

      foreach ($result as $session) {
        $this->sessionDisconnect($session->sid);
      }
    }
  }

  /**
   * Disconnect a sessionId.
   *
   * @param string $sessionId
   *   The session being discconected
   */
  public function sessionDisconnect($sessionId) {
    // @todo we need to put a message into the sessions being ended.

    $this->database->update('sessions')
      ->fields([
        'session' => '',
        'uid' => 0,
      ])
      ->condition('sid', $sessionId)
      ->execute();

    // @todo add a watchdog log entry.
  }

  /**
   * Get the maximum sessions allowed for a specific user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *
   * @return int
   *   The number of allowed sessions. A value less than 1 means unlimited.
   */
  public function getUserMaxSessions(AccountInterface $account) {
    // @todo remove these statics.
    $limit = \Drupal::config('session_limit.settings')->get('session_limit_max');
    $role_limits = \Drupal::config('session_limit.settings')->get('session_limit_roles');

    foreach ($account->getRoles() as $rid) {
      if (!empty($role_limits[$rid])) {
        if ($role_limits[$rid] == -1) {
          // If they have an unlimited role then just return the unlimited value;
          return -1;
        }

        // Otherwise, the user gets the largest limit available.
        $limit = max($limit, $role_limits[$rid]);
      }
    }

    // @todo reinstate per user limits.

    return $limit;
  }

  /**
   * @return int
   *   Will return one of the constants provided by getActions().
   */
  public function getCollisionBehaviour() {
    // @todo get rid of these statics.
    return \Drupal::config('session_limit.settings')->get('session_limit_behaviour');
  }
}
