<?php

/**
 * @file
 * Contains \Drupal\feeds\Plugin\Parser\FeedsOPMLParser.
 */

namespace Drupal\feeds\Plugin\Parser;

use Drupal\feeds\FeedInterface;
use Drupal\feeds\FetcherResultInterface;
use Drupal\feeds\ParserOPML;

/**
 * Feeds parser plugin that parses OPML feeds.
 */
class FeedsOPMLParser extends ParserBase {

  /**
   * {@inheritdoc}
   */
  public function parse(FeedInterface $feed, FetcherResultInterface $fetcher_result) {
    $opml = ParserOPML::parse($fetcher_result->getRaw());
    $result = new FeedsParserResult($opml['items']);
    $result->title = $opml['title'];
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingSources() {
    return array(
      'title' => array(
        'name' => t('Feed title'),
        'description' => t('Title of the feed.'),
      ),
      'xmlurl' => array(
        'name' => t('Feed URL'),
        'description' => t('URL of the feed.'),
      ),
    ) + parent::getMappingSources();
  }

}
