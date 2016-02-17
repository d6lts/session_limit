<?php

/**
 * @file
 * Contains \Drupal\session_limit\Services\SessionLimit.
 */

namespace Drupal\session_limit\Services;

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
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['checkSessionLimit'];
    return $events;
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
      $query = db_select('sessions', 's')
        // Use distict so that HTTP and HTTPS sessions
        // are considered a single sessionId.
        ->distinct()
        ->fields('s', ['sid'])
        ->condition('s.uid', $this->getCurrentUser()->id());

      // @todo add support for masquerade.

      $active_sessions = $query->countQuery()->execute()->fetchField();

      // @todo allow a variable number of sessions.
      $max_sessions = 1;

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
    // @todo this code only deals with the SESSION_LIMIT_DROP behaviour and needs SESSION_LIMIT_DO_NOTHING.

    // Get the number of sessions that should be removed.
    $limit = db_query("SELECT COUNT(DISTINCT(sid)) - :max_sessions FROM {sessions} WHERE uid = :uid", array(
      // @todo replace with variable number of sessions.
      ':max_sessions' => 1,
      ':uid' => $this->getCurrentUser()->id(),
    ))->fetchField();

    if ($limit > 0) {
      // Secure sessionId ids are separate rows in the database, but we don't
      // want to kick the user off there http sessionId and not there https
      // sessionId or vice versa. This is why this query is DISTINCT.
      $result = db_select('sessions', 's')
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

    db_update('sessions')
      ->fields([
        'session' => '',
        'uid' => 0,
      ])
      ->condition('sid', $sessionId)
      ->execute();

    // @todo add a watchdog log entry.
  }
}
