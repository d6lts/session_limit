<?php

/**
 * @file
 * Contains \Drupal\session_limit\Form\SessionLimitSettings.
 */

namespace Drupal\session_limit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\session_limit\Services\SessionLimit;

class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'session_limit_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['session_limit.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['session_limit_max'] = [
      '#type' => 'textfield',
      '#title' => t('Default maximum number of active sessions'),
      '#default_value' => \Drupal::config('session_limit.settings')->get('session_limit_max'),
      '#size' => 2,
      '#maxlength' => 3,
      '#description' => t('The maximum number of active sessions a user can have. 0 implies unlimited sessions.'),
    ];

    $form['session_limit_behaviour'] = [
      '#type' => 'radios',
      '#title' => t('When the session limit is exceeded'),
      '#default_value' => \Drupal::config('session_limit.settings')->get('session_limit_behaviour'),
      '#options' => SessionLimit::getActions(),
    ];

    if (\Drupal::moduleHandler()->moduleExists('masquerade')) {
      $form['session_limit_masquerade_ignore'] = [
        '#type' => 'checkbox',
        '#title' => t('Ignore masqueraded sessions.'),
        '#description' => t("When a user administrator uses the masquerade module to impersonate a different user, it won't count against the session limit counter"),
        '#default_value' => \Drupal::config('session_limit.settings')->get('session_limit_masquerade_ignore'),
      ];
    }

    $form['session_limit_logged_out_message_severity'] = [
      '#type' => 'select',
      '#title' => t('Logged out message severity'),
      '#default_value' => \Drupal::config('session_limit.settings')->get('session_limit_logged_out_message_severity'),
      '#options' => [
        'error' => t('Error'),
        'warning' => t('Warning'),
        'status' => t('Status'),
        '_none' => t('No Message'),
      ],
      '#description' => t('The Drupal message type.  Defaults to Error.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $maxsessions = $form_state->getValue(['session_limit_max']);
    if (!is_numeric($maxsessions)) {
      $form_state->setErrorByName('session_limit_max', t('You must enter a number for the maximum number of active sessions'));
    }
    elseif ($maxsessions < 0) {
      $form_state->setErrorByName('session_limit_max', t('Maximum number of active sessions must be positive'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('session_limit.settings');

    foreach (Element::children($form) as $variable) {
      $config->set($variable, $form_state->getValue($form[$variable]['#parents']));
    }
    $config->save();

    if (method_exists($this, '_submitForm')) {
      $this->_submitForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

}
