<?php

/**
 * @file
 * Contains Drupal\session_limit\Event\SessionLimitDisconnectEvent.
 */

namespace Drupal\session_limit\Event;

use Symfony\Component\EventDispatcher\Event;
use Drupal\Core\Session\AccountInterface;

class SessionLimitDisconnectEvent extends Event {

  /**
   * @var int
   */
  protected $sessionId;

  /**
   * @var SessionLimitCollisionEvent
   */
  protected $collisionEvent;

  /**
   * @var bool
   */
  protected $preventDisconnect = FALSE;

  /**
   * SessionLimitCollisionEvent constructor.
   *
   * @param int $sessionId
   * @param SessionLimitCollisionEvent $collisionEvent
   */
  public function __construct($sessionId, SessionLimitCollisionEvent $collisionEvent) {
    $this->sessionId = $sessionId;
    $this->collisionEvent = $collisionEvent;
  }

  /**
   * @return int
   */
  public function getSessionId() {
    return $this->sessionId;
  }

  /**
   * @return SessionLimitCollisionEvent
   */
  public function getCollisionEvent() {
    return $this->collisionEvent;
  }

  /**
   * Call to prevent the session being disconnected.
   *
   * @param bool $state
   *   Set to TRUE to prevent the disconnection (default) any other
   *   value is ignored. Just one listener to the event calling this will
   *   prevent the disconnection.
   */
  public function preventDisconnect($state = TRUE) {
    $this->preventDisconnect = !empty($state) ? TRUE : $this->preventDisconnect;
  }

  /**
   * Determine if the session disconnection should be prevented.
   *
   * @return bool
   */
  public function shouldPreventDisconnect() {
    return $this->preventDisconnect;
  }

}
