<?php

/**
 * @file
 * Contains \Drupal\session_limit\Form\SessionLimitPage.
 */

namespace Drupal\session_limit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class SessionLimitPage extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'session_limit_page';
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $user = \Drupal::currentUser();

    // @FIXME
    // Could not extract the default value because it is either indeterminate, or
    // not scalar. You'll need to provide a default value in
    // config/install/session_limit.settings.yml and config/schema/session_limit.schema.yml.
    if (\Drupal::config('session_limit.settings')->get('session_limit_behaviour') == SESSION_LIMIT_DISALLOW_NEW) {
      session_destroy();
      $user = drupal_anonymous_user();

      return;
    }

    $result = db_query('SELECT * FROM {sessions} WHERE uid = :uid', [
      ':uid' => $user->uid
      ]);
    foreach ($result as $obj) {
      $message = $user->sid == $obj->sid ? t('Your current session.') : '';

      $sids[$obj->sid] = t('<strong>Host:</strong> %host (idle: %time) <b>@message</b>', [
        '%host' => $obj->hostname,
        '@message' => $message,
        '%time' => \Drupal::service("date.formatter")->formatInterval(time() - $obj->timestamp),
      ]);
    }

    $form['sid'] = [
      '#type' => 'radios',
      '#title' => t('Select a session to disconnect.'),
      '#options' => $sids,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Disconnect session'),
    ];

    return $form;
  }

  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    if (\Drupal::currentUser()->sid == $form_state->getValue(['sid'])) {
      drupal_goto('user/logout');
    }
    else {
      session_limit_invoke_session_limit($form_state->getValue(['sid']), 'disconnect');
      drupal_goto();
    }
  }

}
