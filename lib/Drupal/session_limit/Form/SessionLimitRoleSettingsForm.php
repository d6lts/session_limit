<?php

/**
 * @file
 * Contains \Drupal\session_limit\Form\SessionLimitSettingsForm.
 */

namespace Drupal\session_limit\Form;

use Drupal\Core\Form\ConfigFormBase;

/**
 * Displays the session_limit settings form.
 */
class SessionLimitRoleSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'session_limit_role_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $config = $this->configFactory->get('session_limit.settings');

    $result = db_select('variable', 'v')
      ->fields('v', array('name', 'value'))
      ->condition('name', 'session_limit_rid_%', 'LIKE')
      ->orderBy('name')
      ->execute();

    foreach ($result as $setting) {
      $role_limits[$setting->name] = unserialize($setting->value);
    }

    $roles = user_roles(TRUE);
    foreach ($roles as $rid => $role) {
      $form["session_limit_rid_$rid"] = array(
        '#type' => 'select',
        '#options' => _session_limit_user_options(),
        '#title' => check_plain($role),
        '#default_value' => empty($role_limits["session_limit_rid_$rid"]) ? 0 : $role_limits["session_limit_rid_$rid"],
      );
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    $maxsessions = $form_state['values']['session_limit_max'];
    if (!is_numeric($maxsessions)) {
      form_set_error('session_limit_max', t('You must enter a number for the maximum number of active sessions'));
    }
    elseif ($maxsessions < 0) {
      form_set_error('session_limit_max', t('Maximum number of active sessions must be positive'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    $this->configFactory->get('session_limit.settings')
      ->set('session_limit_max', $form_state['values']['session_limit_max'])
      ->set('session_limit_behaviour', $form_state['values']['session_limit_behaviour'])
      ->set('session_limit_logged_out_message_severity', $form_state['values']['session_limit_logged_out_message_severity'])
      ->set('session_limit_logged_out_message', $form_state['values']['session_limit_logged_out_message'])
      ->save();

    parent::SubmitForm($form, $form_state);
  }
}
