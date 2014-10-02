<?php

/**
 * @file
 * Contains \Drupal\feeds\Form\FeedUnlockForm.
 */

namespace Drupal\feeds\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for unlocking a feed.
 */
class FeedUnlockForm extends ContentEntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to unlock the feed %feed?', array('%feed' => $this->entity->label()));
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->urlInfo();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Unlock');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->unlock();
    $args = array('@importer' => $this->entity->getImporter()->label(), '%title' => $this->entity->label());

    watchdog('feeds', '@importer: unlocked %title.', $args);
    drupal_set_message($this->t('%title has been unlocked.', $args));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
