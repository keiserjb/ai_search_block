<?php

declare(strict_types=1);

namespace Drupal\ai_search_block;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\ResultSet;
use Drupal\search_api\Query\ResultSetInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Helper service to do RA stuff.
 */
class AiSearchBlockHelper implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  public function __construct(protected PrivateTempStoreFactory    $tmpStore,
                              protected EntityTypeManagerInterface $entityTypeManager,
                              protected RendererInterface          $renderer,
                              protected HtmlConverter              $converter,
                              protected AiProviderPluginManager    $aiProviderManager,
                              protected RequestStack               $requestStack,
                              protected LanguageManagerInterface   $languageManager,
                              protected AccountProxyInterface      $currentUser,
                              protected ConfigFactoryInterface     $configFactory,
  ) {

    // Set the default converter settings.
    $this->converter->getConfig()->setOption('strip_tags', TRUE);
    $this->converter->getConfig()->setOption('strip_placeholder_links', TRUE);
    $this->converter->getEnvironment()->addConverter(new TableConverter());
    //parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      new HtmlConverter(),
      $container->get('ai.provider'),
      $container->get('request_stack'),
      $container->get('language_manager'),
      $container->get('current_user'),
      $container->get('config.factory'),
    );
  }

  /**
   *
   */
  public function setConfig($config) {
    $this->configuration = $config;
  }

  /**
   * Take rag action.
   */
  public function searchRagAction($query) {
    // Fall Use the default RAG database from this plugin configuration.
    if (!empty($this->configuration['database'])) {
      $rag_database = $this->configuration;
    }

    if (!isset($rag_database)) {
      $this->setOutputContext('rag', 'No RAG database found.');
      return;
    }
    $results = $this->getRagResults($rag_database, $query);
    // Get the results we are interested in as a string.
    return $this->renderRagResponseAsString($results, $query, $rag_database);
  }

  /**
   * @param $type
   * @param $msg
   *
   * @return mixed
   */
  private function setOutputContext($type, $msg) {
    return $msg;
  }


  /**
   * Full entity check with a LLM checking the rendered entity.
   *
   * @param ItemInterface[] $result_items
   *   The result to check.
   * @param string $query_string
   *   The query to search for.
   * @param array $rag_database
   *   The RAG database array data.
   *
   * @return StreamedResponse
   *   The response.
   */
  protected function fullEntityCheck(array $result_items, string $query_string, array $rag_database) {
    $rendered_entities = [];
    foreach ($result_items as $result) {
      $entity_string = $result->getExtraData('drupal_entity_id');
      // Load the entity from search api key.
      // @todo probably exists a function for this.
      [, $entity_parts, $lang] = explode(':', $entity_string);
      [$entity_type, $entity_id] = explode('/', $entity_parts);
      /** @var ContentEntityBase */
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);

      // Get translated if possible.
      if (
        $entity instanceof TranslatableInterface
        && $entity->language()->getId() !== $lang
        && $entity->hasTranslation($lang)
      ) {
        $entity = $entity->getTranslation($lang);
      }

      // Render the entity in selected view mode.

      $view_mode = $this->configuration['aggregated_llm'] ?? 'full';
      $pre_render_entity = $this->entityTypeManager->getViewBuilder($entity_type)->view($entity, $view_mode);
      $rendered = $this->renderer->render($pre_render_entity);
      $rendered_entities[] = $this->converter->convert((string)$rendered);
    }

    $message = str_replace([
      '[question]',
      '[entity]',
    ], [
      $query_string,
      implode("\n------------\n", $rendered_entities),
    ], nl2br($this->configuration['aggregated_llm']));

    foreach ($this->getPrePromptDrupalContext() as $key => $replace) {
      $message = str_replace('[' . $key . ']', is_null($replace) ? '' : $replace, $message);
    }

    $tomorrow = strtotime('+ 1 day');
    $yesterday = strtotime('- 1 day');
    $date_today = date("D M j G:i:s T Y");
    $date_tomorrow = date("D M j G:i:s T Y", $tomorrow);
    $date_yesterday = date("D M j G:i:s T Y", $yesterday);
    $time_now = date("H:i:s");

    $message = str_replace('[time_now]', $time_now, $message);
    $message = str_replace('[date_today]',  $date_today, $message);
    $message = str_replace('[date_tomorrow]',  $date_tomorrow, $message);
    $message = str_replace('[date_yesterday]', $date_yesterday, $message);

    // Now we have the entity, we can check it with the LLM.
    $ai_provider_model = $this->configuration['llm_model'];

    if ($ai_provider_model === '') {
      $default_provider = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      $ai_provider_model = $default_provider['provider_id'] . '__' . $default_provider['model_id'];
      $ai_model_to_use = $default_provider['model_id'];
    }
    else {
      $parts = explode('__', $ai_provider_model);
      $ai_model_to_use = $parts[1];
    }

    $provider = $this->aiProviderManager->loadProviderFromSimpleOption($ai_provider_model);
    $config = [];
    foreach ($this->configuration as $key => $val) {
      $config[$key] = $val;
    }
    //$provider->setConfiguration($config);
    $input = new ChatInput([
      new ChatMessage('user', $message),
    ]);


    if ($this->configuration['stream']) {
      $provider->streamedOutput();
      $output = $provider->chat($input, $ai_model_to_use, ['ai_search_block']);
      $response = $output->getNormalized();
      if (is_object($response) && $response instanceof StreamedChatMessageIteratorInterface) {
        return new StreamedResponse(function () use ($response) {
          foreach ($response as $message) {
            $item = [];
            $item['in_html'] = FALSE;
            $item['answer_piece'] = $message->getText();
            $out = json_encode($item);
            unset($item);
            echo $out . '|§|';
            //echo $message->getText();
            ob_flush();
            flush();
          }
        }, 200, [
          'Cache-Control' => 'no-cache, must-revalidate',
          'Content-Type' => 'text/event-stream',
          'X-Accel-Buffering' => 'no',
        ]);
      }
      else {
        $output = $provider->chat($input, $ai_model_to_use, ['ai_search_block']);
        $response = $output->getNormalized()->getText() . "\n";
        return $response;
      }
    }
    else {
      $output = $provider->chat($input, $ai_model_to_use, ['ai_search_block']);
      $response = $output->getNormalized()->getText() . "\n";
      return $response;
    }
  }

  /**
   * Get preprompt Drupal context.
   *
   * @return string[]
   *   This is the Drupal context that you can add to the pre prompt.
   */
  public function getPrePromptDrupalContext() {
    $context = [];
    $current_request = $this->requestStack->getCurrentRequest();
    $context['is_logged_in'] = $this->currentUser->isAuthenticated() ? 'is logged in' : 'is not logged in';
    $context['user_roles'] = implode(', ', $this->currentUser->getRoles());
    $context['user_id'] = $this->currentUser->id();
    $context['user_name'] = $this->currentUser->getDisplayName();
    $context['user_language'] = $this->currentUser->getPreferredLangcode();
    $context['user_timezone'] = $this->currentUser->getTimeZone();
    $context['page_path'] = $current_request->getRequestUri();
    $context['page_language'] = $this->languageManager->getCurrentLanguage()->getId();
    $context['site_name'] = $this->configFactory->get('system.site')->get('name');
    return $context;
  }

  /**
   * Process RAG.
   *
   * @param array $rag_database
   *   The RAG database array data.
   * @param string $query_string
   *   The query to search for (optional).
   *
   * @return ResultSetInterface
   *   The RAG response.
   */
  protected function getRagResults(array $rag_database, string $query_string = '') {
    /** @var Index */
    $rag_storage = $this->entityTypeManager->getStorage('search_api_index');
    // Get the index.
    $index = $rag_storage->load($rag_database['database']);
    if (!$index) {
      throw new Exception('RAG database not found.');
    }

    // Then we try to search.
    try {
      $query = $index->query([
        'limit' => $this->configuration['max_results'],
      ]);
      $query->setOption('search_api_bypass_access', !$this->configuration['access_check']);
      $query->setOption('search_api_ai_get_chunks_result', $this->configuration['output_mode'] == 'chunks');
      $queries = $query_string;
      $query->keys($queries);
      $results = $query->execute();
    }
    catch (Exception $e) {
      throw new Exception('Failed to search: ' . $e->getMessage());
    }
    return $results;
  }

  /**
   * Render the RAG response as string.
   *
   * @param ResultSet $results
   *   The RAG results.
   * @param string $query
   *   The query to search for (optional).
   * @param array $rag_database
   *   The RAG database array data.
   *
   * @return string
   *   The RAG response.
   */
  protected function renderRagResponseAsString($results, string $query, array $rag_database) {
    $result_items = [];
    foreach ($results->getResultItems() as $result) {
      // Filter the results.
      if ($this->configuration['score_threshold'] > $result->getScore()) {
        continue;
      }

      $result_items[] = $result;

      // Chunked mode is easy.
      if ($this->configuration['output_mode'] == 'chunks') {
        return $result->getExtraData('content') . "\n\n";
      }
    }
    // For the full entity check, we make a single subsequent chat call to
    // have the LLM extract relevant data for the conversation based on the
    // question the user asked.
    if ($this->configuration['output_mode'] === 'rendered' && !empty($result_items)) {
      return $this->fullEntityCheck($result_items, $query, $rag_database);
    }
  }

}
