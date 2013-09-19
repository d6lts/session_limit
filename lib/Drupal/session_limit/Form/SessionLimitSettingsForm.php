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
class SessionLimitSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'session_limit_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $config = $this->configFactory->get('session_limit.settings');

    $form['session_limit_max'] = array(
      '#type' => 'textfield',
      '#title' => t('Default maximum number of active sessions'),
      '#default_value' => $config->get('session_limit_max'),
      '#size' => 2,
      '#maxlength' => 3,
      '#description' => t('The maximum number of active sessions a user can have. 0 implies unlimited sessions.'),
    );

    $limit_behaviours = array(
      SESSION_LIMIT_DO_NOTHING => t('Do nothing.'),
      SESSION_LIMIT_DROP => t('Automatically drop the oldest sessions without prompting.'),
      SESSION_LIMIT_DISALLOW_NEW => t('Prevent new session.'),
    );

    $form['session_limit_behaviour'] = array(
      '#type' => 'radios',
      '#title' => t('When the session limit is exceeded'),
      '#default_value' => $config->get('session_limit_behaviour'),
      '#options' => $limit_behaviours,
    );

    if (module_exists('masquerade')) {
      $form['session_limit_masquerade_ignore'] = array(
        '#type' => 'checkbox',
        '#title' => t('Ignore masqueraded sessions.'),
        '#description' => t("When a user administrator uses the masquerade module to impersonate a different user, it won't count against the session limit counter"),
        '#default_value' => $config->get('session_limit_masquerade_ignore'),
      );
    }

    $form['session_limit_logged_out_message_severity'] = array(
      '#type' => 'select',
      '#title' => t('Logged out message severity'),
      '#default_value' => $config->get('session_limit_logged_out_message_severity'),
      '#options' => array(
        'error' => t('Error'),
        'warning' => t('Warning'),
        'status' => t('Status'),
        '_none' => t('No Message'),
      ),
      '#description' => t('The Drupal message type.  Defaults to Error.'),
    );

    $form['session_limit_logged_out_message'] = array(
      '#type' => 'textarea',
      '#title' => t('Logged out message'),
      '#default_value' => $config->get('session_limit_logged_out_message'),
      '#description' => t('The message that is displayed to a user if the workstation has been logged out.<br />
      @number is replaced with the maximum number of simultaneous sessions.'),
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * Implements \Drupal\Core\Form\FormInterface:validateForm()
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
   * Implements \Drupal\Core\Form\FormInterface:submitForm()
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
