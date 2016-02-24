<?php

/**
 * @file
 * Contains \Drupal\session_limit\Services\SessionLimit.
 */

namespace Drupal\session_limit\Services;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\session_limit\Event\SessionLimitBypassEvent;
use Drupal\session_limit\Event\SessionLimitCollisionEvent;
use Drupal\session_limit\Event\SessionLimitDisconnectEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Database\Connection;

class SessionLimit implements EventSubscriberInterface {

  const ACTION_DO_NOTHING = 0;

  const ACTION_SESSION_LIMIT_DROP = 1;

  const ACTION_SESSION_LIMIT_DISALLOW_NEW = 2;

  const USER_UNLIMITED_SESSIONS = -1;

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
    $events[KernelEvents::REQUEST][] = ['onKernelRequest'];
    $events['session_limit.bypass'][] = ['onSessionLimitBypass'];
    $events['session_limit.collision'][] = ['onSessionCollision'];
    return $events;
  }

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * @var RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * @var EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * SessionLimit constructor.
   *
   * @param Connection $database
   *   The database connection
   * @param EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service
   * @param RouteMatchInterface $routeMatch
   *   The Route
   */
  public function __construct(Connection $database, EventDispatcherInterface $eventDispatcher, RouteMatchInterface $routeMatch) {
    $this->routeMatch = $routeMatch;
    $this->database = $database;
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * @return RouteMatchInterface
   */
  public function getRouteMatch() {
    return $this->routeMatch;
  }

  /**
   * @return EventDispatcherInterface
   */
  public function getEventDispatcher() {
    return $this->eventDispatcher;
  }

  /**
   * @return \Drupal\Core\Session\AccountProxyInterface
   */
  protected function getCurrentUser() {
    if (!isset($this->currentUser)) {
      // @todo can we get rid of this static call?
      $this->currentUser = \Drupal::currentUser();
    }

    return $this->currentUser;
  }

  /**
   * Event listener, on executing a Kernel request.
   *
   * Check the users active sessions and invoke a session collision if it is
   * higher than the configured limit.
   */
  public function onKernelRequest() {
    /** @var SessionLimitBypassEvent $bypassEvent */
    $bypassEvent = $this
      ->getEventDispatcher()
      ->dispatch('session_limit.bypass', new SessionLimitBypassEvent());

    // Check the result of the event to see if we should bypass.
    if ($bypassEvent->shouldBypass()) {
      return;
    }

    $active_sessions = $this->getUserActiveSessionCount($this->getCurrentUser());
    $max_sessions = $this->getUserMaxSessions($this->getCurrentUser());

    if ($max_sessions > 0 && $active_sessions > $max_sessions) {
      $collisionEvent = new SessionLimitCollisionEvent(session_id(), $this->getCurrentUser(), $active_sessions, $max_sessions);

      $this
        ->getEventDispatcher()
        ->dispatch('session_limit.collision', $collisionEvent);
    }
    else {
      // force checking this twice as there's a race condition around
      // sessionId creation see issue #1176412.
      // @todo accessing the $_SESSION super global is bad.
      if (!isset($_SESSION['session_limit_checkonce'])) {
        $_SESSION['session_limit_checkonce'] = TRUE;
      }
      else {
        // mark sessionId as verified to bypass this in future.
        $_SESSION['session_limit'] = TRUE;
      }
    }
  }

  /**
   * Event listener, on check for session check bypass.
   *
   * @param SessionLimitBypassEvent $event
   */
  public function onSessionLimitBypass(SessionLimitBypassEvent $event) {
    if ($this->getCurrentUser()->id() < 2) {
      // User 1 and anonymous don't get session checked.
      $event->setBypass(TRUE);
      return;
    }

    // @todo accessing the $_SESSION super global is probably bad.
    if (isset($_SESSION['session_limit'])) {
      // Already checked people do not get session checked.
      $event->setBypass(TRUE);
      return;
    }

    $route = $this->getRouteMatch();
    $current_path = $route->getRouteObject()->getPath();

    $bypass_paths = [
      '/session/limit',
      '/user/logout',
    ];

    if (in_array($current_path, $bypass_paths)) {
      // Don't session check on these routes.
      $event->setBypass(TRUE);
      return;
    }
  }

  /**
   * React to a collision - a user has multiple sessions.
   *
   * @param SessionLimitCollisionEvent $event
   */
  public function onSessionCollision(SessionLimitCollisionEvent $event) {
    if ($this->getCollisionBehaviour() === self::ACTION_DO_NOTHING) {
      // @todo add a watchdog log here.
      return;
    }

    // @todo need to deal with the ACTION_DISALLOW_NEW.

    // Get the number of sessions that should be removed.
    // @todo replace the straight db query with a select.
    $limit = $this->database->query("SELECT COUNT(DISTINCT(sid)) - :max_sessions FROM {sessions} WHERE uid = :uid", array(
      ':max_sessions' => $event->getUserMaxSessions(),
      ':uid' => $event->getAccount()->id(),
    ))->fetchField();

    if ($limit > 0) {
      // Secure sessionId ids are separate rows in the database, but we don't
      // want to kick the user off there http sessionId and not there https
      // sessionId or vice versa. This is why this query is DISTINCT.
      $result = $this->database->select('sessions', 's')
        ->distinct()
        ->fields('s', array('sid'))
        ->condition('s.uid', $event->getAccount()->id())
        ->orderBy('timestamp', 'ASC')
        ->range(0, $limit)
        ->execute();

      foreach ($result as $session) {
        /** @var SessionLimitDisconnectEvent $disconnectEvent */
        $disconnectEvent = $this
          ->getEventDispatcher()
          ->dispatch('session_limit.disconnect', new SessionLimitDisconnectEvent($session->id, $event));

        if (!$disconnectEvent->shouldPreventDisconnect()) {
          $this->sessionDisconnect($session->sid);
        }
      }
    }
  }

  /**
   * Disconnect a sessionId.
   *
   * @param string $sessionId
   *   The session being disconnected
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
   * Get the number of active sessions for a user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user to check on.
   *
   * @return int
   *   The total number of active sessions for the given user
   */
  public function getUserActiveSessionCount(AccountInterface $account) {
    $query = $this->database->select('sessions', 's')
      // Use distinct so that HTTP and HTTPS sessions
      // are considered a single sessionId.
      ->distinct()
      ->fields('s', ['sid'])
      ->condition('s.uid', $account->id());

    // @todo add support for masquerade.

    return $query->countQuery()->execute()->fetchField();
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
    $limit = \Drupal::config('session_limit.settings')
      ->get('session_limit_max');
    $role_limits = \Drupal::config('session_limit.settings')
      ->get('session_limit_roles');

    foreach ($account->getRoles() as $rid) {
      if (!empty($role_limits[$rid])) {
        if ($role_limits[$rid] === self::USER_UNLIMITED_SESSIONS) {
          // If they have an unlimited role then just return the unlimited value;
          return self::USER_UNLIMITED_SESSIONS;
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
    return \Drupal::config('session_limit.settings')
      ->get('session_limit_behaviour');
  }
}
