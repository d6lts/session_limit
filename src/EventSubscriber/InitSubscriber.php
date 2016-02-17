<?php /**
 * @file
 * Contains \Drupal\session_limit\EventSubscriber\InitSubscriber.
 */

namespace Drupal\session_limit\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class InitSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [KernelEvents::REQUEST => ['onEvent', 0]];
  }

  public function onEvent() {
    $user = \Drupal::currentUser();
    if ($user->uid > 1 && !isset($_SESSION['session_limit'])) {

      if (_session_limit_bypass()) {
        // Bypass the session limitation on this page callback.
        return;
      }

      $query = db_select('sessions', 's')
        // Use distict so that HTTP and HTTPS sessions
        // are considered a single session.
      ->distinct()
        ->fields('s', ['sid'])
        ->condition('s.uid', $user->uid);

      if (\Drupal::moduleHandler()->moduleExists('masquerade') && \Drupal::config('session_limit.settings')->get('session_limit_masquerade_ignore')) {
        $query->leftJoin('masquerade', 'm', 's.uid = m.uid_as AND s.sid = m.sid');
        $query->isNull('m.sid');
      }

      $active_sessions = $query->countQuery()->execute()->fetchField();
      $max_sessions = session_limit_user_max_sessions();

      if (!empty($max_sessions) && $active_sessions > $max_sessions) {
        session_limit_invoke_session_limit(session_id(), 'collision');
      }
      else {
        // force checking this twice as there's a race condition around session creation.
      // see issue #1176412
        if (!isset($_SESSION['session_limit_checkonce'])) {
          $_SESSION['session_limit_checkonce'] = TRUE;
        }
        else {
          // mark session as verified to bypass this in future.
          $_SESSION['session_limit'] = TRUE;
        }
      }
    }
  }

}
