<?php

/**
 * @file
 * Contains \Drupal\feeds\RawFetcherResult.
 */

namespace Drupal\feeds;

/**
 * The default fetcher result object.
 */
class RawFetcherResult extends FetcherResult {

  /**
   * The raw input string.
   *
   * @var string
   */
  protected $raw;

  /**
   * Constructs a new RawFetcherResult object.
   *
   * @param string $raw
   *   The raw result string.
   */
  public function __construct($raw) {
    $this->raw = $raw;
  }

  /**
   * {@inheritdoc}
   */
  public function getRaw() {
    return $this->sanitizeRaw($this->raw);
  }

  /**
   * {@inheritdoc}
   */
  public function getFilePath() {

    // Write to a temporary file if the parser expects a file.
    if (!$this->filePath) {
      $this->filePath = drupal_tempnam('temporary://', 'feeds-raw');
      file_put_contents($this->filePath, $this->getRaw());
    }

    return $this->filePath;
  }

}
