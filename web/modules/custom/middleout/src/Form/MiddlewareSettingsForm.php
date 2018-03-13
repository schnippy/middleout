<?php

namespace Drupal\middleout\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class MiddlewareSettingsForm.
 */
class MiddlewareSettingsForm extends ConfigFormBase {
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'middleout.middlewaresettings',
    ];
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'middleout_settings_form';
  }
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('middleout.middlewaresettings');
    
    $base_url = $config->get('base_url');
    $form['instructions'] = [
      '#markup' => '<br/><h2>Execute Query</h2><p>Instructions here.. </p></br />',
    ];
    $form['base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('Base URL to use, if not catalog.archives.gov.'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => isset($base_url) ? $base_url : 'https://catalog.archives.gov/api/v1/',
    ];
    $form['api_query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('API Query'),
      '#description' => $this->t('API search query to use to populate Dynamo middleware'),
      '#default_value' => $config->get('api_query'),
    ];
    return parent::buildForm($form, $form_state);
  }
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->config('middleout.middlewaresettings')
      ->set('base_url', $form_state->getValue('base_url'))
      ->set('api_query', $form_state->getValue('api_query'))
      ->save();
  }
}
