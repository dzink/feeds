<?php

/**
 * @file
 * Contains \Drupal\feeds\FeedClearHandler.
 */

namespace Drupal\feeds;

use Drupal\feeds\Event\ClearEvent;
use Drupal\feeds\Event\FeedsEvents;
use Drupal\feeds\Event\InitEvent;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\StateInterface;

/**
 * Deletes the items of a feed.
 */
class FeedClearHandler extends FeedHandlerBase {

  /**
   * {@inheritodc}
   */
  public function startBatchClear(FeedInterface $feed) {
    $feed->lock();
    $feed->clearStates();

    $batch = [
      'title' => $this->t('Deleting items from: %title', ['%title' => $feed->label()]),
      'init_message' => $this->t('Deleting items from: %title', ['%title' => $feed->label()]),
      'operations' => [
        [[$this, 'clear'], [$feed]],
      ],
      'progress_message' => $this->t('Deleting items from: %title', ['%title' => $feed->label()]),
      'error_message' => $this->t('An error occored while clearing %title.', ['%title' => $feed->label()]),
    ];

    batch_set($batch);
  }

  /**
   * {@inheritodc}
   */
  public function clear(FeedInterface $feed) {
    try {
      $this->dispatchEvent(FeedsEvents::INIT_CLEAR, new InitEvent($feed));
      $this->dispatchEvent(FeedsEvents::CLEAR, new ClearEvent($feed));
    }
    catch (\Exception $exception) {
      // Do nothing yet.
    }

    // Clean up.
    $result = $feed->progressClearing();

    if ($result === StateInterface::BATCH_COMPLETE || isset($exception)) {
      $feed->clearStates();
      $feed->unlock();
    }

    if (isset($exception)) {
      throw $exception;
    }

    return $result;
  }

}
