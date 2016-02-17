<?php

/**
 * @file
 * Contains \Drupal\session_limit\Form\SessionLimitSettingsByrole.
 */

namespace Drupal\session_limit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

class SessionLimitSettingsByrole extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'session_limit_settings_byrole';
  }

  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    // @FIXME
// $result = db_select('variable', 'v')
//     ->fields('v', array('name', 'value'))
//     ->condition('name', 'session_limit_rid_%', 'LIKE')
//     ->orderBy('name')
//     ->execute();


    foreach ($result as $setting) {
      $role_limits[$setting->name] = unserialize($setting->value);
    }

    $roles = user_roles(TRUE);
    foreach ($roles as $rid => $role) {
      $form["session_limit_rid_$rid"] = [
        '#type' => 'select',
        '#options' => _session_limit_user_options(),
        '#title' => \Drupal\Component\Utility\SafeMarkup::checkPlain($role),
        '#default_value' => empty($role_limits["session_limit_rid_$rid"]) ? 0 : $role_limits["session_limit_rid_$rid"],
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Save permissions'),
    ];

    return $form;
  }

  public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    // @FIXME
// db_delete('variable')
//     ->condition('name', 'session_limit_rid_%', 'LIKE')
//     ->execute();


    foreach ($form_state->getValues() as $setting_name => $setting_limit) {
      // @FIXME
// // @FIXME
// // The correct configuration object could not be determined. You'll need to
// // rewrite this call manually.
// variable_set($setting_name, $setting_limit);

    }

    drupal_set_message(t('Role settings updated.'), 'status');
    \Drupal::logger('session_limit')->info('Role limits updated.', []);
  }

}
