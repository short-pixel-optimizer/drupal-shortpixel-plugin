<?php

/*
 * This is a demo implementation for ShortPixel's POST-REDUCER API.
 * See more info about POST-REDUCER API at shortpixel.com/api-docs.
 *
 * # What to know?
 * ShortPixel API is split in two parts.
 * Reducer API -> shrinks images based on URL (so doesn't work locally)
 * POST Reducer API -> allows you to shrink an image that is not accessible online by uploading
 *                     it via a POST HTTP call.
 *
 * See PHP Multipart usage example on the official docs at shortpixel.com/api-docs.
 *
 * # What's going on here?
 * Each time Drupal ImageStyle generates a new image (using image style most likely), the file is sent
 * to ShortPixel and overrides the result with the optimizes version.
 *
 * ## Why the original file is not overrided?
 * This is because Drupal manages 'the image-style' files within the '/styles' subdirectory.
 * This is important because the orginal files are left untouched and the Drupal uses only
 * the files within '/styles' directory which are indeed overriden.
 *
 * This all happens when Drupal calls this processor in 'applyToImage($image_uri)'
 *
 * # How applyImage works?
 * Then we validate the API key and check if the file path actually exists.
 * We log beforeSize for debugging purposes.
 *
 * At 'callPostReducer' we call ShortPixel and send our file. Here we:
 * 1. do multipart POST (check shortpixel.com/api-docs)
 * 2. load the local file
 * 3. ShortPixel starts processing (Returns a Status Code (1 - pending, 2 - done), OriginalURL, LossyURL/GlossyURL/LosslessURL)
 *
 * Then we enter a poll.
 * We do not load again the file, we instead check if it was loaded and not wait to seconds to repeat, this happens 3 times.
 *
 * Finally if the Code is not 2, we can return TRUE. Why TRUE? This will use the orginal image instead of throwing an error.
 * This is a common pattern across this function because it allows Drupal to continue the process anyway.
 *
 * Then we download the optimizedImage (Lossy/Glossy/Lossless depending on config)
 *
 * Then override the file itself (this is the big moment)
 *
 * And we end the function with a final debug call.
 */

// Check the namings are correct.
namespace Drupal\imageapi_optimize_shortpixel\Plugin\ImageAPIOptimizeProcessor;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\imageapi_optimize\ConfigurableImageAPIOptimizeProcessorBase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uses the ShortPixel webservice to optimize an image.
 *
 * @ImageAPIOptimizeProcessor(
 *   id = "shortpixel",
 *   label = @Translation("ShortPixel"),
 *   description = @Translation("Uses the ShortPixel service to optimize images.")
 * )
 */
class ShortPixel extends ConfigurableImageAPIOptimizeProcessorBase {

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerInterface $logger,
    ImageFactory $image_factory,
    FileSystemInterface $file_system,
    ClientInterface $http_client
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $image_factory);
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('imageapi_optimize'),
      $container->get('image.factory'),
      $container->get('file_system'),
      $container->get('http_client')
    );
  }

  /**
   * Maps compression_type to ShortPixel "lossy" parameter:
   *   1 = lossy, 2 = glossy, 0 = lossless.
   */
  protected function getLossyValue(): int {
    return match ($this->configuration['compression_type'] ?? 'glossy') {
      'lossless' => 0,
      'lossy' => 1,
      'glossy' => 2,
      default => 2,
    };
  }

  /**
   * Pick the correct download URL field based on compression_type.
   */
  protected function getDownloadUrlFromMeta(array $meta): string {
    $candidates = [
      // AVIF preferred.
      $meta['AVIFLossyURL'] ?? '',
      $meta['AVIFLosslessURL'] ?? '',

      // WebP fallback.
      $meta['WebPLossyURL'] ?? '',
      $meta['WebPLosslessURL'] ?? '',

      // Original optimized format fallback.
      $meta['LossyURL'] ?? '',
      $meta['LosslessURL'] ?? '',
    ];

    foreach ($candidates as $url) {
      if (!empty($url) && $url !== 'NA') {
        return (string) $url;
      }
    }

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function applyToImage($image_uri) {
    if (!empty($this->configuration['use_cdn'])) {
      $this->logger->notice('ShortPixel: CDN delivery enabled, skipping Post-Reducer for @uri.', [
        '@uri' => $image_uri,
      ]);
      return TRUE;
    }

    $apiKey = $this->configuration['api_key'] ?? NULL;

    if (empty($apiKey)) {
      $this->logger->error('ShortPixel: Missing API key.');
      return TRUE;
    }

    // Convert stream wrapper URI (public://...) to real filesystem path.
    $realPath = $this->fileSystem->realpath($image_uri);
    if (!$realPath || !is_file($realPath) || !is_readable($realPath)) {
      $this->logger->error('ShortPixel: File not found or not readable: @uri (@path)', [
        '@uri' => $image_uri,
        '@path' => (string) $realPath,
      ]);
      // Return TRUE to let Drupal continue with the original generated file.
      return TRUE;
    }

    $beforeSize = @filesize($realPath) ?: 0;
    $this->logger->notice('ShortPixel START uri=@uri path=@path size=@size mode=@mode', [
      '@uri' => $image_uri,
      '@path' => $realPath,
      '@size' => $beforeSize,
      '@mode' => (string) ($this->configuration['compression_type'] ?? 'glossy'),
    ]);

    try {
      // 1) Upload via Post-Reducer.
      $meta = $this->callPostReducer($apiKey, $realPath);

      $code = (int) ($meta['Status']['Code'] ?? 0);
      $msg = (string) ($meta['Status']['Message'] ?? '');

      // 2) If pending, poll reducer.php using OriginalURL (no re-upload).
      if ($code === 1 && !empty($meta['OriginalURL'])) {
        for ($i = 0; $i < 3 && $code === 1; $i++) {
          sleep(2);
          $meta = $this->callReducerByOriginalUrl($apiKey, (string) $meta['OriginalURL']);
          $code = (int) ($meta['Status']['Code'] ?? 0);
          $msg = (string) ($meta['Status']['Message'] ?? $msg);
        }
      }

      if ($code !== 2) {
        $this->logger->error('ShortPixel: Optimization failed or not ready. Code: @code Message: @msg File: @path', [
          '@code' => $code,
          '@msg' => $msg,
          '@path' => $realPath,
        ]);
        return TRUE;
      }

      // 3) Download optimized image (based on configured compression type) and overwrite local file.
      $downloadUrl = $this->getDownloadUrlFromMeta($meta);
      $this->logger->notice('ShortPixel: Selected optimized URL: @url', [
        '@url' => $downloadUrl,
      ]);
      if ($downloadUrl === '' || $downloadUrl === 'NA') {
        $this->logger->error('ShortPixel: Missing optimized URL for selected mode. File: @path Mode: @mode', [
          '@path' => $realPath,
          '@mode' => (string) ($this->configuration['compression_type'] ?? 'glossy'),
        ]);
        return TRUE;
      }

      $optimizedResponse = $this->httpClient->request('GET', $downloadUrl, [
        'timeout' => 60,
      ]);

      $optimizedImage = (string) $optimizedResponse->getBody();
      if ($optimizedImage === '') {
        $this->logger->error('ShortPixel: Downloaded optimized content is empty for @path', ['@path' => $realPath]);
        return TRUE;
      }

      // Write optimized image to a temp file first.
      $tempUri = 'temporary://shortpixel_' . uniqid() . '_' . basename($image_uri);

      if ($this->fileSystem->saveData($optimizedImage, $tempUri, FileSystemInterface::EXISTS_REPLACE)) {
        // Now safely replace the styled image.
        $this->fileSystem->copy($tempUri, $image_uri, FileSystemInterface::EXISTS_REPLACE);
        $this->fileSystem->delete($tempUri);

        $afterPath = $this->fileSystem->realpath($image_uri) ?: $realPath;
        $afterSize = @filesize($afterPath) ?: 0;

        $this->logger->notice('ShortPixel DONE uri=@uri size_before=@b size_after=@a url=@u', [
          '@uri' => $image_uri,
          '@b' => $beforeSize,
          '@a' => $afterSize,
          '@u' => $downloadUrl,
        ]);

        return TRUE;
      }


      $this->logger->error('ShortPixel: Failed to save optimized image back to @uri', ['@uri' => $image_uri]);
    }
    catch (\Throwable $e) {
      $this->logger->error('ShortPixel: Exception while optimizing "@uri": @err', [
        '@uri' => $image_uri,
        '@err' => $e->getMessage(),
      ]);
    }

    return TRUE;
  }

  /**
   * Call ShortPixel Post-Reducer API (uploads the file).
   *
   * @return array
   *   First metadata item.
   */
  protected function callPostReducer(string $apiKey, string $realPath): array {
    $fileField = 'file1';

    $response = $this->httpClient->request('POST', 'https://api.shortpixel.com/v2/post-reducer.php', [
      'multipart' => [
        [
          'name' => 'key',
          'contents' => $apiKey,
        ],
        [
          'name' => 'plugin_version',
          'contents' => 'DRP01', // max 5 chars
        ],
        [
          'name' => 'lossy',
          'contents' => (string) $this->getLossyValue(), // 1 lossy, 2 glossy, 0 lossless
        ],
        [
          'name' => 'wait',
          'contents' => '30',
        ],
        [
          'name' => 'convertto',
          'contents' => '+avif|+webp',
        ],
        [
          'name' => 'refresh',
          'contents' => '0',
        ],
        [
          // Must be JSON with double quotes.
          'name' => 'file_paths',
          'contents' => json_encode([$fileField => $realPath], JSON_UNESCAPED_SLASHES),
        ],
        [
          'name' => $fileField,
          'contents' => fopen($realPath, 'rb'),
          'filename' => basename($realPath),
        ],
      ],
      'headers' => [
        'Accept' => 'application/json',
      ],
      'timeout' => 60,
    ]);

    $result = json_decode((string) $response->getBody(), TRUE);

    // API returns an array with one item per file.
    if (is_array($result) && isset($result[0]) && is_array($result[0])) {
      return $result[0];
    }

    return is_array($result) ? $result : [];
  }

  /**
   * Poll reducer.php using the OriginalURL received from post-reducer.
   *
   * @return array
   *   First metadata item.
   */
  protected function callReducerByOriginalUrl(string $apiKey, string $originalUrl): array {
    // ShortPixel examples show urlencoded entries inside urllist.
    $encoded = rawurlencode($originalUrl);

    $payload = [
      'key' => $apiKey,
      'plugin_version' => 'DRP01',
      'lossy' => $this->getLossyValue(),
      'wait' => 20,
      'urllist' => [$encoded],
    ];

    $response = $this->httpClient->request('POST', 'https://api.shortpixel.com/v2/reducer.php', [
      'headers' => [
        'Accept' => 'application/json',
      ],
      'json' => $payload,
      'timeout' => 60,
    ]);

    $result = json_decode((string) $response->getBody(), TRUE);

    if (is_array($result) && isset($result[0]) && is_array($result[0])) {
      return $result[0];
    }

    return is_array($result) ? $result : [];
  }

  /**
   * Force Drupal GD JPEG quality globally.
   */
  protected function enforceDrupalJpegQuality(int $quality): void {
    $quality = max(0, min(100, $quality));
    $config = \Drupal::configFactory()->getEditable('system.image.gd');

    if ((int) $config->get('jpeg_quality') !== $quality) {
      $config->set('jpeg_quality', $quality)->save();
      $this->logger->notice('ShortPixel: Set Drupal GD JPEG quality to @quality.', [
        '@quality' => $quality,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => NULL,
      'compression_type' => 'glossy',
      'force_drupal_jpeg_quality' => TRUE,
      'use_cdn' => FALSE,
      'cdn_base_url' => 'https://cdn.shortpixel.ai',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['setup_help'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p><strong>Thank you for using ShortPixel.</strong> If you want the simplest setup, paste your API key and keep the default compression mode on <em>Glossy</em>. If you want faster image delivery, enable <em>Serve images through ShortPixel CDN</em> so your visitors receive images from the ShortPixel server closest to them.</p>'),
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ShortPixel API key'),
      '#description' => $this->t('Paste your ShortPixel API key here. You can get it from <a href="https://shortpixel.com" target="_blank">shortpixel.com</a>. For most websites, this is the standard setup and all you need.'),
      '#default_value' => $this->configuration['api_key'],
      '#size' => 32,
      '#required' => FALSE,
      '#states' => [
        'required' => [
          ':input[name="data[configuration][use_cdn]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['compression_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Compression type'),
      '#description' => $this->t('Choose how strongly images should be optimized. If you are not sure, keep <em>Glossy</em>.'),
      '#options' => [
        'lossy' => $this->t('Lossy - smallest files, best when page speed matters most'),
        'glossy' => $this->t('Glossy - recommended for most sites, keeps very good visual quality'),
        'lossless' => $this->t('Lossless - keeps every pixel, but file sizes stay larger'),
      ],
      '#default_value' => $this->configuration['compression_type'] ?? 'glossy',
    ];

    $form['force_drupal_jpeg_quality'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let ShortPixel handle JPEG quality first'),
      '#description' => $this->t('Recommended. This changes Drupal\'s global JPEG quality setting to 100 so images are not compressed once by Drupal and then again by ShortPixel. That usually helps avoid washed-out or overly compressed results.'),
      '#default_value' => (bool) ($this->configuration['force_drupal_jpeg_quality'] ?? TRUE),
    ];

    $form['use_cdn'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve images through ShortPixel CDN'),
      '#description' => $this->t('Recommended if you want faster image delivery. With CDN enabled, ShortPixel serves images from the server closest to each visitor, which usually means faster loading times. Your site still generates the image URLs, but ShortPixel handles the final delivery.'),
      '#default_value' => (bool) ($this->configuration['use_cdn'] ?? FALSE),
    ];

    $form['cdn_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ShortPixel CDN URL'),
      '#description' => $this->t('The default value is correct for most sites. Change it only if ShortPixel told you to use a different delivery URL, such as <code>https://no-cdn.shortpixel.ai</code>.'),
      '#default_value' => $this->configuration['cdn_base_url'] ?? 'https://cdn.shortpixel.ai',
      '#size' => 48,
      '#states' => [
        'visible' => [
          ':input[name="data[configuration][use_cdn]"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="data[configuration][use_cdn]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $useCdn = (bool) $form_state->getValue('use_cdn');
    $apiKey = trim((string) $form_state->getValue('api_key'));
    $cdnBaseUrl = trim((string) $form_state->getValue('cdn_base_url'));

    if (!$useCdn && $apiKey === '') {
      $form_state->setErrorByName('api_key', $this->t('Enter your ShortPixel API key, or enable CDN delivery if you want ShortPixel to serve images through its CDN instead.'));
    }

    if ($useCdn) {
      if ($cdnBaseUrl === '') {
        $form_state->setErrorByName('cdn_base_url', $this->t('Enter the ShortPixel CDN URL when CDN delivery is enabled.'));
      }
      elseif (!preg_match('@^https?://@i', $cdnBaseUrl)) {
        $form_state->setErrorByName('cdn_base_url', $this->t('The ShortPixel CDN URL must start with http:// or https://.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $this->configuration['api_key'] = trim((string) $form_state->getValue('api_key'));
    $this->configuration['compression_type'] = $form_state->getValue('compression_type');
    $this->configuration['force_drupal_jpeg_quality'] = (bool) $form_state->getValue('force_drupal_jpeg_quality');
    $this->configuration['use_cdn'] = (bool) $form_state->getValue('use_cdn');
    $this->configuration['cdn_base_url'] = trim((string) $form_state->getValue('cdn_base_url'));
    unset($this->configuration['debug_cdn_rewrite']);

    \Drupal::configFactory()
      ->getEditable('imageapi_optimize_shortpixel.settings')
      ->set('use_cdn', $this->configuration['use_cdn'])
      ->set('cdn_base_url', $this->configuration['cdn_base_url'] ?: 'https://cdn.shortpixel.ai')
      ->clear('debug_cdn_rewrite')
      ->set('compression_type', $this->configuration['compression_type'])
      ->save();

    if ($this->configuration['force_drupal_jpeg_quality']) {
      $this->enforceDrupalJpegQuality(100);
    }
  }

}
