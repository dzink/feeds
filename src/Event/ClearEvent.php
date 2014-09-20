<?php

/**
 * @file
 * Contains \Drupal\feeds\Event\ClearEvent.
 */

namespace Drupal\feeds\Event;

use Drupal\feeds\FeedInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 *
 */
class ClearEvent extends Event {

  /**
   * The feed being imported.
   *
   * @var \Drupal\feeds\FeedInterface
   */
  protected $feed;

  /**
   * Constructs a ClearEvent object,
   *
   * @param \Drupal\feeds\FeedInterface $feed
   */
  public function __construct(FeedInterface $feed) {
    $this->feed = $feed;
  }

  public function getFeed() {
    return $this->feed;
  }

}
